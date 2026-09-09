<?php
declare(strict_types=1);

namespace MeNews;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * SQLite connection and lightweight query helpers.
 *
 * The database lives in STORAGE/data/menews.sqlite and is created by scripts/setup.php.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function path(): string
    {
        return Config::storage() . '/data/menews.sqlite';
    }

    public static function installed(): bool
    {
        return is_file(self::path());
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!self::installed()) {
            throw new RuntimeException('Database is not installed. Run: php scripts/setup.php');
        }
        self::$pdo = self::open(self::path());
        return self::$pdo;
    }

    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        return $pdo;
    }

    /** Create the schema from database/schema.sql (idempotent). */
    public static function migrate(?PDO $pdo = null): void
    {
        $pdo ??= self::pdo();
        $pdo->exec(file_get_contents(ME_ROOT . '/database/schema.sql'));
    }

    public static function query(string $sql, array $args = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $row = self::query($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $args = []): array
    {
        return self::query($sql, $args)->fetchAll();
    }

    public static function value(string $sql, array $args = []): mixed
    {
        return self::query($sql, $args)->fetchColumn();
    }

    public static function count(string $sql, array $args = []): int
    {
        return (int)self::value($sql, $args);
    }

    public static function insert(string $table, array $row): void
    {
        $cols = implode(',', array_keys($row));
        $marks = implode(',', array_fill(0, count($row), '?'));
        self::query("INSERT INTO {$table} ({$cols}) VALUES ({$marks})", array_values($row));
    }

    public static function update(string $table, array $row, string $where, array $whereArgs = []): void
    {
        $set = implode(',', array_map(static fn(string $c) => "{$c}=?", array_keys($row)));
        self::query("UPDATE {$table} SET {$set} WHERE {$where}", [...array_values($row), ...$whereArgs]);
    }

    /** Run a callback inside an immediate transaction. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $v = self::value('SELECT value FROM settings WHERE key=?', [$key]);
        return $v === false ? $default : (string)$v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::query('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
    }
}
