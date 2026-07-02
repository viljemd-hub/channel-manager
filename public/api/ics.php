<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/api/ics.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Public token-protected ICS OUT endpoint for external channels.
 *
 * New public URLs:
 *   /app/public/api/ics.php?unit=A1&channel=airbnb&key=...
 *   /app/public/api/ics.php?unit=A1&channel=booking&key=...
 *
 * Compatibility while admin UI catches up:
 *   /app/public/api/ics.php?unit=A1&mode=blocked&key=...  -> airbnb-style feed
 *   /app/public/api/ics.php?unit=A1&mode=booked&key=...   -> booking-style feed
 *
 * Data source:
 *   /var/www/html/app/common/data/json/units/<UNIT>/occupancy_merged.json
 *
 * Key priority:
 *   1) integrations/<UNIT>.json export.ics.<channel>.key
 *   2) integrations/<UNIT>.json export.ics.booked / blocked key, for mode compatibility
 *   3) integrations/<UNIT>.json keys.reservations_out / calendar_out legacy keys
 *
 * No PII is exported.
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

function cm_ics_stable_uid(string $unit, string $channel, array $seg): string {
    $id = $seg['id'] ?? null;
    if (is_string($id) && $id !== '') {
        return 'cm:' . $unit . ':' . $channel . ':' . sha1($id) . '@cm.local';
    }
    $status = (string)($seg['status'] ?? '');
    $start  = (string)($seg['start'] ?? '');
    $end    = (string)($seg['end'] ?? '');
    $source = (string)($seg['source'] ?? '');
    $reason = (string)($seg['reason'] ?? $seg['kind'] ?? '');
    return 'cm:' . $unit . ':' . $channel . ':' . sha1($status . '|' . $start . '|' . $end . '|' . $source . '|' . $reason) . '@cm.local';
}

function cm_ics_block_kind(array $seg): string {
    $reason = strtolower((string)($seg['reason'] ?? $seg['kind'] ?? ''));
    if ($reason === 'cleaning' || str_starts_with($reason, 'clean-')) return 'cleaning';
    if ($reason === 'maintenance' || str_contains($reason, 'maint')) return 'maintenance';
    return 'blocked';
}

function cm_ics_bool_from_settings(array $settings, string $key, bool $default): bool {
    if (!array_key_exists($key, $settings)) return $default;
    return (bool)$settings[$key];
}

function cm_ics_expected_key(array $cfg, string $channel, ?string $legacyMode): ?string {
    $exp = $cfg['export']['ics'] ?? null;
    if (is_array($exp)) {
        $channelKey = $exp[$channel]['key'] ?? null;
        if (is_string($channelKey) && $channelKey !== '') return $channelKey;

        if ($legacyMode === 'booked') {
            $legacyKey = $exp['booked']['key'] ?? null;
            if (is_string($legacyKey) && $legacyKey !== '') return $legacyKey;
        }
        if ($legacyMode === 'blocked') {
            $legacyKey = $exp['blocked']['key'] ?? null;
            if (is_string($legacyKey) && $legacyKey !== '') return $legacyKey;
        }
    }

    $keys = $cfg['keys'] ?? [];
    if (!is_array($keys)) $keys = [];

    if ($channel === 'booking') {
        $k = $keys['reservations_out'] ?? null;
        return (is_string($k) && $k !== '') ? $k : null;
    }

    $k = $keys['calendar_out'] ?? null;
    if (!is_string($k) || $k === '') $k = $keys['reservations_out'] ?? null;
    return (is_string($k) && $k !== '') ? $k : null;
}

function cm_ics_should_export_soft_to_booking(array $seg): bool {
    $export = $seg['export'] ?? null;
    if ($export === true) return true;

    foreach (['export_to_ics', 'export_booking_to_ics', 'export_to_booking_ics'] as $key) {
        if (($seg[$key] ?? false) === true) return true;
    }

    $meta = $seg['meta'] ?? [];
    if (is_array($meta)) {
        foreach (['export_to_ics', 'export_booking_to_ics', 'export_to_booking_ics'] as $key) {
            if (($meta[$key] ?? false) === true) return true;
        }
    }

    return false;
}

