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

    public function test_jpe_extension_swaps_to_webp(): void {
        // WordPress's allowed upload types include .jpe for image/jpeg, and
        // .jfif turns up on real sites. An allow-list regex left those
        // extensions in place and wrote WEBP bytes into a file still named
        // .jpe, which was then labelled image/webp — a mismatch WordPress
        // rejects downstream.
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/photo.webp',
            iwc_resolve_webp_target_path('/uploads/photo.jpe')
        );
    }

    public function test_unexpected_extension_still_produces_a_webp_filename(): void {
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/photo.webp',
            iwc_resolve_webp_target_path('/uploads/photo.jfif')
        );
    }

    public function test_dotted_filename_only_loses_its_final_extension(): void {
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $this->assertSame(
            '/uploads/my.photo.v2.webp',
            iwc_resolve_webp_target_path('/uploads/my.photo.v2.jpg')
        );
    }

}
