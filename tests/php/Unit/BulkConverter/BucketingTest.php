<?php

namespace IWC\Tests\Unit\BulkConverter;

use IWC\Tests\Unit\WpdbTestCase;

/**
 * Proves IWC_Bulk_Converter's bucketing logic (via the public scan()
 * entrypoint) puts attachments in the right bucket, including the ordering
 * rule (serialized wins over plain-content) and the one confirmed,
 * currently-unmitigated gap: a plain, non-serialized string reference
 * living in postmeta/options is caught by neither check.
 *
 * @covers IWC_Bulk_Converter::scan
 */
final class BucketingTest extends WpdbTestCase {
    public function test_unresolvable_attached_file_buckets_serialized_only(): void {
        // No _wp_attached_file postmeta seeded at all for this attachment.
        $attachmentId = $this->seedAttachment();

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only']);
    }

    public function test_no_reference_anywhere_buckets_unreferenced(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_plain_content_reference_buckets_plain_content(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPost(['post_content' => 'see <img src="2024/05/photo-300x200.jpg">', 'post_status' => 'publish', 'post_type' => 'page']);

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['plain_content']);
    }

    public function test_serialized_reference_wins_over_plain_content_match_when_both_present(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        // Both a plain post_content match AND a serialized postmeta match exist.
        $this->seedPost(['post_content' => 'see 2024/05/photo.jpg here', 'post_type' => 'page']);
        $this->seedPostmeta(999, '_page_builder_data', serialize(['bg' => '2024/05/photo.jpg']));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only'], 'serialized reference must win over a plain-content match');
        $this->assertNotContains($attachmentId, $buckets['plain_content']);
    }

    public function test_reference_in_trashed_post_is_ignored(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPost(['post_content' => '2024/05/photo.jpg', 'post_status' => 'trash', 'post_type' => 'page']);

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_reference_in_revision_post_type_is_ignored(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPost(['post_content' => '2024/05/photo.jpg', 'post_type' => 'revision']);

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_plain_non_serialized_string_in_postmeta_is_a_documented_gap_bucketed_unreferenced(): void {
        // Confirmed, currently-unmitigated gap: has_serialized_reference()
        // only counts a LIKE match if is_serialized() is also true, and
        // has_plain_content_reference() only ever searches post_content —
        // never postmeta/options. A plain (non-serialized) string sitting
        // in postmeta (e.g. a page builder storing a raw URL, not a PHP
        // array) is therefore invisible to both checks. This test locks in
        // that CURRENT behavior so a future change is a deliberate
        // decision, not a silent surprise — see class-iwc-bulk-converter.php
        // has_serialized_reference()/has_plain_content_reference() for the
        // fix location if this is ever addressed.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPostmeta(999, 'raw_bg_image_url', '2024/05/photo.jpg'); // plain string, NOT serialize()'d

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced'], 'documents the current (imperfect) behavior for this edge case');
    }

    public function test_already_converted_attachments_are_excluded_from_scan(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPostmeta($attachmentId, '_iwc_converted', '2026-01-01 00:00:00');

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertNotContains($attachmentId, $buckets['unreferenced']);
        $this->assertNotContains($attachmentId, $buckets['plain_content']);
        $this->assertNotContains($attachmentId, $buckets['serialized_only']);
    }

    private function seedAttachment(): int {
        return $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']);
    }

    private function seedAttachmentWithFile(string $relativeFile): int {
        $id = $this->seedAttachment();
        $this->seedPostmeta($id, '_wp_attached_file', $relativeFile);
        return $id;
    }
}
