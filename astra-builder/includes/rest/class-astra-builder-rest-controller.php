<?php
/**
 * Base REST controller that wires common helpers and permission checks.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Controller extends WP_REST_Controller {
    /**
     * REST namespace.
     *
     * @var string
     */
    protected $namespace = 'astra-builder/v1';

    /**
     * Verify the request nonce when mutating data.
     *
     * @return bool
     */
    protected function verify_nonce() {
        $nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( empty( $nonce ) ) {
            return false;
        }

        return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Check a capability and nonce for the current request.
     *
     * @param string $capability Capability to validate.
     *
     * @return true|WP_Error
     */
    protected function permissions_check( $capability ) {
        if ( ! current_user_can( $capability ) ) {
            return new WP_Error( 'astra_builder_forbidden', __( 'Sorry, you are not allowed to access this resource.', 'astra-builder' ), array( 'status' => rest_authorization_required_code() ) );
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'GET' !== $method && ! $this->verify_nonce() ) {
            return new WP_Error( 'astra_builder_invalid_nonce', __( 'Security check failed for the request.', 'astra-builder' ), array( 'status' => rest_authorization_required_code() ) );
        }

        return true;
    }
}
