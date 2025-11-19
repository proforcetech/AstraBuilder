<?php
/**
 * Media utilities for Astra Builder.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Media_Service {
    const FOCAL_POINT_META = '_astra_builder_focal_point';
    const CDN_OPTION       = 'astra_builder_cdn_base';

    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'init', array( $this, 'register_image_sizes' ) );
        add_filter( 'image_size_names_choose', array( $this, 'expose_custom_sizes' ) );
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'include_media_utilities' ), 10, 2 );
        add_action( 'rest_after_insert_attachment', array( $this, 'persist_focal_point' ), 10, 2 );
        add_filter( 'astra_builder_media_markup', array( $this, 'inject_responsive_backgrounds' ), 10, 1 );
    }

    /**
     * Register responsive background sizes used by the canvas.
     */
    public function register_image_sizes() {
        add_image_size( 'astra-builder-landscape', 1600, 900, true );
        add_image_size( 'astra-builder-portrait', 900, 1200, true );
        add_image_size( 'astra-builder-square', 1200, 1200, true );
    }

    /**
     * Surface custom sizes inside media modals.
     *
     * @param array $sizes Existing sizes.
     * @return array
     */
    public function expose_custom_sizes( $sizes ) {
        $sizes['astra-builder-landscape'] = __( 'Astra Landscape', 'astra-builder' );
        $sizes['astra-builder-portrait']  = __( 'Astra Portrait', 'astra-builder' );
        $sizes['astra-builder-square']    = __( 'Astra Square', 'astra-builder' );

        return $sizes;
    }

    /**
     * Persist focal point metadata coming from the REST API.
     *
     * @param WP_Post         $attachment Saved attachment.
     * @param WP_REST_Request $request    Request.
     */
    public function persist_focal_point( $attachment, $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( ! $request instanceof WP_REST_Request ) {
            return;
        }

        $meta = $request->get_param( 'meta' );

        if ( empty( $meta['astraBuilderFocalPoint'] ) || ! is_array( $meta['astraBuilderFocalPoint'] ) ) {
            return;
        }

        $focal = wp_parse_args(
            $meta['astraBuilderFocalPoint'],
            array(
                'x' => 0.5,
                'y' => 0.5,
            )
        );

        $focal['x'] = min( max( 0, floatval( $focal['x'] ) ), 1 );
        $focal['y'] = min( max( 0, floatval( $focal['y'] ) ), 1 );

        update_post_meta( $attachment->ID, self::FOCAL_POINT_META, $focal );
    }

    /**
     * Enrich attachment responses so the editor can enable cropping helpers.
     *
     * @param array   $response Existing response.
     * @param WP_Post $attachment Attachment object.
     *
     * @return array
     */
    public function include_media_utilities( $response, $attachment ) {
        if ( empty( $response ) || ! $attachment instanceof WP_Post ) {
            return $response;
        }

        $response['astraBuilder'] = array(
            'focalPoint' => $this->get_focal_point( $attachment->ID ),
            'cdnUrl'     => $this->maybe_get_cdn_url( isset( $response['url'] ) ? $response['url'] : '' ),
        );

        return $response;
    }

    /**
     * Retrieve focal point meta.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return array
     */
    protected function get_focal_point( $attachment_id ) {
        $meta = get_post_meta( $attachment_id, self::FOCAL_POINT_META, true );

        if ( empty( $meta ) || ! is_array( $meta ) ) {
            return array(
                'x' => 0.5,
                'y' => 0.5,
            );
        }

        return array(
            'x' => min( max( 0, floatval( isset( $meta['x'] ) ? $meta['x'] : 0.5 ) ), 1 ),
            'y' => min( max( 0, floatval( isset( $meta['y'] ) ? $meta['y'] : 0.5 ) ), 1 ),
        );
    }

    /**
     * Filter markup to inject responsive background helpers.
     *
     * @param string $html Existing markup.
     *
     * @return string
     */
    public function inject_responsive_backgrounds( $html ) {
        if ( false === strpos( $html, 'data-astra-bg' ) ) {
            return $html;
        }

        return preg_replace_callback(
            '/<(section|div)([^>]+)data-astra-bg="(\d+)"([^>]*)>/i',
            function( $matches ) {
                $tag       = $matches[1];
                $before    = $matches[2];
                $image_id  = intval( $matches[3] );
                $after     = $matches[4];
                $style     = $this->build_background_style( $image_id );
                $focal     = $this->get_focal_point( $image_id );
                $focalAttr = sprintf( ' data-focal-x="%s" data-focal-y="%s"', esc_attr( $focal['x'] ), esc_attr( $focal['y'] ) );

                return sprintf(
                    '<%1$s%2$s%5$s style="%3$s"%4$s>',
                    $tag,
                    $before,
                    esc_attr( $style ),
                    $after,
                    $focalAttr
                );
            },
            $html
        );
    }

    /**
     * Build background styles with responsive sources.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return string
     */
    protected function build_background_style( $attachment_id ) {
        $sources = $this->get_responsive_background_sources( $attachment_id );

        if ( empty( $sources ) ) {
            return '';
        }

        $desktop = isset( $sources['desktop'] ) ? $sources['desktop'] : '';
        $tablet  = isset( $sources['tablet'] ) ? $sources['tablet'] : $desktop;
        $mobile  = isset( $sources['mobile'] ) ? $sources['mobile'] : $tablet;

        $style = sprintf( '--astra-bg-desktop:url(%s);--astra-bg-tablet:url(%s);--astra-bg-mobile:url(%s);background-image:url(%s);',
            esc_url_raw( $desktop ),
            esc_url_raw( $tablet ),
            esc_url_raw( $mobile ),
            esc_url_raw( $desktop )
        );

        return $style;
    }

    /**
     * Determine responsive background sources for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return array
     */
    protected function get_responsive_background_sources( $attachment_id ) {
        if ( ! $attachment_id ) {
            return array();
        }

        $sizes = array(
            'desktop' => 'full',
            'tablet'  => 'astra-builder-landscape',
            'mobile'  => 'astra-builder-square',
        );

        $sources = array();

        foreach ( $sizes as $device => $size ) {
            $image = wp_get_attachment_image_src( $attachment_id, $size );

            if ( empty( $image[0] ) ) {
                continue;
            }

            $sources[ $device ] = $this->maybe_get_cdn_url( $image[0] );
        }

        return $sources;
    }

    /**
     * Filter URLs through the CDN hook.
     *
     * @param string $url Source URL.
     *
     * @return string
     */
    protected function maybe_get_cdn_url( $url ) {
        if ( empty( $url ) ) {
            return $url;
        }

        $cdn_base = get_option( self::CDN_OPTION, '' );

        if ( empty( $cdn_base ) ) {
            return apply_filters( 'astra_builder_cdn_url', $url, $this );
        }

        return trailingslashit( untrailingslashit( $cdn_base ) ) . ltrim( wp_make_link_relative( $url ), '/' );
    }

    /**
     * Provide editor configuration for media utilities.
     *
     * @return array
     */
    public function get_editor_config() {
        $cdn = get_option( self::CDN_OPTION, '' );

        return array(
            'focalPointMetaKey' => self::FOCAL_POINT_META,
            'cropper'           => array(
                'ratios' => array(
                    array(
                        'label' => __( 'Square', 'astra-builder' ),
                        'value' => '1:1',
                    ),
                    array(
                        'label' => __( 'Landscape', 'astra-builder' ),
                        'value' => '16:9',
                    ),
                    array(
                        'label' => __( 'Portrait', 'astra-builder' ),
                        'value' => '3:4',
                    ),
                ),
            ),
            'responsiveBreakpoints' => array(
                'desktop' => 1440,
                'tablet'  => 1024,
                'mobile'  => 640,
            ),
            'cdn' => array(
                'enabled' => ! empty( $cdn ),
                'base'    => $cdn,
            ),
        );
    }
}
