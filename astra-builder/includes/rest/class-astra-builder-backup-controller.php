<?php
/**
 * REST controller for Astra Builder backups.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Backup_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'backup';

    /**
     * Backup service.
     *
     * @var Astra_Builder_Backup_Service
     */
    protected $backups;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Backup_Service $backups Backup service.
     */
    public function __construct( Astra_Builder_Backup_Service $backups ) {
        $this->backups = $backups;
    }

    /**
     * Register routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'export_bundle' ),
                    'permission_callback' => array( $this, 'can_manage_backups' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'import_bundle' ),
                    'permission_callback' => array( $this, 'can_manage_backups' ),
                ),
            )
        );
    }

    /**
     * Permission callback.
     *
     * @return true|WP_Error
     */
    public function can_manage_backups() {
        return $this->permissions_check( 'manage_options' );
    }

    /**
     * Export bundle response.
     *
     * @return WP_REST_Response
     */
    public function export_bundle() {
        return rest_ensure_response( $this->backups->export_bundle() );
    }

    /**
     * Import payload.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function import_bundle( WP_REST_Request $request ) {
        $bundle = $request->get_json_params();

        return rest_ensure_response( $this->backups->import_bundle( $bundle ) );
    }
}
