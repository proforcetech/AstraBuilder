<?php
/**
 * REST controller powering Astra Builder form submissions.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Form_Submissions_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'form-submissions';

    /**
     * Form service dependency.
     *
     * @var Astra_Builder_Form_Service
     */
    protected $forms;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Form_Service $forms Form service.
     */
    public function __construct( Astra_Builder_Form_Service $forms ) {
        $this->forms = $forms;
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
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'can_manage_forms' ),
                ),
            )
        );
    }

    /**
     * Permission callback for listing submissions.
     *
     * @return true|WP_Error
     */
    public function can_manage_forms() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Retrieve recent submissions.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_items( WP_REST_Request $request ) {
        $per_page = (int) $request->get_param( 'per_page' );
        $items    = $this->forms->get_recent_submissions(
            array(
                'posts_per_page' => $per_page > 0 ? $per_page : 10,
            )
        );

        return rest_ensure_response( $items );
    }

    /**
     * Handle incoming submissions.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( WP_REST_Request $request ) {
        $fields       = $this->forms->sanitize_fields( $request->get_param( 'fields' ) );
        $requirements = $request->get_param( 'requirements' );
        $payload      = array(
            'formId'       => sanitize_text_field( $request->get_param( 'formId' ) ),
            'fields'       => $fields,
            'honeypot'     => sanitize_text_field( $request->get_param( 'honeypot' ) ),
            'submittedAt'  => (int) $request->get_param( 'submittedAt' ),
            'requirements' => $requirements,
            'context'      => array(
                'referer'   => esc_url_raw( $request->get_header( 'referer' ) ),
                'userAgent' => sanitize_text_field( $request->get_header( 'user-agent' ) ),
                'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ),
        );

        $validated = $this->forms->validate_submission( $payload );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $result = $this->forms->persist_submission( $payload['formId'], $payload );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->forms->dispatch_integrations( $result, $payload['formId'], $fields, $payload['context'] );

        return rest_ensure_response(
            array(
                'received'     => true,
                'submissionId' => $result,
            )
        );
    }
}
