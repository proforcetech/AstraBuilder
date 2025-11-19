<?php
/**
 * REST controller that powers collaboration features like inline comments,
 * section locks, presence indicators, and partial publish state.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Collaboration_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'collaboration';

    /**
     * Template service dependency.
     *
     * @var Astra_Builder_Template_Service
     */
    protected $templates;

    /**
     * Constructor.
     *
     * @param Astra_Builder_Template_Service $templates Template service instance.
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
            '/' . $this->rest_base . '/(?P<post_id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/comments',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_comment_thread' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/comments/(?P<thread_id>[a-z0-9-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_comment_thread' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/locks',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'update_section_lock' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/sections',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'update_section_state' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/presence',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'update_presence' ),
                    'permission_callback' => array( $this, 'can_collaborate' ),
                ),
            )
        );
    }

    /**
     * Determine if the current user can use collaboration features.
     *
     * @return true|WP_Error
     */
    public function can_collaborate() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Return collaboration payload for a post.
     *
     * @param WP_REST_Request $request Request data.
     *
     * @return WP_REST_Response
     */
    public function get_item( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );

        if ( ! $post_id ) {
            return new WP_Error( 'astra_builder_invalid_post', __( 'A valid post is required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $response = array(
            'comments' => $this->templates->get_comment_threads( $post_id ),
            'locks'    => $this->templates->get_section_locks( $post_id ),
            'sections' => $this->templates->get_section_state( $post_id ),
            'presence' => array_values( $this->get_presence_roster( $post_id ) ),
            'postLock' => $this->get_post_lock_payload( $post_id ),
        );

        return rest_ensure_response( $response );
    }

    /**
     * Create a new comment thread for a block.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_comment_thread( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );
        $block   = sanitize_text_field( $request->get_param( 'blockId' ) );
        $message = wp_kses_post( $request->get_param( 'message' ) );

        if ( ! $post_id || ! $block || empty( $message ) ) {
            return new WP_Error( 'astra_builder_invalid_thread', __( 'Block and message are required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $threads = $this->templates->get_comment_threads( $post_id );
        $thread  = array(
            'id'        => wp_generate_uuid4(),
            'blockId'   => $block,
            'created'   => current_time( 'mysql', true ),
            'resolved'  => false,
            'comments'  => array( $this->format_comment_message( $message ) ),
        );

        array_unshift( $threads, $thread );
        $this->templates->save_comment_threads( $post_id, $threads );

        return rest_ensure_response( $thread );
    }

    /**
     * Update an existing thread by appending a reply or toggling resolution.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_comment_thread( WP_REST_Request $request ) {
        $post_id   = absint( $request['post_id'] );
        $thread_id = sanitize_text_field( $request['thread_id'] );
        $message   = $request->get_param( 'message' );
        $resolved  = $request->get_param( 'resolved' );

        if ( ! $post_id || ! $thread_id ) {
            return new WP_Error( 'astra_builder_invalid_thread', __( 'Thread not found.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        $threads = $this->templates->get_comment_threads( $post_id );

        foreach ( $threads as &$thread ) {
            if ( $thread_id !== $thread['id'] ) {
                continue;
            }

            if ( null !== $message && '' !== trim( $message ) ) {
                $thread['comments'][] = $this->format_comment_message( wp_kses_post( $message ) );
            }

            if ( null !== $resolved ) {
                $thread['resolved'] = (bool) $resolved;
            }

            $this->templates->save_comment_threads( $post_id, $threads );

            return rest_ensure_response( $thread );
        }

        return new WP_Error( 'astra_builder_invalid_thread', __( 'Thread not found.', 'astra-builder' ), array( 'status' => 404 ) );
    }

    /**
     * Update section locks.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_section_lock( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );
        $section = sanitize_key( $request->get_param( 'section' ) );
        $lock    = filter_var( $request->get_param( 'lock' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

        if ( ! $post_id || ! $section ) {
            return new WP_Error( 'astra_builder_invalid_section', __( 'Section is required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $locks      = $this->templates->get_section_locks( $post_id );
        $current    = isset( $locks[ $section ] ) ? $locks[ $section ] : null;
        $user       = wp_get_current_user();
        $user_id    = (int) $user->ID;
        $user_entry = array(
            'user'      => $user_id,
            'name'      => $user->display_name,
            'role'      => $this->get_current_builder_role(),
            'timestamp' => current_time( 'mysql', true ),
        );

        if ( $lock ) {
            if ( $current && (int) $current['user'] !== $user_id ) {
                return new WP_Error( 'astra_builder_locked', __( 'Another teammate already locked this section.', 'astra-builder' ), array( 'status' => 409 ) );
            }

            $locks[ $section ] = $user_entry;
        } else {
            if ( $current && (int) $current['user'] !== $user_id ) {
                return new WP_Error( 'astra_builder_locked', __( 'Only the teammate that locked the section can unlock it.', 'astra-builder' ), array( 'status' => 403 ) );
            }

            unset( $locks[ $section ] );
        }

        $this->templates->save_section_locks( $post_id, $locks );

        return rest_ensure_response( $locks );
    }

    /**
     * Update section workflow state (partial publish flow).
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_section_state( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );
        $section = sanitize_key( $request->get_param( 'section' ) );
        $status  = sanitize_key( $request->get_param( 'status' ) );

        if ( ! $post_id || ! $section || ! $status ) {
            return new WP_Error( 'astra_builder_invalid_section', __( 'Section and status are required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $valid_status = array( 'draft', 'review', 'approved', 'published' );

        if ( ! in_array( $status, $valid_status, true ) ) {
            return new WP_Error( 'astra_builder_invalid_status', __( 'Invalid section status.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $catalog = $this->templates->get_section_catalog();

        if ( ! isset( $catalog[ $section ] ) ) {
            return new WP_Error( 'astra_builder_invalid_section', __( 'Unknown section.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $state     = $this->templates->get_section_state( $post_id );
        $log       = isset( $state[ $section ]['log'] ) && is_array( $state[ $section ]['log'] ) ? $state[ $section ]['log'] : array();
        $timestamp = current_time( 'mysql', true );

        $log[] = array(
            'status' => $status,
            'user'   => get_current_user_id(),
            'time'   => $timestamp,
        );

        $state[ $section ] = array(
            'status'  => $status,
            'updated' => $timestamp,
            'user'    => get_current_user_id(),
            'log'     => $log,
        );

        $this->templates->save_section_state( $post_id, $state );

        return rest_ensure_response( $state );
    }

    /**
     * Update real-time presence roster.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response
     */
    public function update_presence( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );
        $block   = sanitize_text_field( $request->get_param( 'blockId' ) );

        if ( ! $post_id ) {
            return new WP_Error( 'astra_builder_invalid_post', __( 'A valid post is required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $presence = $this->get_presence_roster( $post_id );
        $user     = wp_get_current_user();
        $user_id  = (int) $user->ID;

        $presence[ $user_id ] = array(
            'user'      => $user_id,
            'name'      => $user->display_name,
            'avatar'    => get_avatar_url( $user_id, array( 'size' => 48 ) ),
            'block'     => $block,
            'role'      => $this->get_current_builder_role(),
            'timestamp' => time(),
        );

        $this->persist_presence( $post_id, $presence );

        return rest_ensure_response( array_values( $presence ) );
    }

    /**
     * Format a single comment entry.
     *
     * @param string $message Comment message.
     *
     * @return array
     */
    protected function format_comment_message( $message ) {
        $user = wp_get_current_user();

        return array(
            'id'      => wp_generate_uuid4(),
            'message' => wp_kses_post( $message ),
            'created' => current_time( 'mysql', true ),
            'author'  => array(
                'id'     => (int) $user->ID,
                'name'   => $user->display_name,
                'avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
            ),
        );
    }

    /**
     * Build the roster of active presences.
     *
     * @param int $post_id Post identifier.
     *
     * @return array
     */
    protected function get_presence_roster( $post_id ) {
        $post_id  = absint( $post_id );
        $presence = get_transient( $this->get_presence_transient_key( $post_id ) );

        if ( ! is_array( $presence ) ) {
            $presence = array();
        }

        $now      = time();
        $filtered = array();

        foreach ( $presence as $entry ) {
            if ( empty( $entry['timestamp'] ) ) {
                continue;
            }

            if ( $now - (int) $entry['timestamp'] > 120 ) {
                continue;
            }

            $filtered[ (int) $entry['user'] ] = $entry;
        }

        if ( count( $filtered ) !== count( $presence ) ) {
            $this->persist_presence( $post_id, $filtered );
        }

        return $filtered;
    }

    /**
     * Persist the active roster.
     *
     * @param int   $post_id  Post identifier.
     * @param array $presence Presence entries keyed by user.
     */
    protected function persist_presence( $post_id, $presence ) {
        set_transient( $this->get_presence_transient_key( $post_id ), $presence, 5 * MINUTE_IN_SECONDS );
    }

    /**
     * Generate the transient key for presence.
     *
     * @param int $post_id Post identifier.
     *
     * @return string
     */
    protected function get_presence_transient_key( $post_id ) {
        return 'astra_builder_presence_' . absint( $post_id );
    }

    /**
     * Compose the current post lock payload.
     *
     * @param int $post_id Post identifier.
     *
     * @return array|null
     */
    protected function get_post_lock_payload( $post_id ) {
        $lock = wp_check_post_lock( $post_id );

        if ( ! $lock ) {
            return null;
        }

        $user = get_userdata( $lock );

        if ( ! $user ) {
            return null;
        }

        return array(
            'user'   => (int) $user->ID,
            'name'   => $user->display_name,
            'avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
        );
    }

    /**
     * Infer the current builder role for the signed-in user.
     *
     * @return string
     */
    protected function get_current_builder_role() {
        if ( current_user_can( 'manage_options' ) ) {
            return 'developer';
        }

        if ( current_user_can( 'publish_pages' ) ) {
            return 'approver';
        }

        if ( current_user_can( 'edit_pages' ) ) {
            return 'editor';
        }

        return 'designer';
    }
}
