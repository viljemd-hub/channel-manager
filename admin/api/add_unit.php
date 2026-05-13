<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/add_unit.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * /app/admin/api/add_unit.php
 *
 * POST JSON (backward compatible):
 * { "id":"A3", "label":"Apartma A3" }
 *
 * Extended:
 * {
 *   "id":"A3",
 *   "label":"Apartma A3",
 *   "template_unit":"A2",              // optional
 *   "meta": {                          // optional → goes to manifest
 *     "property_id":"MATEVZ",
 *     "owner":"Matevž",
 *     "months_ahead":18,
 *     "clean_before":1,
 *     "clean_after":1,
 *     "active":true,
 *     "public":true
 *   },
 *   "copy": { "site_settings":true, "prices":true, "special_offers":true, "occupancy_sources":true },
 *   "reset": { "occupancy":true, "local_bookings":true, "merged":true }
 * }
 */
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/add_unit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/_lib/paths.php';

// ---------- JSON responses ----------
function au_error(string $code, string $message, int $httpStatus = 400, array $extra = []): void {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function au_ok(array $data = []): void {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---------- Helpers ----------
function au_safe_unit_id($s): string {
    return preg_replace('~[^A-Za-z0-9_-]~', '', (string)$s);
}

function au_ensure_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create directory: {$dir}");
    }
}

function au_read_json(string $path) {
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function au_write_json(string $path, $data): void {
    au_ensure_dir(dirname($path));

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    $tmp = $path . '.tmp-' . getmypid() . '-' . uniqid('', true);

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException("Cannot write temporary file: {$tmp}");
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot replace file: {$path}");
    }
}

function au_copy_if_exists(string $src, string $dst): bool {
    if (!is_file($src)) {
        return false;
    }

    au_ensure_dir(dirname($dst));

    if (!@copy($src, $dst)) {
        throw new RuntimeException("Cannot copy {$src} to {$dst}");
    }

    return true;
}

// ---------- Input ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    au_error('method_not_allowed', 'Use POST JSON.', 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($body)) {
    au_error('bad_json', 'Invalid JSON body.');
}

$id = au_safe_unit_id($body['id'] ?? '');
$label = trim((string)($body['label'] ?? ''));

if ($id === '') {
    au_error('missing_id', 'Missing unit id.');
}

if ($label === '') {
    $label = $id;
}

$template = au_safe_unit_id($body['template_unit'] ?? '');
$meta = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : [];

$copy = (isset($body['copy']) && is_array($body['copy'])) ? $body['copy'] : [];
$reset = (isset($body['reset']) && is_array($body['reset'])) ? $body['reset'] : [];

$copy = array_merge([
    'site_settings' => true,
    'prices' => true,
    'special_offers' => true,
    'occupancy_sources' => true,
], $copy);

$reset = array_merge([
    'occupancy' => true,
    'local_bookings' => true,
    'merged' => true,
], $reset);

// ---------- Paths ----------
$rootUnits = units_root();
$unitDir = $rootUnits . '/' . $id;
$manifestPath = $rootUnits . '/manifest.json';

$integrationsDir = integrations_root();
$integrationsPath = $integrationsDir . '/' . $id . '.json';

if (is_dir($unitDir) || file_exists($unitDir)) {
    au_error('unit_exists', 'Unit with this ID already exists.');
}

// ---------- Manifest ----------
$manifest = au_read_json($manifestPath);
if (!is_array($manifest)) {
    $manifest = ['units' => []];
}
if (!isset($manifest['units']) || !is_array($manifest['units'])) {
    $manifest['units'] = [];
}

foreach ($manifest['units'] as $u) {
    if (!is_array($u)) {
        continue;
    }

    $existing = (string)($u['id'] ?? $u['unit'] ?? '');
    if ($existing === $id) {
        au_error('unit_in_manifest', 'Unit with this ID already exists in manifest.json.');
    }
}

// ---------- Create unit ----------
$templateUsed = null;
$templateWarning = null;

