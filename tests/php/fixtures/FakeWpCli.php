<?php

namespace IWC\Tests\fixtures;

/**
 * Records what the CLI commands emit, standing in for WP-CLI's static API.
 *
 * WP-CLI isn't loadable in a plain PHPUnit run, so without this the command
 * class could only ever be syntax-checked. It's thin glue, but the parts worth
 * proving — that --dry-run changes nothing, that --limit is honoured, that the
 * lock is taken and released, that a bad --bucket is refused — are exactly the
 * parts that would otherwise ship unexercised.
 *
 * error() throws, mirroring WP-CLI's real halting behaviour, so a test can
 * tell "refused" apart from "carried on regardless".
 */
class FakeWpCli {
    /** @var string[] */
    public static array $log = [];
    /** @var string[] */
    public static array $warnings = [];
    /** @var string[] */
    public static array $success = [];
    /** @var string[] */
    public static array $errors = [];
    /** @var array<int,array{0:string,1:array}> */
    public static array $commands = [];
    /** @var array<int,array> */
    public static array $tables = [];

    public static function reset(): void {
        self::$log = [];
        self::$warnings = [];
        self::$success = [];
        self::$errors = [];
        self::$commands = [];
        self::$tables = [];
    }

    /** Everything written to any channel, for coarse "was this mentioned" checks. */
    public static function allOutput(): string {
        return implode("\n", array_merge(self::$log, self::$warnings, self::$success, self::$errors));
    }
}

/** Thrown by WP_CLI::error() so a halting command doesn't fall through. */
class WpCliHalt extends \RuntimeException {}
