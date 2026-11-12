<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Process-wide cache for hot-path table and column existence checks.
 *
 * Lookup order: request memory → APCu (when loaded) → file under storage/cache
 * → live SHOW TABLES / SHOW COLUMNS.
 *
 * Positive answers stay warm for a week. Negative answers expire in a minute so
 * a migration that adds a column is picked up without a long stale "missing"
 * result. Call {@see flush()} after migrations / upgrades so new columns are
 * visible immediately on hosts without APCu.
 */
final class SchemaProbe
{
    private const APCU_PREFIX = 'sk_schema_v2_';

    private const FILE_PREFIX = 'sk_schema_';

    private const POSITIVE_TTL = 604800;

    private const NEGATIVE_TTL = 60;

    /** @var array<string, bool> */
    private static array $memory = [];

    private static ?string $schema = null;

    private static ?int $generation = null;

    public static function tableExists(mysqli $db, string $table): bool
    {
        return self::remember($db, $table, '', static function () use ($db, $table): ?bool {
            $like = self::likeLiteral($table);
            $result = $db->query("SHOW TABLES LIKE '{$like}'");
            if ($result === false) {
                return null;
            }

            return $result->num_rows > 0;
        });
    }

    public static function columnExists(mysqli $db, string $table, string $column): bool
    {
        return self::remember($db, $table, $column, static function () use ($db, $table, $column): ?bool {
            $like = self::likeLiteral($column);
            $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$like}'");
            if ($result === false) {
                return null;
            }

            return $result->num_rows > 0;
        });
    }

    public static function forget(mysqli $db, string $table, string $column): void
    {
        if (!self::isIdentifier($table) || ($column !== '' && !self::isIdentifier($column))) {
            return;
        }
        $key = self::cacheKey($db, $table, $column);
        unset(self::$memory[$key]);
        if (function_exists('apcu_delete')) {
            apcu_delete(self::apcuKey($key));
        }
        $path = self::filePath($key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Drop all remembered probes (memory, APCu generation, file entries).
     * Safe to call after every successful migration.
     */
    public static function flush(): void
    {
        self::$memory = [];
        self::$schema = null;

        $next = self::currentGeneration() + 1;
        self::$generation = $next;
        self::persistGeneration($next);
        self::clearFileEntries();

        if (class_exists(ClicksTableResolver::class)) {
            ClicksTableResolver::resetCache();
        }
    }

    /**
     * @param callable(): ?bool $query null means the probe failed and must not be cached
     */
    private static function remember(mysqli $db, string $table, string $column, callable $query): bool
    {
        if (!self::isIdentifier($table) || ($column !== '' && !self::isIdentifier($column))) {
            return false;
        }

        $key = self::cacheKey($db, $table, $column);
        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        $apcuKey = self::apcuKey($key);
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_bool($cached)) {
                return self::$memory[$key] = $cached;
            }
        }

        $fromFile = self::readFileEntry($key);
        if ($fromFile !== null) {
            self::$memory[$key] = $fromFile;
            if (function_exists('apcu_store')) {
                apcu_store($apcuKey, $fromFile, $fromFile ? self::POSITIVE_TTL : self::NEGATIVE_TTL);
            }

            return $fromFile;
        }

        $exists = $query();
        if ($exists === null) {
            return false;
        }

        self::$memory[$key] = $exists;
        $ttl = $exists ? self::POSITIVE_TTL : self::NEGATIVE_TTL;
        if (function_exists('apcu_store')) {
            apcu_store($apcuKey, $exists, $ttl);
        }
        self::writeFileEntry($key, $exists, time() + $ttl);

        return $exists;
    }

    private static function cacheKey(mysqli $db, string $table, string $column): string
    {
        return self::schemaName($db) . "\0" . $table . "\0" . $column;
    }

    private static function apcuKey(string $key): string
    {
        return self::APCU_PREFIX . self::currentGeneration() . '_' . hash('sha256', $key);
    }

    private static function schemaName(mysqli $db): string
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        if (defined('DB_NAME')) {
            $fromConst = trim((string) DB_NAME);
            if ($fromConst !== '') {
                return self::$schema = $fromConst;
            }
        }

        $result = $db->query('SELECT DATABASE()');
        $name = '';
        if ($result !== false) {
            $row = $result->fetch_row();
            $name = is_array($row) && isset($row[0]) ? (string) $row[0] : '';
        }

        return self::$schema = $name;
    }

    private static function currentGeneration(): int
    {
        if (self::$generation !== null) {
            return self::$generation;
        }

        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch(self::APCU_PREFIX . 'gen', $ok);
            if ($ok && is_int($cached) && $cached > 0) {
                return self::$generation = $cached;
            }
        }

        $path = self::generationFilePath();
        if ($path !== null && is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
                return self::$generation = max(1, (int) trim($raw));
            }
        }

        return self::$generation = 1;
    }

    private static function persistGeneration(int $generation): void
    {
        if (function_exists('apcu_store')) {
            apcu_store(self::APCU_PREFIX . 'gen', $generation, 0);
        }
        $path = self::generationFilePath();
        if ($path !== null) {
            @file_put_contents($path, (string) $generation, LOCK_EX);
        }
    }

    private static function readFileEntry(string $key): ?bool
    {
        $path = self::filePath($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw);
        if (!is_array($entry) || !isset($entry['exists'], $entry['expires_at'])) {
            return null;
        }
        if ((int) $entry['expires_at'] < time()) {
            @unlink($path);

            return null;
        }

        return (bool) $entry['exists'];
    }

    private static function writeFileEntry(string $key, bool $exists, int $expiresAt): void
    {
        $path = self::filePath($key);
        if ($path === null) {
            return;
        }
        @file_put_contents(
            $path,
            serialize(['exists' => $exists, 'expires_at' => $expiresAt]),
            LOCK_EX
        );
    }

    private static function clearFileEntries(): void
    {
        $dir = self::cacheDir();
        if ($dir === null) {
            return;
        }
        foreach (glob($dir . '/' . self::FILE_PREFIX . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function filePath(string $key): ?string
    {
        $dir = self::cacheDir();
        if ($dir === null) {
            return null;
        }

        return $dir . '/' . self::FILE_PREFIX . hash('sha256', $key) . '.cache';
    }

    private static function generationFilePath(): ?string
    {
        $dir = self::cacheDir();
        if ($dir === null) {
            return null;
        }

        return $dir . '/' . self::FILE_PREFIX . 'generation.txt';
    }

    private static function cacheDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) {
            return $dir;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/storage/cache',
            sys_get_temp_dir() . '/simplekuma-schema-cache',
        ];
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                $dir = $candidate;

                return $dir;
            }
        }
        $dir = null;

        return null;
    }

    private static function isIdentifier(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    private static function likeLiteral(string $identifier): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $identifier);
    }
}
