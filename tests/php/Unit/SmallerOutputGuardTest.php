<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves a conversion that would make the file bigger is rejected, and that
 * rejecting leaves nothing behind.
 *
 * WEBP is not unconditionally smaller than what it replaces: a small flat or
 * synthetic PNG is usually handled better by PNG's own compression, and an
 * already well-optimised JPEG can re-encode larger. Converting those anyway
 * grows the page weight while the UI reports a saving.
 *
 * @covers iwc_convert_image_file_to_webp
 * @covers iwc_accept_webp_output
 */
final class SmallerOutputGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    public function test_conversion_is_rejected_when_the_webp_would_be_larger(): void {
        // A small synthetic transparent PNG: PNG's own compression wins here.
        $source = $this->tmpPath('flat.png');
        FixtureFactory::transparentPngEllipse($source, 60);
        $out = $this->tmpPath('flat.webp');

        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 90);

        $this->assertFalse($ok, 'a larger WEBP must be reported as a failed conversion');
        $this->assertFileDoesNotExist($out, 'the rejected WEBP must not be left on disk');
        $this->assertFileExists($source, 'the source must be untouched');
    }

    public function test_conversion_is_kept_when_the_webp_is_smaller(): void {
        // A photographic-ish JPEG at high quality: WEBP wins comfortably.
        $source = $this->tmpPath('photo.jpg');
        FixtureFactory::plainJpeg($source, 400, [10, 200, 30], 100);
        $out = $this->tmpPath('photo.webp');

        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 82);

        $this->assertTrue($ok);
        $this->assertFileExists($out);
        $this->assertLessThan(filesize($source), filesize($out));
    }

    public function test_filter_can_opt_out_of_the_size_requirement(): void {
        $source = $this->tmpPath('flat2.png');
        FixtureFactory::transparentPngEllipse($source, 60);
        $out = $this->tmpPath('flat2.webp');

        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_require_smaller_output' ? false : $value;
        });

        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 90);

        $this->assertTrue($ok, 'sites that want WEBP regardless of size can opt out');
        $this->assertFileExists($out);
    }
}
