<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Proves the EXIF/IPTC read from a source image survives its conversion.
 *
 * Conversion runs in the wp_handle_upload filter, before WordPress creates
 * the attachment and asks for the image's metadata — at which point the JPEG
 * carrying it has been replaced by a WEBP that cannot supply it. Captions,
 * credits, copyright and camera data were being dropped on every upload.
 *
 * @covers iwc_remembered_image_meta
 * @covers iwc_convert_upload_to_webp
 */
final class ImageMetadataPreservationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_image_editor_supports')->justReturn(true);
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);
    }

    public function test_metadata_is_remembered_against_the_converted_path(): void {
        $meta = ['caption' => 'A caption', 'credit' => 'A photographer', 'copyright' => '(c) 2026'];

        iwc_remembered_image_meta('/uploads/photo.webp', $meta);

        $this->assertSame($meta, iwc_remembered_image_meta('/uploads/photo.webp'));
    }

    public function test_an_unknown_path_recalls_nothing(): void {
        $this->assertNull(iwc_remembered_image_meta('/uploads/never-seen.webp'));
    }

    public function test_source_metadata_is_carried_across_a_real_upload_conversion(): void {
        $source = $this->tmpPath('upload.jpg');
        \IWC\Tests\fixtures\FixtureFactory::plainJpeg($source, 400, [10, 200, 30], 100);

        // Stand in for WordPress's reader, which needs wp-admin includes.
        Functions\when('wp_read_image_metadata')->justReturn([
            'caption'   => 'Sunrise over the ridge',
            'credit'    => 'A. Belchamber',
            'copyright' => '(c) 2026',
            'camera'    => 'NIKON Z6',
        ]);

        $upload = iwc_convert_upload_to_webp(
            ['file' => $source, 'url' => 'https://example.test/uploads/upload.jpg', 'type' => 'image/jpeg'],
            82
        );

        $this->assertSame('image/webp', $upload['type'], 'sanity: the conversion must actually have happened');

        $recalled = iwc_remembered_image_meta($upload['file']);
        $this->assertIsArray($recalled);
        $this->assertSame('Sunrise over the ridge', $recalled['caption']);
        $this->assertSame('A. Belchamber', $recalled['credit']);
        $this->assertSame('NIKON Z6', $recalled['camera']);
    }

    public function test_nothing_is_remembered_when_the_conversion_is_rejected(): void {
        // A source WEBP can't improve on: the original is kept, so WordPress
        // reads its metadata directly and there is nothing to stand in for.
        $source = $this->tmpPath('flat.png');
        \IWC\Tests\fixtures\FixtureFactory::transparentPngEllipse($source, 60);
        Functions\when('wp_read_image_metadata')->justReturn(['caption' => 'unused']);

        $upload = iwc_convert_upload_to_webp(
            ['file' => $source, 'url' => 'https://example.test/uploads/flat.png', 'type' => 'image/png'],
            82
        );

        $this->assertSame('image/png', $upload['type'], 'sanity: this conversion should have been rejected');
        $this->assertNull(iwc_remembered_image_meta($this->tmpPath('flat.webp')));
    }
}