function cm_ics_event_summary(string $channel, array $seg): ?string {
    $status = strtolower((string)($seg['status'] ?? ''));
    $lock   = strtolower((string)($seg['lock'] ?? ''));

    $isReserved = ($status === 'reserved');
    $isBlocked  = ($status === 'blocked');
    $isHard     = ($lock === 'hard');
    $isSoft     = ($lock === 'soft');

    if ($channel === 'airbnb') {
        // Airbnb policy: hard rows become BOOKED; soft rows become CLOSED/BLOCKED.
        if ($isHard && $isReserved) return 'Reserved';
        if ($isHard && $isBlocked)  return match (cm_ics_block_kind($seg)) {
            'cleaning' => 'Cleaning',
            'maintenance' => 'Maintenance',
            default => 'Blocked',
        };
        if ($isSoft && ($isReserved || $isBlocked)) return 'Blocked';
        return null;
    }

    // Booking policy: hard rows become BOOKED; soft rows only when explicitly export-enabled.
    if ($isHard && ($isReserved || $isBlocked)) {
        return $isReserved ? 'Reserved' : match (cm_ics_block_kind($seg)) {
            'cleaning' => 'Cleaning',
            'maintenance' => 'Maintenance',
            default => 'Blocked',
        };
    }

    if ($isSoft && ($isReserved || $isBlocked) && cm_ics_should_export_soft_to_booking($seg)) {
        return 'Reserved';
    }

    return null;
}

$unit = $_GET['unit'] ?? '';
$key = $_GET['key'] ?? '';
$channelRaw = $_GET['channel'] ?? '';
$legacyModeRaw = $_GET['mode'] ?? '';

if (!is_string($unit) || !preg_match('/^[A-Za-z0-9_-]+$/', $unit)) {
    cm_ics_bad(400, 'Bad or missing unit.');
}
if (!is_string($key) || $key === '') {
    cm_ics_bad(400, 'Missing key.');
}

$legacyMode = is_string($legacyModeRaw) ? strtolower($legacyModeRaw) : '';
$channel = is_string($channelRaw) ? strtolower($channelRaw) : '';

if ($channel === '') {
    if ($legacyMode === 'booked') $channel = 'booking';
    elseif ($legacyMode === 'blocked') $channel = 'airbnb';
}

if (!in_array($channel, ['airbnb', 'booking'], true)) {
    cm_ics_bad(400, 'Bad or missing channel. Use channel=airbnb or channel=booking.');
}

if (!in_array($legacyMode, ['booked', 'blocked'], true)) {
    $legacyMode = null;
}

$dataRoot = '/var/www/html/app/common/data/json';
$cfgPath = $dataRoot . "/integrations/{$unit}.json";
$cfg = cm_ics_read_json($cfgPath);
if (!$cfg) {
    cm_ics_bad(404, "Unit not configured: {$unit}");
}

$expectedKey = cm_ics_expected_key($cfg, $channel, $legacyMode);
if (!$expectedKey || !hash_equals($expectedKey, (string)$key)) {
    cm_ics_bad(403, 'Forbidden: bad key.');
}

$unitDir = $dataRoot . "/units/{$unit}";
$settings = cm_ics_read_json($unitDir . '/site_settings.json') ?? [];
$exportCleanBefore = cm_ics_bool_from_settings($settings, 'export_clean_before_to_ics', true);
$exportCleanAfter  = cm_ics_bool_from_settings($settings, 'export_clean_after_to_ics', true);

$segments = cm_ics_read_json($unitDir . '/occupancy_merged.json');
if (!is_array($segments)) $segments = [];

$title = strtoupper($channel) . " availability {$unit}";
$builder = new IcsBuilder('-//ChannelManager//Public ICS OUT 1.0//EN', $title);
$builder->begin();

foreach ($segments as $seg) {
    if (!is_array($seg)) continue;

    $start = $seg['start'] ?? null;
    $end   = $seg['end'] ?? null;
    if (!cm_ics_valid_ymd($start) || !cm_ics_valid_ymd($end)) continue;

    $kind = cm_ics_block_kind($seg);
    if ($kind === 'cleaning') {
        $id = (string)($seg['id'] ?? '');
        $isBefore = str_contains($id, 'clean-before') || (($seg['meta']['cleaning_position'] ?? '') === 'before');
        $isAfter  = str_contains($id, 'clean-after')  || (($seg['meta']['cleaning_position'] ?? '') === 'after');
        if ($isBefore && !$exportCleanBefore) continue;
        if ($isAfter && !$exportCleanAfter) continue;
    }

    $summary = cm_ics_event_summary($channel, $seg);
    if ($summary === null) continue;

    $builder->addAllDayEvent([
        'summary' => $summary,
        'start' => $start,
        'end' => $end,
        'uid' => cm_ics_stable_uid($unit, $channel, $seg),
    ]);
}

$builder->end();
echo $builder->render();
