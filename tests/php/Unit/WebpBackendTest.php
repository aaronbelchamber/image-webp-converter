<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Proves backend selection is coherent in whatever environment it runs in.
 *
 * extension_loaded() and class_exists() cannot be mocked, so these are written
 * as invariants rather than as fixed expectations: whichever backend is
 * chosen, the capability that justifies choosing it must actually be present.
 * That makes the same assertions meaningful on a full host, on one with no
 * Imagick, on one whose GD cannot encode WebP, and on one with only Imagick —
 * which is precisely the set of environments the playground matrix runs.
 *
 * @covers iwc_webp_backend
 * @covers iwc_imagick_supports_webp
 * @covers iwc_environment_supports_webp
 */
final class WebpBackendTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    public function test_backend_is_one_of_the_three_known_values(): void {
        $this->assertContains(iwc_webp_backend(), ['', 'gd', 'imagick']);
    }

    public function test_gd_is_only_chosen_when_gd_can_encode_webp(): void {
        if (iwc_webp_backend() !== 'gd') {
            $this->markTestSkipped('GD is not the selected backend here.');
        }

        $this->assertTrue(extension_loaded('gd'));
        $this->assertTrue(function_exists('imagewebp'));
    }

    public function test_imagick_is_only_chosen_when_imagick_can_encode_webp(): void {
        if (iwc_webp_backend() !== 'imagick') {
            $this->markTestSkipped('Imagick is not the selected backend here.');
        }

        $this->assertTrue(class_exists('Imagick'));
        $this->assertNotEmpty(\Imagick::queryFormats('WEBP'));
    }

    public function test_no_backend_means_neither_library_can_encode_webp(): void {
        if (iwc_webp_backend() !== '') {
            $this->markTestSkipped('A backend is available here.');
        }

        $this->assertFalse(function_exists('imagewebp') && extension_loaded('gd'));
        $this->assertFalse(iwc_imagick_supports_webp());
    }

    public function test_gd_is_preferred_when_both_are_available(): void {
        // Not a claim that GD is the better encoder — Imagick arguably is.
        // It is the path with years of use behind it, and switching every
        // working site to a different encoder as a side effect of adding a
        // fallback would be a change nobody asked for.
        if (!function_exists('imagewebp') || !iwc_imagick_supports_webp()) {
            $this->markTestSkipped('Needs both libraries present to prove a preference.');
        }

        $this->assertSame('gd', iwc_webp_backend());
    }

    public function test_an_imagick_only_host_converts_without_being_told_to(): void {
        // The reason the second backend exists. A GD built without WebP
        // alongside a perfectly capable ImageMagick used to convert nothing at
        // all and say nothing about it. No filter here on purpose: selection
        // has to reach this on its own, or the fallback only helps sites that
        // already knew to configure it.
        if (function_exists('imagewebp') || !iwc_imagick_supports_webp()) {
            $this->markTestSkipped('Needs a host where GD cannot encode WEBP but Imagick can.');
        }

        $this->assertSame('imagick', iwc_webp_backend());
        $this->assertTrue(iwc_environment_supports_webp());

        $source = $this->tmpPath('fallback.jpg');
        \IWC\Tests\fixtures\FixtureFactory::plainJpeg($source, 200, [10, 200, 30], 100);
        $out = $this->tmpPath('fallback.webp');

        $this->assertTrue(
            iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 82),
            'a host with only Imagick must still convert'
        );
        $this->assertLessThan(filesize($source), filesize($out));
    }

    public function test_environment_support_agrees_with_backend_selection(): void {
        $this->assertSame(iwc_webp_backend() !== '', iwc_environment_supports_webp());
    }

    public function test_filter_can_force_refusal(): void {
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_webp_backend' ? '' : $value;
        });

        $this->assertSame('', iwc_webp_backend());
        $this->assertFalse(iwc_environment_supports_webp());
    }

    public function test_an_unrecognised_filter_value_is_treated_as_no_backend(): void {
        // Refusing is the safe reading of a nonsense value. Falling back to a
        // default would dispatch to an encoder the site explicitly tried to
        // steer away from.
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_webp_backend' ? 'graphicsmagick' : $value;
        });

        $this->assertSame('', iwc_webp_backend());
    }

    public function test_imagick_support_check_is_false_without_the_extension(): void {
        if (class_exists('Imagick')) {
            $this->markTestSkipped('Imagick is installed here.');
        }

        $this->assertFalse(iwc_imagick_supports_webp());
    }
}
