<?php
/**
 * Uninstall cleanup for Image WebP Converter.
 *
 * Removes this plugin's own bookkeeping — settings, the conversion log table,
 * and the per-attachment meta it wrote.
 *
 * It deliberately does NOT delete wp-content/uploads/iwc-trash/. That folder
 * holds the user's original photographs, moved there rather than deleted
 * precisely so they'd be recoverable. Deleting them because a plugin was
 * removed would be the single most destructive thing this plugin could do,
 * and it's trivially undone by hand if the user does want the space back.
 *
 * @package Image_WebP_Converter
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/** Remove every trace of the plugin from the current site. */
function iwc_uninstall_site(): void {
    global $wpdb;

    delete_option('iwc_quality');
    delete_option('iwc_enabled');
    delete_option('iwc_db_version');

    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_iwc_converted']);
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_iwc_backup_path']);

    $table = $wpdb->prefix . 'iwc_conversion_log';
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        iwc_uninstall_site();
        restore_current_blog();
    }
} else {
    iwc_uninstall_site();
}
