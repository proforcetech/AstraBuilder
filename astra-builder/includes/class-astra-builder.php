<?php
/**
 * Core bootstrap for the Astra Builder plugin.
 *
 * @package AstraBuilder
 */

class Astra_Builder {
    /**
     * Singleton instance.
     *
     * @var Astra_Builder|null
     */
    protected static $instance = null;

    /**
     * Registered service instances.
     *
     * @var array
     */
    protected $services = array();

    /**
     * Retrieve the singleton instance.
     *
     * @return Astra_Builder
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Bootstraps plugin functionality.
     */
    public function init() {
        require_once __DIR__ . '/services/class-astra-builder-template-service.php';
        require_once __DIR__ . '/services/class-astra-builder-token-service.php';
        require_once __DIR__ . '/services/class-astra-builder-form-service.php';
        require_once __DIR__ . '/services/class-astra-builder-data-binding-service.php';
        require_once __DIR__ . '/services/class-astra-builder-media-service.php';
        require_once __DIR__ . '/services/class-astra-builder-locale-service.php';
        require_once __DIR__ . '/services/class-astra-builder-insights-service.php';
        require_once __DIR__ . '/services/class-astra-builder-backup-service.php';
        require_once __DIR__ . '/rest/class-astra-builder-rest-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-templates-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-tokens-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-snapshots-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-settings-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-form-submissions-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-diff-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-collaboration-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-backup-controller.php';

        $this->services['locale'] = new Astra_Builder_Locale_Service();
        $this->services['locale']->register();

        $this->services['tokens'] = new Astra_Builder_Token_Service();
        $this->services['tokens']->register();

        $this->services['templates'] = new Astra_Builder_Template_Service( $this->services['tokens'] );
        $this->services['templates']->register();

        $this->services['forms'] = new Astra_Builder_Form_Service();
        $this->services['forms']->register();

        $this->services['binding'] = new Astra_Builder_Data_Binding_Service();
        $this->services['binding']->register();

        $this->services['media'] = new Astra_Builder_Media_Service();
        $this->services['media']->register();

        $this->services['insights'] = new Astra_Builder_Insights_Service();
        $this->services['insights']->register();

        $this->services['backup'] = new Astra_Builder_Backup_Service( $this->services['templates'], $this->services['tokens'] );
        $this->services['backup']->register();

        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_head', array( $this, 'output_global_token_styles' ), 20 );
    }

