<?php

namespace IWC\Tests\Unit;

/**
 * Proves environment detection reports only what is actually present.
 *
 * Detection is by constant/class rather than is_plugin_active() so it needs
 * no extra include and also catches must-use and theme-bundled copies.
 *
 * @covers IWC_Compat
 */
final class CompatDetectionTest extends TestCase {
    public function test_absent_plugins_are_not_reported(): void {
        // Asserting specific absences rather than an empty array: the tests
        // below declare stand-in classes, and PHP has no way to undeclare
        // them, so an emptiness assertion here would depend on test order.
        $this->assertNotContains('WPML', \IWC_Compat::custom_table_plugins());
        $this->assertNotContains('Bricks', \IWC_Compat::page_builders());
        $this->assertNotContains('ShortPixel', \IWC_Compat::conflicting_optimizers());
    }

    public function test_a_page_builder_is_detected_by_its_class(): void {
        // Elementor's signature class, declared here to stand in for the real
        // plugin being loaded.
        if (!class_exists('Elementor\Plugin')) {
            eval('namespace Elementor; class Plugin {}');
        }

        $this->assertContains('Elementor', \IWC_Compat::page_builders());
    }

    public function test_a_custom_table_plugin_is_detected_and_raises_the_risk_flag(): void {
        if (!class_exists('RevSliderFront')) {
            eval('class RevSliderFront {}');
        }

        $this->assertContains('Slider Revolution', \IWC_Compat::custom_table_plugins());
        $this->assertTrue(\IWC_Compat::has_custom_table_risk());
    }
}
