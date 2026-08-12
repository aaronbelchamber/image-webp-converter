<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @covers iwc_environment_supports_webp
 */
final class EnvironmentSupportTest extends TestCase {
    public function test_supported_when_editor_reports_webp_support(): void {
        // This machine's real GD (loaded in this test process) must already
        // support encoding webp for the guard clauses to even be reached —
        // skip if not, rather than giving a false pass/fail on an unrelated
        // environment gap.
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with webp encode support is required to exercise this function meaningfully.');
        }

        Functions\when('wp_image_editor_supports')->justReturn(true);

        $this->assertTrue(iwc_environment_supports_webp());
    }

    public function test_unsupported_when_encode_capable_but_decode_incapable(): void {
        // The exact host scenario the function exists to catch: a GD build
        // that can encode webp but WordPress's own editor abstraction
        // reports it can't reliably round-trip it (so thumbnail generation
        // would silently fail). Must be refused even though this test
        // process's real GD can encode fine.
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with webp encode support is required to exercise this function meaningfully.');
        }

        Functions\when('wp_image_editor_supports')->justReturn(false);

        $this->assertFalse(iwc_environment_supports_webp());
    }

    public function test_editor_support_check_receives_webp_mime_type(): void {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with webp encode support is required to exercise this function meaningfully.');
        }

        Functions\expect('wp_image_editor_supports')
            ->once()
            ->with(['mime_type' => 'image/webp'])
            ->andReturn(true);

        $this->assertTrue(iwc_environment_supports_webp());
    }
}
