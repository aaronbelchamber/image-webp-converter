<?php

namespace IWC\Tests\Unit\BulkConverter;

use Brain\Monkey\Functions;
use IWC\Tests\Unit\WpdbTestCase;

/**
 * Proves only one bulk conversion run can hold the lock at a time.
 *
 * Batches arrive as separate HTTP requests, so two tabs can interleave over
 * the same attachments. convert_attachment()'s already-converted guard is a
 * read followed by a write, so both callers can pass it before either writes,
 * and both then convert the same image.
 *
 * The lock leans on wp_options' UNIQUE index on option_name to decide the
 * race — hence the fixture carrying that constraint for real.
 *
 * @covers IWC_Lock
 */
final class LockTest extends WpdbTestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_cache_delete')->justReturn(true);
    }

    public function test_the_first_caller_takes_the_lock(): void {
        $this->assertTrue(\IWC_Lock::acquire());
    }

    public function test_a_second_caller_is_refused_while_it_is_held(): void {
        \IWC_Lock::acquire();

        $this->assertFalse(\IWC_Lock::acquire(), 'a concurrent run must not proceed');
    }

    public function test_releasing_lets_the_next_caller_in(): void {
        \IWC_Lock::acquire();
        \IWC_Lock::release();

        $this->assertTrue(\IWC_Lock::acquire());
    }

    public function test_a_lock_left_behind_by_a_dead_request_is_reclaimed(): void {
        // A request that fataled mid-batch never released; without reclaim the
        // bulk converter would stay unusable until someone edited the database.
        $staleTimestamp = time() - (\IWC_Lock::TIMEOUT + 60);
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (?, ?, 'no')",
            [\IWC_Lock::OPTION, (string) $staleTimestamp]
        );

        $this->assertTrue(\IWC_Lock::acquire());
    }

    public function test_a_recently_taken_lock_is_not_reclaimed(): void {
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (?, ?, 'no')",
            [\IWC_Lock::OPTION, (string) (time() - 5)]
        );

        $this->assertFalse(\IWC_Lock::acquire());
    }

    public function test_reclaiming_resets_the_held_since_timestamp(): void {
        $this->wpdb->seed_prepared(
            "INSERT INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (?, ?, 'no')",
            [\IWC_Lock::OPTION, (string) (time() - (\IWC_Lock::TIMEOUT + 60))]
        );
        \IWC_Lock::acquire();

        // The reclaiming holder now owns it, so the next caller must be refused
        // rather than seeing a still-stale timestamp and reclaiming again.
        $this->assertFalse(\IWC_Lock::acquire());
    }
}
