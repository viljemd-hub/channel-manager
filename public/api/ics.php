<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/api/ics.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Public token-protected ICS OUT endpoint.
 *
 * Business model:
 *   - CM is the source of truth.
 *   - Each external platform is an OUT connector with its own tokens.
 *   - Each connector can expose two feed modes:
 *       mode=booked  -> hard reservations only
 *       mode=blocked -> hard reservations + hard admin blocks
 *
 * Preferred URLs:
 *   /app/public/api/ics.php?unit=A1&connector=airbnb&mode=booked&key=...
 *   /app/public/api/ics.php?unit=A1&connector=airbnb&mode=blocked&key=...
 *   /app/public/api/ics.php?unit=A1&connector=booking&mode=booked&key=...
 *   /app/public/api/ics.php?unit=A1&connector=booking&mode=blocked&key=...
 *
 * Preferred config in integrations/<UNIT>.json:
 *   {
 *     "connections": { ... ICS IN ... },
 *     "connectors": {
 *       "airbnb": {
 *         "out": {
 *           "enabled": true,
 *           "label": "Airbnb",
 *           "booked":  { "enabled": true, "key": "..." },
 *           "blocked": { "enabled": true, "key": "..." }
 *         }
 *       }
 *     }
 *   }
 *
 * Legacy fallback without connector remains supported:
 *   /app/public/api/ics.php?unit=A1&mode=booked&key=...
 *   /app/public/api/ics.php?unit=A1&mode=blocked&key=...
 *
 * Legacy key priority:
 *   1) export.ics.booked.key / export.ics.blocked.key
 *   2) keys.reservations_out / keys.calendar_out
 *
 * Data source:
 *   /var/www/html/app/common/data/json/units/<UNIT>/occupancy_merged.json
 *
 * Privacy:
 *   - No guest PII is exported.
 */

declare(strict_types=1);

use App\ICS\IcsBuilder;

require_once __DIR__ . '/../../common/lib/ics_builder.php';

header('Content-Type: text/calendar; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function cm_ics_bad(int $code, string $msg): void {
    http_response_code($code);
    $b = new IcsBuilder('-//ChannelManager//ICS Error//EN', 'ICS error');
    $b->begin();
    $b->addAllDayEvent([
        'summary' => 'ICS error',
        'start' => '2000-01-01',
        'end' => '2000-01-02',
        'uid' => sha1('ics-error-' . $msg) . '@cm.local',
        'description' => $msg,
    ]);
    $b->end();
    echo $b->render();
    exit;
}

function cm_ics_read_json(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function cm_ics_valid_ymd(?string $s): bool {
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
}

function cm_ics_slug(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $s) ? $s : '';
}

function cm_ics_block_kind(array $seg): string {
    $reason = strtolower((string)($seg['reason'] ?? $seg['kind'] ?? ''));
    if ($reason === 'cleaning' || str_starts_with($reason, 'clean-')) return 'cleaning';
    if ($reason === 'maintenance' || str_contains($reason, 'maint')) return 'maintenance';
    return 'blocked';
}

function cm_ics_stable_uid(string $unit, string $scope, array $seg): string {
    $id = $seg['id'] ?? null;
    if (is_string($id) && $id !== '') {
        return 'cm:' . $unit . ':' . $scope . ':' . sha1($id) . '@cm.local';
    }
    $status = (string)($seg['status'] ?? '');
    $start  = (string)($seg['start'] ?? '');
    $end    = (string)($seg['end'] ?? '');
    $source = (string)($seg['source'] ?? '');
    $reason = (string)($seg['reason'] ?? $seg['kind'] ?? '');
    return 'cm:' . $unit . ':' . $scope . ':' . sha1($status . '|' . $start . '|' . $end . '|' . $source . '|' . $reason) . '@cm.local';
}

function cm_ics_feed_summary(array $seg): string {
    $status = strtolower((string)($seg['status'] ?? ''));
    if ($status === 'reserved') return 'Reserved';

    return match (cm_ics_block_kind($seg)) {
        'cleaning' => 'Cleaning',
        'maintenance' => 'Maintenance',
        default => 'Blocked',
    };
}

function cm_ics_connector_out(array $cfg, string $connector): ?array {
    if ($connector === '') return null;

    // Preferred CM Free structure: connectors.<name>.out
    $top = $cfg['connectors'][$connector]['out'] ?? null;
    if (is_array($top)) return $top;

    // Tolerant fallback for early experiments: export.ics.connectors.<name>.out
    $nested = $cfg['export']['ics']['connectors'][$connector]['out'] ?? null;
    if (is_array($nested)) return $nested;

    return null;
}

function cm_ics_bool_enabled(array $cfg, string $key, bool $default = true): bool {
    if (!array_key_exists($key, $cfg)) return $default;
    return (bool)$cfg[$key];
}

