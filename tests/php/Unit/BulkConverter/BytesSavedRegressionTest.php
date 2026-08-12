<?php

namespace IWC\Tests\Unit\BulkConverter;

/**
 * Regression test for a real bug found during this test suite's authoring:
 * convert_attachment()'s 'trashed' return branch previously hardcoded
 * `bytes_saved => 0` even though the DB log row correctly recorded the
 * real bytes_before/bytes_after — a webp-savings number silently wrong in
 * anything that surfaces this return value, undermining exactly the kind
 * of trust this test suite exists to build. Fixed in
 * class-iwc-bulk-converter.php's convert_attachment().
 *
 * @covers IWC_Bulk_Converter::convert_attachment
 */
final class BytesSavedRegressionTest extends BulkConverterTestCase {
    public function test_convert_attachment_returns_the_real_nonzero_bytes_saved(): void {
        $id = $this->seedRealAttachment('2024/05/photo.jpg');
        $originalPath = $this->attachedFiles[$id];
        $bytesBefore = filesize($originalPath);

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);

        $webpPath = $this->uploadsBasedir . '/2024/05/photo.webp';
        $this->assertFileExists($webpPath);
        $bytesAfter = filesize($webpPath);
        $expectedSaved = max(0, $bytesBefore - $bytesAfter);

        $this->assertArrayHasKey('bytes_saved', $result);
        $this->assertSame($expectedSaved, $result['bytes_saved'], 'the returned bytes_saved must reflect the real conversion, not a hardcoded 0');

        // Separately assert the fix didn't regress the already-correct DB
        // persistence path (IWC_Logger::log_result() writes the real
        // bytes_before/bytes_after regardless of this bug).
        $row = $this->wpdb->get_results("SELECT * FROM wp_iwc_conversion_log ORDER BY id DESC LIMIT 1")[0];
        $this->assertSame($bytesBefore, (int) $row->bytes_before);
        $this->assertSame($bytesAfter, (int) $row->bytes_after);
    }

}
