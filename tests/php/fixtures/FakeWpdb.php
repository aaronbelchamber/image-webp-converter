<?php

namespace IWC\Tests\fixtures;

use PDO;

/**
 * A minimal, SQLite-backed stand-in for WordPress's $wpdb, implementing only
 * the surface the plugin's bulk-converter and logger actually call:
 * prepare(), get_col(), get_var(), get_results(), insert(), update(),
 * esc_like(), plus the public properties used to build table names
 * ({$wpdb->posts}, {$wpdb->postmeta}, {$wpdb->options}, ->prefix).
 *
 * Deliberately backed by real SQL execution (not mocked return values): the
 * bucketing/re-verify logic under test decides what gets moved to disk, so
 * proving the actual LIKE/IN/prepare() queries are correct is the point —
 * a mock would only prove "the code called what we told it to," not that
 * the SQL itself is right.
 */
class FakeWpdb {
    public string $prefix = 'wp_';
    public string $posts;
    public string $postmeta;
    public string $options;
    public int $insert_id = 0;

    private PDO $pdo;

    public function __construct() {
        $this->posts = $this->prefix . 'posts';
        $this->postmeta = $this->prefix . 'postmeta';
        $this->options = $this->prefix . 'options';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->create_schema();
    }

    private function create_schema(): void {
        $this->pdo->exec("CREATE TABLE {$this->posts} (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_content TEXT NOT NULL DEFAULT '',
            post_title TEXT NOT NULL DEFAULT '',
            post_status TEXT NOT NULL DEFAULT 'publish',
            post_type TEXT NOT NULL DEFAULT 'post',
            post_mime_type TEXT NOT NULL DEFAULT ''
        )");
        $this->pdo->exec("CREATE TABLE {$this->postmeta} (
            meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            meta_key TEXT NOT NULL,
            meta_value TEXT
        )");
        $this->pdo->exec("CREATE TABLE {$this->options} (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL,
            option_value TEXT
        )");
        $this->pdo->exec("CREATE TABLE {$this->prefix}iwc_conversion_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER NOT NULL,
            original_path TEXT NOT NULL,
            old_files_json TEXT,
            new_path TEXT,
            bucket TEXT NOT NULL,
            status TEXT NOT NULL,
            bytes_before INTEGER DEFAULT 0,
            bytes_after INTEGER DEFAULT 0,
            references_updated TEXT,
            message TEXT,
            started_at INTEGER NOT NULL,
            completed_at INTEGER
        )");
    }

    /** Direct access for test setUp() seeding — bypasses prepare()/escaping since the test supplies trusted literals. */
    public function seed(string $sql): void {
        $this->pdo->exec($sql);
    }

    public function seed_prepared(string $sql, array $params): void {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Mimics wpdb::prepare(): %s/%d placeholders, positional, sprintf-style.
     * Accepts either prepare($sql, $a, $b, ...) or prepare($sql, [$a, $b]).
     */
    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $result = preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $value = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            return $this->pdo->quote((string) $value);
        }, $query);
        return $result;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function get_col(string $query): array {
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function get_var(string $query) {
        $stmt = $this->pdo->query($query);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public function get_results(string $query, string $output = 'OBJECT') {
        $mode = $output === 'ARRAY_A' ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ;
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll($mode);
    }

    public function insert(string $table, array $data, array $formats = []): bool {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return true;
    }

    public function update(string $table, array $data, array $where, array $formats = [], array $where_formats = []): bool {
        $set = implode(', ', array_map(fn($col) => "$col = ?", array_keys($data)));
        $whereSql = implode(' AND ', array_map(fn($col) => "$col = ?", array_keys($where)));
        $sql = "UPDATE $table SET $set WHERE $whereSql";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($data), ...array_values($where)]);
        return true;
    }

    public function get_charset_collate(): string {
        return '';
    }
}