function cm_ics_expected_key(array $cfg, string $mode, string $connector, array &$meta): ?string {
    $meta = [
        'source' => 'legacy',
        'connector' => $connector,
        'label' => $connector,
    ];

    if ($connector !== '') {
        $out = cm_ics_connector_out($cfg, $connector);
        if (!$out) {
            cm_ics_bad(404, "ICS connector not configured: {$connector}");
        }

        if (!cm_ics_bool_enabled($out, 'enabled', true)) {
            cm_ics_bad(403, "ICS connector disabled: {$connector}");
        }

        $modeCfg = $out[$mode] ?? null;
        if (!is_array($modeCfg)) {
            cm_ics_bad(404, "ICS connector mode not configured: {$connector}/{$mode}");
        }

        if (!cm_ics_bool_enabled($modeCfg, 'enabled', true)) {
            cm_ics_bad(403, "ICS connector mode disabled: {$connector}/{$mode}");
        }

        $k = $modeCfg['key'] ?? null;
        if (!is_string($k) || $k === '') {
            cm_ics_bad(403, "ICS connector key missing: {$connector}/{$mode}");
        }

        $meta = [
            'source' => 'connector',
            'connector' => $connector,
            'label' => (is_string($out['label'] ?? null) && trim((string)$out['label']) !== '')
                ? trim((string)$out['label'])
                : $connector,
        ];
        return $k;
    }

    // Legacy mode-based export. Keep this until old URLs are rotated away.
    $exp = $cfg['export']['ics'] ?? null;
    if (is_array($exp)) {
        $legacyKey = $exp[$mode]['key'] ?? null;
        if (is_string($legacyKey) && $legacyKey !== '') return $legacyKey;
    }

    $keys = $cfg['keys'] ?? [];
    if (!is_array($keys)) $keys = [];

    if ($mode === 'booked') {
        $k = $keys['reservations_out'] ?? null;
        return (is_string($k) && $k !== '') ? $k : null;
    }

    $k = $keys['calendar_out'] ?? null;
    if (!is_string($k) || $k === '') $k = $keys['reservations_out'] ?? null;
    return (is_string($k) && $k !== '') ? $k : null;
}

$unit = cm_ics_slug($_GET['unit'] ?? '');
$connector = cm_ics_slug($_GET['connector'] ?? '');
$key = $_GET['key'] ?? '';
$modeRaw = strtolower((string)($_GET['mode'] ?? 'blocked'));
$mode = ($modeRaw === 'booked') ? 'booked' : 'blocked';

if ($unit === '') {
    cm_ics_bad(400, 'Bad or missing unit.');
}
if (!is_string($key) || $key === '') {
    cm_ics_bad(400, 'Missing key.');
}

$dataRoot = '/var/www/html/app/common/data/json';
$cfgPath = $dataRoot . "/integrations/{$unit}.json";
$cfg = cm_ics_read_json($cfgPath);
if (!$cfg) {
    cm_ics_bad(404, "Unit not configured: {$unit}");
}

$keyMeta = [];
$expectedKey = cm_ics_expected_key($cfg, $mode, $connector, $keyMeta);
if (!$expectedKey || !hash_equals($expectedKey, (string)$key)) {
    cm_ics_bad(403, 'Forbidden: bad key.');
}

$mergedPath = $dataRoot . "/units/{$unit}/occupancy_merged.json";
$segments = cm_ics_read_json($mergedPath);
if (!is_array($segments)) $segments = [];

$scope = ($connector !== '') ? $connector . '-' . $mode : 'legacy-' . $mode;
$titlePrefix = ($connector !== '') ? strtoupper((string)$keyMeta['label']) . ' ' : '';
$title = $titlePrefix . (($mode === 'booked') ? "Booked {$unit}" : "Booked+Blocked {$unit}");

$builder = new IcsBuilder('-//ChannelManager//Public ICS OUT 2.0//EN', $title);
$builder->begin();

foreach ($segments as $seg) {
    if (!is_array($seg)) continue;

    $start = $seg['start'] ?? null;
    $end   = $seg['end'] ?? null;
    if (!cm_ics_valid_ymd($start) || !cm_ics_valid_ymd($end)) continue;

    $status = strtolower((string)($seg['status'] ?? ''));
    $lock   = strtolower((string)($seg['lock'] ?? ''));

    if ($lock !== 'hard') continue;

    $isReserved = ($status === 'reserved');
    $isBlocked  = ($status === 'blocked');

    if ($mode === 'booked') {
        if (!$isReserved) continue;
    } else {
        if (!$isReserved && !$isBlocked) continue;
        if (($seg['export'] ?? null) === false) continue;
    }

    $builder->addAllDayEvent([
        'summary' => cm_ics_feed_summary($seg),
        'start' => $start,
        'end' => $end,
        'uid' => cm_ics_stable_uid($unit, $scope, $seg),
    ]);
}

$builder->end();
echo $builder->render();
