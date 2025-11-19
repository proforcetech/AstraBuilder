<?php
/**
 * Plugin Name: Astra Builder
 * Description: A drag-and-drop visual builder that enhances the Gutenberg editor with Wix- and Squarespace-style layout controls.
 * Version: 0.1.0
 * Author: Astra Builder Team
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: astra-builder
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ASTRA_BUILDER_VERSION' ) ) {
    define( 'ASTRA_BUILDER_VERSION', '0.1.0' );
}

if ( ! defined( 'ASTRA_BUILDER_FILE' ) ) {
    define( 'ASTRA_BUILDER_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/class-astra-builder.php';

Astra_Builder::instance()->init();

register_deactivation_hook( __FILE__, array( 'Astra_Builder', 'deactivate_plugin' ) );
