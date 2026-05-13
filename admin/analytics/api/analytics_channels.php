<?php
/**
 * CM Free / CM Plus / CM PRO – Channel Manager
 * File: admin/analytics/api/analytics_channels.php
 * Purpose:
 * - Read raw ICS-normalized channel files from units/<UNIT>/external/
 * - Return channel analytics split by:
 *   1) booked days
 *   2) blocked days
 * - Works with Booking + Airbnb (and is easy to extend later)
 *
 * Notes:
 * - Intervals are treated as [start, end), end-exclusive.
 * - Days are counted uniquely within each channel bucket.
 * - This endpoint is intentionally separate from occupancy analytics.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ac_respond(bool $ok, array $payload = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        array_merge(['ok' => $ok], $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function ac_read_json(string $path): array
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

function ac_is_iso_date(string $date): bool
{
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
        return false;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}

/**
 * @return array{0:string,1:string}|null
 */
function ac_overlap_range(string $start, string $end, string $from, string $to): ?array
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
function ac_each_day(string $start, string $endExclusive): array
{
    $days = [];
    $d = $start;

    while ($d < $endExclusive) {
        $days[] = $d;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    return $days;
}

function ac_month_key(string $iso): string
{
    return substr($iso, 0, 7);
}

function ac_days_between(string $start, string $endExclusive): int
{
    $a = strtotime($start);
    $b = strtotime($endExclusive);
    if ($a === false || $b === false || $b <= $a) {
        return 0;
    }
    return (int)(($b - $a) / 86400);
}

/**
 * Build month map inside [from,to)
 *
 * @return array<string,array{days_total:int,booked_days:int,blocked_days:int,booked_pct:float,blocked_pct:float}>
 */
function ac_init_months(string $from, string $to): array
{
    $months = [];
    $d = $from;

    while ($d < $to) {
        $mk = ac_month_key($d);
        if (!isset($months[$mk])) {
            $months[$mk] = [
                'days_total'   => 0,
                'booked_days'  => 0,
                'blocked_days' => 0,
                'booked_pct'   => 0.0,
                'blocked_pct'  => 0.0,
            ];
        }
        $months[$mk]['days_total']++;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    return $months;
}

/**
 * Normalize external JSON to event list
 *
 * Supported shape:
 * {
 *   unit: "...",
 *   platform: "...",
 *   fetched_at: "...",
 *   count: n,
 *   events: [...]
 * }
 *
 * @return array<int,array<string,mixed>>
 */
function ac_extract_events(array $json): array
{
    if (isset($json['events']) && is_array($json['events'])) {
        return $json['events'];
    }

    // fallback if file itself is already an array
    if (array_is_list($json)) {
        return $json;
    }

    return [];
}

/**
 * Very pragmatic channel classification.
 *
 * Returns: 'booked' | 'blocked' | null
 */
function ac_classify_event(string $channel, array $event): ?string
{
    $type    = strtolower(trim((string)($event['type'] ?? '')));
    $status  = strtolower(trim((string)($event['status'] ?? '')));
    $summary = strtolower(trim((string)($event['meta']['summary'] ?? $event['summary'] ?? '')));

    // Preferred new model
    if ($type === 'booked') {
        return 'booked';
    }
    if ($type === 'blocked') {
        return 'blocked';
    }

    // Backward compatibility fallback
    if ($status === 'reserved') {
        return 'booked';
    }
    if ($status === 'blocked') {
        return 'blocked';
    }

    // Last-resort legacy fallback
    if ($channel === 'airbnb') {
        if ($summary === 'reserved') return 'booked';
        if (str_contains($summary, 'not available')) return 'blocked';
    }

    if ($channel === 'booking') {
        if (str_contains($summary, 'not available')) return 'blocked';
    }

    return null;
}

function ac_build_channel_stats(string $channel, array $events, string $from, string $to): array
{
    $bookedDays  = [];
    $blockedDays = [];

    $months = ac_init_months($from, $to);

    $entriesBooked  = 0;
    $entriesBlocked = 0;

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $start = trim((string)($event['start'] ?? ''));
        $end   = trim((string)($event['end'] ?? ''));

        if (!ac_is_iso_date($start) || !ac_is_iso_date($end) || $start >= $end) {
            continue;
        }

        $type = ac_classify_event($channel, $event);
        if ($type === null) {
            continue;
        }

        $overlap = ac_overlap_range($start, $end, $from, $to);
        if ($overlap === null) {
            continue;
        }

        [$s, $e] = $overlap;
        $days = ac_each_day($s, $e);

        if ($type === 'booked') {
            $entriesBooked++;
            foreach ($days as $day) {
                $bookedDays[$day] = true;
            }
        } elseif ($type === 'blocked') {
            $entriesBlocked++;
            foreach ($days as $day) {
                $blockedDays[$day] = true;
            }
        }
    }

    foreach (array_keys($bookedDays) as $day) {
        $mk = ac_month_key($day);
        if (isset($months[$mk])) {
            $months[$mk]['booked_days']++;
        }
    }

    foreach (array_keys($blockedDays) as $day) {
        $mk = ac_month_key($day);
        if (isset($months[$mk])) {
            $months[$mk]['blocked_days']++;
        }
    }

    foreach ($months as $mk => $row) {
        $den = max(1, (int)$row['days_total']);
        $months[$mk]['booked_pct']  = round(($row['booked_days'] / $den) * 100, 2);
        $months[$mk]['blocked_pct'] = round(($row['blocked_days'] / $den) * 100, 2);
    }

    $totalDays = 0;
    $d = $from;
    while ($d < $to) {
        $totalDays++;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    return [
        'summary' => [
            'total_days'            => $totalDays,
            'booked_days'           => count($bookedDays),
            'blocked_days'          => count($blockedDays),
            'booked_pct'            => $totalDays > 0 ? round((count($bookedDays) / $totalDays) * 100, 2) : 0.0,
            'blocked_pct'           => $totalDays > 0 ? round((count($blockedDays) / $totalDays) * 100, 2) : 0.0,
            'entries_booked'        => $entriesBooked,
            'entries_blocked'       => $entriesBlocked,
        ],
        'months' => $months,
        'days' => [
            'booked'  => array_keys($bookedDays),
            'blocked' => array_keys($blockedDays),
        ],
    ];
}

/**
 * Dynamic project root
 * /admin/analytics/api/analytics_channels.php -> three levels up
 */
$projectRoot = dirname(__DIR__, 3);

$unit = trim((string)($_GET['unit'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

if ($from === '') {
    $from = date('Y-01-01');
}
if ($to === '') {
    $to = date('Y-m-d', strtotime(date('Y-01-01', strtotime($from)) . ' +1 year'));
}

if ($unit === '' || !preg_match('~^[A-Za-z0-9_-]+$~', $unit)) {
    ac_respond(false, ['error' => 'invalid_unit'], 400);
}

if (!ac_is_iso_date($from) || !ac_is_iso_date($to) || $from >= $to) {
    ac_respond(false, ['error' => 'invalid_range'], 400);
}

$baseExternal = $projectRoot . '/common/data/json/units/' . $unit . '/external';

$channelFiles = [
    'booking' => $baseExternal . '/booking_ics.json',
    'airbnb'  => $baseExternal . '/airbnb_ics.json',
];

$outChannels = [];
$combinedBookedDays = [];
$combinedBlockedDays = [];

foreach ($channelFiles as $channel => $file) {
    $json = ac_read_json($file);

    $events = ac_extract_events($json);
    $stats  = ac_build_channel_stats($channel, $events, $from, $to);

    $outChannels[$channel] = [
        'source_file' => $file,
        'events_count_raw' => count($events),
        'summary' => $stats['summary'],
        'months' => $stats['months'],
    ];

    foreach ($stats['days']['booked'] as $day) {
        $combinedBookedDays[$day] = true;
    }
    foreach ($stats['days']['blocked'] as $day) {
        $combinedBlockedDays[$day] = true;
    }
}

$totalDays = 0;
$combinedMonths = ac_init_months($from, $to);
$d = $from;
while ($d < $to) {
    $totalDays++;
    $d = date('Y-m-d', strtotime($d . ' +1 day'));
}

foreach (array_keys($combinedBookedDays) as $day) {
    $mk = ac_month_key($day);
    if (isset($combinedMonths[$mk])) {
        $combinedMonths[$mk]['booked_days']++;
    }
}
foreach (array_keys($combinedBlockedDays) as $day) {
    $mk = ac_month_key($day);
    if (isset($combinedMonths[$mk])) {
        $combinedMonths[$mk]['blocked_days']++;
    }
}
foreach ($combinedMonths as $mk => $row) {
    $den = max(1, (int)$row['days_total']);
    $combinedMonths[$mk]['booked_pct']  = round(($row['booked_days'] / $den) * 100, 2);
    $combinedMonths[$mk]['blocked_pct'] = round(($row['blocked_days'] / $den) * 100, 2);
}

ac_respond(true, [
    'unit' => $unit,
    'from' => $from,
    'to'   => $to,
    'channels' => $outChannels,
    'combined' => [
        'summary' => [
            'total_days'   => $totalDays,
            'booked_days'  => count($combinedBookedDays),
            'blocked_days' => count($combinedBlockedDays),
            'booked_pct'   => $totalDays > 0 ? round((count($combinedBookedDays) / $totalDays) * 100, 2) : 0.0,
            'blocked_pct'  => $totalDays > 0 ? round((count($combinedBlockedDays) / $totalDays) * 100, 2) : 0.0,
        ],
        'months' => $combinedMonths,
    ],
]);