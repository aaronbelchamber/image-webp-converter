<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @covers iwc_resolve_webp_target_path
 */
final class WebpTargetPathTest extends TestCase {
    public function test_jpg_extension_swaps_to_webp(): void {
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/2024/05/photo.webp',
            iwc_resolve_webp_target_path('/uploads/2024/05/photo.jpg')
        );
    }

    public function test_jpeg_extension_swaps_to_webp(): void {
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/photo.webp',
            iwc_resolve_webp_target_path('/uploads/photo.jpeg')
        );
    }

    public function test_extension_swap_is_case_insensitive(): void {
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/PHOTO.webp',
            iwc_resolve_webp_target_path('/uploads/PHOTO.JPG')
        );
    }

    public function test_collision_avoidance_delegates_to_wp_unique_filename(): void {
        // wp_unique_filename() is WP core's own collision-avoidance
        // (e.g. photo.webp -> photo-1.webp if one already exists) — this
        // proves the plugin actually uses its return value rather than
        // reimplementing (and potentially getting wrong) its own scheme.
        Functions\when('wp_unique_filename')->justReturn('photo-1.webp');

        $this->assertSame(
            '/uploads/photo-1.webp',
            iwc_resolve_webp_target_path('/uploads/photo.png')
        );
    }
}
