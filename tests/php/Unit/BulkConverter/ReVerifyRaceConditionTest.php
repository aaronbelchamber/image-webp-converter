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
        $this->seedPost([
            'post_content' => 'look at https://example.test/wp-content/uploads/2024/05/photo.jpg',
            'post_type' => 'page',
        ]);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status'], 'must fall through to the safer path when a reference appeared since the scan, regardless of the bucket the caller passed');
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg', 'original must be left in place, not moved, when a reference is found at conversion time');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
    }

    public function test_reference_that_cannot_be_rewritten_is_reported_not_marked_ready_for_cleanup(): void {
        // A relative path matches the reference scan but no URL rewrite can
        // touch it. That used to be logged as 'pending_cleanup' with zero
        // references updated — indistinguishable on the Cleanup Review screen
        // from a genuine no-op, so approving it moved the originals out from
        // under content still pointing at them.
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPost(['post_content' => 'look at 2024/05/photo.jpg', 'post_type' => 'page']);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'plain_content', 80);

        $this->assertSame('references_failed', $result['status']);
        $this->assertSame(0, $result['references_updated']);
        $this->assertSame(1, $result['references_failed']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
        $this->assertSame([], \IWC_Logger::get_pending_cleanup(), 'must never be offered for cleanup');
    }

    public function test_thumbnail_size_references_are_rewritten_not_just_the_full_size_url(): void {
        // get_relative_base() searches without an extension, so a post using
        // only a thumbnail counts as a reference. Rewriting just the full-size
        // URL left it matched-but-untouched while the thumbnail was still
        // collected for the holding folder.
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->oldMetadata = ['sizes' => ['medium' => ['file' => 'photo-300x200.jpg']]];
        $this->newMetadata = ['sizes' => ['medium' => ['file' => 'photo-300x200.webp']]];
        $postId = $this->seedPost([
            'post_content' => '<img src="https://example.test/wp-content/uploads/2024/05/photo-300x200.jpg">',
            'post_type' => 'page',
            'post_title' => 'Thumb page',
        ]);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'plain_content', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertSame(1, $result['references_updated']);
        $content = $this->wpdb->get_results("SELECT post_content FROM {$this->wpdb->posts} WHERE ID = $postId")[0]->post_content;
        $this->assertStringContainsString('photo-300x200.webp', $content);
        $this->assertStringNotContainsString('photo-300x200.jpg', $content);
    }

    public function test_serialized_reference_appearing_between_scan_and_conversion_also_falls_through(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPostmeta(9999, '_widget_data', serialize(['image' => '2024/05/photo.jpg']));

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
    }

    public function test_elementor_json_reference_blocks_the_destructive_path(): void {
        // The re-verify runs the same reference check the scan does, so it
        // has to be blind to storage format too — otherwise a stale
        // 'unreferenced' bucket walks an Elementor image straight into the
        // holding folder.
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $pageId = $this->seedPost(['post_content' => '', 'post_type' => 'page']);
        $this->seedPostmeta($pageId, '_elementor_data', json_encode([
            ['elType' => 'widget', 'settings' => ['image' => ['url' => '2024/05/photo.jpg', 'id' => $id]]],
        ]));

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg', 'an Elementor-referenced original must never be moved out of the uploads dir');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
    }

    public function test_own_attachment_metadata_does_not_block_the_destructive_path(): void {
        // Regression guard: the attachment's own serialized
        // _wp_attachment_metadata contains its path, and counting it as a
        // reference made this branch unreachable on every real site.
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPostmeta($id, '_wp_attachment_metadata', serialize(['file' => '2024/05/photo.jpg']));

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
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

    public function test_scaled_originals_pre_scale_file_is_collected_and_moved(): void {
        // WordPress 5.3+ downscales large uploads: get_attached_file() returns
        // photo-scaled.jpg while the untouched full-resolution photo.jpg lives
        // on as metadata['original_image']. Missing it left the single largest
        // file on disk behind forever and under-reported the space saved.
        $id = $this->seedRealAttachment('2024/05/photo-scaled.jpg');
        \IWC\Tests\fixtures\FixtureFactory::plainJpeg($this->uploadsBasedir . '/2024/05/photo.jpg', 80, [10, 200, 30]);
        $this->oldMetadata = ['original_image' => 'photo.jpg', 'sizes' => []];

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/2024/05/photo.jpg', 'the pre-scale original must be moved too');
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo-scaled.jpg');
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
