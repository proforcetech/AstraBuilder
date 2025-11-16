<?php
/**
 * REST controller that manages token snapshots.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Snapshots_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'snapshots';

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
     * Register routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'can_manage_snapshots' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'can_manage_snapshots' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[a-z0-9-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'can_manage_snapshots' ),
                ),
            )
        );
    }

    /**
     * Permission callback.
     *
     * @return true|WP_Error
     */
    public function can_manage_snapshots() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Get all snapshots.
     *
     * @return WP_REST_Response
     */
    public function get_items() {
        return rest_ensure_response( $this->tokens->get_snapshots() );
    }

    /**
     * Create a snapshot of the current tokens.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response
     */
    public function create_item( WP_REST_Request $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $snapshots = $this->tokens->get_snapshots();
        $tokens    = $this->tokens->get_tokens();

        $snapshot = array(
            'id'      => wp_generate_uuid4(),
            'created' => current_time( 'mysql', true ),
            'tokens'  => $tokens,
        );

        array_unshift( $snapshots, $snapshot );
        $this->tokens->set_snapshots( $snapshots );

        return rest_ensure_response( $snapshot );
    }

    /**
     * Delete a snapshot.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response
     */
    public function delete_item( WP_REST_Request $request ) {
        $id        = sanitize_text_field( $request['id'] );
        $snapshots = $this->tokens->get_snapshots();
        $filtered  = array_filter(
            $snapshots,
            function( $snapshot ) use ( $id ) {
                return isset( $snapshot['id'] ) && $snapshot['id'] !== $id;
            }
        );

        $this->tokens->set_snapshots( array_values( $filtered ) );

        return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
    }
}
