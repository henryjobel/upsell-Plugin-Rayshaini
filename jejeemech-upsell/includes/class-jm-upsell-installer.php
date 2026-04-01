<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JM_Upsell_Installer {

    /**
     * Run the installer.
     */
    public static function install() {
        self::create_tables();
        update_option( 'jm_upsell_version', JM_UPSELL_VERSION );
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $funnels_table   = $wpdb->prefix . 'jm_upsell_funnels';
        $steps_table     = $wpdb->prefix . 'jm_upsell_steps';

        // Use DATETIME with a static default so that DEFAULT CURRENT_TIMESTAMP and
        // ON UPDATE CURRENT_TIMESTAMP quirks on MySQL 5.5 / Amazon RDS / dbDelta are avoided.
        // created_at for funnels is set explicitly in PHP on INSERT.
        $sql = "CREATE TABLE {$funnels_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  trigger_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY trigger_product_id (trigger_product_id),
  KEY status (status)
) {$charset_collate};
CREATE TABLE {$steps_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  funnel_id bigint(20) unsigned NOT NULL DEFAULT 0,
  step_type varchar(20) NOT NULL DEFAULT 'upsell',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  discount decimal(5,2) NOT NULL DEFAULT 0.00,
  step_order int(11) NOT NULL DEFAULT 1,
  parent_step_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY funnel_id (funnel_id),
  KEY step_order (step_order)
) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop tables on uninstall.
     */
    public static function uninstall() {
        global $wpdb;

        $funnels_table = $wpdb->prefix . 'jm_upsell_funnels';
        $steps_table   = $wpdb->prefix . 'jm_upsell_steps';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$steps_table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$funnels_table}" );

        delete_option( 'jm_upsell_version' );
    }
}
