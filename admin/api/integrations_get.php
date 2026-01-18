<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Read integrations configuration for admin UI.
 *
 * Responsibilities:
 * - Load global and per-unit integration settings from
 *   /common/data/json/integrations/*.json.
 * - Return a merged JSON structure suitable for the integrations.js UI.
 *
 * Used by:
 * - admin/ui/js/integrations.js (initial page load).
 *
 * Notes:
 * - This endpoint is read-only; saving changes goes through
 *   integrations_save.php.
 */

// /var/www/html/app/admin/api/integrations_get.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = '/var/www/html/app';
$unitsDir = $root.'/common/data/json/units';
$connsFile = $root.'/common/data/json/integrations/connections.json';
$siteFile  = $root.'/common/data/json/site_settings.json';

$baseUrl = (function(){
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $proto.'://'.$host;
})();

$units = [];
if (is_dir($unitsDir)) {
  foreach (scandir($unitsDir) ?: [] as $d) {
    if ($d === '.' || $d === '..') continue;
    if (is_dir($unitsDir.'/'.$d)) $units[] = $d;
  }
}
sort($units, SORT_NATURAL);

$settings = [
  'booking' => [
    'import_url' => '',
    'mode'       => 'booked_only',
  ],
  'custom'  => [
    'import_url' => '',
    'mode'       => 'booked_only',
  ],
  'airbnb'  => [
    'enabled'       => false,
    'test_mode_2027'=> false,
    'mode'          => 'booked_only',
  ],
  'googlecal' => [
    'mode' => 'booked_only',
  ],
  'ics' => [
    'mode' => 'booked_only',
  ],
];

if (is_file($connsFile)) {
  $j = json_decode(file_get_contents($connsFile), true);
  if (is_array($j)) {
    // merge iz datoteke preko defaultov
    $settings = array_replace_recursive($settings, $j);
  }
}


if (is_file($connsFile)) {
  $j = json_decode(file_get_contents($connsFile), true);
  if (is_array($j)) $settings = array_replace_recursive($settings, $j);
}

echo json_encode([
  'ok' => true,
  'base_url' => $baseUrl,
  'units' => $units,
  'settings' => $settings,
], JSON_UNESCAPED_SLASHES);
