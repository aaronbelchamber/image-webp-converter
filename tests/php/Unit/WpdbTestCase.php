<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FakeWpdb;

/**
 * Base for tests that exercise code touching `global $wpdb` — wires up a
 * real SQLite-backed FakeWpdb as $GLOBALS['wpdb'] so the actual SQL in
 * class-iwc-bulk-converter.php / class-iwc-logger.php runs for real.
 *
 * get_post_meta()/update_post_meta() are real WP core functions, not
 * $wpdb-driven directly in this plugin's own code — they're given a real
 * backing implementation here (reading/writing the same FakeWpdb postmeta
 * table) rather than being mocked per test, so a seeded postmeta row is
 * visible through both the plugin's raw-SQL reference-scanning queries AND
 * its get_post_meta() calls, exactly as it would be against a real $wpdb.
 */
abstract class WpdbTestCase extends TestCase {
    protected FakeWpdb $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('get_post_meta')->alias(function (int $postId, string $key, bool $single = false) {
            $rows = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                $postId,
                $key
            ));
            if ($single) {
                return $rows[0] ?? '';
            }
            return $rows;
        });

        Functions\when('update_post_meta')->alias(function (int $postId, string $key, $value) {
            $this->wpdb->seed_prepared("DELETE FROM {$this->wpdb->postmeta} WHERE post_id = ? AND meta_key = ?", [$postId, $key]);
            $this->seedPostmeta($postId, $key, (string) $value);
            return true;
        });
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** Seed a post row, returns its ID. */
    protected function seedPost(array $overrides = []): int {
        $defaults = [
            'post_content' => '',
            'post_title' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_mime_type' => '',
        ];
        $row = array_merge($defaults, $overrides);
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->posts} (post_content, post_title, post_status, post_type, post_mime_type) VALUES (?, ?, ?, ?, ?)",
            array_values($row)
        );
        return (int) $this->wpdb->get_var("SELECT last_insert_rowid()");
    }

    /** Seed a postmeta row. */
    protected function seedPostmeta(int $postId, string $key, ?string $value): void {
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (?, ?, ?)",
            [$postId, $key, $value]
        );
    }

    /** Seed an options row. */
    protected function seedOption(string $name, ?string $value): void {
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->options} (option_name, option_value) VALUES (?, ?)",
            [$name, $value]
        );
    }
}
