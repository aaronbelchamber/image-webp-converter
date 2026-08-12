<?php

namespace IWC\Tests\Unit\BulkConverter;

/**
 * Exercises move_files_to_holding_folder() indirectly through
 * convert_attachment()'s 'unreferenced' path (it's private), on a real
 * filesystem — proves the trash-not-delete mechanics: files are moved
 * (not deleted), missing files are silently skipped, and the directory
 * listing-block stub is written once.
 *
 * @covers IWC_Bulk_Converter
 */
final class HoldingFolderTest extends BulkConverterTestCase {
    public function test_original_and_intermediate_sizes_are_moved_not_deleted(): void {
        // Give this attachment one intermediate size, so
        // collect_attachment_files() has more than just the full-size file
        // to move.
        \Brain\Monkey\Functions\when('wp_get_attachment_metadata')->justReturn([
            'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg']],
        ]);

        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $thumbPath = $this->uploadsBasedir . '/2024/05/photo-150x150.jpg';
        copy($this->attachedFiles[$id], $thumbPath);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/2024/05/photo.jpg');
        $this->assertFileDoesNotExist($thumbPath);
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo-150x150.jpg');
    }

    public function test_index_php_stub_is_written_once_at_trash_root(): void {
        $id = $this->seedRealAttachment('2024/05/a.jpg');
        \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $stubPath = $this->uploadsBasedir . '/iwc-trash/index.php';
        $this->assertFileExists($stubPath);
        $firstMtime = filemtime($stubPath);
        $firstContent = file_get_contents($stubPath);

        // A second, independent trash-worthy conversion must not rewrite
        // the existing stub.
        sleep(0); // mtime resolution guard is unnecessary on most filesystems, but keep the check content-based, not just time-based
        $id2 = $this->seedRealAttachment('2024/05/b.jpg');
        \IWC_Bulk_Converter::convert_attachment($id2, 'unreferenced', 80);

        $this->assertSame($firstContent, file_get_contents($stubPath));
        $this->assertSame($firstMtime, filemtime($stubPath));
    }

    public function test_already_missing_intermediate_file_is_silently_skipped(): void {
        // Metadata references a thumbnail that was never actually created
        // on disk (e.g. a previous failed regeneration) — the real-world
        // case move_files_to_holding_folder()'s file_exists() guard exists for.
        \Brain\Monkey\Functions\when('wp_get_attachment_metadata')->justReturn([
            'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg']],
        ]);

        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        // Deliberately do NOT create photo-150x150.jpg on disk.

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status'], 'a missing intermediate file must not block trashing the files that do exist');
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/iwc-trash/2024/05/photo-150x150.jpg');
    }
}
