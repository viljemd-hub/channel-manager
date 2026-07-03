<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/connector_out_update.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Manage ICS OUT connectors in:
 *   /app/common/data/json/integrations/<UNIT>.json
 *
 * POST params:
 *   - unit
 *   - connector
 *   - key              admin key
 *   - action           add | toggle | rotate | delete | update_label
 *   - label            for add/update_label
 *   - enabled          for toggle: 1/0
 *
 * Contract:
 *   - connections = ICS IN
 *   - connectors  = ICS OUT / platform connections reading from CM
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/paths.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jexit(array $o, int $code = 200): void {
  http_response_code($code);
  echo json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function read_param(string $k): string {
  $v = $_POST[$k] ?? $_GET[$k] ?? '';
  return is_string($v) ? trim($v) : '';
}

function save_json_atomic(string $path, array $data): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) return false;

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $json .= "\n";

  $tmp = $path . '.tmp.' . uniqid('', true);
  $ok = @file_put_contents($tmp, $json, LOCK_EX);
  if ($ok === false) { @unlink($tmp); return false; }
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }

  return true;
}

function connector_token(string $unit, string $connector, string $mode): string {
  $prefix = strtoupper($unit . '-' . $connector . '-' . $mode);
  $prefix = preg_replace('/[^A-Z0-9_-]+/', '-', $prefix) ?: 'CM-ICS';
  return $prefix . '-' . bin2hex(random_bytes(8));
}

function label_from_connector(string $connector): string {
  $map = [
    'airbnb' => 'Airbnb',
    'booking' => 'Booking',
    'googlecal' => 'Google Calendar',
    'gcal' => 'Google Calendar',
    'ical' => 'iCal',
  ];
  $k = strtolower($connector);
  if (isset($map[$k])) return $map[$k];
  return ucwords(str_replace(['_', '-'], ' ', $connector));
}

$unit      = read_param('unit');
$connector = strtolower(read_param('connector'));
$action    = strtolower(read_param('action'));
$key       = read_param('key');
$label     = read_param('label');
$enabledIn = read_param('enabled');

if ($unit === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $unit)) jexit(['ok'=>false, 'error'=>'bad_unit'], 400);
if ($connector === '' || !preg_match('/^[a-z0-9_-]{2,64}$/i', $connector)) jexit(['ok'=>false, 'error'=>'bad_connector'], 400);
if (!in_array($action, ['add','toggle','rotate','delete','update_label'], true)) jexit(['ok'=>false, 'error'=>'bad_action'], 400);

$adminKeyFile = admin_key_path();
if (!is_file($adminKeyFile)) jexit(['ok'=>false, 'error'=>'admin_key_missing'], 403);
$expected = trim((string)@file_get_contents($adminKeyFile));
if ($expected === '' || !hash_equals($expected, $key)) jexit(['ok'=>false, 'error'=>'forbidden'], 403);

$cfgFile = "/var/www/html/app/common/data/json/integrations/{$unit}.json";
if (!is_file($cfgFile)) jexit(['ok'=>false, 'error'=>'unit_config_missing', 'path'=>$cfgFile], 404);

$cfg = json_decode((string)@file_get_contents($cfgFile), true);
if (!is_array($cfg)) jexit(['ok'=>false, 'error'=>'bad_unit_config_json'], 500);

if (!isset($cfg['connectors']) || !is_array($cfg['connectors'])) $cfg['connectors'] = [];

if ($action === 'delete') {
  $existed = array_key_exists($connector, $cfg['connectors']);
  unset($cfg['connectors'][$connector]);

  if (!save_json_atomic($cfgFile, $cfg)) jexit(['ok'=>false, 'error'=>'write_failed'], 500);
  jexit(['ok'=>true, 'unit'=>$unit, 'connector'=>$connector, 'deleted'=>$existed]);
}

if (!isset($cfg['connectors'][$connector]) || !is_array($cfg['connectors'][$connector])) {
  $cfg['connectors'][$connector] = [];
}
if (!isset($cfg['connectors'][$connector]['out']) || !is_array($cfg['connectors'][$connector]['out'])) {
  $cfg['connectors'][$connector]['out'] = [];
}

$out =& $cfg['connectors'][$connector]['out'];

if ($action === 'add') {
  $out['enabled'] = true;
  $out['label'] = ($label !== '') ? $label : label_from_connector($connector);
  $out['booked'] = [
    'enabled' => true,
    'key' => connector_token($unit, $connector, 'booked'),
  ];
  $out['blocked'] = [
    'enabled' => true,
    'key' => connector_token($unit, $connector, 'blocked'),
  ];
}

if ($action === 'toggle') {
  $out['enabled'] = in_array(strtolower($enabledIn), ['1','true','yes','on','enabled'], true);
}

if ($action === 'rotate') {
  if (!isset($out['booked']) || !is_array($out['booked'])) $out['booked'] = [];
  if (!isset($out['blocked']) || !is_array($out['blocked'])) $out['blocked'] = [];
  $out['booked']['enabled'] = $out['booked']['enabled'] ?? true;
  $out['blocked']['enabled'] = $out['blocked']['enabled'] ?? true;
  $out['booked']['key'] = connector_token($unit, $connector, 'booked');
  $out['blocked']['key'] = connector_token($unit, $connector, 'blocked');
}

if ($action === 'update_label') {
  if ($label === '') jexit(['ok'=>false, 'error'=>'missing_label'], 400);
  $out['label'] = $label;
}

if (!isset($out['enabled'])) $out['enabled'] = true;
if (!isset($out['label']) || !is_string($out['label']) || trim($out['label']) === '') {
  $out['label'] = label_from_connector($connector);
}
if (!isset($out['booked']) || !is_array($out['booked'])) {
  $out['booked'] = ['enabled'=>true, 'key'=>connector_token($unit, $connector, 'booked')];
}
if (!isset($out['blocked']) || !is_array($out['blocked'])) {
  $out['blocked'] = ['enabled'=>true, 'key'=>connector_token($unit, $connector, 'blocked')];
}

if (!save_json_atomic($cfgFile, $cfg)) jexit(['ok'=>false, 'error'=>'write_failed'], 500);

jexit([
  'ok' => true,
  'unit' => $unit,
  'connector' => $connector,
  'action' => $action,
  'out' => $out,
]);
