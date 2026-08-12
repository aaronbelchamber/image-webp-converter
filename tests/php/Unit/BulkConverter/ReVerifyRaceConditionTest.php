<?php

namespace IWC\Tests\Unit\BulkConverter;

/**
 * The deepest test in the suite: proves IWC_Bulk_Converter's core safety
 * mechanism — the destructive "move original to trash" action re-checks
 * "still unreferenced" AT THE MOMENT of conversion, not trusting the bucket
 * a possibly-stale earlier scan assigned. Scan and conversion happen in
 * separate requests in real usage, so content can change in between.
 *
 * @covers IWC_Bulk_Converter::convert_attachment
 */
final class ReVerifyRaceConditionTest extends BulkConverterTestCase {
    public function test_still_unreferenced_at_conversion_time_is_trashed(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/2024/05/photo.jpg', 'original should have been moved out of the uploads dir');
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg', 'original should now be in the holding folder');
    }

    public function test_reference_appearing_between_scan_and_conversion_falls_through_to_pending_cleanup(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        // Simulates content changing after the scan ran but before this
        // batch's conversion call: a post now references the file, even
        // though the caller still passes the stale 'unreferenced' bucket.
        $this->seedPost(['post_content' => 'look at 2024/05/photo.jpg', 'post_type' => 'page']);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status'], 'must fall through to the safer path when a reference appeared since the scan, regardless of the bucket the caller passed');
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg', 'original must be left in place, not moved, when a reference is found at conversion time');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
    }

    public function test_serialized_reference_appearing_between_scan_and_conversion_also_falls_through(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPostmeta(9999, '_widget_data', serialize(['image' => '2024/05/photo.jpg']));

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_plain_content_bucket_replaces_url_in_referencing_post(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $postId = $this->seedPost(['post_content' => 'see https://example.test/wp-content/uploads/2024/05/photo.jpg here', 'post_type' => 'page', 'post_title' => 'A page']);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'plain_content', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertSame(1, $result['references_updated']);
        $row = $this->wpdb->get_results("SELECT post_content FROM {$this->wpdb->posts} WHERE ID = $postId")[0];
        $this->assertStringContainsString('photo.webp', $row->post_content);
        $this->assertStringNotContainsString('photo.jpg', $row->post_content);
        // Originals are left in place for manual review in this bucket.
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_already_converted_attachment_is_skipped(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPostmeta($id, '_iwc_converted', '2026-01-01 00:00:00');

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('skipped', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_ineligible_bucket_is_skipped_without_touching_the_file(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'serialized_only', 80);

        $this->assertSame('skipped', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_non_image_mime_type_is_skipped(): void {
        $id = $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => 'application/pdf']);
        $this->seedPostmeta($id, '_wp_attached_file', '2024/05/doc.pdf');
        $this->mimeTypes[$id] = 'application/pdf';

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('skipped', $result['status']);
    }

    public function test_missing_original_file_returns_error(): void {
        $id = $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']);
        $this->seedPostmeta($id, '_wp_attached_file', '2024/05/missing.jpg');
        // Wire the ID to a path with no file actually written on disk.
        $this->mimeTypes[$id] = 'image/jpeg';
        $this->attachedFiles[$id] = $this->uploadsBasedir . '/2024/05/missing.jpg';

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('error', $result['status']);
    }
}
