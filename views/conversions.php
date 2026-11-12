<?php
// Conversion Log Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Stats/ConversionsQueryService.php';

use SimpleKuma\Stats\ConversionsQueryService;
use SimpleKuma\Tracking\ConversionOptInClassifier;
use SimpleKuma\Tracking\ConversionPostbackParams;
use SimpleKuma\Utils\Formatter;

function renderClickLookupLink(string $clickId): string
{
    $url = APP_BASE_URL . '/index.php?page=click-lookup&click_id=' . rawurlencode($clickId);
    $icon = ASSETS_BASE_URL . '/assets/images/clicklookbear.png';
    return sprintf(
        '<a href="%s" class="click-lookup-link" title="Look up this click" aria-label="Look up click %s">'
        . '<img src="%s" alt="" width="22" height="22">'
        . '</a>',
        htmlspecialchars($url),
        htmlspecialchars($clickId),
        htmlspecialchars($icon)
    );
}

function renderConversionStatusBadge(string $status): string
{
    $class = 'badge';
    if ($status === 'approved') {
        $class .= ' badge-success';
    } elseif ($status === 'rejected') {
        $class .= ' badge-danger';
    } else {
        $class .= ' badge-warning';
    }

    return '<span class="' . htmlspecialchars($class) . '">' . htmlspecialchars($status) . '</span>';
}

function renderConversionEventCell(?string $eventKey): string
{
    if (ConversionOptInClassifier::isOptIn($eventKey)) {
        $key = htmlspecialchars((string) $eventKey);
        return '<span class="badge badge-optin" title="Counted as Opt-in (not a purchase conversion)">Opt-in</span>'
            . ' <span class="conversion-mono conversion-event-key">' . $key . '</span>';
    }

    if ($eventKey === null || $eventKey === '') {
        return '<span class="conversion-mono">-</span>';
    }

    return '<span class="conversion-mono">' . htmlspecialchars($eventKey) . '</span>';
}

$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = isset($_GET['per_page_limit']) ? (int) $_GET['per_page_limit'] : 50;
$perPage = in_array($perPage, [50, 100, 200, 500], true) ? $perPage : 50;

$campaignFilter = isset($_GET['campaign']) && $_GET['campaign'] !== '' ? (int) $_GET['campaign'] : null;
if ($campaignFilter === 0) {
    $campaignFilter = null;
}

$eventTypeRaw = isset($_GET['event_type']) ? (string) $_GET['event_type'] : 'all';
$parsedEventType = ConversionsQueryService::parseEventTypeParam($eventTypeRaw);
$eventTypeFilter = $parsedEventType['event_type'];
$eventKeyExact = $parsedEventType['event_key'];
$eventTypeSelectValue = $eventKeyExact !== null
    ? ('ek:' . $eventKeyExact)
    : $eventTypeFilter;

$extraFilters = ConversionPostbackParams::parseLogFilters($_GET);

$viewMode = isset($_GET['view']) ? (string) $_GET['view'] : 'log';
if (!in_array($viewMode, ['log', 'cohort'], true)) {
    $viewMode = 'log';
}

$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $dateFrom = $todayInUserTz;
    $dateTo = $todayInUserTz;
} else {
    $dateFrom = $_GET['date_from'] ?? $todayInUserTz;
    $dateTo = $_GET['date_to'] ?? $todayInUserTz;
}

$service = new ConversionsQueryService($db);

$availableEventKeys = $service->listDistinctEventKeys(
    $campaignFilter,
    $dateFrom,
    $dateTo,
    $userTimezone
);
// Keep a selected exact key visible even if it has no rows in the current window.
if ($eventKeyExact !== null && !in_array($eventKeyExact, $availableEventKeys, true)) {
    $availableEventKeys[] = $eventKeyExact;
    sort($availableEventKeys);
}

$conversions = [];
$cohortRows = [];
$clickTokenKeys = [];
$cohortTruncated = false;
$cohortMaxRows = 500;
$totalRows = 0;
$totalRevenue = 0.0;
$totalPages = 1;

if ($viewMode === 'cohort') {
    $cohort = $service->listConversionCohort(
        $campaignFilter,
        $dateFrom,
        $dateTo,
        $userTimezone,
        $eventTypeFilter,
        $extraFilters,
        $cohortMaxRows,
        $eventKeyExact
    );
    $cohortRows = $cohort['rows'];
    $conversions = $cohortRows; // for shared visible-extra detection
    $totalRows = $cohort['total'];
    $totalRevenue = $cohort['total_revenue'];
    $cohortTruncated = $cohort['truncated'];
    $cohortMaxRows = $cohort['max_rows'];
    $clickTokenKeys = $cohort['click_token_keys'];
    $totalPages = 1;
} else {
    $result = $service->listConversionsForLog(
        $campaignFilter,
        $dateFrom,
        $dateTo,
        $userTimezone,
        $pageNum,
        $perPage,
        null,
        null,
        $eventTypeFilter,
        $extraFilters,
        $eventKeyExact
    );
    $conversions = $result['rows'];
    $totalRows = $result['total'];
    $totalRevenue = $result['total_revenue'];
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
}

