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

declare(strict_types=1);

// ---------- Helpers ----------
function send_json_error(string $code, string $message, int $httpStatus = 400, array $extra = []): void {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok'      => false,
        'error'   => $code,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function send_json_ok(array $data = []): void {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function write_json_file(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ne morem ustvariti mape: {$dir}");
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('json_encode failed: ' . json_last_error_msg());

    $tmpPath = $path . '.tmp-' . getmypid() . '-' . uniqid('', true);
    if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        @unlink($tmpPath);
        throw new RuntimeException("Ne morem zapisati: {$tmpPath}");
    }
    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException("Ne morem zamenjati datoteke: {$path}");
    }
}
function read_json_file(string $path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}
function ensure_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ne morem ustvariti mape: {$dir}");
    }
}
function safe_unit_id($s): string {
    return preg_replace('~[^A-Za-z0-9_-]~', '', (string)$s);
}
function copy_file_if_exists(string $src, string $dst): bool {
    if (!is_file($src)) return false;
    ensure_dir(dirname($dst));
    return @copy($src, $dst);
}
function deep_merge(array $base, array $over): array {
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = deep_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

// ---------- Input ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_json_error('method_not_allowed', 'Uporabi POST JSON.', 405);
}
$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    send_json_error('bad_json', 'Neveljaven JSON body.');
}

$id    = safe_unit_id($body['id'] ?? '');
$label = trim((string)($body['label'] ?? ''));

if ($id === '') send_json_error('missing_id', 'Manjka id.');
if ($label === '') $label = $id;

$template = safe_unit_id($body['template_unit'] ?? '');
$meta     = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : [];

$copy = (isset($body['copy']) && is_array($body['copy'])) ? $body['copy'] : [];
$reset = (isset($body['reset']) && is_array($body['reset'])) ? $body['reset'] : [];

// sensible defaults
$copy = array_merge([
    'site_settings'     => true,
    'prices'            => true,
    'special_offers'    => true,
    'occupancy_sources' => true,
], $copy);

$reset = array_merge([
    'occupancy'      => true,
    'local_bookings' => true,
    'merged'         => true,
], $reset);

// ---------- Paths ----------
$rootUnits      = '/var/www/html/app/common/data/json/units';
$unitDir        = $rootUnits . '/' . $id;
$manifestPath   = $rootUnits . '/manifest.json';

$integrationsDir  = '/var/www/html/app/common/data/json/integrations';
$integrationsPath = $integrationsDir . '/' . $id . '.json';

// ---------- Exists? ----------
if (is_dir($unitDir) || file_exists($unitDir)) {
    send_json_error('unit_exists', 'Enota s takim ID že obstaja.');
}

// ---------- Manifest load / check ----------
$manifest = read_json_file($manifestPath);
if (!is_array($manifest)) $manifest = ['units' => []];
if (!isset($manifest['units']) || !is_array($manifest['units'])) $manifest['units'] = [];

foreach ($manifest['units'] as $u) {
    if (!is_array($u)) continue;
    $existing = (string)($u['id'] ?? $u['unit'] ?? '');
    if ($existing === $id) {
        send_json_error('unit_in_manifest', 'Enota s takim ID že obstaja v manifest.json.');
    }
}

