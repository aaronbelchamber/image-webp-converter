<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves the safety guard when Imagick genuinely isn't loaded: a detected
 * CMYK JPEG (channels === 4, via real getimagesize() parsing) must be
 * skipped — never handed to GD, which is known to invert its colors —
 * rather than falling back to a silently-wrong conversion.
 *
 * Deliberately carries no PHPUnit "requires-imagick" group tag: this test
 * only proves anything when ext-imagick is absent, so it's skipped when
 * present rather than gated the other way. It runs for real both on any
 * dev machine without ext-imagick installed and in CI's dedicated
 * no-Imagick job.
 *
 * @covers iwc_load_cmyk_jpeg_as_rgb
 * @covers iwc_convert_image_file_to_webp
 */
final class CmykWithoutImagickTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (FixtureFactory::isImagickAvailable()) {
            $this->markTestSkipped('This test only proves anything when ext-imagick is absent; see CmykConversionTest for the with-Imagick behavior.');
        }
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    public function test_load_cmyk_jpeg_as_rgb_returns_false_without_imagick(): void {
        $source = $this->tmpPath('cmyk-detect.jpg');
        FixtureFactory::rawJpegWithComponentCount($source, 4);

        $this->assertFalse(iwc_load_cmyk_jpeg_as_rgb($source));
    }

    public function test_full_pipeline_skips_cmyk_jpeg_without_imagick_original_untouched(): void {
        $source = $this->tmpPath('cmyk-pipeline.jpg');
        FixtureFactory::rawJpegWithComponentCount($source, 4);

        $info = getimagesize($source);
        $this->assertSame(4, $info['channels'], 'sanity check: fixture must be detected as 4-channel, same as the plugin\'s own detection mechanism');

        $out = $this->tmpPath('cmyk-pipeline.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 80);

        $this->assertFalse($ok, 'conversion must be refused for a CMYK JPEG when Imagick is unavailable, not silently attempted via GD');
        $this->assertFileDoesNotExist($out);
        $this->assertFileExists($source, 'the original source file must be left untouched when conversion is skipped');
    }

    public function test_non_cmyk_jpeg_is_unaffected_by_the_guard(): void {
        $source = $this->tmpPath('plain.jpg');
        FixtureFactory::plainJpeg($source, 20, [10, 200, 30]);

        $out = $this->tmpPath('plain.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 80);

        $this->assertTrue($ok, 'a genuinely 3-channel JPEG should convert normally even with no Imagick present');
    }
}