/** @var list<array{key: string, get: string, label: string}> $extraColumnDefs */
$extraColumnDefs = [];
for ($i = 1; $i <= 10; $i++) {
    $extraColumnDefs[] = ['key' => 'sub' . $i, 'get' => 'sub' . $i, 'label' => 'Sub' . $i];
}
$extraColumnDefs[] = ['key' => 'offer_name', 'get' => 'pb_offer_name', 'label' => 'offer_name'];
$extraColumnDefs[] = ['key' => 'offer_id', 'get' => 'pb_offer_id', 'label' => 'offer_id'];
$extraColumnDefs[] = ['key' => 'lander_id', 'get' => 'lander_id', 'label' => 'lander_id'];

$visibleExtraCols = [];
foreach ($extraColumnDefs as $def) {
    $filterActive = isset($extraFilters[$def['key']]);
    $hasData = ConversionsQueryService::rowsHaveExtra($conversions, $def['key']);
    if ($filterActive || $hasData) {
        $visibleExtraCols[] = $def;
    }
}

function buildConversionViewUrl(string $view, array $currentParams): string
{
    $params = $currentParams;
    $params['page'] = 'conversions';
    $params['view'] = $view;
    if ($view === 'cohort') {
        unset($params['p']);
    }
    return '?' . http_build_query($params);
}

$campaigns = $db->query('SELECT id, name FROM campaigns ORDER BY name')->fetch_all(MYSQLI_ASSOC);

if (!isset($GLOBALS['db'])) {
    $db->close();
}

function buildConversionPaginationUrl(int $pageNumber, array $currentParams): string
{
    $params = array_diff_key($currentParams, ['p' => '', 'page' => '']);
    $params['page'] = 'conversions';
    $params['view'] = 'log';
    $params['p'] = $pageNumber;
    return '?' . http_build_query($params);
}

function generateConversionPagination(int $currentPage, int $totalPages, array $getParams): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">';

    if ($currentPage > 1) {
        $firstUrl = buildConversionPaginationUrl(1, $getParams);
        $html .= '<a href="' . htmlspecialchars($firstUrl) . '" class="btn btn-outline" style="min-width: 80px;">First</a>';
    }

    if ($currentPage > 1) {
        $prevUrl = buildConversionPaginationUrl($currentPage - 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($prevUrl) . '" class="btn btn-outline">Previous</a>';
    }

    $html .= '<span style="padding: 8px 16px; color: #666; font-weight: 500;">Page '
        . $currentPage . ' of ' . $totalPages . '</span>';

    if ($currentPage < $totalPages) {
        $nextUrl = buildConversionPaginationUrl($currentPage + 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($nextUrl) . '" class="btn btn-outline">Next</a>';
    }

    $html .= '</div>';

    return $html;
}

$activePreset = null;
try {
    $tz = new DateTimeZone($userTimezone);
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    $yesterdayDt = clone $now;
    $yesterdayDt->modify('-1 day');
    $yesterday = $yesterdayDt->format('Y-m-d');

    $last7Dt = clone $now;
    $last7Dt->modify('-6 days');
    $last7Start = $last7Dt->format('Y-m-d');

    $last14Dt = clone $now;
    $last14Dt->modify('-13 days');
    $last14Start = $last14Dt->format('Y-m-d');

    $last30Dt = clone $now;
    $last30Dt->modify('-29 days');
    $last30Start = $last30Dt->format('Y-m-d');

    $lastMonthStartDt = clone $now;
    $lastMonthStartDt->modify('first day of last month');
    $lastMonthStart = $lastMonthStartDt->format('Y-m-d');

    $lastMonthEndDt = clone $now;
    $lastMonthEndDt->modify('last day of last month');
    $lastMonthEnd = $lastMonthEndDt->format('Y-m-d');

    $thisMonthStartDt = clone $now;
    $thisMonthStartDt->modify('first day of this month');
    $thisMonthStart = $thisMonthStartDt->format('Y-m-d');

    $allTimeStart = '2025-01-01';
} catch (Exception $e) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $last7Start = date('Y-m-d', strtotime('-6 days'));
    $last14Start = date('Y-m-d', strtotime('-13 days'));
    $last30Start = date('Y-m-d', strtotime('-29 days'));
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd = date('Y-m-t', strtotime('last month'));
    $thisMonthStart = date('Y-m-01');
    $allTimeStart = '2025-01-01';
}

if ($dateFrom === $today && $dateTo === $today) {
    $activePreset = 'today';
} elseif ($dateFrom === $yesterday && $dateTo === $yesterday) {
    $activePreset = 'yesterday';
} elseif ($dateFrom === $last7Start && $dateTo === $today) {
    $activePreset = 'last7';
} elseif ($dateFrom === $last14Start && $dateTo === $today) {
    $activePreset = 'last14';
} elseif ($dateFrom === $last30Start && $dateTo === $today) {
    $activePreset = 'last30';
} elseif ($dateFrom === $lastMonthStart && $dateTo === $lastMonthEnd) {
    $activePreset = 'lastmonth';
} elseif ($dateFrom === $thisMonthStart && $dateTo === $today) {
    $activePreset = 'thismonth';
} elseif ($dateFrom === $allTimeStart && $dateTo === $today) {
    $activePreset = 'alltime';
}

