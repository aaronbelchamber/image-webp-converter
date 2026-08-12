<?php
/**
 * PHPUnit bootstrap for the Image WebP Converter test suite.
 *
 * No WordPress install is used. Brain Monkey mocks the handful of WP core
 * functions individual tests need dynamic control over (see each test's
 * setUp()); a small set of *pure, deterministic* WP helper functions that
 * every test path touches are given real, permanent implementations here
 * instead of being re-mocked per test — they have one correct behavior and
 * mocking them repeatedly would just be boilerplate, not a meaningful test
 * seam.
 */

// Points at a fixture directory (not the plugin root) containing a stub
// wp-admin/includes/image.php, since class-iwc-bulk-converter.php does a
// real require_once for that path before calling the (Brain Monkey mocked)
// wp_generate_attachment_metadata().
define('ABSPATH', __DIR__ . '/fixtures/wp-stub/');

// wpdb::get_results()'s $output_type constants.
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// --- Real, deterministic WP helper implementations (not mocked per test) ---

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default: return $num;
        }
    }
}

if (!function_exists('is_serialized')) {
    // Ported from WordPress core (wp-includes/functions.php) verbatim logic,
    // since class-iwc-bulk-converter.php's serialized-reference safety check
    // depends on real serialization-detection behavior, not a stub.
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if (':' !== $data[1]) {
            return false;
        }
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) {
                return false;
            }
        } else {
            $semicolon = strpos($data, ';');
            $brace = strpos($data, '}');
            if (false === $semicolon && false === $brace) {
                return false;
            }
            if (false !== $semicolon && $semicolon < 3) {
                return false;
            }
            if (false !== $brace && $brace < 4) {
                return false;
            }
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict) {
                    if ('"' !== substr($data, -2, 1)) {
                        return false;
                    }
                } elseif (false === strpos($data, '"')) {
                    return false;
                }
                // fall through
            case 'a':
            case 'O':
            case 'E':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
        }
        return false;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0777, true);
    }
}

// --- Load the real plugin source under test ---
require_once dirname(__DIR__, 2) . '/src/convert-images-to-webp.php';
require_once dirname(__DIR__, 2) . '/src/class-iwc-logger.php';
require_once dirname(__DIR__, 2) . '/src/class-iwc-bulk-converter.php';
