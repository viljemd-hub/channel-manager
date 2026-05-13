<?php
/**
 * CM Free / CM Plus / CM PRO – Channel Manager
 * File: admin/analytics/api/analytics_occupancy.php
 * Purpose:
 * - Return occupancy analytics for one unit in a selected date range.
 * - Read from units/<UNIT>/occupancy_merged.json
 * - Distinguish between:
 *   1) blocked occupancy  = all unavailable days
 *   2) booked occupancy   = real booked/reserved days only
 *
 * Notes:
 * - Date intervals are treated as [start, end), end-exclusive.
 * - Counting is unique per day (overlaps are not double-counted).
 * - Designed to work in both /app and /app_pro installs.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/**
 * JSON response helper
 */
function ao_respond(bool $ok, array $payload = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        array_merge(['ok' => $ok], $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

/**
 * Safe JSON file reader
 */
function ao_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Validate YYYY-MM-DD
 */
function ao_is_iso_date(string $date): bool
{
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
        return false;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}

/**
 * Return overlap of [start,end) with [from,to)
 * Dates are YYYY-MM-DD and safe for lexical compare.
 *
 * @return array{0:string,1:string}|null
 */
function ao_overlap_range(string $start, string $end, string $from, string $to): ?array
{
    $s = max($start, $from);
    $e = min($end, $to);

    if ($s >= $e) {
        return null;
    }

    return [$s, $e];
}

/**
 * Expand [start,end) to daily YYYY-MM-DD values
 *
 * @return string[]
 */
function ao_each_day(string $start, string $endExclusive): array
{
    $days = [];
    $d = $start;

    while ($d < $endExclusive) {
        $days[] = $d;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    return $days;
}

/**
 * Month key helper
 */
function ao_month_key(string $iso): string
{
    return substr($iso, 0, 7); // YYYY-MM
}

/**
 * Determine whether the row means "calendar unavailable"
 */
function ao_is_blocked_row(array $row): bool
{
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $lock   = strtolower(trim((string)($row['lock'] ?? '')));

    if ($status === 'reserved') {
        return true;
    }

    if ($status === 'blocked') {
        return true;
    }

    if ($lock === 'hard') {
        return true;
    }

    return false;
}

/**
 * Determine whether the row should count as a true booking
 *
 * We intentionally exclude obvious "not available" / manual closure rows.
 */
function ao_is_booked_row(array $row): bool
{
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status !== 'reserved') {
        return false;
    }

    $summary = strtolower(trim((string)($row['meta']['summary'] ?? '')));
    $source  = strtolower(trim((string)($row['source'] ?? '')));
    $id      = strtolower(trim((string)($row['id'] ?? '')));

    // Internal confirmed reservations are always bookings.
    if ($source === 'public' || $source === 'direct' || $source === 'internal' || $source === 'external') {
        return true;
    }

    // Explicit "not available" / closures from channels are blocked, not booked.
    if ($summary === 'closed - not available') {
        return false;
    }
    if ($summary === 'airbnb (not available)') {
        return false;
    }
    if ($summary === 'not available') {
        return false;
    }

    // If ICS row explicitly says reserved, count it as booked.
    if ($summary === 'reserved') {
        return true;
    }

    // Conservative fallback:
    // booking/airbnb ICS rows without a "not available" marker count as booked.
    if ($source === 'ics') {
        if (str_starts_with($id, 'ics:booking:') || str_starts_with($id, 'ics:airbnb:')) {
            return true;
        }
    }

    return false;
}

/**
 * Build list of months inside [from,to)
 *
 * @return array<string,array{days_total:int,days_blocked:int,days_booked:int,occupancy_blocked_pct:float,occupancy_booked_pct:float}>
 */
function ao_init_months(string $from, string $to): array
{
    $months = [];
    $d = $from;

    while ($d < $to) {
        $mk = ao_month_key($d);
        if (!isset($months[$mk])) {
            $months[$mk] = [
                'days_total' => 0,
                'days_blocked' => 0,
                'days_booked' => 0,
                'occupancy_blocked_pct' => 0.0,
                'occupancy_booked_pct' => 0.0,
            ];
        }
        $months[$mk]['days_total']++;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    return $months;
}

/**
 * Dynamic root discovery
 * This file lives in /admin/analytics/api/
 * projectRoot = three levels up
 */
$projectRoot = dirname(__DIR__, 3);

// Input
$unit = trim((string)($_GET['unit'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

// Sensible defaults if not provided
if ($from === '') {
    $from = date('Y-01-01');
}
if ($to === '') {
    $to = date('Y-12-31', strtotime($from . ' +1 year'));
    $to = date('Y-m-d', strtotime($to . ' +1 day')); // keep end-exclusive
}

if ($unit === '' || !preg_match('~^[A-Za-z0-9_-]+$~', $unit)) {
    ao_respond(false, ['error' => 'invalid_unit'], 400);
}

if (!ao_is_iso_date($from) || !ao_is_iso_date($to) || $from >= $to) {
    ao_respond(false, ['error' => 'invalid_range'], 400);
}

$occPath = $projectRoot . '/common/data/json/units/' . $unit . '/occupancy_merged.json';
if (!is_file($occPath)) {
    ao_respond(false, ['error' => 'occupancy_merged_not_found', 'path' => $occPath], 404);
}

$rows = ao_read_json($occPath);
if (!is_array($rows)) {
    ao_respond(false, ['error' => 'invalid_occupancy_merged'], 500);
}

// Unique day maps
$blockedDays = [];
$bookedDays  = [];

// Extra analytics counters
$sourceNights = [];
$sourceEntries = [
    'blocked' => 0,
    'booked' => 0,
];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $start = trim((string)($row['start'] ?? ''));
    $end   = trim((string)($row['end'] ?? ''));

    if (!ao_is_iso_date($start) || !ao_is_iso_date($end) || $start >= $end) {
        continue;
    }

    $overlap = ao_overlap_range($start, $end, $from, $to);
    if ($overlap === null) {
        continue;
    }

    [$s, $e] = $overlap;
    $days = ao_each_day($s, $e);

    $isBlocked = ao_is_blocked_row($row);
    $isBooked  = ao_is_booked_row($row);

    $source = strtolower(trim((string)($row['source'] ?? 'unknown')));
    if ($source === '') {
        $source = 'unknown';
    }

    if ($isBlocked) {
        $sourceEntries['blocked']++;
        foreach ($days as $day) {
            $blockedDays[$day] = true;
            if (!isset($sourceNights[$source])) {
                $sourceNights[$source] = ['blocked_days' => 0, 'booked_days' => 0];
            }
            $sourceNights[$source]['blocked_days']++;
        }
    }

    if ($isBooked) {
        $sourceEntries['booked']++;
        foreach ($days as $day) {
            $bookedDays[$day] = true;
            if (!isset($sourceNights[$source])) {
                $sourceNights[$source] = ['blocked_days' => 0, 'booked_days' => 0];
            }
            $sourceNights[$source]['booked_days']++;
        }
    }
}

// Build denominator
$totalDays = 0;
$d = $from;
while ($d < $to) {
    $totalDays++;
    $d = date('Y-m-d', strtotime($d . ' +1 day'));
}

// Monthly breakdown
$months = ao_init_months($from, $to);

foreach (array_keys($blockedDays) as $day) {
    $mk = ao_month_key($day);
    if (isset($months[$mk])) {
        $months[$mk]['days_blocked']++;
    }
}
foreach (array_keys($bookedDays) as $day) {
    $mk = ao_month_key($day);
    if (isset($months[$mk])) {
        $months[$mk]['days_booked']++;
    }
}

foreach ($months as $mk => $row) {
    $den = max(1, (int)$row['days_total']);
    $months[$mk]['occupancy_blocked_pct'] = round(($row['days_blocked'] / $den) * 100, 2);
    $months[$mk]['occupancy_booked_pct']  = round(($row['days_booked'] / $den) * 100, 2);
}

// Optional occupancy_data-style flat monthly map for quick legacy use
$occupancyBlockedFlat = [];
$occupancyBookedFlat  = [];
foreach ($months as $mk => $row) {
    $occupancyBlockedFlat[$mk] = $row['occupancy_blocked_pct'];
    $occupancyBookedFlat[$mk]  = $row['occupancy_booked_pct'];
}

ao_respond(true, [
    'unit' => $unit,
    'from' => $from,
    'to'   => $to, // end-exclusive
    'source_file' => $occPath,

    'summary' => [
        'total_days' => $totalDays,
        'occupied_blocked_days' => count($blockedDays),
        'occupied_booked_days'  => count($bookedDays),
        'occupancy_blocked_pct' => $totalDays > 0 ? round((count($blockedDays) / $totalDays) * 100, 2) : 0.0,
        'occupancy_booked_pct'  => $totalDays > 0 ? round((count($bookedDays)  / $totalDays) * 100, 2) : 0.0,
    ],

    'months' => $months,

    'legacy_month_maps' => [
        'blocked_pct' => $occupancyBlockedFlat,
        'booked_pct'  => $occupancyBookedFlat,
    ],

    'debug' => [
        'rows_total' => count($rows),
        'entries_counted_as_blocked' => $sourceEntries['blocked'],
        'entries_counted_as_booked'  => $sourceEntries['booked'],
        'source_nights' => $sourceNights,
    ],
]);