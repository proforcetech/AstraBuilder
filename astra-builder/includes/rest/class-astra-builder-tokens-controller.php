<?php
/**
 * REST controller that provides CRUD access to design tokens.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Tokens_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'tokens';

    /**
     * Token service.
     *
     * @var Astra_Builder_Token_Service
     */
    protected $tokens;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Token_Service $tokens Token service.
     */
    public function __construct( Astra_Builder_Token_Service $tokens ) {
        $this->tokens = $tokens;
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
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'can_read_tokens' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'can_manage_tokens' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'export_item' ),
                    'permission_callback' => array( $this, 'can_manage_tokens' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'import_item' ),
                    'permission_callback' => array( $this, 'can_manage_tokens' ),
                ),
            )
        );
    }

    /**
     * Permission callback for reading.
     *
     * @return true|WP_Error
     */
    public function can_read_tokens() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Permission callback for edits.
     *
     * @return true|WP_Error
     */
    public function can_manage_tokens() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Retrieve the currently saved tokens.
     *
     * @return WP_REST_Response
     */
    public function get_item() {
        return rest_ensure_response( $this->tokens->get_tokens() );
    }

    /**
     * Update and synchronize tokens.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( WP_REST_Request $request ) {
        $body   = $request->get_json_params();
        $result = $this->tokens->update_tokens( is_array( $body ) ? $body : array() );

        if ( ! $result ) {
            return new WP_Error( 'astra_builder_update_failed', __( 'Unable to save tokens.', 'astra-builder' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response( $this->tokens->get_tokens() );
    }

    /**
     * Export design tokens as JSON.
     *
     * @return WP_REST_Response
     */
    public function export_item() {
        return rest_ensure_response( array( 'data' => $this->tokens->export_tokens() ) );
    }

    /**
     * Import design tokens from a JSON body.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function import_item( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $json = isset( $body['data'] ) ? $body['data'] : '';

        if ( empty( $json ) ) {
            return new WP_Error( 'astra_builder_invalid_import', __( 'No import data received.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $result = $this->tokens->import_tokens( $json );

        if ( ! $result ) {
            return new WP_Error( 'astra_builder_invalid_import', __( 'Invalid token JSON.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->tokens->get_tokens() );
    }
}
