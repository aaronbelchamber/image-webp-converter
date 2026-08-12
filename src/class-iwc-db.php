<?php
/**
 * Database provisioner for Image WebP Converter's conversion log.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_DB {
    public static function maybe_upgrade(): void {
        if (get_option('iwc_db_version') !== IWC_VERSION) {
            self::activate();
        }
    }

    public static function activate(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $wpdb->prefix . 'iwc_conversion_log';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            original_path text NOT NULL,
            old_files_json text DEFAULT NULL,
            new_path text DEFAULT NULL,
            bucket varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            bytes_before bigint(20) DEFAULT 0,
            bytes_after bigint(20) DEFAULT 0,
            references_updated text DEFAULT NULL,
            message text DEFAULT NULL,
            started_at bigint(20) NOT NULL,
            completed_at bigint(20) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);

        update_option('iwc_db_version', IWC_VERSION);
    }
}
