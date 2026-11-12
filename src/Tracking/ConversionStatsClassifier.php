<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Decides which conversion event_key values count toward Conversions / CR / revenue.
 *
 * Opt-ins stay on ConversionOptInClassifier (separate Opt-ins KPI).
 * Empty allowlist = legacy: every non-opt-in event counts (including NULL/missing key).
 * Non-empty allowlist = only listed canonical keys count; others are stored but ignored by KPIs.
 */
final class ConversionStatsClassifier
{
    public const SETTING_KEY = 'stats_conversion_event_keys';

    /** Suggested defaults shown in Settings (not applied until saved). */
    public const SUGGESTED_KEYS = [
        'purchase',
        'sale',
        'ftd',
        'complete_payment',
        'lead_sale',
    ];

    /** @var list<string>|null */
    private static ?array $allowlist = null;

    private static bool $loaded = false;

    public static function clearCache(): void
    {
        self::$allowlist = null;
        self::$loaded = false;
    }

    /**
     * @return list<string> Empty = legacy (all non-opt-in events count)
     */
    public static function allowlist(?mysqli $db = null): array
    {
        if (self::$loaded) {
            return self::$allowlist ?? [];
        }
        if ($db === null) {
            return [];
        }

        $raw = (new SettingsManager($db))->get(self::SETTING_KEY, '');
        self::$allowlist = self::parseStored($raw);
        self::$loaded = true;

        return self::$allowlist;
    }

    /**
     * @return list<string>
     */
    public static function parseStored(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return self::canonicalizeList($raw);
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return [];
        }
        if ($str[0] === '[') {
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                return self::canonicalizeList($decoded);
            }
        }

        return self::parseFromTextarea($str);
    }

    /**
     * @return list<string>
     */
    public static function parseFromTextarea(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', $text) ?: [];

        return self::canonicalizeList($parts);
    }

    /**
     * @param list<mixed> $items
     * @return list<string>
     */
    public static function canonicalizeList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = ConversionEventKey::canonicalize(is_string($item) ? $item : (string) $item);
            if ($key === null) {
                continue;
            }
            // Opt-in keys never belong on the conversion allowlist.
            if (ConversionOptInClassifier::isOptIn($key)) {
                continue;
            }
            if (!in_array($key, $out, true)) {
                $out[] = $key;
            }
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     */
    public static function encodeForStorage(array $keys): string
    {
        $keys = self::canonicalizeList($keys);

        return $keys === [] ? '' : (string) json_encode(array_values($keys), JSON_UNESCAPED_SLASHES);
    }

    public static function countsAsConversion(?string $eventKey, ?mysqli $db = null): bool
    {
        if (ConversionOptInClassifier::isOptIn($eventKey)) {
            return false;
        }
        $list = self::allowlist($db);
        if ($list === []) {
            return true;
        }
        $canon = ConversionEventKey::canonicalize($eventKey);

        return $canon !== null && in_array($canon, $list, true);
    }

    /**
     * SQL CASE → 1 when the row should increment Conversions / CR.
     */
    public static function conversionCountCaseSql(string $eventKeyExpr = 'cv.event_key', ?mysqli $db = null): string
    {
        $optInList = ConversionOptInClassifier::sqlInList();
        $list = self::allowlist($db);
        if ($list === []) {
            return "CASE WHEN {$eventKeyExpr} IS NULL OR LOWER(COALESCE({$eventKeyExpr}, '')) NOT IN ({$optInList}) THEN 1 ELSE 0 END";
        }
        $in = self::sqlQuoteList($list);

        return "CASE WHEN LOWER(COALESCE({$eventKeyExpr}, '')) IN ({$in}) THEN 1 ELSE 0 END";
    }

    /**
     * SQL CASE → revenue contribution for conversion-counted rows only.
     */
    public static function conversionRevenueCaseSql(
        string $eventKeyExpr = 'cv.event_key',
        string $amountExpr = 'COALESCE(cv.payout, cv.value)',
        ?mysqli $db = null
    ): string {
        $optInList = ConversionOptInClassifier::sqlInList();
        $list = self::allowlist($db);
        if ($list === []) {
            return "CASE WHEN {$eventKeyExpr} IS NULL OR LOWER(COALESCE({$eventKeyExpr}, '')) NOT IN ({$optInList}) THEN {$amountExpr} ELSE 0 END";
        }
        $in = self::sqlQuoteList($list);

        return "CASE WHEN LOWER(COALESCE({$eventKeyExpr}, '')) IN ({$in}) THEN {$amountExpr} ELSE 0 END";
    }

    public static function optinCountCaseSql(string $eventKeyExpr = 'cv.event_key'): string
    {
        $optInList = ConversionOptInClassifier::sqlInList();

        return "CASE WHEN LOWER(COALESCE({$eventKeyExpr}, '')) IN ({$optInList}) THEN 1 ELSE 0 END";
    }

    /**
     * WHERE fragment: rows that count toward Conversions (for Dashboard raw paths).
     */
    public static function conversionWhereSql(string $eventKeyExpr = 'cv.event_key', ?mysqli $db = null): string
    {
        $optInList = ConversionOptInClassifier::sqlInList();
        $list = self::allowlist($db);
        if ($list === []) {
            return "({$eventKeyExpr} IS NULL OR LOWER(COALESCE({$eventKeyExpr}, '')) NOT IN ({$optInList}))";
        }
        $in = self::sqlQuoteList($list);

        return "(LOWER(COALESCE({$eventKeyExpr}, '')) IN ({$in}))";
    }

    /**
     * @param list<string> $keys
     */
    private static function sqlQuoteList(array $keys): string
    {
        $quoted = array_map(
            static fn (string $k): string => "'" . str_replace("'", "''", $k) . "'",
            $keys
        );

        return implode(',', $quoted);
    }
}