$exportParams = $_GET;
unset($exportParams['export'], $exportParams['p']);
$exportParams['page'] = 'conversions';
$exportParams['view'] = 'log';
$exportParams['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportParams);

$logTabUrl = buildConversionViewUrl('log', $_GET);
$cohortTabUrl = buildConversionViewUrl('cohort', $_GET);
?>

<div class="page-header">
    <h1 class="page-title">Conversion Log</h1>
    <p class="page-description">Spreadsheet-style view of all conversions with full attribution details. Opt-ins show a teal badge and can be filtered separately.</p>
</div>

<div class="conversion-view-tabs" style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center;">
    <a href="<?= htmlspecialchars($logTabUrl) ?>"
       class="btn <?= $viewMode === 'log' ? 'btn-primary' : 'btn-outline' ?>"
       style="text-decoration: none;">Conversion log</a>
    <a href="<?= htmlspecialchars($cohortTabUrl) ?>"
       class="btn <?= $viewMode === 'cohort' ? 'btn-primary' : 'btn-outline' ?>"
       style="text-decoration: none;"
       title="Same conversions, plus device and tracking details from each click">With click details</a>
</div>

<div class="card conversion-log-filters" style="margin-bottom: 24px;">
    <div class="card-body">
        <div class="visitor-date-presets conversion-date-presets" style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;">
            <button type="button" onclick="setConversionDate('today')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'today' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'today' ? '#fff' : '#666' ?>; cursor: pointer;">Today</button>
            <button type="button" onclick="setConversionDate('yesterday')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'yesterday' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'yesterday' ? '#fff' : '#666' ?>; cursor: pointer;">Yesterday</button>
            <button type="button" onclick="setConversionDate('last7')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last7' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'last7' ? '#fff' : '#666' ?>; cursor: pointer;">7d</button>
            <button type="button" onclick="setConversionDate('last14')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last14' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'last14' ? '#fff' : '#666' ?>; cursor: pointer;">14d</button>
            <button type="button" onclick="setConversionDate('last30')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last30' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'last30' ? '#fff' : '#666' ?>; cursor: pointer;">30d</button>
            <button type="button" onclick="setConversionDate('lastmonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'lastmonth' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'lastmonth' ? '#fff' : '#666' ?>; cursor: pointer;">Last Mo</button>
            <button type="button" onclick="setConversionDate('thismonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'thismonth' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'thismonth' ? '#fff' : '#666' ?>; cursor: pointer;">This Mo</button>
            <button type="button" onclick="setConversionDate('alltime')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'alltime' ? 'var(--accent)' : '#fff' ?>; color: <?= $activePreset === 'alltime' ? '#fff' : '#666' ?>; cursor: pointer;">ALL TIME</button>
        </div>

        <form method="get" action="" id="conversion-date-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
            <input type="hidden" name="page" value="conversions">
            <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date From</label>
                <input type="date" name="date_from" id="conversion_date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date To</label>
                <input type="date" name="date_to" id="conversion_date_to" value="<?= htmlspecialchars($dateTo) ?>"
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Campaign</label>
                <select name="campaign" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $camp): ?>
                        <option value="<?= (int) $camp['id'] ?>" <?= $campaignFilter === (int) $camp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($camp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Event type</label>
                <select name="event_type" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="all" <?= $eventTypeSelectValue === 'all' ? 'selected' : '' ?>>All events</option>
                    <option value="optins" <?= $eventTypeSelectValue === 'optins' ? 'selected' : '' ?>>Opt-ins only</option>
                    <option value="conversions" <?= $eventTypeSelectValue === 'conversions' ? 'selected' : '' ?>>Conversions only</option>
                    <?php if ($availableEventKeys !== []): ?>
                    <optgroup label="Specific event">
                        <?php foreach ($availableEventKeys as $ek): ?>
                            <?php $ekVal = 'ek:' . $ek; ?>
                            <option value="<?= htmlspecialchars($ekVal) ?>" <?= $eventTypeSelectValue === $ekVal ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ek) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Per Page</label>
                <select name="per_page_limit" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $perPage === 500 ? 'selected' : '' ?>>500</option>
                </select>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Sub1</label>
                <input type="text" name="sub1" value="<?= htmlspecialchars($extraFilters['sub1'] ?? '') ?>"
                       placeholder="Filter sub1" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Sub2</label>
                <input type="text" name="sub2" value="<?= htmlspecialchars($extraFilters['sub2'] ?? '') ?>"
                       placeholder="Filter sub2" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">offer_name</label>
                <input type="text" name="pb_offer_name" value="<?= htmlspecialchars($extraFilters['offer_name'] ?? '') ?>"
                       placeholder="Postback offer_name" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">offer_id</label>
                <input type="text" name="pb_offer_id" value="<?= htmlspecialchars($extraFilters['offer_id'] ?? '') ?>"
                       placeholder="Postback offer_id" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">lander_id</label>
                <input type="text" name="lander_id" value="<?= htmlspecialchars($extraFilters['lander_id'] ?? '') ?>"
                       placeholder="Postback lander_id" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>

            <button type="submit" class="btn btn-primary">Apply Filters</button>
        </form>
        <details style="margin-top: 12px;">
            <summary style="cursor: pointer; font-size: 13px; color: #666;">More sub filters (sub3–sub10)</summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 12px;">
                <?php for ($si = 3; $si <= 10; $si++): ?>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 12px;">Sub<?= $si ?></label>
                        <input type="text" name="sub<?= $si ?>" form="conversion-date-form"
                               value="<?= htmlspecialchars($extraFilters['sub' . $si] ?? '') ?>"
                               placeholder="sub<?= $si ?>"
                               style="width: 100%; padding: 6px; border: 2px solid #ddd; border-radius: 4px;">
                    </div>
                <?php endfor; ?>
            </div>
        </details>
    </div>
