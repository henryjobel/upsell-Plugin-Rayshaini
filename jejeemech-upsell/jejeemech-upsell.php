<?php
/**
 * Plugin Name: Upsell Rayshani
 * Plugin URI: https://rayshani.com
 * Description: Post-checkout upsell & downsell funnel for WooCommerce COD orders. Increase average order value with one-click upsells.
 * Version: 1.1.4
 * Author: jobelhenry
 * Author URI: https://rayshani.com
 * Text Domain: jejeemech-upsell
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JM_UPSELL_VERSION', '1.1.6' );
define( 'JM_UPSELL_FILE', __FILE__ );
define( 'JM_UPSELL_PATH', plugin_dir_path( __FILE__ ) );
define( 'JM_UPSELL_URL', plugin_dir_url( __FILE__ ) );
define( 'JM_UPSELL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 */
function jm_upsell_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'jm_upsell_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

function jm_upsell_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'JejeeMech Upsell & Downsell requires WooCommerce to be installed and active.', 'jejeemech-upsell' );
    echo '</p></div>';
}

/**
 * Plugin activation.
 */
function jm_upsell_activate() {
    require_once JM_UPSELL_PATH . 'includes/class-jm-upsell-installer.php';
    JM_Upsell_Installer::install();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'jm_upsell_activate' );

/**
 * Plugin deactivation.
 */
function jm_upsell_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'jm_upsell_deactivate' );

/**
 * Initialize the plugin.
 */
function jm_upsell_init() {
    if ( ! jm_upsell_check_woocommerce() ) {
        return;
    }

    // Load includes.
    require_once JM_UPSELL_PATH . 'includes/class-jm-upsell-installer.php';

    // Run DB upgrade when plugin version changes (e.g. new columns added).
    if ( get_option( 'jm_upsell_version' ) !== JM_UPSELL_VERSION ) {
        JM_Upsell_Installer::install();
    }
    require_once JM_UPSELL_PATH . 'includes/class-jm-upsell-funnel.php';
    require_once JM_UPSELL_PATH . 'includes/class-jm-upsell-offer-page.php';
    require_once JM_UPSELL_PATH . 'includes/class-jm-upsell-ajax.php';

    if ( is_admin() ) {
        require_once JM_UPSELL_PATH . 'admin/class-jm-upsell-admin.php';
        new JM_Upsell_Admin();
    }

    new JM_Upsell_Funnel();
    new JM_Upsell_Offer_Page();
    new JM_Upsell_Ajax();
}
add_action( 'plugins_loaded', 'jm_upsell_init' );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
