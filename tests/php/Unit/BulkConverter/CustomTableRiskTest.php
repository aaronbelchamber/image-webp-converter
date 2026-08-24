<?php

namespace IWC\Tests\Unit\BulkConverter;

use Brain\Monkey\Functions;

/**
 * Proves that when a plugin is present which keeps image URLs in its own
 * tables, the bulk converter stops trusting "nothing references this".
 *
 * The reference scan only reads posts/postmeta/options. TranslatePress keeps
 * translated markup — image URLs included — in wp_trp_dictionary_*, Slider
 * Revolution in wp_revslider_slides, MailPoet in its newsletter tables. An
 * image used only there looks unreferenced, so moving its original would
 * break the translated page or the slider while the English page stays fine.
 *
 * @covers IWC_Bulk_Converter::convert_attachment
 * @covers IWC_Compat::has_custom_table_risk
 */
final class CustomTableRiskTest extends BulkConverterTestCase {
    private function forceCustomTableRisk(bool $risk): void {
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) use ($risk) {
            return $hook === 'iwc_custom_table_risk' ? $risk : $value;
        });
    }

    public function test_originals_are_kept_when_a_custom_table_plugin_is_present(): void {
        $this->forceCustomTableRisk(true);
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('pending_cleanup', $result['status'], 'the destructive path must be withheld');
        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.jpg');
        $this->assertFileDoesNotExist($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
    }

    public function test_conversion_still_happens_only_the_cleanup_is_withheld(): void {
        $this->forceCustomTableRisk(true);
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertFileExists($this->uploadsBasedir . '/2024/05/photo.webp', 'the WEBP must still be produced');
    }

    public function test_destructive_path_is_restored_when_no_risk_is_detected(): void {
        $this->forceCustomTableRisk(false);
        $id = $this->seedRealAttachment('2024/05/photo.jpg');

        $result = \IWC_Bulk_Converter::convert_attachment($id, 'unreferenced', 80);

        $this->assertSame('trashed', $result['status']);
        $this->assertFileExists($this->uploadsBasedir . '/iwc-trash/2024/05/photo.jpg');
    }
}