</div>

<script>
const conversionDatePresets = {
    today: '<?= $today ?>',
    yesterday: '<?= $yesterday ?>',
    last7Start: '<?= $last7Start ?>',
    last14Start: '<?= $last14Start ?>',
    last30Start: '<?= $last30Start ?>',
    lastMonthStart: '<?= $lastMonthStart ?>',
    lastMonthEnd: '<?= $lastMonthEnd ?>',
    thisMonthStart: '<?= $thisMonthStart ?>',
    allTimeStart: '<?= $allTimeStart ?>'
};

function setConversionDate(preset) {
    const dateFromInput = document.getElementById('conversion_date_from');
    const dateToInput = document.getElementById('conversion_date_to');
    let fromDate;
    let toDate;

    switch (preset) {
        case 'today':
            fromDate = conversionDatePresets.today;
            toDate = conversionDatePresets.today;
            break;
        case 'yesterday':
            fromDate = conversionDatePresets.yesterday;
            toDate = conversionDatePresets.yesterday;
            break;
        case 'last7':
            fromDate = conversionDatePresets.last7Start;
            toDate = conversionDatePresets.today;
            break;
        case 'last14':
            fromDate = conversionDatePresets.last14Start;
            toDate = conversionDatePresets.today;
            break;
        case 'last30':
            fromDate = conversionDatePresets.last30Start;
            toDate = conversionDatePresets.today;
            break;
        case 'lastmonth':
            fromDate = conversionDatePresets.lastMonthStart;
            toDate = conversionDatePresets.lastMonthEnd;
            break;
        case 'thismonth':
            fromDate = conversionDatePresets.thisMonthStart;
            toDate = conversionDatePresets.today;
            break;
        case 'alltime':
            fromDate = conversionDatePresets.allTimeStart;
            toDate = conversionDatePresets.today;
            break;
    }

    dateFromInput.value = fromDate;
    dateToInput.value = toDate;
    document.getElementById('conversion-date-form').submit();
}
</script>

<?php
$showingCount = $viewMode === 'cohort' ? count($cohortRows) : min($perPage, count($conversions));
?>
<div class="conversion-log-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Total Conversions</div>
        <div style="font-size: 28px; font-weight: 700; color: var(--accent);"><?= number_format($totalRows) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Total Revenue</div>
        <div style="font-size: 28px; font-weight: 700; color: #2ecc71;"><?= Formatter::formatCurrency($totalRevenue, $userCurrency) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Showing</div>
        <div style="font-size: 28px; font-weight: 700; color: var(--accent);"><?= number_format($showingCount) ?></div>
    </div>
    <?php if ($viewMode === 'log'): ?>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Page</div>
        <div style="font-size: 28px; font-weight: 700; color: var(--accent);"><?= $pageNum ?> / <?= $totalPages ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($viewMode === 'log' && $totalPages > 1): ?>
    <div style="margin-bottom: 24px;">
        <?= generateConversionPagination($pageNum, $totalPages, $_GET) ?>
    </div>
<?php endif; ?>

