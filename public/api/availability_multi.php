<?php
declare(strict_types=1);

/**
 * CM PRO / CM Plus / CM Free
 * File: public/api/availability_multi.php
 *
 * Public read-only multi-unit availability endpoint.
 *
 * SYSTEM CHECK ONLY:
 * - This endpoint is intended for availability diagnostics / machine-readable checks.
 * - It does not create reservations.
 * - It does not create blocks.
 * - It does not confirm inquiries.
 *
 * Query params:
 *   - from=YYYY-MM-DD                    (required)
 *   - to=YYYY-MM-DD                      (required)
 *   - units=T1,A1,B2 | units=all         (required)
 *   - mode=basic|extended                (default: basic)
 *   - include_soft=0|1                   (default: 0)
 *   - limit=50                           (default: 50)  [only applies when units=all]
 *   - require_available=0|1              (default: 0)   [filter results]
 *   - best=0|1                           (default: 0)   [adds best_unit]
 *
 * Behavior:
 *   - reads occupancy_merged.json for each unit
 *   - excludes internal soft holds by default
 *   - can include soft holds only for internal/system checks
 *   - returns machine-readable availability for AI / partner platforms
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../admin/api/_lib/paths.php';

/* --------------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------------- */

function api_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function is_valid_iso_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function nights_between(string $from, string $to): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    return (int)$a->diff($b)->days;
}

function bool_query_param(string $key, bool $default = false): bool
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    $raw = strtolower(trim((string)$_GET[$key]));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function int_query_param(string $key, int $default, int $min, int $max): int
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $raw = trim((string)$_GET[$key]);
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
        return $default;
    }
    $v = (int)$raw;
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function now_iso_utc(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
    } catch (Throwable $e) {
        return gmdate('c');
    }
}

/**
 * Public/system-check availability filter.
 *
 * By default:
 * - exclude soft holds
 * - include confirmed / blocked / hard-reserved segments
 *
 * If includeSoft=true:
 * - internal soft holds are also treated as blocking
 *   (system/debug check only)
 */
function segment_blocks_range(array $seg, bool $includeSoft): bool
{
    $status = (string)($seg['status'] ?? '');
    $lock   = (string)($seg['lock'] ?? '');
    $source = (string)($seg['source'] ?? '');

    // Internal soft holds are excluded from public output by default.
    if ($lock === 'soft') {
        return $includeSoft;
    }

    // Explicit hard locks / hard facts are blocking.
    if ($lock === 'hard') {
        return true;
    }

    // Confirmed / blocked segments are blocking.
    if ($status === 'confirmed' || $status === 'blocked') {
        return true;
    }

    // Reserved segments without soft lock are treated as blocking.
    // This covers ICS hard facts and any future non-soft reserved segments.
    if ($status === 'reserved' && $lock !== 'soft') {
        return true;
    }

    // Conservative fallback for known sources.
    if (in_array($source, ['ics', 'internal', 'admin', 'local'], true) && $status !== '') {
        return in_array($status, ['reserved', 'confirmed', 'blocked'], true);
    }

    return false;
}

function normalize_conflict(array $seg): array
{
    $meta = isset($seg['meta']) && is_array($seg['meta']) ? $seg['meta'] : [];

    return [
        'id'       => (string)($seg['id'] ?? ''),
        'from'     => (string)($seg['start'] ?? ''),
        'to'       => (string)($seg['end'] ?? ''),
        'status'   => (string)($seg['status'] ?? ''),
        'lock'     => (string)($seg['lock'] ?? ''),
        'source'   => (string)($seg['source'] ?? ''),
        'platform' => (string)($seg['platform'] ?? ($meta['platform'] ?? '')),
        'summary'  => (string)($meta['summary'] ?? ''),
    ];
}

function build_reason(bool $available, int $conflictCount, bool $includeSoft): string
{
    if ($available) {
        return 'available';
    }

    if ($includeSoft) {
        return $conflictCount > 0 ? 'conflict_found_including_soft' : 'not_available';
    }

    return $conflictCount > 0 ? 'conflict_found' : 'not_available';
}

