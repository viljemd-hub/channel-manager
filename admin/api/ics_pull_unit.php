<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/ics_pull_unit.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/ics_pull_unit.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';   // cm_regen_merged_for_unit
require_once __DIR__ . '/../../common/lib/autopilot.php';      // cm_autopilot_regen_merged_for_unit (če obstaja)

$APP        = '/var/www/html/app';
$ROOT_UNITS = $APP . '/common/data/json/units';
$CFG_DIR    = $APP . '/common/data/json/integrations';
$ADMIN_KEY_FILE = $APP . '/common/data/admin_key.txt';

/**
 * VARNOST / BACKWARD COMPAT
 * - Zdaj: key je OPTIONAL (da ti ne razbijem obstoječih testov).
 * - Kasneje v produkciji: samo preklopi na true in bo endpoint zahteval ?key=...
 */
$REQUIRE_ADMIN_KEY = false;

function respond(bool $ok, array $payload = [], int $status = 200): void {
  http_response_code($status);
  $payload['ok'] = $ok;
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

function read_admin_key(string $path): string {
  if (!is_file($path)) return '';
  return trim((string)@file_get_contents($path));
}

function read_unit_cfg(string $cfgDir, string $unit): array {
  $path = $cfgDir . '/' . $unit . '.json';
  if (!is_file($path)) return ['_ok'=>false, '_path'=>$path];
  $j = json_decode((string)@file_get_contents($path), true);
  if (!is_array($j)) return ['_ok'=>false, '_path'=>$path, '_error'=>'bad_json'];
  $j['_ok'] = true;
  $j['_path'] = $path;
  return $j;
}

function merge_regen(string $rootUnits, string $unit): array {
  try {
    if (function_exists('cm_autopilot_regen_merged_for_unit')) {
      $info = cm_autopilot_regen_merged_for_unit($unit);
      if (is_array($info)) {
        $info['mode'] = $info['mode'] ?? 'cm_autopilot_regen_merged_for_unit';
        return $info;
      }
      return ['ok'=>true, 'mode'=>'cm_autopilot_regen_merged_for_unit', 'unit'=>$unit];
    }
    if (function_exists('cm_regen_merged_for_unit')) {
      cm_regen_merged_for_unit($rootUnits, $unit);
      return ['ok'=>true, 'mode'=>'direct_cm_regen_merged_for_unit', 'unit'=>$unit];
    }
    return ['ok'=>false, 'error'=>'NO_MERGE_HELPER'];
  } catch (Throwable $e) {
    return ['ok'=>false, 'error'=>'MERGE_EXCEPTION', 'msg'=>$e->getMessage()];
  }
}

function run_legacy_python(string $scriptPath): array {
  $cmd = sprintf('python3 %s 2>&1', escapeshellarg($scriptPath));
  $output = [];
  $exitCode = 0;
  exec($cmd, $output, $exitCode);

  $ok = ($exitCode === 0);
  return [
    'attempted' => true,
    'ok'        => $ok,
    'exit_code' => $exitCode,
    'output'    => implode("\n", array_slice($output, -25)),
    'cmd'       => $cmd,
  ];
}

function http_get_json(string $url, int $timeoutSec = 25): array {
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'GET',
      'timeout' => $timeoutSec,
      'header'  => "Accept: application/json\r\n",
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) {
    return ['ok'=>false, 'error'=>'HTTP_FETCH_FAILED', 'url'=>$url];
  }
  $j = json_decode($raw, true);
  if (!is_array($j)) {
    return ['ok'=>false, 'error'=>'HTTP_BAD_JSON', 'url'=>$url, 'raw_tail'=>substr($raw, -400)];
  }
  return $j;
}

function run_integrations_pull_now(string $unit, string $adminKey, string $platform = 'booking'): array {
  // kličemo interno prek localhost, da ne delamo “zunanjega” HTTP kroga
  $url = sprintf(
    'http://127.0.0.1/app/admin/api/integrations/pull_now.php?unit=%s&platform=%s&key=%s',
    rawurlencode($unit),
    rawurlencode($platform),
    rawurlencode($adminKey)
  );
  $j = http_get_json($url, 35);
  $j['_url'] = $url;
  return $j;
}

/* ---------------- input ---------------- */
$unit = $_POST['unit'] ?? $_GET['unit'] ?? '';
$unit = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$unit);
if ($unit === '') respond(false, ['error'=>'MISSING_UNIT'], 400);

