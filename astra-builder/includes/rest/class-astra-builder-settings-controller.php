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
     * Insights service dependency.
     *
     * @var Astra_Builder_Insights_Service|null
     */
    protected $insights;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Token_Service        $tokens    Token service.
     * @param Astra_Builder_Insights_Service|null $insights Insights service.
     */
    public function __construct( Astra_Builder_Token_Service $tokens, ?Astra_Builder_Insights_Service $insights = null ) {
        $this->tokens   = $tokens;
        $this->insights = $insights;
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
        $settings = $this->tokens->get_settings();

        if ( $this->insights ) {
            $settings['insights'] = $this->insights->get_settings();
        }

        return rest_ensure_response( $settings );
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
        $payload  = is_array( $settings ) ? $settings : array();

        $token_settings = $payload;

        if ( isset( $token_settings['insights'] ) ) {
            unset( $token_settings['insights'] );
        }

        $this->tokens->set_settings( $token_settings );

        if ( $this->insights && isset( $payload['insights'] ) ) {
            $this->insights->update_settings( $payload['insights'] );
        }

        return $this->get_item();
    }
}