// ---------- Create / init ----------
try {
    ensure_dir($unitDir);
    $externalDir     = $unitDir . '/external';
    $occupancyRawDir = $unitDir . '/occupancy_raw';
    ensure_dir($externalDir);
    ensure_dir($occupancyRawDir);

    // If template was provided and exists, copy selected files first (optional feature)
    if ($template !== '') {
        $tplDir = $rootUnits . '/' . $template;

        if (is_dir($tplDir)) {
            // Template directory exists → copy what we can
            if (!empty($copy['site_settings'])) {
                copy_file_if_exists($tplDir . '/site_settings.json', $unitDir . '/site_settings.json');
            }
            if (!empty($copy['prices'])) {
                copy_file_if_exists($tplDir . '/prices.json', $unitDir . '/prices.json');
            }
            if (!empty($copy['special_offers'])) {
                copy_file_if_exists($tplDir . '/special_offers.json', $unitDir . '/special_offers.json');
            }
            if (!empty($copy['occupancy_sources'])) {
                copy_file_if_exists($tplDir . '/occupancy_sources.json', $unitDir . '/occupancy_sources.json');
            }
            // optional: copy day_use.json baseline if exists
            copy_file_if_exists($tplDir . '/day_use.json', $unitDir . '/day_use.json');
        } else {
            // Template is optional in CM Free – if it does not exist, just ignore it
            $template = '';
        }
    }

    // Ensure core files exist (create if missing)
    if (!is_file($unitDir . '/local_bookings.json')) write_json_file($unitDir . '/local_bookings.json', []);
    if (!is_file($unitDir . '/occupancy.json')) write_json_file($unitDir . '/occupancy.json', []);
    if (!is_file($unitDir . '/occupancy_merged.json')) write_json_file($unitDir . '/occupancy_merged.json', []);
    if (!is_file($unitDir . '/occupancy_external_ics.json')) write_json_file($unitDir . '/occupancy_external_ics.json', []);
    if (!is_file($occupancyRawDir . '/local.json')) write_json_file($occupancyRawDir . '/local.json', []);

    if (!is_file($unitDir . '/occupancy_data.json')) {
        write_json_file($unitDir . '/occupancy_data.json', [
            'January'=>0,'February'=>0,'March'=>0,'April'=>0,'May'=>0,'June'=>0,
            'July'=>0,'August'=>0,'September'=>0,'October'=>0,'November'=>0,'December'=>0,
        ]);
    }
    if (!is_file($unitDir . '/day_use.json')) write_json_file($unitDir . '/day_use.json', new stdClass());

    // external cache placeholder
    if (!is_file($externalDir . '/booking_ics.json')) {
        write_json_file($externalDir . '/booking_ics.json', [
            'unit' => $id,
            'platform' => 'booking',
            'fetched_at' => null,
            'count' => 0,
            'events' => [],
        ]);
    }

    // occupancy_sources default if still missing
    if (!is_file($unitDir . '/occupancy_sources.json')) {
        $occupancySources = [
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
        ];
        write_json_file($unitDir . '/occupancy_sources.json', $occupancySources);
    }

    // prices + offers defaults if missing
    if (!is_file($unitDir . '/prices.json')) write_json_file($unitDir . '/prices.json', new stdClass());
    if (!is_file($unitDir . '/special_offers.json')) write_json_file($unitDir . '/special_offers.json', ['offers' => []]);

// site_settings default if missing (or merge-in unit identity)
$settingsPath  = $unitDir . '/site_settings.json';
$unitSettings  = read_json_file($settingsPath);

// 1) Če ne obstaja, zgradi baseline (brez template-a)
if (!is_array($unitSettings)) {
    $unitSettings = [
        'auto_block' => [
            'before_arrival'  => false,
            'after_departure' => false,
        ],
        'day_use' => [
            'enabled'          => false,
            'from'             => '14:00',
            'to'               => '20:00',
            'max_persons'      => 1,
            'max_days_ahead'   => 0,
            'day_price_person' => null,
        ],
        'month_render' => 12,
        'booking' => [
            'min_nights'               => 1,
            'allow_same_day_departure' => false,
            'cleaning_fee_eur'         => 0,
        ],
        // privzeti popusti za novo enoto (v nočeh)
        'weekly_threshold'    => 7,
        'weekly_discount_pct' => 10.0,
        'long_threshold'      => 30,
        'long_discount_pct'   => 20.0,
        'autopilot' => [
            'enabled'                  => false,
            'mode'                     => 'auto_confirm_on_accept',
            'min_days_before_arrival'  => 0,
            'max_nights'               => 0,
            'allowed_sources'          => [],
            'check_ics_on_accept'      => false,
            'check_ics_on_guest_confirm' => false,
        ],
        'display' => [
            'name'  => $label,
            'short' => $id,
            'color' => '#007bff',
        ],
        'block_departure_day' => false,
    ];
} else {
    // 2) Template obstaja: poskrbi za minimalne strukture / defaulte
    if (!isset($unitSettings['booking']) || !is_array($unitSettings['booking'])) {
        $unitSettings['booking'] = [
            'min_nights'               => 1,
            'allow_same_day_departure' => false,
            'cleaning_fee_eur'         => 0,
        ];
    }
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
    if (!isset($unitSettings['autopilot']) || !is_array($unitSettings['autopilot'])) {
        $unitSettings['autopilot'] = [
            'enabled'                  => false,
            'mode'                     => 'auto_confirm_on_accept',
            'min_days_before_arrival'  => 0,
            'max_nights'               => 0,
            'allowed_sources'          => [],
            'check_ics_on_accept'      => false,
            'check_ics_on_guest_confirm' => false,
        ];
    }
}

// 3) Vedno nastavi identiteto (display)
if (!isset($unitSettings['display']) || !is_array($unitSettings['display'])) {
    $unitSettings['display'] = [];
}
$unitSettings['display']['name']  = $label;
$unitSettings['display']['short'] = $id;

// 4) Aplikacija morebitnih override-ov iz meta (Add Unit modal)
$bm = $meta['booking_min_nights']       ?? null;
$cf = $meta['booking_cleaning_fee_eur'] ?? null;
$wt = $meta['weekly_threshold']         ?? null;
$wd = $meta['weekly_discount_pct']      ?? null;
$lt = $meta['long_threshold']           ?? null;
$ld = $meta['long_discount_pct']        ?? null;

if ($bm !== null && $bm !== '') {
    $unitSettings['booking']['min_nights'] = (int)$bm;
}
if ($cf !== null && $cf !== '') {
    $unitSettings['booking']['cleaning_fee_eur'] = (float)$cf;
}
if ($wt !== null && $wt !== '') {
    $unitSettings['weekly_threshold'] = (int)$wt;
}
if ($wd !== null && $wd !== '') {
    $unitSettings['weekly_discount_pct'] = (float)$wd;
}
if ($lt !== null && $lt !== '') {
    $unitSettings['long_threshold'] = (int)$lt;
}
if ($ld !== null && $ld !== '') {
    $unitSettings['long_discount_pct'] = (float)$ld;
}

write_json_file($settingsPath, $unitSettings);

    // RESET layers (if requested)
    if (!empty($reset['local_bookings'])) {
        write_json_file($unitDir . '/local_bookings.json', []);
    }
    if (!empty($reset['occupancy'])) {
        write_json_file($unitDir . '/occupancy.json', []);
        write_json_file($unitDir . '/occupancy_external_ics.json', []);
        write_json_file($occupancyRawDir . '/local.json', []);
        // keep external/booking_ics.json structure but empty it
        write_json_file($externalDir . '/booking_ics.json', [
            'unit' => $id,
            'platform' => 'booking',
            'fetched_at' => null,
            'count' => 0,
            'events' => [],
        ]);
    }
    if (!empty($reset['merged'])) {
        write_json_file($unitDir . '/occupancy_merged.json', []);
    }

    // Ensure integrations/<unit>.json exists (Channels/ICS IN lab reads this)
    ensure_dir($integrationsDir);
    if (!is_file($integrationsPath)) {
        write_json_file($integrationsPath, [
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
                    ]
                ]
            ]
        ]);
    }

} catch (Throwable $e) {
    send_json_error('unit_init_failed', 'Napaka pri inicializaciji nove enote: ' . $e->getMessage(), 500, [
        'unit_dir' => $unitDir,
        'template' => $template,
    ]);
}

// ---------- Update manifest ----------
$entry = [
    'id'    => $id,
    'label' => $label,
    'name'  => $label,
];

// Only allow known meta keys to avoid junk in manifest
$allowedMeta = ['property_id','owner','months_ahead','clean_before','clean_after','active','public'];
foreach ($allowedMeta as $k) {
    if (array_key_exists($k, $meta)) $entry[$k] = $meta[$k];
}

$manifest['units'][] = $entry;

try {
    write_json_file($manifestPath, $manifest);
} catch (Throwable $e) {
    send_json_error('manifest_write_error', 'Enota je bila ustvarjena, a zapis v manifest.json ni uspel: ' . $e->getMessage(), 500, [
        'unit_dir' => $unitDir,
    ]);
}

send_json_ok([
    'created'     => $id,
    'label'       => $label,
    'unit_dir'    => $unitDir,
    'template'    => $template ?: null,
    'manifestRow' => $entry,
]);
