<?php

namespace IWC\Tests\Unit\BulkConverter;

/**
 * @covers IWC_Bulk_Converter::finalize_cleanup
 */
final class FinalizeCleanupTest extends BulkConverterTestCase {
    public function test_missing_log_row_returns_false(): void {
        $this->assertFalse(\IWC_Bulk_Converter::finalize_cleanup(999999));
    }

    public function test_row_not_pending_cleanup_returns_false(): void {
        $logId = $this->seedLogRow(['status' => 'trashed']);

        $this->assertFalse(\IWC_Bulk_Converter::finalize_cleanup($logId));
    }

    public function test_valid_pending_cleanup_row_moves_files_and_updates_status(): void {
        $originalPath = $this->uploadsBasedir . '/2024/05/photo.jpg';
        @mkdir(dirname($originalPath), 0777, true);
        file_put_contents($originalPath, 'fake-jpeg-bytes');

        $logId = $this->seedLogRow([
            'status' => 'pending_cleanup',
            'attachment_id' => 42,
            'original_path' => $originalPath,
            'old_files_json' => json_encode([$originalPath]),
        ]);

        $ok = \IWC_Bulk_Converter::finalize_cleanup($logId);

        $this->assertTrue($ok);
        $this->assertFileDoesNotExist($originalPath);
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');

        $row = $this->wpdb->get_results("SELECT * FROM wp_iwc_conversion_log WHERE id = $logId")[0];
        $this->assertSame('trashed', $row->status);

        $meta = $this->wpdb->get_col("SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = 42 AND meta_key = '_iwc_backup_path'");
        $this->assertNotEmpty($meta, '_iwc_backup_path should be recorded for the finalized attachment');
    }

    public function test_invalid_old_files_json_falls_back_to_original_path(): void {
        $originalPath = $this->uploadsBasedir . '/2024/05/orphan.jpg';
        @mkdir(dirname($originalPath), 0777, true);
        file_put_contents($originalPath, 'fake-jpeg-bytes');

        $logId = $this->seedLogRow([
            'status' => 'pending_cleanup',
            'attachment_id' => 43,
            'original_path' => $originalPath,
            'old_files_json' => 'not valid json {{{',
        ]);

        $ok = \IWC_Bulk_Converter::finalize_cleanup($logId);

        $this->assertTrue($ok);
        $this->assertFileDoesNotExist($originalPath);
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/orphan.jpg');
    }

    private function seedLogRow(array $overrides = []): int {
        $defaults = [
            'attachment_id' => 1,
            'original_path' => '/uploads/x.jpg',
            'old_files_json' => null,
            'bucket' => 'plain_content',
            'status' => 'pending_cleanup',
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
