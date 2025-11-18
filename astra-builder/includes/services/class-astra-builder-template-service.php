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
    const PREVIEW_TRANSIENT_PREFIX  = 'astra_builder_preview_';
    const PREVIEW_QUERY_VAR         = 'astra_builder_preview';
    const PREVIEW_TRANSIENT_EXPIRY  = 6; // Hours.

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

        echo '</head><body>';
        echo '<div class="astra-builder-preview">';
        echo wp_kses_post( $snapshot['html'] );
        echo '</div>';
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

        $compiled = $this->compose_template_from_content( $post, $post->post_content );

        update_post_meta( $post_id, self::META_RENDERED_MARKUP, $compiled['html'] );
        update_post_meta( $post_id, self::META_CRITICAL_CSS, $compiled['css'] );
    }

    /**
     * Compose blocks into markup using current theme supports.
     *
     * @param WP_Post $post    Post being rendered.
     * @param string  $content Content to render.
     *
     * @return array
     */
    protected function compose_template_from_content( $post, $content ) {
        $blocks = parse_blocks( $content );
        $html   = '';

        foreach ( $blocks as $block ) {
            $html .= render_block( $block );
        }

        $supports = $this->get_theme_support_flags();
        $classes  = $this->get_wrapper_classes( $supports, $post->ID );

        $markup = sprintf( '<div class="%s">%s</div>', esc_attr( implode( ' ', $classes ) ), $html );

        $critical_css = $this->extract_critical_css( $content, $supports, $post->ID );

        return array(
            'html'     => $markup,
            'css'      => $critical_css,
            'supports' => $supports,
        );
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
     * @param string $content  Template content.
     * @param array  $supports Theme supports in play.
     * @param int    $post_id  Post ID.
     *
     * @return string
     */
    protected function extract_critical_css( $content, $supports, $post_id ) {
        $css_chunks = array();

        if ( function_exists( 'wp_get_global_stylesheet' ) ) {
            $css_chunks[] = wp_get_global_stylesheet();
        }

        if ( function_exists( 'wp_get_global_styles_custom_css' ) ) {
            $css_chunks[] = wp_get_global_styles_custom_css();
        }

        $blocks = parse_blocks( $content );
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
            'html' => get_post_meta( $post_id, self::META_RENDERED_MARKUP, true ),
            'css'  => get_post_meta( $post_id, self::META_CRITICAL_CSS, true ),
        );
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
        );
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
        $content    = isset( $args['content'] ) ? wp_kses_post( $args['content'] ) : $post->post_content;
        $conditions = isset( $args['conditions'] ) ? $this->sanitize_conditions( $args['conditions'] ) : $this->get_conditions( $post->ID );
        $status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : $post->post_status;

        $compiled = $this->compose_template_from_content( $post, $content );
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
