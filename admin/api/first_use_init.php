<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/first_use_init.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/first_use_init.php
declare(strict_types=1);

require __DIR__ . '/_lib/json_io.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, array $extra = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $in = require_post_json();
} catch (Throwable $e) {
  respond(false, ['error' => 'invalid_json'], 400);
}

$root = '/var/www/html/app';
$common = $root . '/common/data/json';
$unitsRoot = $common . '/units';

$ownerName  = trim((string)($in['owner_name'] ?? ''));
$ownerEmail = trim((string)($in['owner_email'] ?? ''));
$domain     = trim((string)($in['domain'] ?? ''));
$publicIp   = trim((string)($in['public_ip'] ?? ''));
$installPath= trim((string)($in['install_path'] ?? '/app'));
$tz         = trim((string)($in['timezone'] ?? 'Europe/Ljubljana'));

$unitId     = strtoupper(trim((string)($in['unit_id'] ?? '')));
$unitLabel  = trim((string)($in['unit_label'] ?? $unitId));

if ($unitId === '') respond(false, ['error' => 'missing_unit_id'], 400);
if (!preg_match('/^[A-Z0-9_]{1,12}$/', $unitId)) respond(false, ['error' => 'bad_unit_id'], 400);
if ($installPath === '') $installPath = '/app';
if ($installPath[0] !== '/') $installPath = '/' . $installPath;

// compute base_url
// priority: domain → public_ip → current request host
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

$baseUrl = '';
if ($domain !== '') {
  $baseUrl = 'https://' . $domain;
} elseif ($publicIp !== '') {
  // default http for raw IP (can be upgraded later)
  $baseUrl = 'http://' . $publicIp;
} else {
  $baseUrl = $scheme . '://' . $host;
}

// ensure directories
@mkdir($common, 0775, true);
@mkdir($unitsRoot, 0775, true);

// 1) instance.json
$instanceFile = $common . '/instance.json';
$instance = [
  'initialized' => true,
  'instance' => [
    'base_url' => $baseUrl,
    'domain' => ($domain !== '' ? $domain : null),
    'fallback_ip' => ($publicIp !== '' ? $publicIp : null),
    'installed_path' => $installPath,
    'timezone' => ($tz !== '' ? $tz : 'Europe/Ljubljana'),
    'created_at' => gmdate('c'),
  ],
  'owner' => [
    'name' => $ownerName,
    'email' => $ownerEmail,
  ],
];
write_json($instanceFile, $instance);

// 2) manifest.json (create if missing; ensure unit is present)
$manifestFile = $unitsRoot . '/manifest.json';
$manifest = is_file($manifestFile) ? read_json($manifestFile) : null;
if (!is_array($manifest)) $manifest = [];

$units = $manifest['units'] ?? [];
if (!is_array($units)) $units = [];

// normalize units array to [{id,label,active}]
$norm = [];
foreach ($units as $u) {
  if (is_string($u)) {
    $norm[] = ['id' => $u, 'label' => $u, 'active' => true];
  } elseif (is_array($u) && isset($u['id'])) {
    $norm[] = [
      'id' => (string)$u['id'],
      'label' => (string)($u['label'] ?? $u['id']),
      'active' => ($u['active'] ?? true) !== false,
    ];
  }
}

// add if missing
$exists = false;
foreach ($norm as $u) {
  if (($u['id'] ?? '') === $unitId) { $exists = true; break; }
}
if (!$exists) {
  $norm[] = ['id' => $unitId, 'label' => ($unitLabel ?: $unitId), 'active' => true];
}

$manifest['units'] = $norm;
$manifest['generated_by'] = 'first_use_init';
$manifest['updated_at'] = gmdate('c');
write_json($manifestFile, $manifest);

// 3) per-unit templates
$unitDir = $unitsRoot . '/' . $unitId;
@mkdir($unitDir, 0775, true);

// site_settings.json (minimal defaults)
$siteSettingsFile = $unitDir . '/site_settings.json';
if (!is_file($siteSettingsFile)) {
  $site = [
    'display' => ['month_render' => 13],
    'booking' => ['min_nights' => 1],
    'auto_block' => ['before_arrival' => false, 'after_departure' => false],
    'day_use' => ['enabled' => false, 'max_days_ahead' => 7],
    'email' => ['enabled' => true],
  ];
  write_json($siteSettingsFile, $site);
}

// required JSONs to avoid 404 / parse issues
$defaults = [
  $unitDir . '/prices.json'            => new stdClass(), // {}
  $unitDir . '/occupancy.json'         => [],             // []
  $unitDir . '/occupancy_merged.json'  => [],             // []
  $unitDir . '/local_bookings.json'    => [],             // []
  $unitDir . '/day_use.json'           => new stdClass(), // {}
  $unitDir . '/special_offers.json'    => ['offers' => []],
];

foreach ($defaults as $file => $content) {
  if (!is_file($file)) write_json($file, $content);
}

// Ensure marked_pending.json exists and is valid (you already have it; we keep it non-destructive)
$markedPending = $common . '/marked_pending.json';
if (!is_file($markedPending)) {
  write_json($markedPending, []); // []
}

respond(true, [
  'base_url' => $baseUrl,
  'install_path' => $installPath,
  'unit' => $unitId,
  'created' => [
    'instance.json',
    'units/manifest.json',
    "units/{$unitId}/site_settings.json",
    "units/{$unitId}/prices.json",
    "units/{$unitId}/occupancy.json",
    "units/{$unitId}/occupancy_merged.json",
    "units/{$unitId}/local_bookings.json",
    "units/{$unitId}/day_use.json",
    "units/{$unitId}/special_offers.json",
  ],
]);
