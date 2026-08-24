<?php

namespace IWC\Tests\Unit;

use ReflectionMethod;

/**
 * Proves the translated strings handed to assets/bulk-convert.js and the keys
 * that script actually asks for stay in step.
 *
 * The script reads its text from a localised object and falls back to an
 * English literal when a key is missing. That fallback is deliberate — a
 * missing string should not render "undefined" at the user — but it also means
 * a typo or a rename fails silently: the interface simply stops translating,
 * looks completely normal in English, and nobody notices.
 *
 * @covers IWC_Admin
 */
final class ScriptStringsTest extends TestCase {
    /** @return array<string,string> */
    private function phpStrings(): array {
        // Reflection rather than widening the class's public surface: this is
        // internal wiring, and the test needing at it is not a reason for the
        // rest of the plugin to be able to call it.
        $method = new ReflectionMethod('IWC_Admin', 'script_strings');
        // A no-op since PHP 8.1 and deprecated in 8.5, but still required on
        // the 7.4 minimum this plugin supports — same guard shape as the
        // imagedestroy() call in the converter.
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method->invoke(null);
    }

    /** @return string[] keys the script asks for, via its t('key', …) calls. */
    private function jsKeys(): array {
        $js = file_get_contents(dirname(__DIR__, 3) . '/assets/bulk-convert.js');
        $this->assertNotFalse($js, 'could not read the script');
        preg_match_all("/\bt\(\s*'([a-zA-Z0-9_]+)'/", $js, $matches);
        return array_values(array_unique($matches[1]));
    }

    public function test_every_key_the_script_asks_for_is_supplied(): void {
        $missing = array_diff($this->jsKeys(), array_keys($this->phpStrings()));

        $this->assertSame(
            [],
            array_values($missing),
            'the script asks for strings that are never localised, so it would silently stay in English'
        );
    }

    public function test_no_localised_string_is_left_unused(): void {
        // The other direction. A leftover key is harmless at runtime but sends
        // translators to work on wording nobody will ever see.
        $unused = array_diff(array_keys($this->phpStrings()), $this->jsKeys());

        $this->assertSame([], array_values($unused), 'these strings are localised but never used by the script');
    }

    public function test_the_script_actually_asks_for_something(): void {
        // Guards the two assertions above: if the regex ever stopped matching,
        // both would compare empty sets and pass while proving nothing.
        $this->assertNotEmpty($this->jsKeys());
    }

    public function test_placeholder_counts_match_between_key_and_usage(): void {
        // A string promising two placeholders that the script fills with one
        // argument renders a literal "%2$s" at the user.
        $js = file_get_contents(dirname(__DIR__, 3) . '/assets/bulk-convert.js');
        foreach ($this->phpStrings() as $key => $text) {
            preg_match_all('/%(?:\d+\$)?s/', $text, $found);
            $expected = count($found[0]);

            // Find the fmt(t('key', 'fallback'), [args]) call for this key.
            if (!preg_match("/fmt\(\s*t\(\s*'" . preg_quote($key, '/') . "'.*?\)\s*,\s*\[([^\]]*)\]/s", $js, $call)) {
                $this->assertSame(0, $expected, "'$key' carries placeholders but is not used with fmt()");
                continue;
            }

            $args = array_filter(array_map('trim', explode(',', $call[1])));
            $this->assertCount($expected, $args, "'$key' expects $expected placeholder(s) but is given " . count($args));
        }
    }
}
