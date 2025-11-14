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
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
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
            'astra-builder-editor',
            $asset_base . 'assets/editor.js',
            $dependencies,
            filemtime( $asset_path . 'assets/editor.js' ),
            true
        );

        wp_register_style(
            'astra-builder-editor',
            $asset_base . 'assets/editor.css',
            array( 'wp-edit-blocks' ),
            filemtime( $asset_path . 'assets/editor.css' )
        );

        wp_enqueue_script( 'astra-builder-editor' );
        wp_enqueue_style( 'astra-builder-editor' );
    }
}
