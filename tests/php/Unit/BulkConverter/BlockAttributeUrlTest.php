<?php

namespace IWC\Tests\Unit\BulkConverter;

/**
 * Proves image URLs stored inside block-delimiter JSON are rewritten.
 *
 * Gutenberg serialises a block's attributes as JSON in its HTML comment
 * delimiter, and json_encode() escapes every forward slash. A Cover block's
 * background image URL exists *only* there — there is no <img> tag carrying
 * it — so a search-and-replace over the unescaped URL found the post and then
 * changed nothing in it.
 *
 * @covers IWC_Bulk_Converter::convert_attachment
 */
final class BlockAttributeUrlTest extends BulkConverterTestCase {
    public function test_escaped_slash_url_in_block_attributes_is_rewritten(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $escaped = 'https:\/\/example.test\/wp-content\/uploads\/2024\/05\/photo.jpg';
        $postId = $this->seedPost([
            'post_content' => '<!-- wp:cover {"url":"' . $escaped . '","id":9} --><div></div><!-- /wp:cover -->',
            'post_type' => 'page',
            'post_title' => 'Cover page',
        ]);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'plain_content', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $this->assertSame(1, $result['references_updated']);

        $content = $this->wpdb->get_results("SELECT post_content FROM {$this->wpdb->posts} WHERE ID = $postId")[0]->post_content;
        $this->assertStringContainsString('photo.webp', $content);
        $this->assertStringNotContainsString('photo.jpg', $content);
        $this->assertStringContainsString('\/wp-content\/', $content, 'the JSON escaping must be preserved, not flattened');
    }

    public function test_escaped_slash_reference_is_found_by_the_bucketing_scan(): void {
        // The scan and the rewrite have to agree about what counts as a
        // reference; if only one of them understands the escaped form, an
        // attachment gets bucketed as rewritable and then nothing is rewritten.
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $this->seedPost([
            'post_content' => '<!-- wp:cover {"url":"https:\/\/example.test\/wp-content\/uploads\/2024\/05\/photo.jpg"} -->',
            'post_type' => 'page',
        ]);

        $buckets = \IWC_Bulk_Converter::scan();

        $this->assertContains($id, $buckets['plain_content']);
    }

    public function test_a_post_mixing_escaped_and_plain_urls_has_both_rewritten(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $postId = $this->seedPost([
            'post_content' =>
                '<!-- wp:cover {"url":"https:\/\/example.test\/wp-content\/uploads\/2024\/05\/photo.jpg"} -->'
                . '<img src="https://example.test/wp-content/uploads/2024/05/photo.jpg">',
            'post_type' => 'page',
        ]);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'plain_content', 80);

        $this->assertSame('pending_cleanup', $result['status']);
        $content = $this->wpdb->get_results("SELECT post_content FROM {$this->wpdb->posts} WHERE ID = $postId")[0]->post_content;
        $this->assertStringNotContainsString('photo.jpg', $content);
        $this->assertSame(2, substr_count($content, 'photo.webp'));
    }
}
