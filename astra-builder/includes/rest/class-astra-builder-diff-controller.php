<?php
/**
 * REST controller that exposes diffing endpoints for pages, templates, and components.
 *
 * @package AstraBuilder\REST
 */

class Astra_Builder_REST_Diff_Controller extends Astra_Builder_REST_Controller {
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'diff';

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
     * Register routes.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<type>page|template|component)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_diff' ),
                    'permission_callback' => array( $this, 'can_view_diff' ),
                    'args'                => array(
                        'from' => array(
                            'type'     => 'integer',
                            'required' => true,
                        ),
                        'to'   => array(
                            'type'     => 'integer',
                            'required' => true,
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Permission callback.
     *
     * @return true|WP_Error
     */
    public function can_view_diff() {
        return $this->permissions_check( 'edit_theme_options' );
    }

    /**
     * Generate a diff payload for two posts.
     *
     * @param WP_REST_Request $request Request payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_diff( WP_REST_Request $request ) {
        $type    = sanitize_key( $request['type'] );
        $from_id = absint( $request->get_param( 'from' ) );
        $to_id   = absint( $request->get_param( 'to' ) );

        if ( ! $from_id || ! $to_id ) {
            return new WP_Error( 'astra_builder_invalid_diff', __( 'Both revision identifiers are required.', 'astra-builder' ), array( 'status' => 400 ) );
        }

        $from = $this->get_post_for_type( $type, $from_id );
        $to   = $this->get_post_for_type( $type, $to_id );

        if ( ! $from || ! $to ) {
            return new WP_Error( 'astra_builder_invalid_diff', __( 'Unable to find the requested revisions.', 'astra-builder' ), array( 'status' => 404 ) );
        }

        $payload = array(
            'type'    => $type,
            'from'    => $this->format_post_summary( $from ),
            'to'      => $this->format_post_summary( $to ),
            'content' => $this->build_content_diff( $from, $to ),
            'meta'    => $this->build_meta_diff( $type, $from->ID, $to->ID ),
        );

        return rest_ensure_response( $payload );
    }

    /**
     * Resolve a post for the requested resource type.
     *
     * @param string $type    Resource type.
     * @param int    $post_id Post identifier.
     *
     * @return WP_Post|null
     */
    protected function get_post_for_type( $type, $post_id ) {
        switch ( $type ) {
            case 'page':
                $post_type = 'page';
                break;
            case 'component':
                $post_type = Astra_Builder_Template_Service::COMPONENT_POST_TYPE;
                break;
            default:
                $post_type = Astra_Builder_Template_Service::TEMPLATE_POST_TYPE;
        }

        $post = get_post( $post_id );

        if ( ! $post || $post_type !== $post->post_type ) {
            return null;
        }

        return $post;
    }

    /**
     * Summarize a post for diff payloads.
     *
     * @param WP_Post $post Post object.
     *
     * @return array
     */
    protected function format_post_summary( WP_Post $post ) {
        return array(
            'id'        => (int) $post->ID,
            'title'     => get_the_title( $post ),
            'status'    => $post->post_status,
            'modified'  => mysql_to_rfc3339( $post->post_modified_gmt ),
            'author'    => get_the_author_meta( 'display_name', $post->post_author ),
        );
    }

    /**
     * Build a diff for the post content.
     *
     * @param WP_Post $from Source post.
     * @param WP_Post $to   Target post.
     *
     * @return array
     */
    protected function build_content_diff( WP_Post $from, WP_Post $to ) {
        $diff = wp_text_diff( $from->post_content, $to->post_content, array( 'title' => __( 'Content changes', 'astra-builder' ) ) );

        return array(
            'hasChanges' => $from->post_content !== $to->post_content,
            'html'       => $diff,
        );
    }

    /**
     * Build a diff payload for registered meta.
     *
     * @param string $type   Resource type.
     * @param int    $from   Source ID.
     * @param int    $to     Target ID.
     *
     * @return array
     */
    protected function build_meta_diff( $type, $from, $to ) {
        $meta_keys = array();

        if ( 'page' === $type ) {
            $meta_keys = array();
        } else {
            $meta_keys = array(
                Astra_Builder_Template_Service::META_CONDITIONS,
                Astra_Builder_Template_Service::META_STYLE_OVERRIDES,
                Astra_Builder_Template_Service::META_ASSET_MANIFEST,
            );
        }

        $diffs = array();

        foreach ( $meta_keys as $meta_key ) {
            $from_value = get_post_meta( $from, $meta_key, true );
            $to_value   = get_post_meta( $to, $meta_key, true );

            if ( $from_value === $to_value ) {
                continue;
            }

            $diffs[ $meta_key ] = array(
                'from' => $from_value,
                'to'   => $to_value,
            );
        }

        return $diffs;
    }
}