function is_valid_unit_id(string $unit): bool
{
    return $unit !== '' && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $unit);
}

/**
 * Scan units_root() and return unit IDs that have occupancy_merged.json present.
 * Hard cap is enforced by $limit.
 */
function scan_units_with_merged(int $limit): array
{
    $root = units_root();
    if (!is_dir($root)) {
        return [];
    }

    $out = [];
    $dh = opendir($root);
    if ($dh === false) {
        return [];
    }

    try {
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            // Skip weird names early
            if (!is_valid_unit_id($name)) {
                continue;
            }

            $p = $root . '/' . $name . '/occupancy_merged.json';
            if (is_file($p)) {
                $out[] = $name;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
    } finally {
        closedir($dh);
    }

    sort($out, SORT_STRING);
    return $out;
}

/* --------------------------------------------------------------------------
 * Input
 * -------------------------------------------------------------------------- */

$from            = trim((string)($_GET['from'] ?? ''));
$to              = trim((string)($_GET['to'] ?? ''));
$unitsRaw         = trim((string)($_GET['units'] ?? ''));
$mode            = strtolower(trim((string)($_GET['mode'] ?? 'basic')));
$includeSoft     = bool_query_param('include_soft', false);
$requireAvail    = bool_query_param('require_available', false);
$wantBest        = bool_query_param('best', false);
$limit           = int_query_param('limit', 50, 1, 200); // applies only for units=all

if ($mode === '') {
    $mode = 'basic';
}

if (!in_array($mode, ['basic', 'extended'], true)) {
    api_out([
        'ok'           => false,
        'error'        => 'invalid_mode',
        'reason'       => 'invalid_mode',
        'generated_at' => now_iso_utc(),
        'hint'         => 'Allowed values: basic, extended',
    ], 400);
}

if ($from === '' || $to === '' || $unitsRaw === '') {
    api_out([
        'ok'           => false,
        'error'        => 'missing_params',
        'reason'       => 'missing_params',
        'generated_at' => now_iso_utc(),
        'hint'         => 'Required params: from, to, units (CSV) or units=all',
    ], 400);
}

if (!is_valid_iso_date($from) || !is_valid_iso_date($to)) {
    api_out([
        'ok'           => false,
        'error'        => 'invalid_date_format',
        'reason'       => 'invalid_date_format',
        'generated_at' => now_iso_utc(),
        'hint'         => 'Expected YYYY-MM-DD',
    ], 400);
}

if ($from >= $to) {
    api_out([
        'ok'           => false,
        'error'        => 'invalid_range',
        'reason'       => 'invalid_range',
        'generated_at' => now_iso_utc(),
        'hint'         => '"from" must be earlier than "to"',
    ], 400);
}

$nights = nights_between($from, $to);
if ($nights <= 0) {
    api_out([
        'ok'           => false,
        'error'        => 'invalid_nights',
        'reason'       => 'invalid_nights',
        'generated_at' => now_iso_utc(),
    ], 400);
}

/* --------------------------------------------------------------------------
 * Resolve units list
 * -------------------------------------------------------------------------- */

$unitsRequested = [];
$unitsEvaluated = [];

if (strtolower($unitsRaw) === 'all') {
    $unitsRequested = ['all'];
    $unitsEvaluated = scan_units_with_merged($limit);

    if (empty($unitsEvaluated)) {
        api_out([
            'ok'             => false,
            'error'          => 'no_units_found',
            'reason'         => 'no_units_found',
            'generated_at'   => now_iso_utc(),
            'from'           => $from,
            'to'             => $to,
            'nights'         => $nights,
            'units_requested'=> $unitsRequested,
            'units_evaluated'=> 0,
            'hint'           => 'No units with occupancy_merged.json found under units_root()',
        ], 404);
    }
} else {
    $parts = preg_split('/\s*,\s*/', $unitsRaw, -1, PREG_SPLIT_NO_EMPTY);
    $unitsRequested = is_array($parts) ? $parts : [];

    // De-dup, preserve order
    $seen = [];
    foreach ($unitsRequested as $u) {
        $u = trim((string)$u);
        if ($u === '' || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $unitsEvaluated[] = $u;
    }

    if (empty($unitsEvaluated)) {
        api_out([
            'ok'           => false,
            'error'        => 'invalid_units',
            'reason'       => 'invalid_units',
            'generated_at' => now_iso_utc(),
            'hint'         => 'Provide units as CSV (e.g. units=T1,A1) or units=all',
        ], 400);
    }
}

/* --------------------------------------------------------------------------
 * Evaluate per-unit availability
 * -------------------------------------------------------------------------- */

$results = [];
$availableUnits = [];
$errors = [];

foreach ($unitsEvaluated as $unit) {
    $unit = trim((string)$unit);

    if (!is_valid_unit_id($unit)) {
        $results[$unit] = [
            'ok'             => false,
            'unit'           => $unit,
            'available'      => null,
            'reason'         => 'invalid_unit',
            'conflict_count' => null,
            'error'          => 'invalid_unit',
        ];
        $errors[] = ['unit' => $unit, 'error' => 'invalid_unit'];
        continue;
    }

    $mergedPath = units_root() . '/' . $unit . '/occupancy_merged.json';
    if (!is_file($mergedPath)) {
        $results[$unit] = [
            'ok'             => false,
            'unit'           => $unit,
            'available'      => null,
            'reason'         => 'unit_not_found',
            'conflict_count' => null,
            'error'          => 'unit_not_found',
        ];
        $errors[] = ['unit' => $unit, 'error' => 'unit_not_found'];
        continue;
    }

    $raw  = @file_get_contents($mergedPath);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        $results[$unit] = [
            'ok'             => false,
            'unit'           => $unit,
            'available'      => null,
            'reason'         => 'merged_state_invalid',
            'conflict_count' => null,
            'error'          => 'merged_state_invalid',
        ];
        $errors[] = ['unit' => $unit, 'error' => 'merged_state_invalid'];
        continue;
    }

    $conflicts = [];

    foreach ($data as $seg) {
        if (!is_array($seg)) {
            continue;
        }

        $segFrom = trim((string)($seg['start'] ?? ''));
        $segTo   = trim((string)($seg['end'] ?? ''));

        if ($segFrom === '' || $segTo === '') {
            continue;
        }

        if (!segment_blocks_range($seg, $includeSoft)) {
            continue;
        }

        if (!is_overlap($segFrom, $segTo, $from, $to)) {
            continue;
        }

        $conflicts[] = normalize_conflict($seg);
    }

    $available = empty($conflicts);
    $reason    = build_reason($available, count($conflicts), $includeSoft);

    $row = [
        'ok'             => true,
        'unit'           => $unit,
        'available'      => $available,
        'reason'         => $reason,
        'conflict_count' => count($conflicts),
    ];

    if ($mode === 'extended') {
        $row['conflicts'] = $conflicts;
        $row['meta'] = [
            'mode'                => 'extended',
            'query_layer'         => 'occupancy_merged',
            'soft_holds_included' => $includeSoft,
            'public_read_only'    => true,
            'system_check_only'   => true,
        ];
    }

    // Filter if requested
    if ($requireAvail && !$available) {
        continue;
    }

    $results[$unit] = $row;

    if ($available) {
        $availableUnits[] = $unit;
    }
}

/* --------------------------------------------------------------------------
 * Aggregate output
 * -------------------------------------------------------------------------- */

$payload = [
    'ok'              => true,
    'from'            => $from,
    'to'              => $to,
    'nights'          => $nights,
    'mode'            => $mode,
    'include_soft'    => $includeSoft,
    'require_available' => $requireAvail,
    'units_requested' => $unitsRequested,
    'units_evaluated' => count($unitsEvaluated),
    'results_count'   => count($results),
    'available_units' => $availableUnits,
    'available_count' => count($availableUnits),
    'generated_at'    => now_iso_utc(),
    'results'         => $results,
];

if (!empty($errors)) {
    $payload['errors'] = $errors;
}

if ($wantBest) {
    // Best = first available in current results order (results preserve evaluated order, except require_available filter)
    $payload['best_unit'] = !empty($availableUnits) ? $availableUnits[0] : null;
}

api_out($payload); 