try {
    au_ensure_dir($unitDir);

    $externalDir = $unitDir . '/external';
    $occupancyRawDir = $unitDir . '/occupancy_raw';

    au_ensure_dir($externalDir);
    au_ensure_dir($occupancyRawDir);

    // Template is optional. If missing, continue with baseline files.
    if ($template !== '') {
        $tplDir = $rootUnits . '/' . $template;

        if (is_dir($tplDir)) {
            $templateUsed = $template;

            if (!empty($copy['site_settings'])) {
                au_copy_if_exists($tplDir . '/site_settings.json', $unitDir . '/site_settings.json');
            }
            if (!empty($copy['prices'])) {
                au_copy_if_exists($tplDir . '/prices.json', $unitDir . '/prices.json');
            }
            if (!empty($copy['special_offers'])) {
                au_copy_if_exists($tplDir . '/special_offers.json', $unitDir . '/special_offers.json');
            }
            if (!empty($copy['occupancy_sources'])) {
                au_copy_if_exists($tplDir . '/occupancy_sources.json', $unitDir . '/occupancy_sources.json');
            }

            au_copy_if_exists($tplDir . '/day_use.json', $unitDir . '/day_use.json');
        } else {
            $templateWarning = "Template unit '{$template}' was not found. Baseline unit files were created instead.";
            error_log('[add_unit] ' . $templateWarning);
        }
    }

    // Core JSON files.
    if (!is_file($unitDir . '/local_bookings.json')) {
        au_write_json($unitDir . '/local_bookings.json', []);
    }
    if (!is_file($unitDir . '/occupancy.json')) {
        au_write_json($unitDir . '/occupancy.json', []);
    }
    if (!is_file($unitDir . '/occupancy_merged.json')) {
        au_write_json($unitDir . '/occupancy_merged.json', []);
    }
    if (!is_file($unitDir . '/occupancy_external_ics.json')) {
        au_write_json($unitDir . '/occupancy_external_ics.json', []);
    }
    if (!is_file($occupancyRawDir . '/local.json')) {
        au_write_json($occupancyRawDir . '/local.json', []);
    }

    if (!is_file($unitDir . '/occupancy_data.json')) {
        au_write_json($unitDir . '/occupancy_data.json', [
            'January' => 0,
            'February' => 0,
            'March' => 0,
            'April' => 0,
            'May' => 0,
            'June' => 0,
            'July' => 0,
            'August' => 0,
            'September' => 0,
            'October' => 0,
            'November' => 0,
            'December' => 0,
        ]);
    }

    if (!is_file($unitDir . '/day_use.json')) {
        au_write_json($unitDir . '/day_use.json', []);
    }

    if (!is_file($externalDir . '/booking_ics.json')) {
        au_write_json($externalDir . '/booking_ics.json', [
            'unit' => $id,
            'platform' => 'booking',
            'fetched_at' => null,
            'count' => 0,
            'events' => [],
        ]);
    }

    if (!is_file($unitDir . '/occupancy_sources.json')) {
        au_write_json($unitDir . '/occupancy_sources.json', [
            'channels' => [
                'local' => [
                    'type' => 'json',
                    'path' => $unitDir . '/local_bookings.json',
                    'enabled' => true,
                ],
                'booking' => [
                    'type' => 'ics',
                    'url' => '',
                    'enabled' => false,
                ],
                'airbnb' => [
                    'type' => 'ics',
                    'url' => '',
                    'enabled' => false,
                ],
            ],
            'priority' => ['local', 'booking', 'airbnb'],
            'suppressions' => [],
        ]);
    }

    if (!is_file($unitDir . '/prices.json')) {
        au_write_json($unitDir . '/prices.json', []);
    }

    if (!is_file($unitDir . '/special_offers.json')) {
        au_write_json($unitDir . '/special_offers.json', ['offers' => []]);
    }

    // Unit settings baseline / merge.
    $settingsPath = $unitDir . '/site_settings.json';
    $unitSettings = au_read_json($settingsPath);

    if (!is_array($unitSettings)) {
        $unitSettings = [
            'auto_block' => [
                'before_arrival' => false,
                'after_departure' => false,
            ],
            'day_use' => [
                'enabled' => false,
                'from' => '14:00',
                'to' => '20:00',
                'max_persons' => 1,
                'max_days_ahead' => 0,
                'day_price_person' => null,
            ],
            'month_render' => 12,
            'booking' => [
                'min_nights' => 1,
                'allow_same_day_departure' => false,
                'cleaning_fee_eur' => 0,
            ],
            'weekly_threshold' => 7,
            'weekly_discount_pct' => 10.0,
            'long_threshold' => 30,
            'long_discount_pct' => 20.0,
            'autopilot' => [
                'enabled' => false,
                'mode' => 'auto_confirm_on_accept',
                'min_days_before_arrival' => 0,
                'max_nights' => 0,
                'allowed_sources' => [],
                'check_ics_on_accept' => false,
                'check_ics_on_guest_confirm' => false,
            ],
            'display' => [
                'name' => $label,
                'short' => $id,
                'color' => '#007bff',
            ],
            'block_departure_day' => false,
        ];
    }

    if (!isset($unitSettings['booking']) || !is_array($unitSettings['booking'])) {
        $unitSettings['booking'] = [];
    }

    $unitSettings['booking'] = array_merge([
        'min_nights' => 1,
        'allow_same_day_departure' => false,
        'cleaning_fee_eur' => 0,
    ], $unitSettings['booking']);

    if (!isset($unitSettings['autopilot']) || !is_array($unitSettings['autopilot'])) {
        $unitSettings['autopilot'] = [];
    }

    $unitSettings['autopilot'] = array_merge([
        'enabled' => false,
        'mode' => 'auto_confirm_on_accept',
        'min_days_before_arrival' => 0,
        'max_nights' => 0,
        'allowed_sources' => [],
        'check_ics_on_accept' => false,
        'check_ics_on_guest_confirm' => false,
    ], $unitSettings['autopilot']);

    if (!isset($unitSettings['display']) || !is_array($unitSettings['display'])) {
        $unitSettings['display'] = [];
    }

    $unitSettings['display']['name'] = $label;
    $unitSettings['display']['short'] = $id;

    if (!array_key_exists('weekly_threshold', $unitSettings)) {
        $unitSettings['weekly_threshold'] = 7;
    }
    if (!array_key_exists('weekly_discount_pct', $unitSettings)) {
        $unitSettings['weekly_discount_pct'] = 10.0;
    }
    if (!array_key_exists('long_threshold', $unitSettings)) {
        $unitSettings['long_threshold'] = 30;
    }
    if (!array_key_exists('long_discount_pct', $unitSettings)) {
        $unitSettings['long_discount_pct'] = 20.0;
    }
    if (!array_key_exists('month_render', $unitSettings)) {
        $unitSettings['month_render'] = 12;
    }
    if (!array_key_exists('block_departure_day', $unitSettings)) {
        $unitSettings['block_departure_day'] = false;
    }

    // Optional overrides from Add Unit modal.
    if (array_key_exists('booking_min_nights', $meta) && $meta['booking_min_nights'] !== '') {
        $unitSettings['booking']['min_nights'] = (int)$meta['booking_min_nights'];
    }
    if (array_key_exists('booking_cleaning_fee_eur', $meta) && $meta['booking_cleaning_fee_eur'] !== '') {
        $unitSettings['booking']['cleaning_fee_eur'] = (float)$meta['booking_cleaning_fee_eur'];
    }
    if (array_key_exists('weekly_threshold', $meta) && $meta['weekly_threshold'] !== '') {
        $unitSettings['weekly_threshold'] = (int)$meta['weekly_threshold'];
    }
    if (array_key_exists('weekly_discount_pct', $meta) && $meta['weekly_discount_pct'] !== '') {
        $unitSettings['weekly_discount_pct'] = (float)$meta['weekly_discount_pct'];
    }
    if (array_key_exists('long_threshold', $meta) && $meta['long_threshold'] !== '') {
        $unitSettings['long_threshold'] = (int)$meta['long_threshold'];
    }
    if (array_key_exists('long_discount_pct', $meta) && $meta['long_discount_pct'] !== '') {
        $unitSettings['long_discount_pct'] = (float)$meta['long_discount_pct'];
    }

    au_write_json($settingsPath, $unitSettings);

    // Reset layers if requested.
    if (!empty($reset['local_bookings'])) {
        au_write_json($unitDir . '/local_bookings.json', []);
    }

    if (!empty($reset['occupancy'])) {
        au_write_json($unitDir . '/occupancy.json', []);
        au_write_json($unitDir . '/occupancy_external_ics.json', []);
        au_write_json($occupancyRawDir . '/local.json', []);
        au_write_json($externalDir . '/booking_ics.json', [
            'unit' => $id,
            'platform' => 'booking',
            'fetched_at' => null,
            'count' => 0,
            'events' => [],
        ]);
    }

    if (!empty($reset['merged'])) {
        au_write_json($unitDir . '/occupancy_merged.json', []);
    }

    // Integrations JSON.
    au_ensure_dir($integrationsDir);

    if (!is_file($integrationsPath)) {
        au_write_json($integrationsPath, [
            'unit' => $id,
            'connections' => [
                'booking' => [
                    'in' => [
                        'enabled' => false,
                        'ics_url' => '',
                    ],
                    'status' => [
                        'last_ok' => null,
                        'last_error' => null,
                    ],
                ],
            ],
        ]);
    }

} catch (Throwable $e) {
    au_error('unit_init_failed', 'Error while initializing unit: ' . $e->getMessage(), 500, [
        'unit_dir' => $unitDir,
        'template' => $template !== '' ? $template : null,
    ]);
}

// ---------- Update manifest ----------
$entry = [
    'id' => $id,
    'label' => $label,
    'name' => $label,
];

$allowedMeta = [
    'alias',
    'property_id',
    'owner',
    'months_ahead',
    'clean_before',
    'clean_after',
    'active',
    'public',
];

foreach ($allowedMeta as $key) {
    if (array_key_exists($key, $meta)) {
        $entry[$key] = $meta[$key];
    }
}

$manifest['units'][] = $entry;

try {
    au_write_json($manifestPath, $manifest);
} catch (Throwable $e) {
    au_error('manifest_write_error', 'Unit was created, but manifest.json could not be updated: ' . $e->getMessage(), 500, [
        'unit_dir' => $unitDir,
    ]);
}

$response = [
    'created' => $id,
    'label' => $label,
    'unit_dir' => $unitDir,
    'manifest' => $manifestPath,
    'manifestRow' => $entry,
    'template' => $templateUsed,
];

if ($templateWarning !== null) {
    $response['warning'] = $templateWarning;
}

au_ok($response);
