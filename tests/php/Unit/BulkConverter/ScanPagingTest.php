<?php

namespace IWC\Tests\Unit\BulkConverter;

use IWC\Tests\Unit\WpdbTestCase;

/**
 * Proves the paged scan visits every eligible attachment exactly once.
 *
 * Bucketing costs several unindexed LIKE scans per attachment, so the whole
 * library can't be done in one request. Paging is where a scan can silently
 * skip images, which would look identical to "nothing to convert" — hence
 * covering the cursor directly rather than only through scan().
 *
 * @covers IWC_Bulk_Converter::scan_batch
 * @covers IWC_Bulk_Converter::scan
 * @covers IWC_Bulk_Converter::count_eligible
 */
final class ScanPagingTest extends WpdbTestCase {
    public function test_batch_respects_the_limit_and_reports_not_done(): void {
        $this->seedAttachments(5);

        $batch = \IWC_Bulk_Converter::scan_batch(0, 2);

        $this->assertSame(2, $batch['scanned']);
        $this->assertFalse($batch['done']);
    }

    public function test_a_short_page_reports_done(): void {
        $this->seedAttachments(3);

        $batch = \IWC_Bulk_Converter::scan_batch(0, 10);

        $this->assertSame(3, $batch['scanned']);
        $this->assertTrue($batch['done']);
    }

    public function test_paging_by_cursor_visits_every_attachment_exactly_once(): void {
        $ids = $this->seedAttachments(7);

        $seen = [];
        $afterId = 0;
        do {
            $batch = \IWC_Bulk_Converter::scan_batch($afterId, 2);
            foreach ($batch['buckets'] as $bucket) {
                $seen = array_merge($seen, $bucket);
            }
            $afterId = $batch['last_id'];
        } while (!$batch['done']);

        sort($seen);
        $this->assertSame($ids, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_scan_aggregates_every_page(): void {
        $ids = $this->seedAttachments(250); // more than one SCAN_BATCH_SIZE page

        $buckets = \IWC_Bulk_Converter::scan();
        $all = array_merge($buckets['unreferenced'], $buckets['plain_content'], $buckets['serialized_only']);

        sort($all);
        $this->assertSame($ids, $all);
    }

    public function test_empty_library_terminates_immediately(): void {
        $batch = \IWC_Bulk_Converter::scan_batch(0, 10);

        $this->assertSame(0, $batch['scanned']);
        $this->assertTrue($batch['done']);
        $this->assertSame(0, $batch['last_id']);
        $this->assertSame([], \IWC_Bulk_Converter::scan()['unreferenced']);
    }

    public function test_count_eligible_excludes_already_converted(): void {
        $ids = $this->seedAttachments(4);
        $this->seedPostmeta($ids[0], '_iwc_converted', '2026-01-01 00:00:00');

        $this->assertSame(3, \IWC_Bulk_Converter::count_eligible());
    }

    public function test_count_eligible_excludes_non_image_attachments(): void {
        $this->seedAttachments(2);
        $pdf = $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => 'application/pdf']);
        $this->seedPostmeta($pdf, '_wp_attached_file', '2024/05/doc.pdf');

        $this->assertSame(2, \IWC_Bulk_Converter::count_eligible());
    }

    /** @return int[] seeded attachment IDs, ascending */
    private function seedAttachments(int $count): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']);
            $this->seedPostmeta($id, '_wp_attached_file', sprintf('2024/05/photo-%03d.jpg', $i));
            $ids[] = $id;
        }
        sort($ids);
        return $ids;
    }
}
