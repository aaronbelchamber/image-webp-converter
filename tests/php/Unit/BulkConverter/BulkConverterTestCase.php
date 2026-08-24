<?php

namespace IWC\Tests\Unit\BulkConverter;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;
use IWC\Tests\Unit\WpdbTestCase;

/**
 * Shared setup for tests that drive IWC_Bulk_Converter::convert_attachment()
 * end-to-end — real image conversion (a real JPEG on real disk, run through
 * the real iwc_convert_image_file_to_webp() pipeline), real SQLite-backed
 * $wpdb for the reference-scan/re-verify queries, and Brain Monkey mocks
 * only for the WP core functions this plugin doesn't itself talk SQL to
 * (attachment metadata plumbing, current_time(), etc.).
 */
abstract class BulkConverterTestCase extends WpdbTestCase {
    /** These encode real images, which a GD built without WEBP cannot do. */
    protected bool $requiresWebpEncoding = true;

    protected string $uploadsBasedir;

    /** @var array<int,string> */
    protected array $mimeTypes = [];
    /** @var array<int,string> */
    protected array $attachedFiles = [];

    /**
     * Attachment metadata as it stands before conversion, and as WordPress
     * regenerates it afterwards. Tests that care about intermediate sizes or
     * a -scaled original_image override these in place; the defaults describe
     * a plain single-size image.
     */
    protected array $oldMetadata = ['sizes' => []];
    protected array $newMetadata = ['sizes' => []];

    protected function setUp(): void {
        parent::setUp();

        $this->uploadsBasedir = sys_get_temp_dir() . '/iwc-uploads-' . uniqid('', true);
        mkdir($this->uploadsBasedir, 0777, true);

        // Pin the custom-table risk off by default. IWC_Compat detects by
        // class_exists(), and PHP cannot undeclare a class — so a stand-in
        // declared by any other test in the run would otherwise leak in here
        // and silently withhold the destructive path, making these tests pass
        // or fail on execution order. Tests that care about the risk set it
        // explicitly (see CustomTableRiskTest).
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_custom_table_risk' ? false : $value;
        });

        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);
        Functions\when('wp_image_editor_supports')->justReturn(true);
        Functions\when('wp_get_upload_dir')->justReturn([
            'basedir' => $this->uploadsBasedir,
            'baseurl' => 'https://example.test/wp-content/uploads',
        ]);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_get_attachment_metadata')->alias(fn() => $this->oldMetadata);
        Functions\when('wp_generate_attachment_metadata')->alias(fn() => $this->newMetadata);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_attached_file')->justReturn(true);

        Functions\when('get_post_mime_type')->alias(fn($id) => $this->mimeTypes[$id] ?? 'image/jpeg');
        Functions\when('get_attached_file')->alias(fn($id) => $this->attachedFiles[$id] ?? '');

        Functions\when('wp_update_post')->alias(function (array $args, $wpError = false) {
            if (isset($args['post_content'])) {
                $this->wpdb->seed_prepared(
                    "UPDATE {$this->wpdb->posts} SET post_content = ? WHERE ID = ?",
                    [$args['post_content'], $args['ID']]
                );
            }
            if (isset($args['post_mime_type'])) {
                $this->wpdb->seed_prepared(
                    "UPDATE {$this->wpdb->posts} SET post_mime_type = ? WHERE ID = ?",
                    [$args['post_mime_type'], $args['ID']]
                );
            }
            return (int) $args['ID'];
        });

        Functions\when('get_post')->alias(function ($id) {
            $rows = $this->wpdb->get_results("SELECT * FROM {$this->wpdb->posts} WHERE ID = " . (int) $id);
            return $rows[0] ?? null;
        });
    }

    /**
     * Creates a real attachment: a DB row, an on-disk JPEG under the fake
     * uploads basedir, and wires get_post_mime_type()/get_attached_file()
     * to point at it. Returns the attachment ID.
     */
    protected function seedRealAttachment(string $relativeFile = '2024/05/photo.jpg', string $mimeType = 'image/jpeg'): int {
        $id = $this->seedPost(['post_type' => 'attachment', 'post_mime_type' => $mimeType]);
        $this->seedPostmeta($id, '_wp_attached_file', $relativeFile);

        $absolutePath = $this->uploadsBasedir . '/' . $relativeFile;
        @mkdir(dirname($absolutePath), 0777, true);
        if ($mimeType === 'image/jpeg') {
            FixtureFactory::plainJpeg($absolutePath, 30, [10, 200, 30]);
        } else {
            FixtureFactory::opaquePng($absolutePath, 30);
        }

        $this->mimeTypes[$id] = $mimeType;
        $this->attachedFiles[$id] = $absolutePath;

        return $id;
    }
}
