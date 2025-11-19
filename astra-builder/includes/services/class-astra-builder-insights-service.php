<?php
/**
 * Handles SEO panels, analytics, and telemetry consent.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Insights_Service {
    const OPTION = 'astra_builder_insights';

    /**
     * Register service hooks.
     */
    public function register() {
        add_action( 'init', array( $this, 'maybe_seed_option' ) );
        add_action( 'init', array( $this, 'maybe_ping_telemetry' ), 15 );
        add_action( 'admin_notices', array( $this, 'maybe_render_consent_notice' ) );
        add_action( 'wp_head', array( $this, 'output_seo_tags' ), 1 );
        add_action( 'wp_head', array( $this, 'output_analytics_pixels' ), 20 );
    }

    /**
     * Ensure option exists.
     */
    public function maybe_seed_option() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, $this->get_default_settings() );
        }
    }

    /**
     * Retrieve default settings.
     *
     * @return array
     */
    protected function get_default_settings() {
        return array(
            'consent'   => false,
            'seo'       => array(
                'metaTitlePattern'       => '%site_title% – %page_title%',
                'metaDescriptionFallback'=> __( 'Page created with Astra Builder.', 'astra-builder' ),
                'twitterHandle'          => '',
            ),
            'analytics' => array(
                'providers' => array(),
            ),
            'telemetry' => array(
                'last_ping' => 0,
            ),
        );
    }

    /**
     * Fetch stored settings.
     *
     * @return array
     */
    public function get_settings() {
        $settings = get_option( self::OPTION, array() );

        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return $this->get_default_settings();
        }

        return wp_parse_args( $settings, $this->get_default_settings() );
    }

    /**
     * Update stored settings.
     *
     * @param array $settings Settings to persist.
     */
    public function update_settings( $settings ) {
        $current  = $this->get_settings();
        $sanitized = $this->sanitize_settings( $settings );
        $merged   = array_replace_recursive( $current, $sanitized );

        update_option( self::OPTION, $merged );
    }

    /**
     * Sanitize payloads.
     *
     * @param array $settings Raw settings.
     *
     * @return array
     */
    protected function sanitize_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return $this->get_default_settings();
        }

        $sanitized = array();

        $sanitized['consent'] = ! empty( $settings['consent'] );

        if ( isset( $settings['seo'] ) && is_array( $settings['seo'] ) ) {
            $sanitized['seo'] = array(
                'metaTitlePattern'        => sanitize_text_field( isset( $settings['seo']['metaTitlePattern'] ) ? $settings['seo']['metaTitlePattern'] : '' ),
                'metaDescriptionFallback' => sanitize_text_field( isset( $settings['seo']['metaDescriptionFallback'] ) ? $settings['seo']['metaDescriptionFallback'] : '' ),
                'twitterHandle'           => sanitize_text_field( isset( $settings['seo']['twitterHandle'] ) ? $settings['seo']['twitterHandle'] : '' ),
            );
        }

        if ( isset( $settings['analytics']['providers'] ) && is_array( $settings['analytics']['providers'] ) ) {
            $sanitized['analytics']['providers'] = array();

            foreach ( $settings['analytics']['providers'] as $provider => $id ) {
                $sanitized['analytics']['providers'][ sanitize_key( $provider ) ] = sanitize_text_field( $id );
            }
        }

        return $sanitized;
    }

    /**
     * Determine if telemetry consent exists.
     *
     * @return bool
     */
    public function has_consent() {
        $settings = $this->get_settings();

        return ! empty( $settings['consent'] );
    }

    /**
     * Possibly ping telemetry endpoint once per day.
     */
    public function maybe_ping_telemetry() {
        if ( ! $this->has_consent() ) {
            return;
        }

        $settings = $this->get_settings();
        $last_ping = isset( $settings['telemetry']['last_ping'] ) ? absint( $settings['telemetry']['last_ping'] ) : 0;

        if ( $last_ping && ( time() - $last_ping ) < DAY_IN_SECONDS ) {
            return;
        }

        $payload = array(
            'siteUrl'   => home_url(),
            'locale'    => get_locale(),
            'timestamp' => time(),
            'versions'  => array(
                'php'      => PHP_VERSION,
                'wp'       => get_bloginfo( 'version' ),
                'plugin'   => defined( 'ASTRA_BUILDER_VERSION' ) ? ASTRA_BUILDER_VERSION : '0.1.0',
            ),
        );

        do_action( 'astra_builder_send_telemetry', $payload );

        $settings['telemetry']['last_ping'] = time();
        update_option( self::OPTION, $settings );
    }

    /**
     * Output SEO tags using configured defaults.
     */
    public function output_seo_tags() {
        if ( is_admin() ) {
            return;
        }

        $settings = $this->get_settings();
        $pattern  = isset( $settings['seo']['metaTitlePattern'] ) ? $settings['seo']['metaTitlePattern'] : '%site_title% – %page_title%';
        $fallback = isset( $settings['seo']['metaDescriptionFallback'] ) ? $settings['seo']['metaDescriptionFallback'] : '';

        $title = str_replace(
            array( '%site_title%', '%page_title%' ),
            array( get_bloginfo( 'name' ), wp_get_document_title() ),
            $pattern
        );

        $description = $fallback;

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_excerpt( $post ) ) {
                $description = wp_strip_all_tags( get_the_excerpt( $post ) );
            }
        }

        if ( empty( $description ) ) {
            $description = get_bloginfo( 'description' );
        }

        printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
        printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );

        if ( ! empty( $settings['seo']['twitterHandle'] ) ) {
            printf( '<meta name="twitter:site" content="%s" />' . "\n", esc_attr( $settings['seo']['twitterHandle'] ) );
        }
    }

    /**
     * Print lightweight analytics snippets when IDs exist.
     */
    public function output_analytics_pixels() {
        if ( is_admin() ) {
            return;
        }

        $settings = $this->get_settings();
        $providers = isset( $settings['analytics']['providers'] ) ? $settings['analytics']['providers'] : array();

        if ( empty( $providers ) ) {
            return;
        }

        foreach ( $providers as $provider => $id ) {
            if ( empty( $id ) ) {
                continue;
            }

            switch ( $provider ) {
                case 'google':
                    printf( '<script async src="https://www.googletagmanager.com/gtag/js?id=%s"></script>' . "\n", esc_attr( $id ) );
                    printf( '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","%s");</script>' . "\n", esc_js( $id ) );
                    break;
                case 'meta':
                    printf( '<script>!(function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod? n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)})(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","%s");fbq("track","PageView");</script>' . "\n", esc_js( $id ) );
                    printf( '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=%1$s&ev=PageView&noscript=1" /></noscript>' . "\n", esc_attr( $id ) );
                    break;
                default:
                    printf( '<!-- %1$s analytics enabled with id %2$s -->' . "\n", esc_html( ucfirst( $provider ) ), esc_html( $id ) );
                    break;
            }
        }
    }

    /**
     * Show a consent notice when telemetry is disabled.
     */
    public function maybe_render_consent_notice() {
        if ( ! current_user_can( 'manage_options' ) || $this->has_consent() ) {
            return;
        }

        printf(
            '<div class="notice notice-info"><p>%s</p></div>',
            wp_kses_post(
                sprintf(
                    /* translators: %s is a bolded label. */
                    __( '%s Help us improve Astra Builder by sharing anonymous metrics. Enable telemetry inside Builder settings.', 'astra-builder' ),
                    '<strong>' . esc_html__( 'Telemetry opt-in:', 'astra-builder' ) . '</strong>'
                )
            )
        );
    }

    /**
     * Provide editor configuration for SEO/analytics panels.
     *
     * @return array
     */
    public function get_editor_config() {
        $settings = $this->get_settings();

        return array(
            'seo'        => isset( $settings['seo'] ) ? $settings['seo'] : array(),
            'analytics'  => isset( $settings['analytics'] ) ? $settings['analytics'] : array(),
            'telemetry'  => array(
                'consent'  => $this->has_consent(),
                'lastPing' => isset( $settings['telemetry']['last_ping'] ) ? $settings['telemetry']['last_ping'] : 0,
            ),
        );
    }
}
