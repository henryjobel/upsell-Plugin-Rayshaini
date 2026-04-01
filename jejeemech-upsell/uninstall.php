<?php
/**
 * Uninstall script for JejeeMech Upsell & Downsell.
 * Runs when the plugin is deleted from WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Load installer for table cleanup.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jm-upsell-installer.php';
JM_Upsell_Installer::uninstall();
