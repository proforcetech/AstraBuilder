<?php
/**
 * Localization helpers for Astra Builder.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Locale_Service {
    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    /**
     * Load translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'astra-builder', false, dirname( plugin_basename( ASTRA_BUILDER_FILE ) ) . '/languages' );
    }

    /**
     * Provide locale configuration for the editor.
     *
     * @return array
     */
    public function get_editor_config() {
        return array(
            'locale'   => determine_locale(),
            'isRTL'    => is_rtl(),
            'language' => get_bloginfo( 'language' ),
        );
    }

    /**
     * Detect the current language slug supporting WPML/Polylang.
     *
     * @return string
     */
    public function get_current_language_code() {
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );

            if ( $lang ) {
                return $lang;
            }
        }

        $wpml_lang = apply_filters( 'wpml_current_language', null );

        if ( $wpml_lang ) {
            return $wpml_lang;
        }

        return determine_locale();
    }
}
