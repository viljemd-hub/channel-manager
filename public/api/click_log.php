<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

// Read raw body (JSON)
$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';

// Hard cap to avoid abuse
if (strlen($raw) > 8192) {
  http_response_code(413);
  echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

// Minimal allowlist + truncation
$event = isset($data['event']) && is_string($data['event']) ? substr($data['event'], 0, 64) : 'unknown';
$ts    = isset($data['ts']) && is_string($data['ts'])    ? substr($data['ts'], 0, 40)    : '';
$path  = isset($data['path']) && is_string($data['path'])? substr($data['path'], 0, 200) : '';
$ref   = isset($data['ref']) && is_string($data['ref'])  ? substr($data['ref'], 0, 500)  : '';
$ua    = isset($data['ua']) && is_string($data['ua'])    ? substr($data['ua'], 0, 300)   : '';

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (is_string($ip)) $ip = substr($ip, 0, 80);

// Log dir/file (inside /public/data/logs)
$logDir  = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
$logDir .= '/logs';

if (!is_dir($logDir)) {
  @mkdir($logDir, 0775, true);
}

$logFile = $logDir . '/clicks.log';

$line = json_encode([
  'event' => $event,
  'ts'    => $ts,
  'path'  => $path,
  'ref'   => $ref,
  'ua'    => $ua,
  'ip'    => $ip,
], JSON_UNESCAPED_SLASHES);

if (!is_string($line)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'json_encode_failed']);
  exit;
}

// Append line
@file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);