<?php if ($viewMode === 'cohort'): ?>
<div class="card conversion-log-card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 class="card-title" style="margin: 0;">With click details (<?= number_format(count($cohortRows)) ?>)</h2>
            <p style="margin: 6px 0 0 0; font-size: 13px; color: #666;">
                Same filtered conversions as the log, with each row joined to its click (device, cost, and tracking tokens) so you can search and sort them together.
                <?php if ($cohortTruncated): ?>
                    Showing the newest <?= number_format($cohortMaxRows) ?> of <?= number_format($totalRows) ?> matches — narrow filters to see the rest.
                <?php endif; ?>
            </p>
        </div>
        <div class="conversion-log-col-tools">
            <input type="search" id="cohort-sift-input" placeholder="Search this table…"
                   style="min-width: 220px; padding: 8px 12px; border: 2px solid #ddd; border-radius: 4px;">
            <button type="button" class="btn btn-secondary" id="cohort-columns-btn" aria-expanded="false" aria-controls="cohort-columns-panel">Columns</button>
            <div class="conversion-log-col-panel" id="cohort-columns-panel" role="dialog" aria-label="Arrange columns"></div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($cohortRows)): ?>
            <div style="text-align: center; padding: 60px; color: #999;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="" width="64" height="64" style="margin-bottom: 16px; opacity: 0.5;">
                <p>No conversions found for the selected filters.</p>
            </div>
        <?php else: ?>
            <div class="conversion-log-table-wrapper table-wrapper">
                <table class="table conversion-log-table" id="cohort-sift-table" data-col-prefs="cohort">
                    <thead>
                        <tr>
                            <th class="cohort-sort" data-col="time" style="cursor: pointer;">Time</th>
                            <th class="cohort-sort" data-col="click_id" style="cursor: pointer;">Click ID</th>
                            <th class="cohort-sort" data-col="campaign" style="cursor: pointer;">Campaign</th>
                            <th class="cohort-sort" data-col="offer" style="cursor: pointer;">Offer</th>
                            <th class="cohort-sort" data-col="revenue" style="cursor: pointer;">Revenue</th>
                            <th class="cohort-sort" data-col="country" style="cursor: pointer;">Country</th>
                            <th class="cohort-sort" data-col="device" style="cursor: pointer;">Device</th>
                            <th class="cohort-sort" data-col="os" style="cursor: pointer;">OS</th>
                            <th class="cohort-sort" data-col="browser" style="cursor: pointer;">Browser</th>
                            <th class="cohort-sort" data-col="click_cost" style="cursor: pointer;">Click cost</th>
                            <?php foreach ($visibleExtraCols as $col): ?>
                                <th class="cohort-sort" data-col="pb:<?= htmlspecialchars($col['key']) ?>" style="cursor: pointer;"><?= htmlspecialchars($col['label']) ?></th>
                            <?php endforeach; ?>
                            <?php foreach ($clickTokenKeys as $tokenKey): ?>
                                <th class="cohort-sort" data-col="tok:<?= htmlspecialchars($tokenKey) ?>" style="cursor: pointer;"><?= htmlspecialchars($tokenKey) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cohortRows as $index => $conv): ?>
                        <?php
                            $isOptIn = ConversionOptInClassifier::isOptIn($conv['event_key'] ?? null);
                            $rowClass = ($index % 2 === 0 ? 'conversion-row-even' : 'conversion-row-odd')
                                . ($isOptIn ? ' conversion-row-optin' : '');
                            $tokens = $conv['click_tokens'] ?? [];
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td data-col="time" class="conversion-mono conversion-nowrap" data-sort-value="<?= htmlspecialchars($conv['ts'] ?? '') ?>"><?= htmlspecialchars(Formatter::formatDateTime($conv['ts'], $userTimezone)) ?></td>
                            <td data-col="click_id" data-sort-value="<?= htmlspecialchars($conv['click_id']) ?>">
                                <div class="click-id-row">
                                    <code
                                        class="click-id-copyable conversion-mono"
                                        data-click-id="<?= htmlspecialchars($conv['click_id']) ?>"
                                        onclick="copyClickId(this)"
                                        title="Click to copy: <?= htmlspecialchars($conv['click_id']) ?>">
                                        <?= htmlspecialchars($conv['click_id']) ?>
                                    </code>
                                    <?= renderClickLookupLink($conv['click_id']) ?>
                                </div>
                            </td>
                            <td data-col="campaign" data-sort-value="<?= htmlspecialchars($conv['campaign_name'] ?? '') ?>"><strong><?= htmlspecialchars($conv['campaign_name'] ?? '-') ?></strong></td>
                            <td data-col="offer" data-sort-value="<?= htmlspecialchars($conv['offer_name'] ?? '') ?>"><?= htmlspecialchars($conv['offer_name'] ?? '-') ?></td>
                            <td data-col="revenue" class="conversion-num" data-sort-value="<?= htmlspecialchars((string) $conv['revenue']) ?>"><?= Formatter::formatCurrency($conv['revenue'], $conv['currency'] ?? $userCurrency) ?></td>
                            <td data-col="country" data-sort-value="<?= htmlspecialchars($conv['country'] ?? '') ?>"><?= htmlspecialchars($conv['country'] ?? '-') ?></td>
                            <td data-col="device" data-sort-value="<?= htmlspecialchars($conv['click_device'] ?? '') ?>"><?= htmlspecialchars($conv['click_device'] ?? '-') ?></td>
                            <td data-col="os" data-sort-value="<?= htmlspecialchars($conv['click_os'] ?? '') ?>"><?= htmlspecialchars($conv['click_os'] ?? '-') ?></td>
                            <td data-col="browser" data-sort-value="<?= htmlspecialchars($conv['click_browser'] ?? '') ?>"><?= htmlspecialchars($conv['click_browser'] ?? '-') ?></td>
                            <td data-col="click_cost" class="conversion-num" data-sort-value="<?= htmlspecialchars((string) ($conv['click_cost'] ?? '')) ?>"><?= $conv['click_cost'] !== null ? Formatter::formatCurrency($conv['click_cost'], $userCurrency) : '-' ?></td>
                            <?php foreach ($visibleExtraCols as $col): ?>
                                <?php $extraVal = $conv['pb_' . $col['key']] ?? null; ?>
                                <td data-col="pb:<?= htmlspecialchars($col['key']) ?>" class="conversion-mono" data-sort-value="<?= htmlspecialchars((string) ($extraVal ?? '')) ?>"><?= htmlspecialchars($extraVal ?? '-') ?></td>
                            <?php endforeach; ?>
                            <?php foreach ($clickTokenKeys as $tokenKey): ?>
                                <?php $tv = $tokens[$tokenKey] ?? null; ?>
                                <td data-col="tok:<?= htmlspecialchars($tokenKey) ?>" class="conversion-mono" data-sort-value="<?= htmlspecialchars((string) ($tv ?? '')) ?>"><?= htmlspecialchars($tv ?? '-') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('cohort-sift-input');
    const table = document.getElementById('cohort-sift-table');
    if (!input || !table) return;
    const tbody = table.tBodies[0];

    input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        Array.from(tbody.rows).forEach(function (row) {
            const text = row.textContent.toLowerCase();
            row.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
        });
    });

    let sortColId = null;
    let sortAsc = true;
    table.querySelectorAll('th.cohort-sort').forEach(function (th) {
        th.addEventListener('click', function () {
            const colId = th.getAttribute('data-col');
            if (!colId) return;
            if (sortColId === colId) {
                sortAsc = !sortAsc;
            } else {
                sortColId = colId;
                sortAsc = true;
            }
            const rows = Array.from(tbody.rows);
            rows.sort(function (a, b) {
                const aCell = Array.prototype.find.call(a.cells, function (c) { return c.getAttribute('data-col') === colId; });
                const bCell = Array.prototype.find.call(b.cells, function (c) { return c.getAttribute('data-col') === colId; });
                const aVal = (aCell && aCell.getAttribute('data-sort-value')) || (aCell ? aCell.textContent : '');
                const bVal = (bCell && bCell.getAttribute('data-sort-value')) || (bCell ? bCell.textContent : '');
                const aNum = parseFloat(aVal);
                const bNum = parseFloat(bVal);
                let cmp;
                if (!isNaN(aNum) && !isNaN(bNum) && aVal !== '' && bVal !== '') {
                    cmp = aNum - bNum;
                } else {
                    cmp = String(aVal).localeCompare(String(bVal), undefined, { sensitivity: 'base' });
                }
                return sortAsc ? cmp : -cmp;
            });
            rows.forEach(function (row) { tbody.appendChild(row); });
        });
    });
})();
</script>