// optional: driver=legacy|integrations|auto
$driverReq = (string)($_POST['driver'] ?? $_GET['driver'] ?? '');
$driverReq = strtolower(trim($driverReq));
if (!in_array($driverReq, ['', 'auto', 'legacy', 'integrations'], true)) {
  respond(false, ['error'=>'BAD_DRIVER', 'driver'=>$driverReq], 400);
}

// optional: platform for integrations (currently booking only in pull_now)
$platform = (string)($_POST['platform'] ?? $_GET['platform'] ?? 'booking');
$platform = strtolower(trim($platform));

$key = (string)($_POST['key'] ?? $_GET['key'] ?? '');
$adminKey = read_admin_key($ADMIN_KEY_FILE);

// optional hard requirement (future prod switch)
if ($REQUIRE_ADMIN_KEY) {
  if ($adminKey === '') respond(false, ['error'=>'ADMIN_KEY_MISSING'], 403);
  if ($key === '' || !hash_equals($adminKey, $key)) respond(false, ['error'=>'FORBIDDEN'], 403);
} else {
  // če key je poslan, ga validiramo (mehka zaščita)
  if ($key !== '' && $adminKey !== '' && !hash_equals($adminKey, $key)) {
    respond(false, ['error'=>'FORBIDDEN'], 403);
  }
}

/* ---------------- driver resolve ---------------- */
$cfg = read_unit_cfg($CFG_DIR, $unit);

// 1) driver iz requesta (če je podan)
$driver = $driverReq !== '' ? $driverReq : 'auto';

// 2) če ni request driverja, poskusi prebrat iz integrations/<UNIT>.json -> pull_driver
if ($driverReq === '' && ($cfg['_ok'] ?? false)) {
  $cfgDriver = strtolower(trim((string)($cfg['pull_driver'] ?? '')));
  if (in_array($cfgDriver, ['legacy','integrations','auto'], true)) {
    $driver = $cfgDriver;
  }
}

// 3) auto odločanje
$scriptPath = $ROOT_UNITS . '/' . $unit . '/update_occupancy_from_ics.py';
if ($driver === 'auto') {
  $driver = is_file($scriptPath) ? 'legacy' : 'integrations';
}

/* ---------------- execute ---------------- */
$payload = [
  'unit'   => $unit,
  'driver' => $driver,
  'cfg'    => [
    'ok'   => (bool)($cfg['_ok'] ?? false),
    'path' => $cfg['_path'] ?? ($CFG_DIR . '/' . $unit . '.json'),
  ],
];

if ($driver === 'legacy') {
  if (!is_file($scriptPath)) {
    respond(false, ['error'=>'SCRIPT_NOT_FOUND', 'scriptPath'=>$scriptPath] + $payload, 404);
  }

  $ics = run_legacy_python($scriptPath);
  $payload['ics'] = $ics;

  if (!($ics['ok'] ?? false)) {
    $payload['merge'] = ['ok'=>false, 'error'=>'ICS_FAILED_SKIP_MERGE'];
    respond(false, $payload, 500);
  }

  $payload['merge'] = merge_regen($ROOT_UNITS, $unit);
  respond(true, $payload, 200);
}

/* integrations driver */
if ($driver === 'integrations') {
  if ($adminKey === '') {
    // pull_now endpoint zahteva key; zato mora obstajati admin_key.txt
    respond(false, ['error'=>'ADMIN_KEY_MISSING_FOR_INTEGRATIONS'] + $payload, 500);
  }
  if ($platform !== 'booking') {
    respond(false, ['error'=>'UNSUPPORTED_PLATFORM', 'platform'=>$platform] + $payload, 400);
  }

  $j = run_integrations_pull_now($unit, $adminKey, $platform);
  $payload['integrations'] = $j;

  $ok = (bool)($j['ok'] ?? false);
  if (!$ok) {
    // pull_now že vrne error razlog
    respond(false, $payload, 500);
  }

  // pull_now že regenerira merged, ampak regeneriramo še enkrat za “safety”
  $payload['merge'] = merge_regen($ROOT_UNITS, $unit);
  respond(true, $payload, 200);
}

respond(false, ['error'=>'UNREACHABLE'] + $payload, 500);
