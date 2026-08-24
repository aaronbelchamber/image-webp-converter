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

    public function test_external_reference_wins_over_plain_content_match_when_both_present(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        // Both a plain post_content match AND a postmeta match exist. The
        // postmeta one can't be safely rewritten, so it has to win — updating
        // only the content reference would leave the builder pointing at a
        // file that's about to move.
        $pageId = $this->seedPage('see 2024/05/photo.jpg here');
        $this->seedPostmeta($pageId, '_page_builder_data', serialize(['bg' => '2024/05/photo.jpg']));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only'], 'a postmeta reference must win over a plain-content match');
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

    public function test_plain_non_serialized_string_in_postmeta_is_treated_as_referenced(): void {
        // A page builder storing a raw URL string rather than a PHP array.
        // Previously invisible to both checks (has_serialized_reference()
        // required is_serialized(), has_plain_content_reference() only ever
        // searched post_content) and therefore bucketed 'unreferenced' —
        // meaning its original would be moved to the holding folder and the
        // reference left dangling.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPostmeta($this->seedPage(), 'raw_bg_image_url', '2024/05/photo.jpg'); // plain string, NOT serialize()'d

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only']);
        $this->assertNotContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_elementor_json_reference_is_treated_as_referenced(): void {
        // The regression this bucketing fix exists for. Elementor stores its
        // layout as JSON in _elementor_data, not a PHP serialized array, and
        // leaves post_content empty — so an image placed via Elementor used
        // to satisfy neither check and land in 'unreferenced', the one bucket
        // whose originals get moved out of the uploads path. Same shape for
        // Bricks, Oxygen, and Breakdance.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $pageId = $this->seedPage(''); // Elementor pages have empty post_content
        $this->seedPostmeta($pageId, '_elementor_data', json_encode([
            ['id' => 'a1b2c3', 'elType' => 'widget', 'settings' => [
                'image' => ['url' => 'https://example.test/wp-content/uploads/2024/05/photo.jpg', 'id' => $attachmentId],
            ]],
        ]));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only']);
        $this->assertNotContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_attachments_own_serialized_metadata_does_not_count_as_a_reference(): void {
        // Every real image attachment carries a serialized
        // _wp_attachment_metadata containing its own path. Counting that as
        // a reference made every attachment bucket 'serialized_only', so the
        // bulk converter silently converted nothing at all on a real site —
        // invisible here until this fixture existed, because the suite only
        // ever seeded _wp_attached_file.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPostmeta($attachmentId, '_wp_attachment_metadata', serialize([
            'file'  => '2024/05/photo.jpg',
            'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg']],
        ]));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_a_sibling_attachments_metadata_does_not_count_as_a_reference(): void {
        // The search base is extension-stripped ("2024/05/photo"), so it also
        // matches a differently-named sibling's metadata ("2024/05/photo-2.jpg")
        // — WordPress's own dedup naming makes those pairs common. Excluding
        // all attachment-owned meta, not just this attachment's, avoids it.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $siblingId = $this->seedAttachmentWithFile('2024/05/photo-2.jpg');
        $this->seedPostmeta($siblingId, '_wp_attachment_metadata', serialize(['file' => '2024/05/photo-2.jpg']));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_transient_match_does_not_count_as_a_reference(): void {
        // Transients are regenerable caches, not references — a stale sitemap
        // or gallery transient mentioning the path must not block conversion.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedOption('_transient_my_gallery_cache', serialize(['2024/05/photo.jpg']));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['unreferenced']);
    }

    public function test_non_transient_option_match_is_treated_as_referenced(): void {
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedOption('theme_mods_twentytwentyfour', serialize(['custom_logo_url' => '2024/05/photo.jpg']));

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only']);
    }

    public function test_orphaned_postmeta_reference_is_treated_as_referenced(): void {
        // post_id points at a row that no longer exists. Leftover page-builder
        // data is exactly the case worth being conservative about.
        $attachmentId = $this->seedAttachmentWithFile('2024/05/photo.jpg');
        $this->seedPostmeta(999999, '_elementor_data', '{"url":"2024/05/photo.jpg"}');

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($attachmentId, $buckets['serialized_only']);
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

    private function seedPage(string $content = ''): int {
        return $this->seedPost(['post_content' => $content, 'post_type' => 'page']);
    }

    private function seedAttachmentWithFile(string $relativeFile): int {
        $id = $this->seedAttachment();
        $this->seedPostmeta($id, '_wp_attached_file', $relativeFile);
        return $id;
    }
}
