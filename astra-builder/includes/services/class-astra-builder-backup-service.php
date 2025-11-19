<?php
/**
 * Export/import utilities for Astra Builder data.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Backup_Service {
    /**
     * Template service.
     *
     * @var Astra_Builder_Template_Service
     */
    protected $templates;

    /**
     * Token service.
     *
     * @var Astra_Builder_Token_Service
     */
    protected $tokens;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Template_Service $templates Template service.
     * @param Astra_Builder_Token_Service    $tokens    Token service.
     */
    public function __construct( Astra_Builder_Template_Service $templates, Astra_Builder_Token_Service $tokens ) {
        $this->templates = $templates;
        $this->tokens    = $tokens;
    }

    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'shutdown', array( $this, 'maybe_store_automatic_backup' ) );
    }

    /**
     * Export tokens/templates/components as a JSON-safe bundle.
     *
     * @return array
     */
    public function export_bundle() {
        $this->templates->set_language_scope_enabled( false );

        $templates  = get_posts(
            array(
                'post_type'      => Astra_Builder_Template_Service::TEMPLATE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft' ),
                'posts_per_page' => -1,
            )
        );

        $components = get_posts(
            array(
                'post_type'      => Astra_Builder_Template_Service::COMPONENT_POST_TYPE,
                'post_status'    => array( 'publish', 'draft' ),
                'posts_per_page' => -1,
            )
        );

        $this->templates->set_language_scope_enabled( true );

        return array(
            'version'    => '1.0.0',
            'generated'  => current_time( 'mysql' ),
            'locale'     => get_locale(),
            'tokens'     => $this->tokens->get_tokens(),
            'templates'  => array_map( array( $this, 'format_post_for_export' ), $templates ),
            'components' => array_map( array( $this, 'format_post_for_export' ), $components ),
        );
    }

    /**
     * Import a bundle of templates/tokens/components.
     *
     * @param array $bundle Payload to import.
     *
     * @return array
     */
    public function import_bundle( $bundle ) {
        if ( ! is_array( $bundle ) ) {
            return array();
        }

        if ( isset( $bundle['tokens'] ) && is_array( $bundle['tokens'] ) ) {
            $this->tokens->update_tokens( $bundle['tokens'] );
        }

        $created = array(
            'templates'  => array(),
            'components' => array(),
        );

        foreach ( array( 'templates' => Astra_Builder_Template_Service::TEMPLATE_POST_TYPE, 'components' => Astra_Builder_Template_Service::COMPONENT_POST_TYPE ) as $key => $post_type ) {
            if ( empty( $bundle[ $key ] ) || ! is_array( $bundle[ $key ] ) ) {
                continue;
            }

            foreach ( $bundle[ $key ] as $item ) {
                $created[ $key ][] = $this->maybe_import_single( $item, $post_type );
            }
        }

        return $created;
    }

    /**
     * Persist a backup bundle for graceful degradation.
     */
    public function maybe_store_automatic_backup() {
        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        $bundle = $this->export_bundle();
        update_option( 'astra_builder_last_backup', $bundle );
    }

    /**
     * Normalize a post for export.
     *
     * @param WP_Post $post Post instance.
     *
     * @return array
     */
    protected function format_post_for_export( $post ) {
        $meta = array(
            'conditions'  => get_post_meta( $post->ID, Astra_Builder_Template_Service::META_CONDITIONS, true ),
            'styles'      => get_post_meta( $post->ID, Astra_Builder_Template_Service::META_STYLE_OVERRIDES, true ),
            'language'    => get_post_meta( $post->ID, Astra_Builder_Template_Service::META_LANGUAGE, true ),
            'patternSlug' => get_post_meta( $post->ID, Astra_Builder_Template_Service::META_PATTERN_SLUG, true ),
        );

        return array(
            'title'     => $post->post_title,
            'slug'      => $post->post_name,
            'content'   => $post->post_content,
            'status'    => $post->post_status,
            'meta'      => $meta,
            'type'      => $post->post_type,
            'modified'  => $post->post_modified_gmt,
        );
    }

    /**
     * Import a single template/component.
     *
     * @param array  $payload  Data.
     * @param string $post_type Target post type.
     *
     * @return int|WP_Error
     */
    protected function maybe_import_single( $payload, $post_type ) {
        if ( empty( $payload['title'] ) ) {
            return new WP_Error( 'astra_builder_invalid_backup', __( 'Missing title inside backup payload.', 'astra-builder' ) );
        }

        $args = array(
            'post_type'    => $post_type,
            'post_title'   => sanitize_text_field( $payload['title'] ),
            'post_name'    => isset( $payload['slug'] ) ? sanitize_title( $payload['slug'] ) : '',
            'post_status'  => isset( $payload['status'] ) ? sanitize_key( $payload['status'] ) : 'draft',
            'post_content' => isset( $payload['content'] ) ? wp_kses_post( $payload['content'] ) : '',
        );

        $existing = $args['post_name'] ? get_page_by_path( $args['post_name'], OBJECT, $post_type ) : null;

        if ( $existing ) {
            $args['ID'] = $existing->ID;
            $post_id    = wp_update_post( $args, true );
        } else {
            $post_id = wp_insert_post( $args, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
            foreach ( $payload['meta'] as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        return $post_id;
    }
}
