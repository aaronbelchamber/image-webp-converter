<?php
/**
 * A single-holder lock for bulk conversion runs.
 *
 * The scan and each conversion batch arrive as separate HTTP requests, so two
 * browser tabs — or two administrators — can interleave batches over the same
 * attachments. The _iwc_converted guard in convert_attachment() is a read
 * followed by a write, so both callers can pass it before either has written,
 * and then both convert the same image: duplicate encodes, a stray orphaned
 * .webp, and a race over moving the originals aside.
 *
 * Implemented as a raw INSERT rather than add_option(), because add_option()
 * checks for an existing value and then writes — two callers can both see
 * "absent" and both proceed. wp_options has a UNIQUE index on option_name, so
 * a plain INSERT is decided by the database: exactly one caller gets a row.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Lock {

    const OPTION = 'iwc_bulk_lock';

    /**
     * How long a held lock stays valid. A batch is capped at 20 images and
     * each one is bounded by its own time limit, so anything holding this
     * long has died mid-request rather than being slow.
     */
    const TIMEOUT = 300;

    /**
     * Take the lock, or reclaim it if the previous holder died. Returns false
     * when someone else legitimately holds it.
     */
    public static function acquire(): bool {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION,
            (string) time()
        ));

        // The raw INSERT bypasses the options cache, which would otherwise
        // keep serving a stale "this option does not exist".
        wp_cache_delete(self::OPTION, 'options');

        if ($inserted) {
            return true;
        }

        return self::reclaim_if_stale();
    }

    /**
     * Take over a lock whose holder never released it.
     *
     * The check-then-write here is not atomic, unlike acquire(): two callers
     * arriving in the same second on an already-expired lock could both
     * reclaim it. That needs a five-minute-dead holder and simultaneous
     * retries to hit, and the cost is the duplicate-work case this class
     * exists to make rare rather than impossible — worth accepting over a
     * lock that can wedge a site's bulk converter until someone edits the
     * database.
     */
    private static function reclaim_if_stale(): bool {
        global $wpdb;

        $held_since = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));

        if ($held_since === 0 || (time() - $held_since) <= self::TIMEOUT) {
            return false;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            (string) time(),
            self::OPTION
        ));
        wp_cache_delete(self::OPTION, 'options');

        return true;
    }

    public static function release(): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));
        wp_cache_delete(self::OPTION, 'options');
    }
}