<?php else: ?>
<div class="card conversion-log-card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <h2 class="card-title">Conversions (<?= number_format($totalRows) ?>)</h2>
        <div class="conversion-log-col-tools">
            <?php if (!empty($conversions)): ?>
            <button type="button" class="btn btn-secondary" id="log-columns-btn" aria-expanded="false" aria-controls="log-columns-panel">Columns</button>
            <div class="conversion-log-col-panel" id="log-columns-panel" role="dialog" aria-label="Arrange columns"></div>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($conversions)): ?>
            <div style="text-align: center; padding: 60px; color: #999;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="" width="64" height="64" style="margin-bottom: 16px; opacity: 0.5;">
                <p>No conversions found for the selected filters.</p>
            </div>
        <?php else: ?>
            <div class="conversion-log-table-wrapper table-wrapper">
                <table class="table conversion-log-table" id="conversion-log-table" data-col-prefs="log">
                    <thead>
                        <tr>
                            <th data-col="conv_id">Conv ID</th>
                            <th data-col="time">Time</th>
                            <th data-col="click_id">Click ID</th>
                            <th data-col="campaign">Campaign</th>
                            <th data-col="offer">Offer</th>
                            <th data-col="lp">LP</th>
                            <th data-col="status">Status</th>
                            <th data-col="payout">Payout</th>
                            <th data-col="value">Value</th>
                            <th data-col="revenue">Revenue</th>
                            <th data-col="currency">Currency</th>
                            <th data-col="txid">TXID</th>
                            <th data-col="event">Event</th>
                            <th data-col="event_id">Event ID</th>
                            <th data-col="traffic_source">Traffic Source</th>
                            <th data-col="country">Country</th>
                            <th data-col="city">City</th>
                            <th data-col="state">State</th>
                            <th data-col="ip">IP</th>
                            <th data-col="source">Source</th>
                            <?php foreach ($visibleExtraCols as $col): ?>
                                <th data-col="pb:<?= htmlspecialchars($col['key']) ?>"><?= htmlspecialchars($col['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversions as $index => $conv): ?>
                        <?php
                            $isOptIn = ConversionOptInClassifier::isOptIn($conv['event_key'] ?? null);
                            $rowClass = ($index % 2 === 0 ? 'conversion-row-even' : 'conversion-row-odd')
                                . ($isOptIn ? ' conversion-row-optin' : '');
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td data-col="conv_id" class="conversion-mono"><?= (int) $conv['id'] ?></td>
                            <td data-col="time" class="conversion-mono conversion-nowrap"><?= htmlspecialchars(Formatter::formatDateTime($conv['ts'], $userTimezone)) ?></td>
                            <td data-col="click_id">
                                <div class="click-id-row">
                                    <code
                                        class="click-id-copyable conversion-mono"
                                        data-click-id="<?= htmlspecialchars($conv['click_id']) ?>"
                                        onclick="copyClickId(this)"
                                        title="Click to copy: <?= htmlspecialchars($conv['click_id']) ?>">
                                        <?= htmlspecialchars($conv['click_id']) ?>
                                    </code>
                                    <?= renderClickLookupLink($conv['click_id']) ?>
                                </div>
                            </td>
                            <td data-col="campaign"><strong><?= htmlspecialchars($conv['campaign_name'] ?? '-') ?></strong></td>
                            <td data-col="offer"><?= htmlspecialchars($conv['offer_name'] ?? '-') ?></td>
                            <td data-col="lp"><?= htmlspecialchars($conv['landing_page_name'] ?? '-') ?></td>
                            <td data-col="status"><?= renderConversionStatusBadge($conv['status'] ?? 'pending') ?></td>
                            <td data-col="payout" class="conversion-num"><?= $conv['payout'] !== null ? Formatter::formatCurrency($conv['payout'], $conv['currency'] ?? $userCurrency) : '-' ?></td>
                            <td data-col="value" class="conversion-num"><?= $conv['value'] !== null ? Formatter::formatCurrency($conv['value'], $conv['currency'] ?? $userCurrency) : '-' ?></td>
                            <td data-col="revenue" class="conversion-num conversion-revenue"><?= Formatter::formatCurrency($conv['revenue'], $conv['currency'] ?? $userCurrency) ?></td>
                            <td data-col="currency" class="conversion-mono"><?= htmlspecialchars($conv['currency'] ?? '-') ?></td>
                            <td data-col="txid" class="conversion-mono"><?= htmlspecialchars($conv['txid'] ?? '-') ?></td>
                            <td data-col="event"><?= renderConversionEventCell($conv['event_key'] ?? null) ?></td>
                            <td data-col="event_id" class="conversion-mono"><?= htmlspecialchars($conv['event_id'] ?? '-') ?></td>
                            <td data-col="traffic_source"><?= htmlspecialchars($conv['traffic_source_name'] ?? '-') ?></td>
                            <td data-col="country"><?= htmlspecialchars($conv['country'] ?? '-') ?></td>
                            <td data-col="city"><?= htmlspecialchars($conv['city'] ?? '-') ?></td>
                            <td data-col="state"><?= htmlspecialchars($conv['region'] ?? '-') ?></td>
                            <td data-col="ip" class="conversion-mono"><?= htmlspecialchars($conv['ip'] ?? '-') ?></td>
                            <td data-col="source"><?= htmlspecialchars($conv['source'] ?? '-') ?></td>
                            <?php foreach ($visibleExtraCols as $col): ?>
                                <td data-col="pb:<?= htmlspecialchars($col['key']) ?>" class="conversion-mono"><?= htmlspecialchars($conv['pb_' . $col['key']] ?? '-') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div style="margin-top: 24px;">
        <?= generateConversionPagination($pageNum, $totalPages, $_GET) ?>
    </div>
<?php endif; ?>
<?php endif; ?>

<script>
function showCopyFeedback(element, status, message) {
    const row = element.closest('.click-id-row');
    if (!element._copyState) {
        element._copyState = {
            text: element.textContent,
            color: element.style.color,
            weight: element.style.fontWeight,
            bg: element.style.background
        };
    }
    element.textContent = message;
    row?.classList.remove('is-copy-failed', 'is-copied');
    if (status === 'success') {
        row?.classList.add('is-copied');
    } else if (status === 'error') {
        row?.classList.add('is-copy-failed');
    }
}

function resetCopyFeedback(element) {
    const row = element.closest('.click-id-row');
    const state = element._copyState;
    if (!state) {
        return;
    }
    element.textContent = state.text;
    row?.classList.remove('is-copy-failed', 'is-copied');
    delete element._copyState;
}

function copyClickId(element) {
    const clickId = element.getAttribute('data-click-id');

    function onCopySuccess() {
        showCopyFeedback(element, 'success', 'Copied!');
        setTimeout(function() { resetCopyFeedback(element); }, 2000);
    }

    function onCopyError() {
        showCopyFeedback(element, 'error', 'Failed');
        setTimeout(function() { resetCopyFeedback(element); }, 2000);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(clickId).then(onCopySuccess).catch(function() {
            const textArea = document.createElement('textarea');
            textArea.value = clickId;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                onCopySuccess();
            } catch (err) {
                onCopyError();
            }
            document.body.removeChild(textArea);
        });
    }
}

(function initConversionLogColumnPrefs() {
    var STORAGE_PREFIX = 'simplekuma.conversionLog.columns.v1.';

    function loadPrefs(scope, defaultOrder) {
        var order = defaultOrder.slice();
        var hidden = [];
        try {
            var raw = localStorage.getItem(STORAGE_PREFIX + scope);
            if (!raw) {
                return { order: order, hidden: hidden };
            }
            var parsed = JSON.parse(raw);
            if (parsed && Array.isArray(parsed.order)) {
                var seen = {};
                var merged = [];
                parsed.order.forEach(function (id) {
                    if (typeof id === 'string' && defaultOrder.indexOf(id) !== -1 && !seen[id]) {
                        merged.push(id);
                        seen[id] = true;
                    }
                });
                defaultOrder.forEach(function (id) {
                    if (!seen[id]) {
                        merged.push(id);
                    }
                });
                order = merged;
            }
            if (parsed && Array.isArray(parsed.hidden)) {
                hidden = parsed.hidden.filter(function (id) {
                    return typeof id === 'string' && defaultOrder.indexOf(id) !== -1;
                });
            }
        } catch (e) { /* ignore corrupt prefs */ }
        return { order: order, hidden: hidden };
    }

    function savePrefs(scope, prefs) {
        try {
            localStorage.setItem(STORAGE_PREFIX + scope, JSON.stringify({
                order: prefs.order,
                hidden: prefs.hidden
            }));
        } catch (e) { /* private mode / quota */ }
    }

    function cellByCol(row, colId) {
        return Array.prototype.find.call(row.cells, function (c) {
            return c.getAttribute('data-col') === colId;
        }) || null;
    }

    function applyLayout(table, prefs) {
        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) return;
        var hiddenSet = {};
        prefs.hidden.forEach(function (id) { hiddenSet[id] = true; });

        var thByCol = {};
        Array.prototype.forEach.call(headRow.cells, function (th) {
            var id = th.getAttribute('data-col');
            if (id) thByCol[id] = th;
        });

        prefs.order.forEach(function (colId) {
            var th = thByCol[colId];
            if (!th) return;
            headRow.appendChild(th);
            if (hiddenSet[colId]) {
                th.classList.add('col-pref-hidden');
            } else {
                th.classList.remove('col-pref-hidden');
            }
        });

        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
            prefs.order.forEach(function (colId) {
                var td = cellByCol(row, colId);
                if (!td) return;
                row.appendChild(td);
                if (hiddenSet[colId]) {
                    td.classList.add('col-pref-hidden');
                } else {
                    td.classList.remove('col-pref-hidden');
                }
            });
        });
    }

    function renderPanel(panel, table, scope, prefs, defaultOrder) {
        panel.innerHTML = '';
        var title = document.createElement('p');
        title.className = 'conversion-log-col-panel-title';
        title.textContent = 'Columns';
        panel.appendChild(title);

        var hint = document.createElement('p');
        hint.className = 'conversion-log-col-panel-hint';
        hint.textContent = 'Show or hide columns and move them left/right. Saved in this browser only. CSV export still includes all columns.';
        panel.appendChild(hint);

        var list = document.createElement('ul');
        list.className = 'conversion-log-col-list';

        prefs.order.forEach(function (colId, index) {
            var th = Array.prototype.find.call(table.tHead.rows[0].cells, function (c) {
                return c.getAttribute('data-col') === colId;
            });
            var labelText = th ? (th.textContent || colId).trim() : colId;
            var li = document.createElement('li');

            var label = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = prefs.hidden.indexOf(colId) === -1;
            cb.addEventListener('change', function () {
                if (cb.checked) {
                    prefs.hidden = prefs.hidden.filter(function (id) { return id !== colId; });
                } else if (prefs.hidden.indexOf(colId) === -1) {
                    prefs.hidden.push(colId);
                }
                savePrefs(scope, prefs);
                applyLayout(table, prefs);
            });
            var span = document.createElement('span');
            span.textContent = labelText;
            span.title = labelText;
            label.appendChild(cb);
            label.appendChild(span);
            li.appendChild(label);

            var move = document.createElement('div');
            move.className = 'conversion-log-col-move';

            var up = document.createElement('button');
            up.type = 'button';
            up.className = 'btn btn-outline';
            up.textContent = '↑';
            up.title = 'Move left';
            up.disabled = index === 0;
            up.addEventListener('click', function () {
                if (index === 0) return;
                var tmp = prefs.order[index - 1];
                prefs.order[index - 1] = prefs.order[index];
                prefs.order[index] = tmp;
                savePrefs(scope, prefs);
                applyLayout(table, prefs);
                renderPanel(panel, table, scope, prefs, defaultOrder);
            });

            var down = document.createElement('button');
            down.type = 'button';
            down.className = 'btn btn-outline';
            down.textContent = '↓';
            down.title = 'Move right';
            down.disabled = index === prefs.order.length - 1;
            down.addEventListener('click', function () {
                if (index >= prefs.order.length - 1) return;
                var tmp = prefs.order[index + 1];
                prefs.order[index + 1] = prefs.order[index];
                prefs.order[index] = tmp;
                savePrefs(scope, prefs);
                applyLayout(table, prefs);
                renderPanel(panel, table, scope, prefs, defaultOrder);
            });

            move.appendChild(up);
            move.appendChild(down);
            li.appendChild(move);
            list.appendChild(li);
        });
        panel.appendChild(list);

        var actions = document.createElement('div');
        actions.className = 'conversion-log-col-actions';

        var showAll = document.createElement('button');
        showAll.type = 'button';
        showAll.className = 'btn btn-outline';
        showAll.textContent = 'Show all';
        showAll.addEventListener('click', function () {
            prefs.hidden = [];
            savePrefs(scope, prefs);
            applyLayout(table, prefs);
            renderPanel(panel, table, scope, prefs, defaultOrder);
        });

        var reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'btn btn-outline';
        reset.textContent = 'Reset order';
        reset.addEventListener('click', function () {
            prefs.order = defaultOrder.slice();
            prefs.hidden = [];
            savePrefs(scope, prefs);
            applyLayout(table, prefs);
            renderPanel(panel, table, scope, prefs, defaultOrder);
        });

        actions.appendChild(showAll);
        actions.appendChild(reset);
        panel.appendChild(actions);
    }

    function bindTable(table, btn, panel) {
        if (!table || !btn || !panel) return;
        var scope = table.getAttribute('data-col-prefs') || 'log';
        var defaultOrder = Array.prototype.map.call(table.tHead.rows[0].cells, function (th) {
            return th.getAttribute('data-col');
        }).filter(Boolean);
        if (defaultOrder.length === 0) return;

        var prefs = loadPrefs(scope, defaultOrder);
        applyLayout(table, prefs);
        renderPanel(panel, table, scope, prefs, defaultOrder);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !panel.classList.contains('is-open');
            document.querySelectorAll('.conversion-log-col-panel.is-open').forEach(function (p) {
                p.classList.remove('is-open');
            });
            document.querySelectorAll('[aria-controls$="-columns-panel"]').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
            if (open) {
                panel.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });

        panel.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    document.addEventListener('click', function () {
        document.querySelectorAll('.conversion-log-col-panel.is-open').forEach(function (p) {
            p.classList.remove('is-open');
        });
        document.querySelectorAll('[aria-controls$="-columns-panel"]').forEach(function (b) {
            b.setAttribute('aria-expanded', 'false');
        });
    });

    bindTable(
        document.getElementById('conversion-log-table'),
        document.getElementById('log-columns-btn'),
        document.getElementById('log-columns-panel')
    );
    bindTable(
        document.getElementById('cohort-sift-table'),
        document.getElementById('cohort-columns-btn'),
        document.getElementById('cohort-columns-panel')
    );
})();
</script>
