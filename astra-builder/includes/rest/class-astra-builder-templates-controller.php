<?php
/**
 * REST controller that exposes CRUD endpoints for templates and components.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Templates_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'templates';

    /**
     * Template service.
     *
     * @var Astra_Builder_Template_Service
     */
    protected $templates;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Template_Service $templates Template service.
     */
    public function __construct( Astra_Builder_Template_Service $templates ) {
        $this->templates = $templates;
    }

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'can_read_templates' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'can_manage_templates' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( true ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'can_read_templates' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'can_manage_templates' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( true ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'can_manage_templates' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\d+)/preview',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_preview' ),
                    'permission_callback' => array( $this, 'can_read_templates' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/preview/(?P<token>[a-z0-9-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_preview' ),
                    'permission_callback' => array( $this, 'can_read_templates' ),
                ),
            )
        );
    }

    /**
     * Verify read permissions.
     *
     * @return true|WP_Error
     */
    public function can_read_templates() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Verify manage permissions.
     *
     * @return true|WP_Error
     */
    public function can_manage_templates() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Fetch a collection of templates and components.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_items( WP_REST_Request $request ) {
        $posts = get_posts(
            array(
                'post_type'      => array(
                    Astra_Builder_Template_Service::TEMPLATE_POST_TYPE,
                    Astra_Builder_Template_Service::COMPONENT_POST_TYPE,
                ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        $data = array_map( array( $this, 'prepare_item_for_response' ), $posts, array_fill( 0, count( $posts ), $request ) );

        return rest_ensure_response( $data );
    }

    /**
     * Retrieve a single template.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( WP_REST_Request $request ) {
        $post = $this->templates->get_post( $request['id'] );

        if ( ! $post ) {
            return new WP_Error( 'astra_builder_not_found', __( 'Template not found.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
    }

    /**
     * Create a template record.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( WP_REST_Request $request ) {
        $params = $this->prepare_item_for_database( $request );
        $id     = wp_insert_post( $params, true );

        if ( is_wp_error( $id ) ) {
            return $id;
        }

        $post = $this->templates->get_post( $id );

        return rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
    }

    /**
     * Update a template.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( WP_REST_Request $request ) {
        $post = $this->templates->get_post( $request['id'] );

        if ( ! $post ) {
            return new WP_Error( 'astra_builder_not_found', __( 'Template not found.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        $params           = $this->prepare_item_for_database( $request );
        $params['ID']     = $post->ID;
        $params['status'] = $post->post_status;

        $updated = wp_update_post( $params, true );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        return rest_ensure_response( $this->prepare_item_for_response( get_post( $updated ), $request ) );
    }

    /**
     * Delete a template.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item( WP_REST_Request $request ) {
        $post = $this->templates->get_post( $request['id'] );

        if ( ! $post ) {
            return new WP_Error( 'astra_builder_not_found', __( 'Template not found.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        $result = wp_delete_post( $post->ID, true );

        if ( false === $result ) {
            return new WP_Error( 'astra_builder_delete_failed', __( 'Failed to delete the template.', 'astra-builder' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'deleted' => true, 'id' => $post->ID ) );
    }

    /**
     * Prepare data for persistence.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return array
     */
    protected function prepare_item_for_database( WP_REST_Request $request ) {
        $meta_input = (array) $request->get_param( 'meta' );

        if ( null !== $request->get_param( 'conditions' ) ) {
            $meta_input[ Astra_Builder_Template_Service::META_CONDITIONS ] = $this->templates->sanitize_conditions( $request->get_param( 'conditions' ) );
        }

        return array(
            'post_type'    => $request->get_param( 'type' ) ? sanitize_key( $request->get_param( 'type' ) ) : Astra_Builder_Template_Service::TEMPLATE_POST_TYPE,
            'post_status'  => $request->get_param( 'status' ) ? sanitize_key( $request->get_param( 'status' ) ) : 'publish',
            'post_title'   => sanitize_text_field( $request->get_param( 'title' ) ),
            'post_content' => wp_kses_post( $request->get_param( 'content' ) ),
            'meta_input'   => $meta_input,
        );
    }

    /**
     * Shape the response for consumers.
     *
     * @param WP_Post         $post    Post object.
     * @param WP_REST_Request $request Request.
     *
     * @return array
     */
    public function prepare_item_for_response( $post, $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        return array(
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'type'    => $post->post_type,
            'status'  => $post->post_status,
            'meta'    => get_post_meta( $post->ID ),
            'conditions' => $this->templates->get_conditions( $post->ID ),
            'rendered'   => $this->templates->get_compiled_template( $post->ID ),
        );
    }

    /**
     * Create a preview snapshot for a template.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_preview( WP_REST_Request $request ) {
        $post = $this->templates->get_post( $request['id'] );

        if ( ! $post ) {
            return new WP_Error( 'astra_builder_not_found', __( 'Template not found.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        $snapshot = $this->templates->create_preview_snapshot(
            $post,
            array(
                'content'    => $request->get_param( 'content' ),
                'conditions' => $request->get_param( 'conditions' ),
                'status'     => $request->get_param( 'status' ),
            )
        );

        return rest_ensure_response( $snapshot );
    }

    /**
     * Retrieve a preview snapshot by token.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_preview( WP_REST_Request $request ) {
        $token    = sanitize_text_field( $request['token'] );
        $snapshot = $this->templates->get_preview_snapshot( $token );

        if ( ! $snapshot ) {
            return new WP_Error( 'astra_builder_preview_not_found', __( 'Preview not found or expired.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $snapshot );
    }
}