    /**
     * Register REST controllers.
     */
    public function register_rest_routes() {
        $controllers = array(
            new Astra_Builder_REST_Templates_Controller( $this->services['templates'] ),
            new Astra_Builder_REST_Tokens_Controller( $this->services['tokens'] ),
            new Astra_Builder_REST_Snapshots_Controller( $this->services['tokens'] ),
            new Astra_Builder_REST_Settings_Controller( $this->services['tokens'], $this->services['insights'] ),
            new Astra_Builder_REST_Form_Submissions_Controller( $this->services['forms'] ),
            new Astra_Builder_REST_Diff_Controller( $this->services['templates'] ),
            new Astra_Builder_REST_Collaboration_Controller( $this->services['templates'] ),
            new Astra_Builder_REST_Backup_Controller( $this->services['backup'] ),
        );

        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }
    }

    /**
     * Loads the editor scripts and styles that power the drag-and-drop interface.
     */
    public function enqueue_block_editor_assets() {
        $plugin_file = dirname( __DIR__ ) . '/astra-builder.php';
        $asset_base  = plugin_dir_url( $plugin_file );
        $asset_path  = plugin_dir_path( $plugin_file );
        $version_fn  = function( $relative ) use ( $asset_path ) {
            return $this->get_versioned_asset_time( $asset_path . $relative );
        };

        $dependencies = array(
            'wp-blocks',
            'wp-block-editor',
            'wp-components',
            'wp-compose',
            'wp-data',
            'wp-element',
            'wp-edit-post',
            'wp-i18n',
            'wp-keycodes',
            'wp-plugins',
        );

        wp_register_script(
            'astra-builder-canvas-renderer',
            $asset_base . 'assets/editor/canvas-renderer.js',
            array( 'wp-element', 'wp-data' ),
            $version_fn( 'assets/editor/canvas-renderer.js' ),
            true
        );

        wp_register_script(
            'astra-builder-responsive-context',
            $asset_base . 'assets/editor/responsive-context.js',
            array( 'wp-element', 'wp-data', 'wp-i18n' ),
            $version_fn( 'assets/editor/responsive-context.js' ),
            true
        );

        wp_register_script(
            'astra-builder-editor',
            $asset_base . 'assets/editor.js',
            array_merge(
                $dependencies,
                array( 'astra-builder-canvas-renderer', 'astra-builder-responsive-context' )
            ),
            $version_fn( 'assets/editor.js' ),
            true
        );

        wp_register_style(
            'astra-builder-editor',
            $asset_base . 'assets/editor.css',
            array( 'wp-edit-blocks' ),
            $version_fn( 'assets/editor.css' )
        );
        wp_style_add_data( 'astra-builder-editor', 'rtl', 'replace' );

        wp_enqueue_script( 'astra-builder-canvas-renderer' );
        wp_enqueue_script( 'astra-builder-responsive-context' );

        $current_user = wp_get_current_user();

        $editor_data = array(
            'conditions'    => $this->services['templates']->get_condition_options(),
            'metaKeys'      => $this->services['templates']->get_meta_keys(),
            'defaults'      => array(
                'conditions' => $this->services['templates']->get_default_conditions(),
            ),
            'restNamespace' => 'astra-builder/v1',
            'preview'       => array(
                'queryVar' => Astra_Builder_Template_Service::PREVIEW_QUERY_VAR,
                'metrics'  => array(
                    'lcpTarget' => Astra_Builder_Template_Service::LCP_THRESHOLD,
                    'clsTarget' => Astra_Builder_Template_Service::CLS_THRESHOLD,
                ),
            ),
            'binding'       => $this->services['binding']->get_editor_config(),
            'forms'         => $this->services['forms']->get_editor_config(),
            'tokens'        => array(
                'initial' => $this->services['tokens']->get_tokens(),
            ),
            'user'          => array(
                'id'     => (int) $current_user->ID,
                'name'   => $current_user->display_name,
                'avatar' => get_avatar_url( $current_user->ID, array( 'size' => 64 ) ),
                'capabilities' => array(
                    'install_plugins'   => current_user_can( 'install_plugins' ),
                    'manage_options'    => current_user_can( 'manage_options' ),
                    'upload_files'      => current_user_can( 'upload_files' ),
                    'edit_theme_options'=> current_user_can( 'edit_theme_options' ),
                ),
            ),
            'collaboration' => array(
                'role'             => $this->get_current_builder_role(),
                'roles'            => $this->get_builder_roles(),
                'sections'         => $this->services['templates']->get_section_catalog(),
                'presenceInterval' => 15,
            ),
            'media'         => $this->services['media']->get_editor_config(),
            'insights'      => $this->services['insights']->get_editor_config(),
            'locale'        => $this->services['locale']->get_editor_config(),
            'backup'        => array(
                'endpoint' => rest_url( 'astra-builder/v1/backup' ),
            ),
            'marketplace'  => array(
                'manifest' => $this->get_marketplace_manifest(),
            ),
        );

        wp_localize_script( 'astra-builder-editor', 'AstraBuilderData', $editor_data );
        wp_set_script_translations( 'astra-builder-editor', 'astra-builder', dirname( dirname( __FILE__ ) ) . '/languages' );
        wp_enqueue_script( 'astra-builder-editor' );
        wp_enqueue_style( 'astra-builder-editor' );

        $token_css = $this->services['tokens']->render_global_css_styles();

        if ( $token_css ) {
            wp_add_inline_style( 'astra-builder-editor', $token_css );
        }
    }

    /**
     * Load the marketplace manifest that powers the admin catalog.
     *
     * @return array
     */
    protected function get_marketplace_manifest() {
        $manifest_path = dirname( __DIR__ ) . '/assets/marketplace-manifest.json';

        if ( ! file_exists( $manifest_path ) ) {
            return array(
                'version'  => 1,
                'packages' => array(),
            );
        }

        $contents = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( ! $contents ) {
            return array(
                'version'  => 1,
                'packages' => array(),
            );
        }

        $decoded = json_decode( $contents, true );

        if ( ! is_array( $decoded ) ) {
            return array(
                'version'  => 1,
                'packages' => array(),
            );
        }

        if ( empty( $decoded['packages'] ) || ! is_array( $decoded['packages'] ) ) {
            $decoded['packages'] = array();
        }

        if ( empty( $decoded['version'] ) ) {
            $decoded['version'] = 1;
        }

        return $decoded;
    }

    /**
     * Enqueue assets for the front-end, specifically for form handling.
     */
    public function enqueue_frontend_assets() {
        if ( is_admin() ) {
            return;
        }

        if ( ! function_exists( 'has_block' ) ) {
            return;
        }

        if ( ! is_singular() ) {
            return;
        }

        global $post;

        if ( ! $post || ! has_block( 'astra-builder/form', $post->post_content ) ) {
            return;
        }

        $plugin_file = dirname( __DIR__ ) . '/astra-builder.php';
        $asset_base  = plugin_dir_url( $plugin_file );
        $asset_path  = plugin_dir_path( $plugin_file );

        wp_register_script(
            'astra-builder-forms',
            $asset_base . 'assets/frontend.js',
            array(),
            $this->get_versioned_asset_time( $asset_path . 'assets/frontend.js' ),
            true
        );

        $forms_data = array(
            'endpoint' => rest_url( 'astra-builder/v1/form-submissions' ),
            'spam'     => $this->services['forms']->get_spam_settings(),
            'messages' => array(
                'success' => __( 'Thanks! Your submission was received.', 'astra-builder' ),
                'error'   => __( 'There was a problem submitting the form.', 'astra-builder' ),
                'sending' => __( 'Sending…', 'astra-builder' ),
            ),
        );

        wp_localize_script( 'astra-builder-forms', 'AstraBuilderFormsData', $forms_data );
        wp_enqueue_script( 'astra-builder-forms' );
    }

    /**
     * Print the global design token CSS variables on the front-end.
     */
    public function output_global_token_styles() {
        if ( empty( $this->services['tokens'] ) ) {
            return;
        }

        $css = $this->services['tokens']->render_global_css_styles();

        if ( empty( $css ) ) {
            return;
        }

        printf( '<style id="astra-builder-token-styles">%s</style>', wp_strip_all_tags( $css ) );
    }

    /**
     * Determine the collaboration role for the active user.
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

    /**
     * Get metadata for supported collaboration roles.
     *
     * @return array
     */
    protected function get_builder_roles() {
        return array(
            'designer' => array(
                'label'       => __( 'Designer', 'astra-builder' ),
                'description' => __( 'Shapes layout, content, and design but cannot publish.', 'astra-builder' ),
                'capabilities'=> array(
                    'canEditLayout'  => true,
                    'canEditContent' => true,
                    'canEditStyles'  => true,
                    'canPublish'     => false,
                    'canApprove'     => false,
                ),
            ),
            'editor'   => array(
                'label'       => __( 'Editor', 'astra-builder' ),
                'description' => __( 'Focuses on messaging and assets without layout or style control.', 'astra-builder' ),
                'capabilities'=> array(
                    'canEditLayout'  => false,
                    'canEditContent' => true,
                    'canEditStyles'  => false,
                    'canPublish'     => false,
                    'canApprove'     => false,
                ),
            ),
            'developer' => array(
                'label'       => __( 'Developer', 'astra-builder' ),
                'description' => __( 'Owns implementation details, tokens, and publication rights.', 'astra-builder' ),
                'capabilities'=> array(
                    'canEditLayout'  => true,
                    'canEditContent' => true,
                    'canEditStyles'  => true,
                    'canPublish'     => true,
                    'canApprove'     => false,
                ),
            ),
            'approver'  => array(
                'label'       => __( 'Approver', 'astra-builder' ),
                'description' => __( 'Reviews work, manages locks, and green-lights partial publishes.', 'astra-builder' ),
                'capabilities'=> array(
                    'canEditLayout'  => false,
                    'canEditContent' => false,
                    'canEditStyles'  => false,
                    'canPublish'     => true,
                    'canApprove'     => true,
                ),
            ),
        );
    }

    /**
     * Resolve a cache-busting version for an asset path.
     *
     * Falls back to the plugin version when the file is missing or unreadable,
     * preventing filemtime warnings during build steps.
     *
     * @param string $absolute_path Absolute path to the asset.
     *
     * @return int|string
     */
    protected function get_versioned_asset_time( $absolute_path ) {
        if ( $absolute_path && file_exists( $absolute_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $timestamp = @filemtime( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( $timestamp ) {
                return $timestamp;
            }
        }

        return defined( 'ASTRA_BUILDER_VERSION' ) ? ASTRA_BUILDER_VERSION : time();
    }

    /**
     * Execute shutdown routines when the plugin deactivates.
     */
    public function deactivate() {
        if ( ! empty( $this->services['templates'] ) && method_exists( $this->services['templates'], 'handle_deactivation' ) ) {
            $this->services['templates']->handle_deactivation();
        }

        if ( ! empty( $this->services['backup'] ) && method_exists( $this->services['backup'], 'maybe_store_automatic_backup' ) ) {
            $this->services['backup']->maybe_store_automatic_backup();
        }
    }

    /**
     * Triggered via register_deactivation_hook.
     */
    public static function deactivate_plugin() {
        $instance = self::instance();

        if ( empty( $instance->services ) ) {
            $instance->init();
        }

        $instance->deactivate();
    }
}
