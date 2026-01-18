<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/add__unit.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Add a unit to the integrations registry.
 *
 * Responsibilities:
 * - Create or update per-unit integration JSON (e.g. A1.json, A2.json)
 *   with initial channel entries or placeholders.
 * - Ensure that the connections/registry JSON knows about the unit.
 *
 * Used by:
 * - admin integrations console when enabling integrations for a new unit.
 *
 * Notes:
 * - This is integrations-specific; generic unit creation lives in
 *   /admin/api/add_unit.php.
 */

// /var/www/html/app/admin/api/integrations/add_unit.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$DATA_ROOT = '/var/www/html/app/common/data/json';
$UNITS_DIR = $DATA_ROOT . '/units';
$MANIFEST  = $UNITS_DIR . '/manifest.json';

function jexit($ok, $payload = []) {
  http_response_code($ok ? 200 : 400);
  echo json_encode($ok ? array_merge(['ok'=>true], $payload)
                       : array_merge(['ok'=>false], $payload),
                   JSON_UNESCAPED_SLASHES);
  exit;
}
function sanitize_id($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', $s ?? ''); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit(false, ['error'=>'method_not_allowed']);

$unit  = sanitize_id($_POST['unit'] ?? '');
$label = trim((string)($_POST['label'] ?? ''));

if ($unit === '' || $label === '') jexit(false, ['error'=>'missing_params']);

$dir = $UNITS_DIR . '/' . $unit;
if (is_dir($dir)) jexit(false, ['error'=>'unit_exists']);

@mkdir($dir, 0775, true);
@mkdir($dir.'/external', 0775, true);

file_put_contents($dir.'/prices.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/special_offers.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/occupancy.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/occupancy_merged.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/external/booking_ics.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/site_settings.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/pending_requests.json', json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/promo_codes.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

/** Posodobi manifest.json */
$manifest = ['units'=>[]];
if (file_exists($MANIFEST)) {
  $j = json_decode((string)file_get_contents($MANIFEST), true);
  if (is_array($j) && isset($j['units']) && is_array($j['units'])) {
    $manifest = $j;
  }
}
$manifest['units'][] = ['id'=>$unit, 'label'=>$label];
file_put_contents($MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

jexit(true, ['unit'=>$unit, 'label'=>$label, 'dir'=>$dir]);
