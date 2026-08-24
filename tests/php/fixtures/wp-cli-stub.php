<?php
/**
 * Global WP_CLI stand-in, delegating to FakeWpCli so tests can assert on what
 * a command emitted.
 *
 * Braced namespace syntax because this file has to declare both a global class
 * (WP_CLI, where the plugin and WP-CLI itself look for it) and a namespaced
 * function (WP_CLI\Utils\format_items).
 */

namespace {
    use IWC\Tests\fixtures\FakeWpCli;
    use IWC\Tests\fixtures\WpCliHalt;

    if (!class_exists('WP_CLI')) {
        class WP_CLI {
            public static function add_command(string $name, $callable): void {
                FakeWpCli::$commands[] = [$name, (array) $callable];
            }
            public static function log(string $message): void {
                FakeWpCli::$log[] = $message;
            }
            public static function warning(string $message): void {
                FakeWpCli::$warnings[] = $message;
            }
            public static function success(string $message): void {
                FakeWpCli::$success[] = $message;
            }
            public static function error(string $message): void {
                FakeWpCli::$errors[] = $message;
                throw new WpCliHalt($message);
            }
        }
    }
}

namespace WP_CLI\Utils {
    use IWC\Tests\fixtures\FakeWpCli;

    if (!function_exists('WP_CLI\Utils\format_items')) {
        function format_items(string $format, array $items, array $fields): void {
            FakeWpCli::$tables[] = $items;
        }
    }
}
