<?php
/**
 * Service responsible for registering template and component post types.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Template_Service {
    const TEMPLATE_POST_TYPE        = 'astra_template';
    const COMPONENT_POST_TYPE       = 'astra_component';
    const META_CONDITIONS           = '_astra_builder_conditions';
    const META_RENDERED_MARKUP      = '_astra_builder_rendered_html';
    const META_CRITICAL_CSS         = '_astra_builder_critical_css';
    const META_STYLE_OVERRIDES      = '_astra_builder_style_overrides';
    const PREVIEW_TRANSIENT_PREFIX  = 'astra_builder_preview_';
    const PREVIEW_QUERY_VAR         = 'astra_builder_preview';
    const PREVIEW_TRANSIENT_EXPIRY  = 6; // Hours.
    const META_ASSET_MANIFEST       = '_astra_builder_asset_manifest';
    const META_RESOURCE_HINTS       = '_astra_builder_resource_hints';
    const META_COMMENTS             = '_astra_builder_collab_comments';
    const META_SECTION_LOCKS        = '_astra_builder_collab_locks';
    const META_SECTION_STATE        = '_astra_builder_collab_sections';
    const META_PATTERN_SLUG         = '_astra_builder_pattern_slug';
    const META_PATTERN_FALLBACK     = '_astra_builder_pattern_fallback_id';
    const META_LANGUAGE             = '_astra_builder_language';
    const LCP_THRESHOLD             = 2500; // Milliseconds.
    const CLS_THRESHOLD             = 0.1;

    /**
     * Token service dependency.
     *
     * @var Astra_Builder_Token_Service|null
     */
    protected $tokens;

    /**
     * Whether language scoping is active.
     *
     * @var bool
     */
    protected $language_scope_enabled = true;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Token_Service|null $tokens Token service.
     */
    public function __construct( ?Astra_Builder_Token_Service $tokens = null ) {
        $this->tokens = $tokens;
    }

    /**
     * Bootstrap the service.
     */
    public function register() {
        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'init', array( $this, 'register_post_meta' ) );
        add_action( 'save_post_' . self::TEMPLATE_POST_TYPE, array( $this, 'generate_template_artifacts' ), 10, 3 );
        add_action( 'save_post_' . self::COMPONENT_POST_TYPE, array( $this, 'generate_template_artifacts' ), 10, 3 );
        add_filter( 'query_vars', array( $this, 'add_preview_query_var' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render_preview' ) );
        add_action( 'init', array( $this, 'register_pattern_exports' ), 20 );
        add_action( 'pre_get_posts', array( $this, 'maybe_scope_language' ) );
    }

    /**
     * Register custom post types that power saved templates and components.
     */
    public function register_post_types() {
        $supports = array( 'title', 'editor', 'revisions', 'custom-fields' );

        register_post_type(
            self::TEMPLATE_POST_TYPE,
            array(
                'label'               => __( 'Astra Templates', 'astra-builder' ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_rest'        => true,
                'capability_type'     => 'page',
                'map_meta_cap'        => true,
                'supports'            => $supports,
                'hierarchical'        => false,
                'rewrite'             => false,
                'menu_position'       => 26,
                'menu_icon'           => 'dashicons-layout',
                'rest_base'           => 'astra-template',
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
            )
        );

        register_post_type(
            self::COMPONENT_POST_TYPE,
            array(
                'label'               => __( 'Astra Components', 'astra-builder' ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => 'edit.php?post_type=' . self::TEMPLATE_POST_TYPE,
                'show_in_rest'        => true,
                'capability_type'     => 'page',
                'map_meta_cap'        => true,
                'supports'            => $supports,
                'hierarchical'        => false,
                'rewrite'             => false,
                'rest_base'           => 'astra-component',
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
            )
        );
    }

    /**
     * Register meta used to store template conditions and compiled output.
     */
    public function register_post_meta() {
        $meta_args = array(
            'type'           => 'array',
            'single'         => true,
            'show_in_rest'   => array(
                'schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'postTypes' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'string' ),
                        ),
                        'taxonomies' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'string' ),
                        ),
                        'roles' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
            'auth_callback'  => function() {
                return current_user_can( 'edit_theme_options' );
            },
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_CONDITIONS, $meta_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_CONDITIONS, $meta_args );

        $compiled_args = array(
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => false,
            'auth_callback'=> function() {
                return current_user_can( 'edit_theme_options' );
            },
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_RENDERED_MARKUP, $compiled_args );
        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_CRITICAL_CSS, $compiled_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_RENDERED_MARKUP, $compiled_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_CRITICAL_CSS, $compiled_args );

        $style_args = array(
            'type'              => 'object',
            'single'            => true,
            'show_in_rest'      => array(
                'schema' => array(
                    'type' => 'object',
                ),
            ),
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_style_overrides' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_STYLE_OVERRIDES, $style_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_STYLE_OVERRIDES, $style_args );

        $pattern_slug_args = array(
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => true,
            'auth_callback'=> function() {
                return current_user_can( 'edit_theme_options' );
            },
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_PATTERN_SLUG, $pattern_slug_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_PATTERN_SLUG, $pattern_slug_args );

        $fallback_args = array(
            'type'         => 'integer',
            'single'       => true,
            'show_in_rest' => false,
            'auth_callback'=> function() {
                return current_user_can( 'edit_theme_options' );
            },
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_PATTERN_FALLBACK, $fallback_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_PATTERN_FALLBACK, $fallback_args );

        $language_args = array(
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => true,
            'auth_callback'=> function() {
                return current_user_can( 'edit_theme_options' );
            },
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_LANGUAGE, $language_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_LANGUAGE, $language_args );

        $asset_args = array(
            'type'              => 'object',
            'single'            => true,
            'show_in_rest'      => array(
                'schema' => array(
                    'type' => 'object',
                ),
            ),
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_asset_manifest' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_ASSET_MANIFEST, $asset_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_ASSET_MANIFEST, $asset_args );

        $hint_args = array(
            'type'              => 'array',
            'single'            => true,
            'show_in_rest'      => array(
                'schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'rel'    => array( 'type' => 'string' ),
                            'as'     => array( 'type' => 'string' ),
                            'href'   => array( 'type' => 'string' ),
                            'handle' => array( 'type' => 'string' ),
                            'type'   => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_resource_hints' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_RESOURCE_HINTS, $hint_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_RESOURCE_HINTS, $hint_args );

        $comment_args = array(
            'type'              => 'array',
            'single'            => true,
            'show_in_rest'      => array(
                'schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'author'  => array( 'type' => 'string' ),
                            'message' => array( 'type' => 'string' ),
                            'time'    => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_comment_threads' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_COMMENTS, $comment_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_COMMENTS, $comment_args );

        $lock_args = array(
            'type'              => 'object',
            'single'            => true,
            'show_in_rest'      => true,
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_section_locks' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_SECTION_LOCKS, $lock_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_SECTION_LOCKS, $lock_args );

        $state_args = array(
            'type'              => 'object',
            'single'            => true,
            'show_in_rest'      => true,
            'auth_callback'     => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'sanitize_callback' => array( $this, 'sanitize_section_state' ),
        );

        register_post_meta( self::TEMPLATE_POST_TYPE, self::META_SECTION_STATE, $state_args );
        register_post_meta( self::COMPONENT_POST_TYPE, self::META_SECTION_STATE, $state_args );
    }

    /**
     * Register exported sections as block patterns.
     */
    public function register_pattern_exports() {
        if ( ! function_exists( 'register_block_pattern' ) ) {
            return;
        }

        register_block_pattern_category(
            'astra-builder',
            array(
                'label' => __( 'Astra Builder', 'astra-builder' ),
            )
        );

        $this->set_language_scope_enabled( false );

        $sections = get_posts(
            array(
                'post_type'      => array( self::COMPONENT_POST_TYPE, self::TEMPLATE_POST_TYPE ),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            )
        );

        foreach ( $sections as $section ) {
            $slug = $this->ensure_pattern_slug( $section );

            register_block_pattern(
                'astra-builder/' . $slug,
                array(
                    'title'       => $section->post_title,
                    'description' => __( 'Exported from Astra Builder.', 'astra-builder' ),
                    'categories'  => array( 'astra-builder' ),
                    'content'     => $section->post_content,
                )
            );
        }

        $this->set_language_scope_enabled( true );
    }

    /**
     * Guarantee pattern slugs exist for a post.
     *
     * @param WP_Post $post Post object.
     *
     * @return string
     */
    protected function ensure_pattern_slug( $post ) {
        $slug = get_post_meta( $post->ID, self::META_PATTERN_SLUG, true );

        if ( empty( $slug ) ) {
            $slug = sanitize_title( $post->post_name ? $post->post_name : $post->post_title );
            update_post_meta( $post->ID, self::META_PATTERN_SLUG, $slug );
        }

        return $slug;
    }

    /**
     * Synchronize fallback reusable block markup for graceful degradation.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    protected function synchronize_fallback_block( $post_id, $post ) {
        if ( 'publish' !== $post->post_status ) {
            return;
        }

        $slug    = $this->ensure_pattern_slug( $post );
        $content = $post->post_content;
        $data    = array(
            'post_title'   => $post->post_title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'wp_block',
        );

        $fallback_id = (int) get_post_meta( $post_id, self::META_PATTERN_FALLBACK, true );

        if ( $fallback_id && get_post( $fallback_id ) ) {
            $data['ID'] = $fallback_id;
            $result     = wp_update_post( $data, true );
        } else {
            $result = wp_insert_post( $data, true );
        }

        if ( ! is_wp_error( $result ) ) {
            update_post_meta( $post_id, self::META_PATTERN_FALLBACK, (int) $result );
        }
    }

    /**
     * Return block pattern payload for REST exports.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_pattern_payload( $post_id ) {
        $post = $this->get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $slug = $this->ensure_pattern_slug( $post );

        return array(
            'name'        => 'astra-builder/' . $slug,
            'title'       => $post->post_title,
            'description' => __( 'Exported from Astra Builder.', 'astra-builder' ),
            'categories'  => array( 'astra-builder' ),
            'content'     => $post->post_content,
        );
    }

    /**
     * Convert Gutenberg markup into a canvas-friendly data structure.
     *
     * @param string $markup Raw block markup.
     *
     * @return array
     */
    public function convert_markup_to_layout( $markup ) {
        $markup = (string) $markup;
        $blocks = $this->apply_block_migrations( parse_blocks( $markup ) );

        return array(
            'layout' => $this->prepare_blocks_for_canvas( $blocks ),
            'markup' => $markup,
        );
    }

    /**
     * Normalize block structures so the canvas can render them.
     *
     * @param array $blocks Parsed blocks.
     *
     * @return array
     */
    protected function prepare_blocks_for_canvas( $blocks ) {
        $normalized = array();

        foreach ( $blocks as $block ) {
            $normalized[] = array(
                'name'       => isset( $block['blockName'] ) ? $block['blockName'] : 'core/group',
                'attributes' => isset( $block['attrs'] ) ? $block['attrs'] : array(),
                'content'    => isset( $block['innerHTML'] ) ? wp_kses_post( $block['innerHTML'] ) : '',
                'inner'      => ! empty( $block['innerBlocks'] ) ? $this->prepare_blocks_for_canvas( $block['innerBlocks'] ) : array(),
            );
        }

        return $normalized;
    }

    /**
     * Sanitize inline comment threads.
     *
     * @param mixed $value Meta value.
     *
     * @return array
     */
    public function sanitize_comment_threads( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $value as $thread ) {
            if ( empty( $thread['id'] ) || empty( $thread['blockId'] ) ) {
                continue;
            }

            $comments = array();

            if ( ! empty( $thread['comments'] ) && is_array( $thread['comments'] ) ) {
                foreach ( $thread['comments'] as $comment ) {
                    if ( empty( $comment['id'] ) || empty( $comment['message'] ) ) {
                        continue;
                    }

                    $comments[] = array(
                        'id'      => sanitize_text_field( $comment['id'] ),
                        'message' => wp_kses_post( $comment['message'] ),
                        'created' => isset( $comment['created'] ) ? sanitize_text_field( $comment['created'] ) : '',
                        'author'  => array(
                            'id'     => isset( $comment['author']['id'] ) ? (int) $comment['author']['id'] : 0,
                            'name'   => isset( $comment['author']['name'] ) ? sanitize_text_field( $comment['author']['name'] ) : '',
                            'avatar' => isset( $comment['author']['avatar'] ) ? esc_url_raw( $comment['author']['avatar'] ) : '',
                        ),
                    );
                }
            }

            $sanitized[] = array(
                'id'       => sanitize_text_field( $thread['id'] ),
                'blockId'  => sanitize_text_field( $thread['blockId'] ),
                'created'  => isset( $thread['created'] ) ? sanitize_text_field( $thread['created'] ) : '',
                'resolved' => ! empty( $thread['resolved'] ),
                'comments' => $comments,
            );
        }

        return $sanitized;
    }

    /**
     * Sanitize section locks.
     *
     * @param mixed $value Meta value.
     *
     * @return array
     */
    public function sanitize_section_locks( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $locks = array();

        foreach ( $value as $section => $lock ) {
            $locks[ sanitize_key( $section ) ] = array(
                'user'      => isset( $lock['user'] ) ? (int) $lock['user'] : 0,
                'name'      => isset( $lock['name'] ) ? sanitize_text_field( $lock['name'] ) : '',
                'role'      => isset( $lock['role'] ) ? sanitize_text_field( $lock['role'] ) : '',
                'timestamp' => isset( $lock['timestamp'] ) ? sanitize_text_field( $lock['timestamp'] ) : '',
            );
        }

        return $locks;
    }

    /**
     * Sanitize section workflow state.
     *
     * @param mixed $value Meta value.
     *
     * @return array
     */
    public function sanitize_section_state( $value ) {
        if ( ! is_array( $value ) ) {
            return $this->get_default_section_state();
        }

        $sections = $this->get_default_section_state();

        foreach ( $sections as $key => $defaults ) {
            if ( empty( $value[ $key ] ) || ! is_array( $value[ $key ] ) ) {
                continue;
            }

            $incoming = $value[ $key ];
            $sections[ $key ] = array(
                'status'  => isset( $incoming['status'] ) ? sanitize_key( $incoming['status'] ) : $defaults['status'],
                'updated' => isset( $incoming['updated'] ) ? sanitize_text_field( $incoming['updated'] ) : '',
                'user'    => isset( $incoming['user'] ) ? (int) $incoming['user'] : 0,
                'log'     => isset( $incoming['log'] ) && is_array( $incoming['log'] ) ? array_map(
                    function( $entry ) {
                        return array(
                            'status' => isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'draft',
                            'user'   => isset( $entry['user'] ) ? (int) $entry['user'] : 0,
                            'time'   => isset( $entry['time'] ) ? sanitize_text_field( $entry['time'] ) : '',
                        );
                    },
                    $incoming['log']
                ) : array(),
            );
        }

        return $sections;
    }

    /**
     * Fetch saved comment threads.
     *
     * @param int $post_id Post identifier.
     *
     * @return array
     */
    public function get_comment_threads( $post_id ) {
        $threads = get_post_meta( $post_id, self::META_COMMENTS, true );

        return $this->sanitize_comment_threads( $threads );
    }

    /**
     * Persist comment threads.
     *
     * @param int   $post_id Post identifier.
     * @param array $threads Thread collection.
     */
    public function save_comment_threads( $post_id, $threads ) {
        update_post_meta( $post_id, self::META_COMMENTS, $this->sanitize_comment_threads( $threads ) );
    }

    /**
     * Fetch section locks for a post.
     *
     * @param int $post_id Post identifier.
     *
     * @return array
     */
    public function get_section_locks( $post_id ) {
        $locks = get_post_meta( $post_id, self::META_SECTION_LOCKS, true );

        return $this->sanitize_section_locks( $locks );
    }

    /**
     * Save section locks.
     *
     * @param int   $post_id Post identifier.
     * @param array $locks   Lock payload.
     */
    public function save_section_locks( $post_id, $locks ) {
        update_post_meta( $post_id, self::META_SECTION_LOCKS, $this->sanitize_section_locks( $locks ) );
    }

    /**
     * Retrieve section workflow state.
     *
     * @param int $post_id Post identifier.
     *
     * @return array
     */
    public function get_section_state( $post_id ) {
        $state = get_post_meta( $post_id, self::META_SECTION_STATE, true );

        return $this->sanitize_section_state( $state );
    }

    /**
     * Save section workflow state.
     *
     * @param int   $post_id Post identifier.
     * @param array $state   Section state payload.
     */
    public function save_section_state( $post_id, $state ) {
        update_post_meta( $post_id, self::META_SECTION_STATE, $this->sanitize_section_state( $state ) );
    }

    /**
     * Provide default section metadata.
     *
     * @return array
     */
    public function get_section_catalog() {
        return array(
            'layout'  => array(
                'label'       => __( 'Layout', 'astra-builder' ),
                'description' => __( 'Overall arrangement of sections and blocks.', 'astra-builder' ),
            ),
            'content' => array(
                'label'       => __( 'Content', 'astra-builder' ),
                'description' => __( 'Copywriting, media, and data-driven blocks.', 'astra-builder' ),
            ),
            'styles'  => array(
                'label'       => __( 'Design system', 'astra-builder' ),
                'description' => __( 'Color tokens, typography, and component-specific styling.', 'astra-builder' ),
            ),
        );
    }

    /**
     * Return the default state for sections.
     *
     * @return array
     */
    public function get_default_section_state() {
        $defaults = array();

        foreach ( $this->get_section_catalog() as $key => $section ) {
            $defaults[ $key ] = array(
                'status'  => 'draft',
                'updated' => '',
                'user'    => 0,
                'log'     => array(),
            );
        }

        return $defaults;
    }

    /**
     * Sanitize saved style override data.
     *
     * @param mixed $value Raw value.
     *
     * @return array
     */
    public function sanitize_style_overrides( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $value as $key => $item ) {
            $clean_key = is_string( $key ) ? preg_replace( '/[^a-zA-Z0-9._-]/', '', $key ) : sanitize_key( $key );

            if ( empty( $clean_key ) ) {
                continue;
            }

            if ( is_array( $item ) ) {
                $sanitized[ $clean_key ] = $this->sanitize_style_overrides( $item );
                continue;
            }

            if ( is_bool( $item ) ) {
                $sanitized[ $clean_key ] = (bool) $item;
                continue;
            }

            $sanitized[ $clean_key ] = sanitize_text_field( (string) $item );
        }

        return $sanitized;
    }

    /**
     * Sanitize stored asset manifests.
     *
     * @param mixed $value Raw value.
     *
     * @return array
     */
    public function sanitize_asset_manifest( $value ) {
        if ( ! is_array( $value ) ) {
            return array(
                'styles'  => array(),
                'scripts' => array(),
                'blocks'  => array(),
            );
        }

        $clean = array(
            'styles'  => array(),
            'scripts' => array(),
            'blocks'  => array(),
        );

        if ( ! empty( $value['styles'] ) && is_array( $value['styles'] ) ) {
            foreach ( $value['styles'] as $handle ) {
                if ( is_string( $handle ) ) {
                    $clean['styles'][] = sanitize_key( $handle );
                }
            }
        }

        if ( ! empty( $value['scripts'] ) && is_array( $value['scripts'] ) ) {
            foreach ( $value['scripts'] as $handle ) {
                if ( is_string( $handle ) ) {
                    $clean['scripts'][] = sanitize_key( $handle );
                }
            }
        }

        if ( ! empty( $value['blocks'] ) && is_array( $value['blocks'] ) ) {
            foreach ( $value['blocks'] as $key => $details ) {
                $clean_key = sanitize_key( $key );

                if ( empty( $clean_key ) ) {
                    continue;
                }

                $clean['blocks'][ $clean_key ] = array(
                    'name'    => isset( $details['name'] ) ? sanitize_text_field( $details['name'] ) : '',
                    'styles'  => array(),
                    'scripts' => array(),
                );

                if ( ! empty( $details['styles'] ) && is_array( $details['styles'] ) ) {
                    foreach ( $details['styles'] as $handle ) {
                        if ( is_string( $handle ) ) {
                            $clean['blocks'][ $clean_key ]['styles'][] = sanitize_key( $handle );
                        }
                    }
                }

                if ( ! empty( $details['scripts'] ) && is_array( $details['scripts'] ) ) {
                    foreach ( $details['scripts'] as $handle ) {
                        if ( is_string( $handle ) ) {
                            $clean['blocks'][ $clean_key ]['scripts'][] = sanitize_key( $handle );
                        }
                    }
                }
            }
        }

        $clean['styles']  = array_values( array_unique( $clean['styles'] ) );
        $clean['scripts'] = array_values( array_unique( $clean['scripts'] ) );

        return $clean;
    }

    /**
     * Sanitize resource hint definitions.
     *
     * @param mixed $value Raw value.
     *
     * @return array
     */
    public function sanitize_resource_hints( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $clean = array();

        foreach ( $value as $hint ) {
            if ( empty( $hint ) || ! is_array( $hint ) ) {
                continue;
            }

            $href = isset( $hint['href'] ) ? esc_url_raw( $hint['href'] ) : '';

            if ( empty( $href ) ) {
                continue;
            }

            $clean[] = array(
                'rel'    => isset( $hint['rel'] ) ? sanitize_key( $hint['rel'] ) : 'preload',
                'as'     => isset( $hint['as'] ) ? sanitize_text_field( $hint['as'] ) : '',
                'href'   => $href,
                'handle' => isset( $hint['handle'] ) ? sanitize_key( $hint['handle'] ) : '',
                'type'   => isset( $hint['type'] ) ? sanitize_text_field( $hint['type'] ) : '',
            );
        }

        return $clean;
    }

    /**
     * Retrieve sanitized overrides for a template or component.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_style_overrides( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_STYLE_OVERRIDES, true );

        return $this->sanitize_style_overrides( $raw );
    }

    /**
     * Fetch a post associated with a builder type.
     *
     * @param int $post_id Post ID.
     *
     * @return WP_Post|false
     */
    public function get_post( $post_id ) {
        $post = get_post( (int) $post_id );

        if ( ! $post ) {
            return false;
        }

        if ( self::TEMPLATE_POST_TYPE !== $post->post_type && self::COMPONENT_POST_TYPE !== $post->post_type ) {
            return false;
        }

        return $post;
    }

    /**
     * Ensure the preview query variable is public.
     *
     * @param array $vars Query vars.
     * @return array
     */
    public function add_preview_query_var( $vars ) {
        if ( ! in_array( self::PREVIEW_QUERY_VAR, $vars, true ) ) {
            $vars[] = self::PREVIEW_QUERY_VAR;
        }

        return $vars;
    }

    /**
     * Render the preview snapshot when requested via the front end.
     */
    public function maybe_render_preview() {
        $token = get_query_var( self::PREVIEW_QUERY_VAR );

        if ( empty( $token ) ) {
            return;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'edit_theme_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to view this preview.', 'astra-builder' ),
                esc_html__( 'Template Preview', 'astra-builder' ),
                array( 'response' => 403 )
            );
        }

        $snapshot = $this->get_preview_snapshot( $token );

        if ( ! $snapshot ) {
            wp_die(
                esc_html__( 'The requested preview could not be found or has expired.', 'astra-builder' ),
                esc_html__( 'Template Preview', 'astra-builder' ),
                array( 'response' => 404 )
            );
        }

        status_header( 200 );
        nocache_headers();

        echo '<!DOCTYPE html><html><head><meta charset="utf-8" />';
        echo '<title>' . esc_html__( 'Template Preview', 'astra-builder' ) . '</title>';
        echo '<style>body{margin:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827;}';
        echo '.astra-builder-preview{max-width:1200px;margin:0 auto;padding:40px;}';
        echo '</style>';

        if ( ! empty( $snapshot['css'] ) ) {
            printf( '<style id="astra-builder-preview-css">%s</style>', wp_strip_all_tags( $snapshot['css'] ) );
        }

        if ( ! empty( $snapshot['hints'] ) && is_array( $snapshot['hints'] ) ) {
            foreach ( $snapshot['hints'] as $hint ) {
                if ( empty( $hint['href'] ) ) {
                    continue;
                }

                $rel = isset( $hint['rel'] ) ? $hint['rel'] : 'preload';
                $as  = isset( $hint['as'] ) ? $hint['as'] : '';

                printf(
                    '<link rel="%1$s"%2$s href="%3$s" />',
                    esc_attr( $rel ),
                    $as ? ' as="' . esc_attr( $as ) . '"' : '',
                    esc_url( $hint['href'] )
                );
            }
        }

        echo '</head><body>';
        echo '<div class="astra-builder-preview">';
        echo wp_kses_post( $snapshot['html'] );
        echo '</div>';
        printf( '<script>%s</script>', $this->get_preview_metrics_script() );
        echo '</body></html>';
        exit;
    }

    /**
     * Generate the compiled markup and CSS whenever a template is saved.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an existing post.
     */
    public function generate_template_artifacts( $post_id, $post, $update ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $post || ( self::TEMPLATE_POST_TYPE !== $post->post_type && self::COMPONENT_POST_TYPE !== $post->post_type ) ) {
            return;
        }

        $overrides = $this->get_style_overrides( $post_id );
        $compiled  = $this->compose_template_from_content( $post, $post->post_content, $overrides );

        update_post_meta( $post_id, self::META_RENDERED_MARKUP, $compiled['html'] );
        update_post_meta( $post_id, self::META_CRITICAL_CSS, $compiled['css'] );
        update_post_meta( $post_id, self::META_ASSET_MANIFEST, $compiled['assets'] );
        update_post_meta( $post_id, self::META_RESOURCE_HINTS, $compiled['hints'] );
        update_post_meta( $post_id, self::META_LANGUAGE, $this->get_active_language_code() );

        $this->synchronize_fallback_block( $post_id, $post );
    }

    /**
     * Compose blocks into markup using current theme supports.
     *
     * @param WP_Post $post            Post being rendered.
     * @param string  $content         Content to render.
     * @param array   $style_overrides Template-specific style overrides.
     *
     * @return array
     */
    protected function compose_template_from_content( $post, $content, $style_overrides = array() ) {
        $blocks = $this->apply_block_migrations( parse_blocks( $content ) );
        $assets = $this->collect_block_assets( $blocks );
        $html   = '';

        foreach ( $blocks as $block ) {
            $html .= render_block( $block );
        }

        $html = $this->apply_media_enhancements( $html );

        $supports = $this->get_theme_support_flags();
        $classes  = $this->get_wrapper_classes( $supports, $post->ID );

        $markup = sprintf( '<div class="%s">%s</div>', esc_attr( implode( ' ', $classes ) ), $html );

        $critical_css = $this->extract_critical_css( $content, $supports, $post->ID, $style_overrides );
        $resource_hints = $this->build_resource_hints( $assets );

        $markup        = apply_filters( 'astra_builder_rendered_markup', $markup, $post );
        $critical_css  = apply_filters( 'astra_builder_rendered_css', $critical_css, $post );
        $assets        = apply_filters( 'astra_builder_rendered_assets', $assets, $post );
        $resource_hints = apply_filters( 'astra_builder_rendered_hints', $resource_hints, $post );

        $compiled = array(
            'html'     => $markup,
            'css'      => $critical_css,
            'supports' => $supports,
            'assets'   => $assets,
            'hints'    => $resource_hints,
        );

        return apply_filters( 'astra_builder_compiled_template', $compiled, $post );
    }

    /**
     * Apply optimizations to rendered markup.
     *
     * @param string $html Raw markup string.
     *
     * @return string
     */
    protected function apply_media_enhancements( $html ) {
        if ( empty( $html ) ) {
            return $html;
        }

        $lazy_patterns = array(
            '/<img\b(?![^>]*\bloading=)/i'   => '<img loading="lazy"',
            '/<iframe\b(?![^>]*\bloading=)/i' => '<iframe loading="lazy"',
        );

        foreach ( $lazy_patterns as $pattern => $replacement ) {
            $html = preg_replace( $pattern, $replacement, $html );
        }

        $html = preg_replace( '/<img\b(?![^>]*\bdecoding=)/i', '<img decoding="async"', $html );

        return apply_filters( 'astra_builder_media_markup', $html );
    }

    /**
     * Get wrapper classes that describe active theme supports.
     *
     * @param array $supports Support flags.
     * @return array
     */
    protected function get_wrapper_classes( $supports, $post_id = 0 ) {
        $classes = array( 'astra-builder-template' );

        if ( $post_id ) {
            $classes[] = 'astra-builder-template-' . absint( $post_id );
        }
        $supported = array_filter( $supports );

        foreach ( $supported as $flag => $value ) {
            $classes[] = 'has-' . sanitize_html_class( strtolower( $flag ) );
        }

        return apply_filters( 'astra_builder_template_wrapper_classes', $classes, $supports );
    }

    /**
     * Map common theme supports so templates can react to the active theme.
     *
     * @return array
     */
    protected function get_theme_support_flags() {
        $supports = array(
            'alignWide'        => current_theme_supports( 'align-wide' ),
            'responsiveEmbeds' => current_theme_supports( 'responsive-embeds' ),
            'customSpacing'    => current_theme_supports( 'custom-spacing' ),
            'customLineHeight' => current_theme_supports( 'custom-line-height' ),
        );

        return apply_filters( 'astra_builder_template_support_flags', $supports );
    }

    /**
     * Extract a lightweight CSS bundle for the provided template content.
     *
     * @param string $content        Template content.
     * @param array  $supports       Theme supports in play.
     * @param int    $post_id        Post ID.
     * @param array  $style_overrides Template-specific overrides.
     *
     * @return string
     */
    protected function extract_critical_css( $content, $supports, $post_id, $style_overrides = array() ) {
        $css_chunks = array();

        if ( $this->tokens ) {
            $override_css = $this->tokens->render_template_override_styles( $style_overrides, $post_id );

            if ( $override_css ) {
                $css_chunks[] = $override_css;
            }
        }

        if ( function_exists( 'wp_get_global_stylesheet' ) ) {
            $css_chunks[] = wp_get_global_stylesheet();
        }

        if ( function_exists( 'wp_get_global_styles_custom_css' ) ) {
            $css_chunks[] = wp_get_global_styles_custom_css();
        }

        $blocks = $this->apply_block_migrations( parse_blocks( $content ) );
        $rules  = $this->collect_inline_style_rules( $blocks, $post_id );

        if ( ! empty( $rules ) ) {
            if ( function_exists( 'wp_style_engine_get_stylesheet_from_css_rules' ) ) {
                $css_chunks[] = wp_style_engine_get_stylesheet_from_css_rules( $rules );
            } else {
                $manual_rules = array();
                foreach ( $rules as $rule ) {
                    $declarations = array();
                    foreach ( $rule['declarations'] as $property => $value ) {
                        $declarations[] = $property . ':' . $value . ';';
                    }
                    $manual_rules[] = $rule['selector'] . '{' . implode( '', $declarations ) . '}';
                }
                $css_chunks[] = implode( '', $manual_rules );
            }
        }

        if ( ! empty( $supports['responsiveEmbeds'] ) ) {
            $css_chunks[] = '.astra-builder-template iframe{max-width:100%;height:auto;}';
        }

        return trim( implode( "\n", array_filter( array_map( 'trim', $css_chunks ) ) ) );
    }

    /**
     * Gather inline style rules for each block.
     *
     * @param array $blocks  Parsed blocks.
     * @param int   $post_id Post ID.
     *
     * @return array
     */
    protected function collect_inline_style_rules( $blocks, $post_id ) {
        $rules = array();
        $base  = '.astra-builder-template-' . $post_id;

        foreach ( $blocks as $block ) {
            if ( empty( $block['attrs']['style'] ) && empty( $block['innerBlocks'] ) ) {
                continue;
            }

            $selector     = $base . ' ' . $this->get_block_selector( isset( $block['blockName'] ) ? $block['blockName'] : '' );
            $declarations = $this->build_declarations_from_style( isset( $block['attrs']['style'] ) ? $block['attrs']['style'] : array() );

            if ( ! empty( $declarations ) ) {
                $rules[] = array(
                    'selector'     => trim( $selector ),
                    'declarations' => $declarations,
                );
            }

            if ( ! empty( $block['innerBlocks'] ) ) {
                $rules = array_merge( $rules, $this->collect_inline_style_rules( $block['innerBlocks'], $post_id ) );
            }
        }

        return $rules;
    }

    /**
     * Apply registered block migrations to parsed block trees.
     *
     * @param array $blocks Parsed blocks.
     *
     * @return array
     */
    protected function apply_block_migrations( $blocks ) {
        if ( empty( $blocks ) || ! is_array( $blocks ) ) {
            return array();
        }

        $migrations = apply_filters( 'astra_builder_block_migrations', array() );

        if ( empty( $migrations ) || ! is_array( $migrations ) ) {
            return $blocks;
        }

        $callbacks = array();

        foreach ( $migrations as $migration ) {
            if ( is_callable( $migration ) ) {
                $callbacks[] = $migration;
            }
        }

        if ( empty( $callbacks ) ) {
            return $blocks;
        }

        $transform = function( $block ) use ( &$transform, $callbacks ) {
            if ( ! is_array( $block ) ) {
                return $block;
            }

            foreach ( $callbacks as $callback ) {
                $result = call_user_func( $callback, $block );

                if ( is_array( $result ) ) {
                    $block = array_merge( $block, $result );
                }
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $block['innerBlocks'] = array_map( $transform, $block['innerBlocks'] );
            }

            return $block;
        };

        return array_map( $transform, $blocks );
    }

    /**
     * Gather block asset handles for the provided content.
     *
     * @param array $blocks Parsed blocks.
     *
     * @return array
     */
    protected function collect_block_assets( $blocks ) {
        $manifest = array(
            'styles'  => array(),
            'scripts' => array(),
            'blocks'  => array(),
        );

        if ( empty( $blocks ) ) {
            return $manifest;
        }

        foreach ( $blocks as $block ) {
            $block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
            $handles    = $this->get_block_asset_handles( $block_name );

            if ( ! empty( $handles['styles'] ) ) {
                $manifest['styles'] = array_merge( $manifest['styles'], $handles['styles'] );
            }

            if ( ! empty( $handles['scripts'] ) ) {
                $manifest['scripts'] = array_merge( $manifest['scripts'], $handles['scripts'] );
            }

            if ( ! empty( $block_name ) ) {
                $block_key = sanitize_key( str_replace( '/', '-', $block_name ) );

                if ( ! isset( $manifest['blocks'][ $block_key ] ) ) {
                    $manifest['blocks'][ $block_key ] = array(
                        'name'    => $block_name,
                        'styles'  => array(),
                        'scripts' => array(),
                    );
                }

                $manifest['blocks'][ $block_key ]['styles']  = array_values( array_unique( array_merge( $manifest['blocks'][ $block_key ]['styles'], $handles['styles'] ) ) );
                $manifest['blocks'][ $block_key ]['scripts'] = array_values( array_unique( array_merge( $manifest['blocks'][ $block_key ]['scripts'], $handles['scripts'] ) ) );
            }

            if ( ! empty( $block['innerBlocks'] ) ) {
                $child_manifest = $this->collect_block_assets( $block['innerBlocks'] );
                $manifest['styles']  = array_merge( $manifest['styles'], $child_manifest['styles'] );
                $manifest['scripts'] = array_merge( $manifest['scripts'], $child_manifest['scripts'] );
                $manifest['blocks']  = array_merge( $manifest['blocks'], $child_manifest['blocks'] );
            }
        }

        $manifest['styles']  = array_values( array_unique( $manifest['styles'] ) );
        $manifest['scripts'] = array_values( array_unique( $manifest['scripts'] ) );

        return $manifest;
    }

    /**
     * Fetch asset handles for a registered block.
     *
     * @param string $block_name Block name.
     *
     * @return array
     */
    protected function get_block_asset_handles( $block_name ) {
        if ( empty( $block_name ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
            return array(
                'styles'  => array(),
                'scripts' => array(),
            );
        }

        $registry = WP_Block_Type_Registry::get_instance();

        if ( ! $registry || ! $registry->is_registered( $block_name ) ) {
            return array(
                'styles'  => array(),
                'scripts' => array(),
            );
        }

        $type = $registry->get_registered( $block_name );

        return array(
            'styles'  => array_values( array_unique( array_merge(
                $this->normalize_asset_handles( isset( $type->style ) ? $type->style : array() ),
                $this->normalize_asset_handles( isset( $type->view_style ) ? $type->view_style : array() )
            ) ) ),
            'scripts' => array_values( array_unique( array_merge(
                $this->normalize_asset_handles( isset( $type->script ) ? $type->script : array() ),
                $this->normalize_asset_handles( isset( $type->view_script ) ? $type->view_script : array() )
            ) ) ),
        );
    }

    /**
     * Normalize asset handles into sanitized lists.
     *
     * @param mixed $handles Handles.
     *
     * @return array
     */
    protected function normalize_asset_handles( $handles ) {
        $list = array();

        if ( empty( $handles ) ) {
            return $list;
        }

        foreach ( (array) $handles as $handle ) {
            if ( is_string( $handle ) && ! empty( $handle ) ) {
                $list[] = sanitize_key( $handle );
            }
        }

        return $list;
    }

    /**
     * Build preload/prefetch metadata for required assets.
     *
     * @param array $assets Asset manifest.
     *
     * @return array
     */
    protected function build_resource_hints( $assets ) {
        $hints  = array();
        $styles = isset( $assets['styles'] ) ? (array) $assets['styles'] : array();
        $scripts = isset( $assets['scripts'] ) ? (array) $assets['scripts'] : array();

        foreach ( $styles as $handle ) {
            $href = $this->resolve_asset_src( $handle, 'style' );
            if ( $href ) {
                $hints[] = array(
                    'rel'    => 'preload',
                    'as'     => 'style',
                    'href'   => $href,
                    'handle' => $handle,
                    'type'   => 'style',
                );
            }
        }

        foreach ( $scripts as $handle ) {
            $href = $this->resolve_asset_src( $handle, 'script' );
            if ( $href ) {
                $hints[] = array(
                    'rel'    => 'preload',
                    'as'     => 'script',
                    'href'   => $href,
                    'handle' => $handle,
                    'type'   => 'script',
                );
            }
        }

        return $hints;
    }

    /**
     * Resolve the absolute URL for an asset handle.
     *
     * @param string $handle Handle.
     * @param string $type   Asset type.
     *
     * @return string
     */
    protected function resolve_asset_src( $handle, $type = 'style' ) {
        if ( empty( $handle ) ) {
            return '';
        }

        $registry = 'style' === $type ? wp_styles() : wp_scripts();

        if ( ! $registry || empty( $registry->registered[ $handle ] ) ) {
            return '';
        }

        $item = $registry->registered[ $handle ];
        $src  = isset( $item->src ) ? $item->src : '';

        if ( empty( $src ) ) {
            return '';
        }

        if ( 0 === strpos( $src, 'http://' ) || 0 === strpos( $src, 'https://' ) || 0 === strpos( $src, '//' ) || 0 === strpos( $src, '/' ) ) {
            return $src;
        }

        $base = isset( $registry->base_url ) ? trailingslashit( $registry->base_url ) : '';

        if ( empty( $base ) ) {
            return $src;
        }

        return $base . ltrim( $src, '/' );
    }

    /**
     * Convert block style attributes into CSS declarations.
     *
     * @param array $style Style attribute.
     *
     * @return array
     */
    protected function build_declarations_from_style( $style ) {
        if ( empty( $style ) || ! is_array( $style ) ) {
            return array();
        }

        $declarations = array();

        if ( isset( $style['color']['text'] ) ) {
            $declarations['color'] = $this->sanitize_css_value( $style['color']['text'] );
        }

        if ( isset( $style['color']['background'] ) ) {
            $declarations['background-color'] = $this->sanitize_css_value( $style['color']['background'] );
        }

        if ( isset( $style['typography']['fontSize'] ) ) {
            $declarations['font-size'] = $this->sanitize_css_value( $style['typography']['fontSize'] );
        }

        if ( isset( $style['spacing']['padding'] ) && is_array( $style['spacing']['padding'] ) ) {
            foreach ( $style['spacing']['padding'] as $side => $value ) {
                $declarations[ 'padding-' . $side ] = $this->sanitize_css_value( $value );
            }
        }

        if ( isset( $style['spacing']['margin'] ) && is_array( $style['spacing']['margin'] ) ) {
            foreach ( $style['spacing']['margin'] as $side => $value ) {
                $declarations[ 'margin-' . $side ] = $this->sanitize_css_value( $value );
            }
        }

        if ( isset( $style['border']['radius'] ) ) {
            $declarations['border-radius'] = $this->sanitize_css_value( $style['border']['radius'] );
        }

        return array_filter( $declarations );
    }

    /**
     * Sanitize CSS values.
     *
     * @param string $value CSS value.
     *
     * @return string
     */
    protected function sanitize_css_value( $value ) {
        return trim( preg_replace( '/[^a-zA-Z0-9#%,.()\s-]/', '', (string) $value ) );
    }

    /**
     * Derive the selector for a block.
     *
     * @param string $block_name Block name.
     *
     * @return string
     */
    protected function get_block_selector( $block_name ) {
        if ( empty( $block_name ) ) {
            return '> *';
        }

        $normalized = 'wp-block-' . str_replace( '/', '-', $block_name );

        return '.' . sanitize_html_class( $normalized );
    }

    /**
     * Get default condition payload.
     *
     * @return array
     */
    public function get_default_conditions() {
        return array(
            'postTypes'  => array(),
            'taxonomies' => array(),
            'roles'      => array(),
        );
    }

    /**
     * Sanitize a conditions array.
     *
     * @param mixed $conditions Raw conditions.
     *
     * @return array
     */
    public function sanitize_conditions( $conditions ) {
        $defaults = $this->get_default_conditions();

        if ( ! is_array( $conditions ) ) {
            return $defaults;
        }

        $sanitized = array();

        foreach ( $defaults as $key => $default ) {
            $items = isset( $conditions[ $key ] ) && is_array( $conditions[ $key ] ) ? $conditions[ $key ] : array();
            $items = array_map( 'sanitize_key', array_filter( $items ) );
            $sanitized[ $key ] = array_values( array_unique( $items ) );
        }

        return $sanitized + $defaults;
    }

    /**
     * Fetch saved conditions for a template.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_conditions( $post_id ) {
        $value = get_post_meta( $post_id, self::META_CONDITIONS, true );

        if ( empty( $value ) ) {
            return $this->get_default_conditions();
        }

        return $this->sanitize_conditions( $value );
    }

    /**
     * Retrieve compiled markup and CSS for a template.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_compiled_template( $post_id ) {
        return array(
            'html'   => get_post_meta( $post_id, self::META_RENDERED_MARKUP, true ),
            'css'    => get_post_meta( $post_id, self::META_CRITICAL_CSS, true ),
            'assets' => $this->get_asset_manifest( $post_id ),
            'hints'  => $this->get_resource_hints( $post_id ),
        );
    }

    /**
     * Retrieve stored asset manifest for a template.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_asset_manifest( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_ASSET_MANIFEST, true );

        return $this->sanitize_asset_manifest( $raw );
    }

    /**
     * Retrieve stored resource hints for a template.
     *
     * @param int $post_id Post ID.
     *
     * @return array
     */
    public function get_resource_hints( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_RESOURCE_HINTS, true );

        return $this->sanitize_resource_hints( $raw );
    }

    /**
     * Provide condition choices for the editor UI.
     *
     * @return array
     */
    public function get_condition_options() {
        $post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
        $taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
        $roles      = wp_roles()->roles;

        $map_labels = function( $item ) {
            return array(
                'slug'  => $item->name,
                'label' => $item->labels->singular_name ? $item->labels->singular_name : $item->label,
            );
        };

        $post_type_options = array_map( $map_labels, $post_types );
        $taxonomy_options  = array_map( $map_labels, $taxonomies );
        $role_options      = array();

        foreach ( $roles as $role => $details ) {
            $role_options[] = array(
                'slug'  => $role,
                'label' => isset( $details['name'] ) ? $details['name'] : ucfirst( $role ),
            );
        }

        return array(
            'postTypes'  => array_values( $post_type_options ),
            'taxonomies' => array_values( $taxonomy_options ),
            'roles'      => array_values( $role_options ),
        );
    }

    /**
     * Expose meta keys that scripts rely on.
     *
     * @return array
     */
    public function get_meta_keys() {
        return array(
            'conditions' => self::META_CONDITIONS,
            'markup'     => self::META_RENDERED_MARKUP,
            'css'        => self::META_CRITICAL_CSS,
            'styles'     => self::META_STYLE_OVERRIDES,
            'assets'     => self::META_ASSET_MANIFEST,
            'hints'      => self::META_RESOURCE_HINTS,
            'language'   => self::META_LANGUAGE,
            'pattern'    => self::META_PATTERN_SLUG,
        );
    }

    /**
     * Toggle language scoping.
     *
     * @param bool $enabled Whether the scope is active.
     */
    public function set_language_scope_enabled( $enabled ) {
        $this->language_scope_enabled = (bool) $enabled;
    }

    /**
     * Constrain template queries to the active language when WPML/Polylang is enabled.
     *
     * @param WP_Query $query Query object.
     */
    public function maybe_scope_language( $query ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( ! $this->language_scope_enabled || ! $query instanceof WP_Query ) {
            return;
        }

        $post_types = $query->get( 'post_type' );

        if ( empty( $post_types ) ) {
            $post_types = array( self::TEMPLATE_POST_TYPE, self::COMPONENT_POST_TYPE );
        }

        $types = is_array( $post_types ) ? $post_types : array( $post_types );

        if ( empty( array_intersect( $types, array( self::TEMPLATE_POST_TYPE, self::COMPONENT_POST_TYPE ) ) ) ) {
            return;
        }

        if ( $query->get( 'astra_builder_all_languages' ) ) {
            return;
        }

        $language = $this->get_active_language_code();

        if ( empty( $language ) ) {
            return;
        }

        $meta_query   = $query->get( 'meta_query' );
        $meta_query   = is_array( $meta_query ) ? $meta_query : array();
        $meta_query[] = array(
            'key'   => self::META_LANGUAGE,
            'value' => $language,
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Determine the active language code.
     *
     * @return string
     */
    protected function get_active_language_code() {
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );

            if ( $lang ) {
                return $lang;
            }
        }

        $wpml_lang = apply_filters( 'wpml_current_language', null );

        if ( $wpml_lang ) {
            return $wpml_lang;
        }

        return determine_locale();
    }

    /**
     * Persist fallback resources ahead of plugin deactivation.
     */
    public function handle_deactivation() {
        $this->set_language_scope_enabled( false );

        $posts = get_posts(
            array(
                'post_type'      => array( self::TEMPLATE_POST_TYPE, self::COMPONENT_POST_TYPE ),
                'posts_per_page' => -1,
                'post_status'    => array( 'publish', 'draft' ),
            )
        );

        foreach ( $posts as $post ) {
            $this->synchronize_fallback_block( $post->ID, $post );
        }

        $this->set_language_scope_enabled( true );
    }

    /**
     * Create a preview snapshot for a template.
     *
     * @param WP_Post $post Post being previewed.
     * @param array   $args Preview arguments.
     *
     * @return array
     */
    public function create_preview_snapshot( $post, $args = array() ) {
        $content        = isset( $args['content'] ) ? wp_kses_post( $args['content'] ) : $post->post_content;
        $conditions     = isset( $args['conditions'] ) ? $this->sanitize_conditions( $args['conditions'] ) : $this->get_conditions( $post->ID );
        $status         = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : $post->post_status;
        $style_overrides = isset( $args['styles'] ) ? $this->sanitize_style_overrides( $args['styles'] ) : $this->get_style_overrides( $post->ID );

        $compiled = $this->compose_template_from_content( $post, $content, $style_overrides );
        $token    = wp_generate_uuid4();

        $snapshot = array(
            'id'          => $token,
            'post_id'     => $post->ID,
            'title'       => $post->post_title,
            'status'      => $status,
            'created'     => current_time( 'mysql', true ),
            'conditions'  => $conditions,
            'html'        => $compiled['html'],
            'css'         => $compiled['css'],
            'supports'    => $compiled['supports'],
            'styles'      => $style_overrides,
            'assets'      => $compiled['assets'],
            'hints'       => $compiled['hints'],
        );

        $snapshot['preview_url'] = $this->build_preview_url( $token );

        $this->store_preview_snapshot( $snapshot );

        return $snapshot;
    }

    /**
     * Store the preview snapshot in a transient.
     *
     * @param array $snapshot Snapshot payload.
     */
    protected function store_preview_snapshot( $snapshot ) {
        $expiration = HOUR_IN_SECONDS * self::PREVIEW_TRANSIENT_EXPIRY;
        set_transient( self::PREVIEW_TRANSIENT_PREFIX . $snapshot['id'], $snapshot, $expiration );
    }

    /**
     * Retrieve a previously generated preview snapshot.
     *
     * @param string $token Preview token.
     *
     * @return array|false
     */
    public function get_preview_snapshot( $token ) {
        $sanitized = preg_replace( '/[^a-z0-9-]/i', '', (string) $token );

        return get_transient( self::PREVIEW_TRANSIENT_PREFIX . $sanitized );
    }

    /**
     * Build the public URL used to load the preview snapshot.
     *
     * @param string $token Snapshot token.
     *
     * @return string
     */
    protected function build_preview_url( $token ) {
        return add_query_arg( array( self::PREVIEW_QUERY_VAR => $token ), home_url( '/' ) );
    }

    /**
     * Provide the inline script that records preview performance metrics.
     *
     * @return string
     */
    protected function get_preview_metrics_script() {
        $thresholds = wp_json_encode(
            array(
                'lcpTarget' => self::LCP_THRESHOLD,
                'clsTarget' => self::CLS_THRESHOLD,
            )
        );

        if ( ! $thresholds ) {
            $thresholds = '{}';
        }

        $script  = "(function(){if(!('PerformanceObserver' in window)){return;}var thresholds=" . $thresholds . ';';
        $script .= "var metrics={lcp:null,cls:0,lcpTarget:thresholds.lcpTarget||0,clsTarget:thresholds.clsTarget||0};";
        $script .= "var send=function(){if(!window.opener||!window.opener.postMessage){return;}try{window.opener.postMessage({source:'astra-builder-preview-metrics',payload:metrics},window.location.origin);}catch(e){}};";
        $script .= "try{var lcpObserver=new PerformanceObserver(function(list){var entries=list.getEntries();if(!entries.length){return;}var last=entries[entries.length-1];metrics.lcp=(last.renderTime||last.loadTime||last.startTime||0);send();});lcpObserver.observe({type:'largest-contentful-paint',buffered:true});}catch(e){}";
        $script .= "try{var clsValue=0;var clsObserver=new PerformanceObserver(function(list){list.getEntries().forEach(function(entry){if(!entry.hadRecentInput){clsValue+=entry.value;}});metrics.cls=clsValue;send();});clsObserver.observe({type:'layout-shift',buffered:true});}catch(e){}";
        $script .= "window.addEventListener('beforeunload',function(){send();});})();";

        return $script;
    }

    /**
     * Provide meta data about compiled templates.
     *
     * @param WP_Post $post Post object.
     *
     * @return array
     */
    public function describe_template( $post ) {
        return array(
            'conditions' => $this->get_conditions( $post->ID ),
            'rendered'   => $this->get_compiled_template( $post->ID ),
        );
    }
}
