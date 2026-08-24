<?php

namespace IWC\Tests\Unit\BulkConverter;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FakeWpCli;
use IWC\Tests\fixtures\WpCliHalt;

/**
 * Proves the WP-CLI commands do what they claim.
 *
 * The browser bulk converter is bounded by what an admin-ajax request can
 * survive, so on a large library this is the path that actually completes —
 * which makes "--dry-run really changes nothing" and "the lock is released
 * even when a conversion throws" load-bearing rather than cosmetic.
 *
 * @covers IWC_CLI
 */
final class CliTest extends BulkConverterTestCase {
    protected function setUp(): void {
        parent::setUp();
        FakeWpCli::reset();
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('size_format')->alias(fn($bytes) => $bytes . ' B');
        Functions\when('get_option')->justReturn(82);
    }

    public function test_dry_run_reports_work_without_converting_anything(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_CLI::convert([], ['dry-run' => true]);

        $this->assertStringContainsString('would convert #' . $id, FakeWpCli::allOutput());
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg', 'the original must be untouched');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/2024/05/photo.webp', 'nothing may be written');
    }

    public function test_dry_run_does_not_take_the_lock(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_CLI::convert([], ['dry-run' => true]);

        $this->assertTrue(\IWC_Lock::acquire(), 'a dry run must leave the lock free');
    }

    public function test_convert_processes_eligible_images(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_CLI::convert([], []);

        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.webp');
        $this->assertNotSame([], FakeWpCli::$success);
    }

    public function test_limit_caps_the_number_processed(): void {
        $this->seedRealAttachment('2024/05/a.jpg');
        $this->seedRealAttachment('2024/05/b.jpg');
        $this->seedRealAttachment('2024/05/c.jpg');

        \IWC_CLI::convert([], ['dry-run' => true, 'limit' => 2]);

        $lines = array_filter(FakeWpCli::$log, fn($l) => strpos($l, 'would convert') === 0);
        $this->assertCount(2, $lines);
    }

    public function test_an_unknown_bucket_is_refused(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');

        $this->expectException(WpCliHalt::class);
        try {
            \IWC_CLI::convert([], ['bucket' => 'serialized_only']);
        } finally {
            $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg', 'nothing may be converted on a refused run');
        }
    }

    public function test_the_lock_is_released_after_a_run(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_CLI::convert([], []);

        $this->assertTrue(\IWC_Lock::acquire(), 'the lock must not be left held after the command finishes');
    }

    public function test_a_run_is_refused_while_the_lock_is_held(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');
        \IWC_Lock::acquire();

        $this->expectException(WpCliHalt::class);
        try {
            \IWC_CLI::convert([], []);
        } finally {
            $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
        }
    }

    public function test_an_empty_library_reports_nothing_to_do(): void {
        \IWC_CLI::convert([], []);

        $this->assertStringContainsString('Nothing to convert', FakeWpCli::allOutput());
    }

    public function test_scan_reports_bucket_counts_without_converting(): void {
        $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_CLI::scan([], []);

        $this->assertStringContainsString('Unreferenced', FakeWpCli::allOutput());
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_custom_table_plugins_are_warned_about_on_the_cli_too(): void {
        // A CLI-only operator never sees the admin screen's notices, so the
        // same caveats have to surface here. Declared through the filter
        // rather than by defining a stand-in class: class_exists() detection
        // cannot be undone once a class is declared, so it would leak into
        // every test that runs afterwards.
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            if ($hook === 'iwc_custom_table_plugins') {
                return ['TranslatePress'];
            }
            return $hook === 'iwc_custom_table_risk' ? false : $value;
        });

        \IWC_CLI::scan([], []);

        $this->assertStringContainsString('TranslatePress', implode("
", FakeWpCli::$warnings));
    }

}
