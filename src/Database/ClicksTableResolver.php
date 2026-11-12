<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Resolves whether reporting/conversion lookups use clicks vs clicks_unified / archive.
 */
class ClicksTableResolver
{
    private static ?string $statsTable = null;

    private static ?bool $archiveExists = null;

    private static ?bool $archiveHasRows = null;

    /**
     * Drop request-local resolver decisions (called from SchemaProbe::flush).
     */
    public static function resetCache(): void
    {
        self::$statsTable = null;
        self::$archiveExists = null;
        self::$archiveHasRows = null;
    }

    /**
     * Table or view for aggregate stats queries (includes archived clicks when unified view is current).
     *
     * Prefer the base `clicks` table when the archive is empty: `clicks_unified` is a
     * UNION ALL view and is orders of magnitude slower for the same filters.
     */
    public static function getStatsTable(mysqli $db): string
    {
        if (self::$statsTable !== null) {
            return self::$statsTable;
        }

        if (!self::viewExists($db) || !self::viewHasColumn($db, 'ad_id')) {
            self::$statsTable = 'clicks';
            return self::$statsTable;
        }

        if (!self::archiveTableExists($db) || !self::archiveHasRows($db)) {
            self::$statsTable = 'clicks';
            return self::$statsTable;
        }

        self::$statsTable = 'clicks_unified';
        return self::$statsTable;
    }

    /**
     * True when clicks_archive has at least one row (worth paying for the unified view).
     */
    public static function archiveHasRows(mysqli $db): bool
    {
        if (self::$archiveHasRows !== null) {
            return self::$archiveHasRows;
        }
        if (!self::archiveTableExists($db)) {
            return self::$archiveHasRows = false;
        }
        $result = $db->query('SELECT 1 FROM clicks_archive LIMIT 1');
        return self::$archiveHasRows = ($result !== false && $result->num_rows > 0);
    }

    /**
     * Tables to search for a click_id (active first, then archive).
     *
     * @return list<string>
     */
    public static function getClickLookupTables(mysqli $db): array
    {
        $tables = ['clicks'];
        if (self::archiveTableExists($db)) {
            $tables[] = 'clicks_archive';
        }
        return $tables;
    }

    public static function viewExists(mysqli $db): bool
    {
        // SHOW TABLES includes views; SchemaProbe caches across requests via APCu/file.
        return SchemaProbe::tableExists($db, 'clicks_unified');
    }

    public static function archiveTableExists(mysqli $db): bool
    {
        if (self::$archiveExists !== null) {
            return self::$archiveExists;
        }
        return self::$archiveExists = SchemaProbe::tableExists($db, 'clicks_archive');
    }

    private static function viewHasColumn(mysqli $db, string $column): bool
    {
        return SchemaProbe::columnExists($db, 'clicks_unified', $column);
    }
}
