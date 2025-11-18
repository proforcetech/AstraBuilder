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
        require_once __DIR__ . '/rest/class-astra-builder-rest-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-templates-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-tokens-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-snapshots-controller.php';
        require_once __DIR__ . '/rest/class-astra-builder-settings-controller.php';

        $this->services['templates'] = new Astra_Builder_Template_Service();
        $this->services['templates']->register();

        $this->services['tokens'] = new Astra_Builder_Token_Service();
        $this->services['tokens']->register();

        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Register REST controllers.
     */
    public function register_rest_routes() {
        $controllers = array(
            new Astra_Builder_REST_Templates_Controller( $this->services['templates'] ),
            new Astra_Builder_REST_Tokens_Controller( $this->services['tokens'] ),
            new Astra_Builder_REST_Snapshots_Controller( $this->services['tokens'] ),
            new Astra_Builder_REST_Settings_Controller( $this->services['tokens'] ),
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
            filemtime( $asset_path . 'assets/editor/canvas-renderer.js' ),
            true
        );

        wp_register_script(
            'astra-builder-responsive-context',
            $asset_base . 'assets/editor/responsive-context.js',
            array( 'wp-element', 'wp-data', 'wp-i18n' ),
            filemtime( $asset_path . 'assets/editor/responsive-context.js' ),
            true
        );

        wp_register_script(
            'astra-builder-editor',
            $asset_base . 'assets/editor.js',
            array_merge(
                $dependencies,
                array( 'astra-builder-canvas-renderer', 'astra-builder-responsive-context' )
            ),
            filemtime( $asset_path . 'assets/editor.js' ),
            true
        );

        wp_register_style(
            'astra-builder-editor',
            $asset_base . 'assets/editor.css',
            array( 'wp-edit-blocks' ),
            filemtime( $asset_path . 'assets/editor.css' )
        );

        wp_enqueue_script( 'astra-builder-canvas-renderer' );
        wp_enqueue_script( 'astra-builder-responsive-context' );

        $editor_data = array(
            'conditions'    => $this->services['templates']->get_condition_options(),
            'metaKeys'      => $this->services['templates']->get_meta_keys(),
            'defaults'      => array(
                'conditions' => $this->services['templates']->get_default_conditions(),
            ),
            'restNamespace' => 'astra-builder/v1',
            'preview'       => array(
                'queryVar' => Astra_Builder_Template_Service::PREVIEW_QUERY_VAR,
            ),
        );

        wp_localize_script( 'astra-builder-editor', 'AstraBuilderData', $editor_data );
        wp_enqueue_script( 'astra-builder-editor' );
        wp_enqueue_style( 'astra-builder-editor' );
    }
}
