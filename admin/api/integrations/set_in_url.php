<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/set_in_url.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Set or update incoming ICS URL for a unit/channel.
 *
 * Responsibilities:
 * - Accept unit identifier and channel type (e.g. booking, airbnb).
 * - Store or update the inbound ICS URL in the unit's integration JSON.
 *
 * Used by:
 * - admin/ui/js/integrations.js when the user edits an ICS URL.
 *
 * Notes:
 * - This endpoint does not fetch ICS data; it only stores the URL.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function respond($ok, $data = []) {
  echo json_encode($ok ? array_merge(['ok'=>true], $data) : array_merge(['ok'=>false], $data));
  exit;
}

$adminKey = trim(@file_get_contents('/var/www/html/app/common/data/admin_key.txt'));
if (!$adminKey) respond(false, ['error'=>'admin_key_missing']);

$unit = $_POST['unit'] ?? '';
$platform = $_POST['platform'] ?? 'booking';
$icsUrl = $_POST['ics_url'] ?? '';
$key = $_POST['key'] ?? '';

if ($key !== $adminKey) respond(false, ['error'=>'forbidden']);
if (!preg_match('/^[A-Za-z0-9_-]+$/', $unit)) respond(false, ['error'=>'bad_unit']);
if ($icsUrl === '' || !preg_match('#^https?://#i', $icsUrl)) respond(false, ['error'=>'bad_url']);

$path = "/var/www/html/app/common/data/json/integrations/{$unit}.json";
$j = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
if (!is_array($j)) respond(false, ['error'=>'unit_config_missing']);

$j['connections'][$platform]['in'] = $j['connections'][$platform]['in'] ?? [];
$j['connections'][$platform]['in']['ics_url'] = $icsUrl;
$j['connections'][$platform]['in']['enabled'] = true;
$j['connections'][$platform]['in']['last_fetch'] = null;

if (!@file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))) {
  respond(false, ['error'=>'write_failed']);
}
respond(true, ['unit'=>$unit, 'platform'=>$platform, 'ics_url'=>$icsUrl]);
