<?php

namespace IWC\Tests\Unit\Logger;

use IWC\Tests\Unit\WpdbTestCase;

/**
 * @covers IWC_Logger
 */
final class IwcLoggerTest extends WpdbTestCase {
    public function test_log_start_writes_bytes_before_from_real_file_size(): void {
        $file = $this->tmpPath('original.bin');
        file_put_contents($file, str_repeat('x', 12345));

        $logId = \IWC_Logger::log_start(7, $file, 'unreferenced');

        $this->assertGreaterThan(0, $logId);
        $row = $this->wpdb->get_results("SELECT * FROM wp_iwc_conversion_log WHERE id = $logId")[0];
        $this->assertSame(7, (int) $row->attachment_id);
        $this->assertSame($file, $row->original_path);
        $this->assertSame('unreferenced', $row->bucket);
        $this->assertSame('converting', $row->status);
        $this->assertSame(12345, (int) $row->bytes_before);
    }

    public function test_log_start_defaults_bytes_before_to_zero_when_file_missing(): void {
        $logId = \IWC_Logger::log_start(9, $this->tmpPath('missing.bin'), 'unreferenced');

        $row = $this->wpdb->get_results("SELECT * FROM wp_iwc_conversion_log WHERE id = $logId")[0];
        $this->assertSame(0, (int) $row->bytes_before);
    }

    public function test_log_result_persists_mixed_int_and_string_fields(): void {
        $logId = \IWC_Logger::log_start(1, $this->tmpPath('x.bin'), 'unreferenced');

        \IWC_Logger::log_result($logId, [
            'status' => 'trashed',
            'bytes_after' => 999,
            'new_path' => '/uploads/x.webp',
        ]);

        $row = $this->wpdb->get_results("SELECT * FROM wp_iwc_conversion_log WHERE id = $logId")[0];
        $this->assertSame('trashed', $row->status);
        $this->assertSame(999, (int) $row->bytes_after);
        $this->assertSame('/uploads/x.webp', $row->new_path);
        $this->assertNotNull($row->completed_at);
    }

    public function test_get_pending_cleanup_returns_only_pending_rows_newest_first(): void {
        $this->seedLogRow(['status' => 'trashed', 'completed_at' => 100]);
        $p1 = $this->seedLogRow(['status' => 'pending_cleanup', 'completed_at' => 300]);
        $p2 = $this->seedLogRow(['status' => 'pending_cleanup', 'completed_at' => 200]);

        $rows = \IWC_Logger::get_pending_cleanup();

        $this->assertCount(2, $rows);
        $this->assertSame($p1, (int) $rows[0]->id);
        $this->assertSame($p2, (int) $rows[1]->id);
    }

    public function test_get_by_ids_with_empty_array_returns_empty_without_querying(): void {
        $this->assertSame([], \IWC_Logger::get_by_ids([]));
    }

    public function test_get_by_ids_round_trips_real_rows(): void {
        $a = $this->seedLogRow(['status' => 'trashed']);
        $b = $this->seedLogRow(['status' => 'pending_cleanup']);
        $this->seedLogRow(['status' => 'trashed']); // not requested

        $rows = \IWC_Logger::get_by_ids([$a, $b]);

        $ids = array_map(fn($r) => (int) $r->id, $rows);
        sort($ids);
        $this->assertSame([$a, $b], $ids);
    }

    public function test_get_summary_counts_aggregates_bytes_saved_by_status(): void {
        $this->seedLogRow(['status' => 'trashed', 'bytes_before' => 1000, 'bytes_after' => 400]);
        $this->seedLogRow(['status' => 'trashed', 'bytes_before' => 2000, 'bytes_after' => 800]);
        $this->seedLogRow(['status' => 'pending_cleanup', 'bytes_before' => 500, 'bytes_after' => 500]);

        $summary = \IWC_Logger::get_summary_counts();

        $this->assertSame(2, $summary['trashed']['total']);
        $this->assertSame(1800, $summary['trashed']['bytes_saved']); // (1000-400)+(2000-800)
        $this->assertSame(1, $summary['pending_cleanup']['total']);
        $this->assertSame(0, $summary['pending_cleanup']['bytes_saved']);
    }

    private function seedLogRow(array $overrides = []): int {
        $defaults = [
            'attachment_id' => 1,
            'original_path' => '/uploads/x.jpg',
            'bucket' => 'unreferenced',
            'status' => 'converting',
            'bytes_before' => 0,
            'bytes_after' => 0,
            'started_at' => time(),
            'completed_at' => time(),
        ];
        $row = array_merge($defaults, $overrides);
        $columns = array_keys($row);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $this->wpdb->seed_prepared(
            "INSERT INTO wp_iwc_conversion_log (" . implode(',', $columns) . ") VALUES ($placeholders)",
            array_values($row)
        );
        return (int) $this->wpdb->get_var("SELECT last_insert_rowid()");
    }
}
