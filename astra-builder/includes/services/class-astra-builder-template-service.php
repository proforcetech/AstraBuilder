<?php
/**
 * Service responsible for registering template and component post types.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Template_Service {
    const TEMPLATE_POST_TYPE  = 'astra_template';
    const COMPONENT_POST_TYPE = 'astra_component';

    /**
     * Bootstrap the service.
     */
    public function register() {
        add_action( 'init', array( $this, 'register_post_types' ) );
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
}
