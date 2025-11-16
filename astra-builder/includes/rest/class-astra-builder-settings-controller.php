<?php
/**
 * REST controller that exposes Astra Builder settings.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Settings_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'settings';

    /**
     * Token service dependency.
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
                    'permission_callback' => array( $this, 'can_manage_settings' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'can_manage_settings' ),
                ),
            )
        );
    }

    /**
     * Permission callback.
     *
     * @return true|WP_Error
     */
    public function can_manage_settings() {
        return $this->permissions_check( 'manage_options' );
    }

    /**
     * Retrieve settings.
     *
     * @return WP_REST_Response
     */
    public function get_item() {
        return rest_ensure_response( $this->tokens->get_settings() );
    }

    /**
     * Update settings.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response
     */
    public function update_item( WP_REST_Request $request ) {
        $settings = $request->get_json_params();
        $this->tokens->set_settings( is_array( $settings ) ? $settings : array() );

        return rest_ensure_response( $this->tokens->get_settings() );
    }
}
