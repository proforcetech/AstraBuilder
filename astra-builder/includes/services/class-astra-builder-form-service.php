<?php
/**
 * Form service responsible for persisting submissions and spam safeguards.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Form_Service {
    const SUBMISSION_POST_TYPE = 'astra_form_submission';
    const META_FORM_ID         = '_astra_builder_form_id';
    const META_FIELDS          = '_astra_builder_form_fields';
    const META_META            = '_astra_builder_submission_meta';

    /**
     * Default spam protection configuration.
     *
     * @var array
     */
    protected $spam_settings = array(
        'honeypotField'  => 'astra_builder_field',
        'minimumSeconds' => 3,
    );

    /**
     * Bootstrap the service.
     */
    public function register() {
        add_action( 'init', array( $this, 'register_post_type' ) );
    }

    /**
     * Register the internal submission post type.
     */
    public function register_post_type() {
        register_post_type(
            self::SUBMISSION_POST_TYPE,
            array(
                'label'               => __( 'Astra Form Submissions', 'astra-builder' ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => 'edit.php?post_type=' . Astra_Builder_Template_Service::TEMPLATE_POST_TYPE,
                'supports'            => array( 'title', 'custom-fields' ),
                'capability_type'     => 'page',
                'map_meta_cap'        => true,
                'show_in_rest'        => false,
                'menu_icon'           => 'dashicons-feedback',
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
            )
        );
    }

    /**
     * Provide spam configuration for editor UIs.
     *
     * @return array
     */
    public function get_editor_config() {
        return array(
            'spam' => $this->get_spam_settings(),
        );
    }

    /**
     * Retrieve spam protection settings.
     *
     * @return array
     */
    public function get_spam_settings() {
        return $this->spam_settings;
    }

    /**
     * Ensure submitted fields are sanitized.
     *
     * @param array $fields Raw field payload.
     *
     * @return array
     */
    public function sanitize_fields( $fields ) {
        $fields    = is_array( $fields ) ? $fields : array();
        $sanitized = array();

        foreach ( $fields as $key => $value ) {
            $field_key = is_string( $key ) ? sanitize_key( $key ) : $key;
            if ( is_array( $value ) ) {
                $sanitized[ $field_key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $sanitized[ $field_key ] = sanitize_text_field( (string) $value );
            }
        }

        return $sanitized;
    }

    /**
     * Validate a submission payload.
     *
     * @param array $payload Submission payload.
     *
     * @return true|WP_Error
     */
    public function validate_submission( $payload ) {
        $payload = wp_parse_args(
            $payload,
            array(
                'formId'       => '',
                'fields'       => array(),
                'honeypot'     => '',
                'submittedAt'  => 0,
                'requirements' => array(),
            )
        );

        if ( empty( $payload['formId'] ) ) {
            return new WP_Error( 'astra_builder_invalid_form', __( 'Form identifier missing from submission.', 'astra-builder' ) );
        }

        if ( empty( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) {
            return new WP_Error( 'astra_builder_empty_submission', __( 'No fields were provided with the submission.', 'astra-builder' ) );
        }

        if ( ! empty( $payload['honeypot'] ) ) {
            return new WP_Error( 'astra_builder_spam_detected', __( 'Spam protection triggered by honeypot field.', 'astra-builder' ) );
        }

        $spam_settings = $this->get_spam_settings();
        $timestamp     = isset( $payload['submittedAt'] ) ? (int) $payload['submittedAt'] : 0;

        if ( $timestamp > 0 ) {
            if ( strlen( (string) $timestamp ) > 10 ) {
                $timestamp = (int) floor( $timestamp / 1000 );
            }
            $delta = time() - $timestamp;
            if ( $delta < absint( $spam_settings['minimumSeconds'] ) ) {
                return new WP_Error( 'astra_builder_fast_submission', __( 'Submission happened too quickly and was blocked.', 'astra-builder' ) );
            }
        }

        $requirements = array();
        if ( ! empty( $payload['requirements'] ) ) {
            if ( is_string( $payload['requirements'] ) ) {
                $decoded = json_decode( wp_unslash( $payload['requirements'] ), true );
                if ( is_array( $decoded ) ) {
                    $requirements = $decoded;
                }
            } elseif ( is_array( $payload['requirements'] ) ) {
                $requirements = $payload['requirements'];
            }
        }

        foreach ( $requirements as $required_key ) {
            $required_key = sanitize_key( $required_key );
            if ( empty( $required_key ) ) {
                continue;
            }
            $value = isset( $payload['fields'][ $required_key ] ) ? $payload['fields'][ $required_key ] : '';
            if ( is_array( $value ) ) {
                $value = implode( '', $value );
            }
            if ( '' === trim( (string) $value ) ) {
                return new WP_Error( 'astra_builder_missing_required', sprintf( __( 'Required field %s is missing.', 'astra-builder' ), $required_key ) );
            }
        }

        return true;
    }

    /**
     * Persist a submission inside WordPress.
     *
     * @param string $form_id  Form identifier.
     * @param array  $payload  Submission payload.
     *
     * @return int|WP_Error Post ID or error.
     */
    public function persist_submission( $form_id, $payload ) {
        $title = sprintf( __( 'Submission on %s', 'astra-builder' ), current_time( 'mysql' ) );

        $post_id = wp_insert_post(
            array(
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_type'   => self::SUBMISSION_POST_TYPE,
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, self::META_FORM_ID, sanitize_text_field( $form_id ) );
        update_post_meta( $post_id, self::META_FIELDS, isset( $payload['fields'] ) ? $payload['fields'] : array() );
        update_post_meta( $post_id, self::META_META, isset( $payload['context'] ) ? $payload['context'] : array() );

        return $post_id;
    }

    /**
     * Notify integrations about a new submission.
     *
     * @param int    $submission_id Submission identifier.
     * @param string $form_id       Form identifier.
     * @param array  $fields        Submitted fields.
     * @param array  $context       Contextual metadata.
     */
    public function dispatch_integrations( $submission_id, $form_id, $fields, $context = array() ) {
        $admin_email = get_option( 'admin_email' );
        $subject     = sprintf( __( 'New Astra Builder submission (%s)', 'astra-builder' ), $form_id );
        $lines       = array();

        foreach ( $fields as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
            }
            $lines[] = sprintf( '%s: %s', $key, $value );
        }

        if ( $admin_email && ! empty( $lines ) ) {
            wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
        }

        do_action( 'astra_builder_form_submission', $submission_id, $form_id, $fields, $context );
    }

    /**
     * Retrieve recent submissions for the REST API.
     *
     * @param array $args Query arguments.
     *
     * @return array
     */
    public function get_recent_submissions( $args = array() ) {
        $defaults = array(
            'posts_per_page' => 10,
        );

        $query_args = wp_parse_args(
            $args,
            array(
                'post_type'      => self::SUBMISSION_POST_TYPE,
                'posts_per_page' => $defaults['posts_per_page'],
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $query = new WP_Query( $query_args );
        $items = array();

        foreach ( $query->posts as $post ) {
            $items[] = array(
                'id'      => $post->ID,
                'formId'  => get_post_meta( $post->ID, self::META_FORM_ID, true ),
                'fields'  => get_post_meta( $post->ID, self::META_FIELDS, true ),
                'context' => get_post_meta( $post->ID, self::META_META, true ),
                'created' => get_post_time( 'c', true, $post ),
            );
        }

        return $items;
    }
}
