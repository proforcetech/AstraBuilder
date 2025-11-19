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
                'body' => array(
                    'fontFamily'    => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                    'fontWeight'    => '400',
                    'lineHeight'    => '1.6',
                    'textTransform' => 'none',
                ),
                'heading' => array(
                    'fontFamily'    => 'Outfit, "Segoe UI", sans-serif',
                    'fontWeight'    => '600',
                    'lineHeight'    => '1.3',
                    'textTransform' => 'none',
                ),
            ),
            'components' => array(
                'buttons' => array(
                    'borderRadius'  => '999px',
                    'textTransform' => 'uppercase',
                    'fontWeight'    => '600',
                    'paddingY'      => '0.85rem',
                    'paddingX'      => '1.5rem',
                ),
                'lists'   => array(
                    'gap'         => '0.75rem',
                    'markerColor' => '#3a4f66',
                    'markerStyle' => 'disc',
                ),
                'forms'   => array(
                    'fieldPaddingY' => '0.75rem',
                    'fieldPaddingX' => '1rem',
                    'borderRadius'  => '8px',
                    'borderColor'   => 'rgba(15,23,42,0.12)',
                    'focusColor'    => '#2563eb',
                    'background'    => '#ffffff',
                ),
            ),
            'modes'      => array(
                'dark' => array(
                    'enabled'    => true,
                    'background' => '#0f172a',
                    'surface'    => '#1f2937',
                    'text'       => '#e2e8f0',
                    'muted'      => '#94a3b8',
                    'accent'     => '#38bdf8',
                    'buttons'    => array(
                        'background' => '#2563eb',
                        'color'      => '#f8fafc',
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
        } else {
            $tokens = $this->merge_tokens( $this->get_default_tokens(), $tokens );
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
        $merged    = $this->merge_tokens( $this->get_default_tokens(), $sanitized );
        $previous  = $this->get_tokens();
        $merged    = apply_filters( 'astra_builder_tokens_pre_update', $merged, $previous );
        $updated   = update_option( self::TOKENS_OPTION, $merged );

        if ( $updated ) {
            $this->synchronize_theme_json( $merged );
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

        return $this->deep_sanitize( $tokens );
    }

    /**
     * Deep sanitize arrays or scalar values.
     *
     * @param mixed $value Mixed value.
     *
     * @return mixed
     */
    protected function deep_sanitize( $value ) {
        if ( is_array( $value ) ) {
            $sanitized = array();

            foreach ( $value as $key => $item ) {
                $clean_key = is_int( $key ) ? $key : preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $key );

                if ( '' === $clean_key && ! is_int( $key ) ) {
                    continue;
                }

                $sanitized[ $clean_key ] = $this->deep_sanitize( $item );
            }

            return $sanitized;
        }

        if ( is_bool( $value ) ) {
            return (bool) $value;
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Merge token arrays while preserving defaults.
     *
     * @param array $defaults Default token set.
     * @param array $tokens   Overrides.
     *
     * @return array
     */
    protected function merge_tokens( $defaults, $tokens ) {
        if ( ! is_array( $tokens ) ) {
            return is_array( $defaults ) ? $defaults : array();
        }

        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        return array_replace_recursive( $defaults, $tokens );
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

        return update_option( self::SNAPSHOT_OPTION, $this->sanitize_snapshots( $snapshots ) );
    }

    /**
     * Retrieve saved snapshots.
     *
     * @return array
     */
    public function get_snapshots() {
        $snapshots = get_option( self::SNAPSHOT_OPTION, array() );

        return $this->sanitize_snapshots( $snapshots );
    }

    /**
     * Create and persist a snapshot payload.
     *
     * @param array|null $tokens Optional token override.
     * @param array      $args   Metadata arguments.
     *
     * @return array
     */
    public function create_snapshot( $tokens = null, $args = array() ) {
        $existing = $this->get_snapshots();
        $user     = wp_get_current_user();
        $snapshot = array(
            'id'          => wp_generate_uuid4(),
            'name'        => ! empty( $args['name'] ) ? sanitize_text_field( $args['name'] ) : sprintf( __( 'Snapshot %s', 'astra-builder' ), wp_date( 'M j, Y H:i' ) ),
            'description' => ! empty( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
            'context'     => ! empty( $args['context'] ) ? sanitize_key( $args['context'] ) : 'manual',
            'created'     => current_time( 'mysql', true ),
            'version'     => $this->get_next_snapshot_version( $existing ),
            'author'      => array(
                'id'     => (int) $user->ID,
                'name'   => $user->display_name,
                'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
            ),
            'tokens'      => $tokens ? $this->deep_sanitize( $tokens ) : $this->get_tokens(),
        );

        array_unshift( $existing, $snapshot );
        $this->set_snapshots( $existing );

        return $snapshot;
    }

    /**
     * Determine the next semantic version for snapshots.
     *
     * @param array $snapshots Existing snapshots.
     *
     * @return int
     */
    protected function get_next_snapshot_version( $snapshots ) {
        $version = 1;

        foreach ( $snapshots as $snapshot ) {
            if ( isset( $snapshot['version'] ) ) {
                $version = max( $version, (int) $snapshot['version'] + 1 );
            }
        }

        return $version;
    }

    /**
     * Sanitize stored snapshots.
     *
     * @param mixed $snapshots Snapshot payload.
     *
     * @return array
     */
    protected function sanitize_snapshots( $snapshots ) {
        if ( ! is_array( $snapshots ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $snapshots as $snapshot ) {
            if ( empty( $snapshot['id'] ) ) {
                continue;
            }

            $sanitized[] = array(
                'id'          => sanitize_text_field( $snapshot['id'] ),
                'name'        => isset( $snapshot['name'] ) ? sanitize_text_field( $snapshot['name'] ) : '',
                'description' => isset( $snapshot['description'] ) ? sanitize_textarea_field( $snapshot['description'] ) : '',
                'context'     => isset( $snapshot['context'] ) ? sanitize_key( $snapshot['context'] ) : 'manual',
                'created'     => isset( $snapshot['created'] ) ? sanitize_text_field( $snapshot['created'] ) : '',
                'version'     => isset( $snapshot['version'] ) ? (int) $snapshot['version'] : 1,
                'author'      => array(
                    'id'     => isset( $snapshot['author']['id'] ) ? (int) $snapshot['author']['id'] : 0,
                    'name'   => isset( $snapshot['author']['name'] ) ? sanitize_text_field( $snapshot['author']['name'] ) : '',
                    'avatar' => isset( $snapshot['author']['avatar'] ) ? esc_url_raw( $snapshot['author']['avatar'] ) : '',
                ),
                'tokens'      => isset( $snapshot['tokens'] ) ? $this->deep_sanitize( $snapshot['tokens'] ) : array(),
            );
        }

        return $sanitized;
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
        if ( null === $tokens ) {
            $merged = $this->get_tokens();
        } else {
            $sanitized = $this->sanitize_tokens( $tokens );
            $merged    = $this->merge_tokens( $this->get_default_tokens(), $sanitized );
        }

        $palette     = isset( $merged['colors'] ) ? array_values( $merged['colors'] ) : array();
        $typography  = isset( $merged['typography'] ) ? $merged['typography'] : array();
        $body_type   = isset( $typography['body'] ) ? $typography['body'] : array();
        $heading_type = isset( $typography['heading'] ) ? $typography['heading'] : array();
        $components  = isset( $merged['components'] ) ? $merged['components'] : array();
        $button_comp = isset( $components['buttons'] ) ? $components['buttons'] : array();
        $list_comp   = isset( $components['lists'] ) ? $components['lists'] : array();

        $button_padding = trim( sprintf( '%s %s', isset( $button_comp['paddingY'] ) ? $button_comp['paddingY'] : '', isset( $button_comp['paddingX'] ) ? $button_comp['paddingX'] : '' ) );
        $button_block   = array_filter( array(
            'typography' => array_filter( array(
                'fontWeight'    => isset( $button_comp['fontWeight'] ) ? $button_comp['fontWeight'] : '',
                'textTransform' => isset( $button_comp['textTransform'] ) ? $button_comp['textTransform'] : '',
            ) ),
            'spacing'    => $button_padding ? array( 'padding' => $button_padding ) : array(),
            'border'     => array_filter( array(
                'radius' => isset( $button_comp['borderRadius'] ) ? $button_comp['borderRadius'] : '',
            ) ),
        ) );

        $list_block = array_filter( array(
            'spacing' => array_filter( array(
                'blockGap' => isset( $list_comp['gap'] ) ? $list_comp['gap'] : '',
            ) ),
            'color'   => array_filter( array(
                'text' => isset( $list_comp['markerColor'] ) ? $list_comp['markerColor'] : '',
            ) ),
        ) );

        $block_settings = array_filter( array(
            'core/button' => $button_block,
            'core/list'   => $list_block,
        ) );

        $heading_style = array_filter( array(
            'typography' => array_filter( array(
                'fontFamily'    => isset( $heading_type['fontFamily'] ) ? $heading_type['fontFamily'] : '',
                'fontWeight'    => isset( $heading_type['fontWeight'] ) ? $heading_type['fontWeight'] : '',
                'lineHeight'    => isset( $heading_type['lineHeight'] ) ? $heading_type['lineHeight'] : '',
                'textTransform' => isset( $heading_type['textTransform'] ) ? $heading_type['textTransform'] : '',
            ) ),
        ) );

        $button_style = array_filter( array(
            'border'  => array_filter( array(
                'radius' => isset( $button_comp['borderRadius'] ) ? $button_comp['borderRadius'] : '',
            ) ),
            'spacing' => $button_padding ? array( 'padding' => $button_padding ) : array(),
        ) );

        $element_styles = array_filter( array(
            'heading' => $heading_style,
            'button'  => $button_style,
        ) );

        $theme = array(
            'version'  => 2,
            'settings' => array(
                'color'      => array(
                    'palette' => $palette,
                ),
                'typography' => array(
                    'fontFamilies' => isset( $typography['fontFamilies'] ) ? array_values( $typography['fontFamilies'] ) : array(),
                    'fontSizes'    => isset( $typography['fontSizes'] ) ? array_values( $typography['fontSizes'] ) : array(),
                ),
                'custom'     => array(
                    'astra' => array(
                        'components' => $components,
                        'modes'      => isset( $merged['modes'] ) ? $merged['modes'] : array(),
                        'variables'  => $this->compile_css_variables( $merged ),
                    ),
                ),
            ),
            'styles'   => array(
                'typography' => array_filter( array(
                    'fontFamily'    => isset( $body_type['fontFamily'] ) ? $body_type['fontFamily'] : '',
                    'fontWeight'    => isset( $body_type['fontWeight'] ) ? $body_type['fontWeight'] : '',
                    'lineHeight'    => isset( $body_type['lineHeight'] ) ? $body_type['lineHeight'] : '',
                    'textTransform' => isset( $body_type['textTransform'] ) ? $body_type['textTransform'] : '',
                ) ),
            ),
        );

        if ( ! empty( $block_settings ) ) {
            $theme['settings']['blocks'] = $block_settings;
        }

        if ( ! empty( $element_styles ) ) {
            $theme['styles']['elements'] = $element_styles;
        }

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
     * Create a flat map of CSS variables from the provided tokens.
     *
     * @param array $tokens Token collection.
     *
     * @return array
     */
    protected function compile_css_variables( $tokens ) {
        $variables = array();

        if ( isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ) {
            foreach ( $tokens['colors'] as $color ) {
                if ( empty( $color['slug'] ) || empty( $color['color'] ) ) {
                    continue;
                }
                $slug = sanitize_title( $color['slug'] );
                $this->maybe_set_variable( $variables, '--astra-color-' . $slug, $color['color'] );
            }
        }

        $body     = isset( $tokens['typography']['body'] ) ? $tokens['typography']['body'] : array();
        $heading  = isset( $tokens['typography']['heading'] ) ? $tokens['typography']['heading'] : array();
        $buttons  = isset( $tokens['components']['buttons'] ) ? $tokens['components']['buttons'] : array();
        $lists    = isset( $tokens['components']['lists'] ) ? $tokens['components']['lists'] : array();
        $forms    = isset( $tokens['components']['forms'] ) ? $tokens['components']['forms'] : array();

        $this->maybe_set_variable( $variables, '--astra-typography-body-font-family', isset( $body['fontFamily'] ) ? $body['fontFamily'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-body-font-weight', isset( $body['fontWeight'] ) ? $body['fontWeight'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-body-line-height', isset( $body['lineHeight'] ) ? $body['lineHeight'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-body-text-transform', isset( $body['textTransform'] ) ? $body['textTransform'] : '' );

        $this->maybe_set_variable( $variables, '--astra-typography-heading-font-family', isset( $heading['fontFamily'] ) ? $heading['fontFamily'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-heading-font-weight', isset( $heading['fontWeight'] ) ? $heading['fontWeight'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-heading-line-height', isset( $heading['lineHeight'] ) ? $heading['lineHeight'] : '' );
        $this->maybe_set_variable( $variables, '--astra-typography-heading-text-transform', isset( $heading['textTransform'] ) ? $heading['textTransform'] : '' );

        $this->maybe_set_variable( $variables, '--astra-button-border-radius', isset( $buttons['borderRadius'] ) ? $buttons['borderRadius'] : '' );
        $this->maybe_set_variable( $variables, '--astra-button-text-transform', isset( $buttons['textTransform'] ) ? $buttons['textTransform'] : '' );
        $this->maybe_set_variable( $variables, '--astra-button-font-weight', isset( $buttons['fontWeight'] ) ? $buttons['fontWeight'] : '' );
        $this->maybe_set_variable( $variables, '--astra-button-padding-y', isset( $buttons['paddingY'] ) ? $buttons['paddingY'] : '' );
        $this->maybe_set_variable( $variables, '--astra-button-padding-x', isset( $buttons['paddingX'] ) ? $buttons['paddingX'] : '' );

        $this->maybe_set_variable( $variables, '--astra-list-gap', isset( $lists['gap'] ) ? $lists['gap'] : '' );
        $this->maybe_set_variable( $variables, '--astra-list-marker-color', isset( $lists['markerColor'] ) ? $lists['markerColor'] : '' );
        $this->maybe_set_variable( $variables, '--astra-list-marker-style', isset( $lists['markerStyle'] ) ? $lists['markerStyle'] : '' );

        $this->maybe_set_variable( $variables, '--astra-form-field-padding-y', isset( $forms['fieldPaddingY'] ) ? $forms['fieldPaddingY'] : '' );
        $this->maybe_set_variable( $variables, '--astra-form-field-padding-x', isset( $forms['fieldPaddingX'] ) ? $forms['fieldPaddingX'] : '' );
        $this->maybe_set_variable( $variables, '--astra-form-border-radius', isset( $forms['borderRadius'] ) ? $forms['borderRadius'] : '' );
        $this->maybe_set_variable( $variables, '--astra-form-border-color', isset( $forms['borderColor'] ) ? $forms['borderColor'] : '' );
        $this->maybe_set_variable( $variables, '--astra-form-focus-color', isset( $forms['focusColor'] ) ? $forms['focusColor'] : '' );
        $this->maybe_set_variable( $variables, '--astra-form-background', isset( $forms['background'] ) ? $forms['background'] : '' );

        return $variables;
    }

    /**
     * Build CSS variable overrides for dark mode.
     *
     * @param array $tokens Token collection.
     *
     * @return array
     */
    protected function compile_dark_mode_variables( $tokens ) {
        if ( empty( $tokens['modes']['dark'] ) || empty( $tokens['modes']['dark']['enabled'] ) ) {
            return array();
        }

        $dark      = $tokens['modes']['dark'];
        $variables = array();

        $map = array(
            'background' => '--astra-color-background-dark',
            'surface'    => '--astra-surface-color-dark',
            'text'       => '--astra-color-text-dark',
            'muted'      => '--astra-color-muted-dark',
            'accent'     => '--astra-color-accent-dark',
        );

        foreach ( $map as $key => $variable ) {
            if ( isset( $dark[ $key ] ) ) {
                $this->maybe_set_variable( $variables, $variable, $dark[ $key ] );
            }
        }

        if ( isset( $dark['buttons']['background'] ) ) {
            $this->maybe_set_variable( $variables, '--astra-button-background-dark', $dark['buttons']['background'] );
        }

        if ( isset( $dark['buttons']['color'] ) ) {
            $this->maybe_set_variable( $variables, '--astra-button-color-dark', $dark['buttons']['color'] );
        }

        return $variables;
    }

    /**
     * Append a CSS variable if it has a value.
     *
     * @param array  $variables Variable map passed by reference.
     * @param string $name      Variable name.
     * @param mixed  $value     Value to set.
     */
    protected function maybe_set_variable( array &$variables, $name, $value ) {
        if ( null === $value ) {
            return;
        }

        $value = is_string( $value ) ? trim( $value ) : trim( (string) $value );

        if ( '' === $value ) {
            return;
        }

        $variables[ $name ] = $value;
    }

    /**
     * Convert a CSS variable map into declarations.
     *
     * @param array $variables Variable map.
     *
     * @return string
     */
    protected function format_css_variables( $variables ) {
        if ( empty( $variables ) || ! is_array( $variables ) ) {
            return '';
        }

        $chunks = array();

        foreach ( $variables as $name => $value ) {
            $chunks[] = $name . ':' . $value . ';';
        }

        return implode( '', $chunks );
    }

    /**
     * Render global CSS variable declarations.
     *
     * @param array|null $tokens Optional token set.
     *
     * @return string
     */
    public function render_global_css_styles( $tokens = null ) {
        if ( null === $tokens ) {
            $merged = $this->get_tokens();
        } else {
            $sanitized = $this->sanitize_tokens( $tokens );
            $merged    = $this->merge_tokens( $this->get_default_tokens(), $sanitized );
        }

        $variables = $this->compile_css_variables( $merged );

        if ( empty( $variables ) ) {
            return '';
        }

        $css  = ':root{' . $this->format_css_variables( $variables ) . '}';
        $dark = $this->compile_dark_mode_variables( $merged );

        if ( ! empty( $dark ) ) {
            $css .= 'body.is-dark-mode, body.wp-site-blocks.is-dark-theme, [data-theme="dark"], .is-dark-theme, .astra-builder-template.is-dark{' . $this->format_css_variables( $dark ) . '}';
        }

        return $css;
    }

    /**
     * Render template-specific CSS variable overrides.
     *
     * @param array $overrides  Override set.
     * @param int   $template_id Template identifier.
     *
     * @return string
     */
    public function render_template_override_styles( $overrides, $template_id ) {
        $template_id = absint( $template_id );

        if ( ! $template_id || empty( $overrides ) || ! is_array( $overrides ) ) {
            return '';
        }

        $base_tokens = $this->get_tokens();
        $merged      = $this->merge_tokens( $base_tokens, $this->deep_sanitize( $overrides ) );
        $diff        = $this->diff_css_variables( $base_tokens, $merged );

        if ( empty( $diff ) ) {
            return '';
        }

        return '.astra-builder-template-' . $template_id . '{' . $this->format_css_variables( $diff ) . '}';
    }

    /**
     * Compute CSS variable differences between two token collections.
     *
     * @param array $base     Base tokens.
     * @param array $override Override tokens.
     *
     * @return array
     */
    protected function diff_css_variables( $base, $override ) {
        $base_vars     = $this->compile_css_variables( $base );
        $override_vars = $this->compile_css_variables( $override );
        $diff          = array();

        foreach ( $override_vars as $name => $value ) {
            if ( ! isset( $base_vars[ $name ] ) || $base_vars[ $name ] !== $value ) {
                $diff[ $name ] = $value;
            }
        }

        return $diff;
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
