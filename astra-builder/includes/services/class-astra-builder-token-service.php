<?php
/**
 * Service that manages Astra Builder design tokens and theme.json output.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Token_Service {
    const TOKENS_OPTION      = 'astra_builder_design_tokens';
    const THEME_JSON_OPTION  = 'astra_builder_theme_json';
    const SNAPSHOT_OPTION    = 'astra_builder_snapshots';
    const SETTINGS_OPTION    = 'astra_builder_settings';

    /**
     * Register the service and ensure default data is available.
     */
    public function register() {
        add_action( 'init', array( $this, 'maybe_seed_options' ) );
        add_action( 'update_option_' . self::TOKENS_OPTION, array( $this, 'handle_token_change' ), 10, 2 );
        add_action( 'add_option_' . self::TOKENS_OPTION, array( $this, 'handle_token_change' ), 10, 2 );
    }

    /**
     * Create options on the first run so other subsystems have predictable state.
     */
    public function maybe_seed_options() {
        if ( false === get_option( self::TOKENS_OPTION, false ) ) {
            add_option( self::TOKENS_OPTION, $this->get_default_tokens() );
        }

        if ( false === get_option( self::SNAPSHOT_OPTION, false ) ) {
            add_option( self::SNAPSHOT_OPTION, array() );
        }

        if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
            add_option( self::SETTINGS_OPTION, array() );
        }

        $this->synchronize_theme_json();
    }

    /**
     * Retrieve default tokens allowing themes to extend the set.
     *
     * @return array
     */
    public function get_default_tokens() {
        $defaults = array(
            'colors'  => array(
                array(
                    'slug'  => 'primary',
                    'name'  => __( 'Primary', 'astra-builder' ),
                    'color' => '#3a4f66',
                ),
                array(
                    'slug'  => 'secondary',
                    'name'  => __( 'Secondary', 'astra-builder' ),
                    'color' => '#f04f36',
                ),
            ),
            'typography' => array(
                'fontFamilies' => array(
                    array(
                        'slug'    => 'body',
                        'name'    => __( 'Body Font', 'astra-builder' ),
                        'family'  => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                        'weight'  => '400',
                        'style'   => 'normal',
                    ),
                ),
                'fontSizes' => array(
                    array(
                        'slug' => 'base',
                        'name' => __( 'Base', 'astra-builder' ),
                        'size' => '16px',
                    ),
                ),
            ),
        );

        return apply_filters( 'astra_builder_token_defaults', $defaults );
    }

    /**
     * Fetch the saved tokens.
     *
     * @return array
     */
    public function get_tokens() {
        $tokens = get_option( self::TOKENS_OPTION, array() );

        if ( empty( $tokens ) ) {
            $tokens = $this->get_default_tokens();
        }

        return $tokens;
    }

    /**
     * Update tokens and trigger theme.json generation.
     *
     * @param array $tokens Tokens to persist.
     *
     * @return bool
     */
    public function update_tokens( $tokens ) {
        $sanitized = $this->sanitize_tokens( $tokens );
        $previous  = $this->get_tokens();
        $sanitized = apply_filters( 'astra_builder_tokens_pre_update', $sanitized, $previous );
        $updated   = update_option( self::TOKENS_OPTION, $sanitized );

        if ( $updated ) {
            $this->synchronize_theme_json( $sanitized );
        }

        return $updated;
    }

    /**
     * Sanitize tokens coming from user input.
     *
     * @param array $tokens Tokens to sanitize.
     *
     * @return array
     */
    protected function sanitize_tokens( $tokens ) {
        if ( ! is_array( $tokens ) ) {
            return $this->get_default_tokens();
        }

        $sanitized = array();

        foreach ( $tokens as $group_key => $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $sanitized[ $group_key ] = array();

            foreach ( $group as $item_key => $item ) {
                if ( is_array( $item ) ) {
                    $sanitized[ $group_key ][ $item_key ] = array_map( 'sanitize_text_field', $item );
                } else {
                    $sanitized[ $group_key ][ $item_key ] = sanitize_text_field( $item );
                }
            }
        }

        return $sanitized;
    }

    /**
     * React to tokens that are updated outside the service.
     *
     * @param mixed $old_value Former tokens.
     * @param mixed $value     Current tokens.
     */
    public function handle_token_change( $old_value, $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( empty( $value ) ) {
            return;
        }

        $this->synchronize_theme_json( $value );
    }

    /**
     * Export tokens as JSON.
     *
     * @return string
     */
    public function export_tokens() {
        return wp_json_encode( $this->get_tokens() );
    }

    /**
     * Import tokens from a JSON string.
     *
     * @param string $json JSON string.
     *
     * @return bool
     */
    public function import_tokens( $json ) {
        $decoded = json_decode( $json, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return false;
        }

        return $this->update_tokens( $decoded );
    }

    /**
     * Store an array of snapshots in the option table.
     *
     * @param array $snapshots Snapshots to persist.
     *
     * @return bool
     */
    public function set_snapshots( $snapshots ) {
        if ( ! is_array( $snapshots ) ) {
            $snapshots = array();
        }

        return update_option( self::SNAPSHOT_OPTION, $snapshots );
    }

    /**
     * Retrieve saved snapshots.
     *
     * @return array
     */
    public function get_snapshots() {
        $snapshots = get_option( self::SNAPSHOT_OPTION, array() );

        return is_array( $snapshots ) ? $snapshots : array();
    }

    /**
     * Persist builder settings to the options table.
     *
     * @param array $settings Settings to save.
     *
     * @return bool
     */
    public function set_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return update_option( self::SETTINGS_OPTION, $settings );
    }

    /**
     * Fetch saved settings.
     *
     * @return array
     */
    public function get_settings() {
        $settings = get_option( self::SETTINGS_OPTION, array() );

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Create a theme.json structure from the current tokens and persist it.
     *
     * @param array|null $tokens Optional custom token set.
     *
     * @return array
     */
    public function build_theme_json( $tokens = null ) {
        $tokens = $tokens ? $this->sanitize_tokens( $tokens ) : $this->get_tokens();
        $defaults = $this->get_default_tokens();
        $merged   = wp_parse_args( $tokens, $defaults );

        $theme = array(
            'version'  => 2,
            'settings' => array(
                'color' => array(
                    'palette' => isset( $merged['colors'] ) ? array_values( $merged['colors'] ) : array(),
                ),
                'typography' => array(
                    'fontFamilies' => isset( $merged['typography']['fontFamilies'] ) ? array_values( $merged['typography']['fontFamilies'] ) : array(),
                    'fontSizes'    => isset( $merged['typography']['fontSizes'] ) ? array_values( $merged['typography']['fontSizes'] ) : array(),
                ),
            ),
        );

        /**
         * Filter the generated theme.json structure before it is saved.
         *
         * @param array $theme  The generated theme configuration.
         * @param array $tokens The tokens that produced the file.
         */
        $theme = apply_filters( 'astra_builder_theme_json', $theme, $merged );

        return $theme;
    }

    /**
     * Synchronize the theme.json artifact whenever tokens change.
     *
     * @param array|null $tokens Optional token set.
     */
    public function synchronize_theme_json( $tokens = null ) {
        $theme = $this->build_theme_json( $tokens );

        update_option( self::THEME_JSON_OPTION, $theme );

        $upload_dir = wp_upload_dir();

        if ( empty( $upload_dir['basedir'] ) ) {
            return;
        }

        $dir = trailingslashit( $upload_dir['basedir'] ) . 'astra-builder';
        wp_mkdir_p( $dir );
        $file = trailingslashit( $dir ) . 'theme.json';

        file_put_contents( $file, wp_json_encode( $theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        /**
         * Fires after the generated theme.json file has been synchronized.
         *
         * @param array  $theme Theme configuration array.
         * @param string $file  Path to the saved file.
         */
        do_action( 'astra_builder_theme_json_updated', $theme, $file );
    }
}
