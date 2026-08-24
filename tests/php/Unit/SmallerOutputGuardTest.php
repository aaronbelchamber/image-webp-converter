<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves a conversion that would make the file bigger is rejected, and that
 * rejecting leaves nothing behind.
 *
 * WEBP does not beat every source. An already well-optimised JPEG can
 * re-encode larger, and a flat PNG is often handled better by PNG's own
 * compression — converting those anyway grows the page weight while the UI
 * reports a saving.
 *
 * The size comparison is exercised directly rather than through a real
 * encode: no synthetic fixture reliably produces a losing conversion (every
 * candidate tried compresses well in WEBP), so driving it through the codec
 * would make the test hostage to libwebp's tuning rather than to this
 * plugin's logic.
 *
 * @covers iwc_accept_webp_output
 * @covers iwc_convert_image_file_to_webp
 */
final class SmallerOutputGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    /** Writes a file of exactly $bytes length and returns its path. */
    private function fileOfSize(string $name, int $bytes): string {
        $path = $this->tmpPath($name);
        file_put_contents($path, str_repeat('x', $bytes));
        return $path;
    }

    public function test_output_larger_than_source_is_rejected_and_deleted(): void {
        $source = $this->fileOfSize('source.jpg', 1000);
        $output = $this->fileOfSize('out.webp', 2000);

        $this->assertFalse(iwc_accept_webp_output($source, $output));
        $this->assertFileDoesNotExist($output, 'the rejected WEBP must not be left on disk');
        $this->assertFileExists($source, 'the source must be untouched');
    }

    public function test_output_the_same_size_as_the_source_is_rejected(): void {
        // No gain, so no reason to swap the file and invalidate every cache
        // and CDN copy pointing at it.
        $source = $this->fileOfSize('same-source.jpg', 1500);
        $output = $this->fileOfSize('same-out.webp', 1500);

        $this->assertFalse(iwc_accept_webp_output($source, $output));
        $this->assertFileDoesNotExist($output);
    }

    public function test_output_smaller_than_the_source_is_kept(): void {
        $source = $this->fileOfSize('big-source.jpg', 4000);
        $output = $this->fileOfSize('small-out.webp', 1200);

        $this->assertTrue(iwc_accept_webp_output($source, $output));
        $this->assertFileExists($output);
    }

    public function test_a_zero_byte_output_is_rejected_and_deleted(): void {
        $source = $this->fileOfSize('zero-source.jpg', 900);
        $output = $this->fileOfSize('zero-out.webp', 0);

        $this->assertFalse(iwc_accept_webp_output($source, $output));
        $this->assertFileDoesNotExist($output);
    }

    public function test_an_unreadable_source_does_not_condemn_the_output(): void {
        // Not being able to size the source is no evidence the conversion was
        // bad, so the encode is kept rather than thrown away.
        $output = $this->fileOfSize('orphan-out.webp', 800);

        $this->assertTrue(iwc_accept_webp_output($this->tmpPath('gone.jpg'), $output));
        $this->assertFileExists($output);
    }

    public function test_filter_can_opt_out_of_the_size_requirement(): void {
        $source = $this->fileOfSize('opt-source.jpg', 500);
        $output = $this->fileOfSize('opt-out.webp', 5000);

        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_require_smaller_output' ? false : $value;
        });

        $this->assertTrue(iwc_accept_webp_output($source, $output), 'sites that want WEBP regardless of size can opt out');
        $this->assertFileExists($output);
    }

    public function test_a_real_conversion_that_wins_is_kept(): void {
        $source = $this->tmpPath('photo.jpg');
        FixtureFactory::plainJpeg($source, 400, [10, 200, 30], 100);
        $out = $this->tmpPath('photo.webp');

        $this->assertTrue(iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 82));
        $this->assertLessThan(filesize($source), filesize($out));
    }

    public function test_a_transparent_png_now_survives_the_guard_via_lossless(): void {
        // This is the case the guard used to reject outright: at the lossy
        // alpha quality floor this fixture encodes larger than the PNG it
        // would replace. The lossless attempt is what makes transparent
        // images convertible at all.
        $source = $this->tmpPath('alpha.png');
        FixtureFactory::transparentPngEllipse($source, 60);
        $out = $this->tmpPath('alpha.webp');

        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 82);

        if (!defined('IMG_WEBP_LOSSLESS')) {
            $this->markTestSkipped('IMG_WEBP_LOSSLESS requires PHP 8.1');
        }

        $this->assertTrue($ok);
        $this->assertLessThan(filesize($source), filesize($out));
    }
}
