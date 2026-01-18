<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/remove_platform.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * remove_platform.php
 * Remove a platform entry from per-unit integrations JSON:
 *   /app/common/data/json/integrations/<UNIT>.json
 *
 * POST params:
 *  - unit (string)
 *  - platform (string)
 *  - key (string) admin key from /app/common/data/admin_key.txt
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jexit(array $o, int $code = 200): void {
  http_response_code($code);
  echo json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function read_param(string $k): string {
  $v = $_POST[$k] ?? $_GET[$k] ?? '';
  return is_string($v) ? trim($v) : '';
}

function save_json_atomic(string $path, string $json): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) return false;

  $tmp = $path . '.tmp.' . uniqid('', true);
  $ok = @file_put_contents($tmp, $json, LOCK_EX);
  if ($ok === false) { @unlink($tmp); return false; }
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

$unit     = read_param('unit');
$platform = read_param('platform');
$key      = read_param('key');

if ($unit === '') jexit(['ok' => false, 'error' => 'missing unit'], 400);
if ($platform === '') jexit(['ok' => false, 'error' => 'missing platform'], 400);

if (!preg_match('/^[a-z0-9_]{2,32}$/i', $platform)) {
  jexit(['ok' => false, 'error' => 'bad platform token', 'platform' => $platform], 400);
}

$adminKeyFile = '/var/www/html/app/common/data/admin_key.txt';
if (!is_file($adminKeyFile)) jexit(['ok' => false, 'error' => 'admin key file not found'], 403);

$expected = trim((string)@file_get_contents($adminKeyFile));
if ($expected === '' || !hash_equals($expected, $key)) {
  jexit(['ok' => false, 'error' => 'admin key mismatch'], 403);
}

$cfgFile = "/var/www/html/app/common/data/json/integrations/{$unit}.json";
if (!is_file($cfgFile)) jexit(['ok' => false, 'error' => 'unit config not found', 'path' => $cfgFile], 404);

$cfgRaw = (string)@file_get_contents($cfgFile);
$cfg = json_decode($cfgRaw, true);
if (!is_array($cfg)) jexit(['ok' => false, 'error' => 'bad unit config json'], 500);

if (!isset($cfg['connections']) || !is_array($cfg['connections'])) {
  jexit(['ok' => true, 'unit' => $unit, 'platform' => $platform, 'removed' => false, 'note' => 'no connections key'], 200);
}

if (!array_key_exists($platform, $cfg['connections'])) {
  jexit(['ok' => true, 'unit' => $unit, 'platform' => $platform, 'removed' => false, 'note' => 'platform not present'], 200);
}

unset($cfg['connections'][$platform]);

$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) jexit(['ok' => false, 'error' => 'json encode failed'], 500);

if (!save_json_atomic($cfgFile, $json)) {
  jexit(['ok' => false, 'error' => 'failed to write unit config', 'path' => $cfgFile], 500);
}

jexit(['ok' => true, 'unit' => $unit, 'platform' => $platform, 'removed' => true], 200);
