<?php
declare(strict_types=1);
/*
|--------------------------------------------------------------------------
| CM Analytics
|--------------------------------------------------------------------------
| Module: Analytics
| Purpose: Dashboard metrics + reporting for one CM instance
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../_common.php';
require_key();

function dt_from_iso(?string $s): ?DateTimeImmutable {
    if (!$s) return null;
    try {
        return new DateTimeImmutable($s);
    } catch (Throwable $e) {
        return null;
    }
}

function iter_files(string $root, string $pattern): Generator {
    if (!is_dir($root)) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo) continue;
        if (!$file->isFile()) continue;

        $name = $file->getFilename();

        if ($pattern === '*.json') {
            if (str_ends_with($name, '.json')) {
                yield $file->getPathname();
            }
            continue;
        }

        if (fnmatch($pattern, $name)) {
            yield $file->getPathname();
        }
    }
}

function week_key(DateTimeImmutable $dt): string {
    return $dt->format('o-\WW');
}

function last_n_weeks_labels(int $n): array {
    $labels = [];
    $now = new DateTimeImmutable('now');

    for ($i = $n - 1; $i >= 0; $i--) {
        $w = $now->modify('-' . $i . ' weeks');
        $labels[] = $w->format('o-\WW');
    }

    return $labels;
}

function pick_event_dt(array $j): ?DateTimeImmutable {
    return dt_from_iso(
        $j['confirmed_at']
        ?? $j['accepted_at']
        ?? $j['created']
        ?? $j['created_at']
        ?? null
    );
}

$now = new DateTimeImmutable('now');
$cut30 = $now->modify('-30 days');
$cut90 = $now->modify('-90 days');

$inquiriesRoot     = APP_COMMON . '/data/json/inquiries';
$reservationsRoot  = APP_COMMON . '/data/json/reservations';
$cancellationsRoot = APP_COMMON . '/data/json/cancellations';

$stats = [
    'inquiries_30d' => 0,
    'confirmed_30d' => 0,
    'nights_sum_30d' => 0,
    'nights_cnt_30d' => 0,
];

$funnel = [
    'inquiries' => 0,
    'accepted' => 0,
    'confirmed' => 0,
];

$confirmedPerWeek = [];

/* 1) Public/direct inquiries scan */
foreach (iter_files($inquiriesRoot, '*.json') as $path) {
    $j = load_json($path);
    if (!is_array($j)) continue;

    $created = dt_from_iso($j['created'] ?? ($j['created_at'] ?? null));
    if (!$created || $created < $cut30) continue;

    $status = (string)($j['status'] ?? '');

    $stats['inquiries_30d']++;
    $funnel['inquiries']++;

    if ($status === 'accepted') {
        $funnel['accepted']++;
    }

    if ($status === 'confirmed') {
        $funnel['confirmed']++;
    }
}

/* 2) Real reservations scan */
$conf90 = 0;

foreach (iter_files($reservationsRoot, '*.json') as $path) {
    $j = load_json($path);
    if (!is_array($j)) continue;

    $status = (string)($j['status'] ?? '');
    if ($status !== '' && $status !== 'confirmed') {
        continue;
    }

    $dt = pick_event_dt($j);
    if (!$dt) continue;

    $nights = (int)($j['nights'] ?? 0);

    if ($dt >= $cut30) {
        $stats['confirmed_30d']++;

        if ($nights > 0) {
            $stats['nights_sum_30d'] += $nights;
            $stats['nights_cnt_30d']++;
        }
    }

    if ($dt >= $cut90) {
        $conf90++;
    }

    $wk = week_key($dt);
    $confirmedPerWeek[$wk] = ($confirmedPerWeek[$wk] ?? 0) + 1;
}

/* 3) Cancellations scan */
$canc90 = 0;

foreach (iter_files($cancellationsRoot, '*.json') as $path) {
    $j = load_json($path);
    if (!is_array($j)) continue;

    $created = dt_from_iso($j['created_at'] ?? ($j['created'] ?? null));
    if (!$created) continue;

    if ($created >= $cut90) {
        $canc90++;
    }
}

$den = $canc90 + $conf90;
$cancelRate90 = $den > 0 ? ($canc90 / $den) * 100.0 : null;

$avgNights30 = ($stats['nights_cnt_30d'] > 0)
    ? round($stats['nights_sum_30d'] / $stats['nights_cnt_30d'], 1)
    : null;

$labels12 = last_n_weeks_labels(12);
$values12 = [];

foreach ($labels12 as $wk) {
    $values12[] = (int)($confirmedPerWeek[$wk] ?? 0);
}

json_response([
    'ok' => true,
    'generated' => (new DateTimeImmutable('now'))->format(DATE_ATOM),

    'stats' => [
        'inquiries_30d' => $stats['inquiries_30d'],
        'confirmed_30d' => $stats['confirmed_30d'],
        'avg_nights_30d' => $avgNights30,
        'cancel_rate_90d' => $cancelRate90,
    ],

    'series' => [
        'confirmed_per_week' => [
            'labels' => $labels12,
            'values' => $values12,
        ],
        'funnel_30d' => [
            'labels' => ['inquiries', 'accepted', 'confirmed'],
            'values' => [
                (int)$funnel['inquiries'],
                (int)$funnel['accepted'],
                (int)$stats['confirmed_30d'],
            ],
        ],
    ],

    'debug' => [
        'APP_COMMON' => APP_COMMON,
        'inquiriesRoot' => $inquiriesRoot,
        'inquiries_exists' => is_dir($inquiriesRoot),
        'reservationsRoot' => $reservationsRoot,
        'reservations_exists' => is_dir($reservationsRoot),
        'cancellationsRoot' => $cancellationsRoot,
        'cancellations_exists' => is_dir($cancellationsRoot),
        'confirmed_90d' => $conf90,
        'cancellations_90d' => $canc90,
    ],
]);