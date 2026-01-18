<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/sync_pending_requests.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Send or re-send an accept/confirmation link to a guest.
 *
 * Responsibilities:
 * - Read inquiry or accepted booking data by ID.
 * - Compose and send an email containing a link where the guest can
 *   confirm the reservation (or provide additional details).
 * - Log email sending status for debugging.
 *
 * Used by:
 * - admin/ui/js/admin_info_panel.js (Resend link / send invitation).
 *
 * Notes:
 * - Actual mail transport is typically handled by a shared mail helper
 *   using msmtp/sendmail.
 */

// /var/www/html/app/admin/api/sync_pending_requests.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function respond($ok, $payload = [], $http = null) {
  if ($http !== null) http_response_code($http);
  echo json_encode(['ok'=>$ok] + $payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  exit;
}

$unit   = isset($_REQUEST['unit']) ? trim((string)$_REQUEST['unit']) : '';
$all    = isset($_REQUEST['all']) && $_REQUEST['all'] !== '0';
$debug  = isset($_REQUEST['debug']) && $_REQUEST['debug'] !== '0';

// --- paths ---
$APP_ROOT    = dirname(__DIR__, 2); // -> /var/www/html/app
$DATA_ROOT   = $APP_ROOT . '/common/data/json';
$INQ_ROOT    = $DATA_ROOT . '/inquiries';
$PENDING_FILE= $DATA_ROOT . '/units/pending_requests.json';

// ---- helpers ----
function clean_json_decode(string $raw) {
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $raw = trim($raw);
  $j = json_decode($raw, true);
  return (is_array($j) ? $j : null);
}
function extract_unit_from_filename(string $filepath): ?string {
  $base = basename($filepath);
  if (preg_match('/-([A-Za-z0-9_]+)\.json$/', $base, $m)) return $m[1];
  return null;
}
function extract_dates(array $j): array {
  $from = '';
  $to   = '';
  if (!$from && isset($j['from']))  $from = (string)$j['from'];
  if (!$to   && isset($j['to']))    $to   = (string)$j['to'];
  if (!$from && isset($j['start'])) $from = (string)$j['start'];
  if (!$to   && isset($j['end']))   $to   = (string)$j['end'];
  if (!$from && isset($j['range']['start'])) $from = (string)$j['range']['start'];
  if (!$to   && isset($j['range']['end']))   $to   = (string)$j['range']['end'];
  if (!$from && isset($j['start_date'])) $from = (string)$j['start_date'];
  if (!$to   && isset($j['end_date']))   $to   = (string)$j['end_date'];
  $isDate = fn($s) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s ?? '');
  if (!$isDate($from)) $from = '';
  if (!$isDate($to))   $to   = '';
  return [$from, $to];
}
function normalize_items(array $items, string $unitFilter = '', bool $all = false): array {
  $out = [];
  foreach ($items as $j) {
    if (!is_array($j)) continue;
    $u = (string)($j['unit'] ?? $j['unit_code'] ?? '');
    if ($u === '' || $u === 'UNKNOWN') {
      if (isset($j['__file'])) $u = extract_unit_from_filename($j['__file']) ?? '';
    }
    if (!$all && $unitFilter !== '' && $u !== $unitFilter) continue;
    [$from, $to] = extract_dates($j);
    if ($from === '' || $to === '') continue;
    $out[] = [
      'unit'   => $u,
      'from'   => $from,
      'to'     => $to, // END-exclusive
      'id'     => (string)($j['id'] ?? $j['inquiry_id'] ?? $j['code'] ?? ''),
      'guest'  => (string)($j['guest'] ?? $j['guest_name'] ?? $j['name'] ?? $j['email'] ?? ''),
      'status' => 'pending'
    ];
  }
  // de-dup
  $seen = [];
  $uniq = [];
  foreach ($out as $r) {
    $k = $r['unit'].'|'.$r['from'].'|'.$r['to'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $uniq[] = $r;
  }
  return $uniq;
}

// ----------- BRANJE VNOSA (prefer POST JSON: {items:[...]}) -----------
$inputItems = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  if (is_string($raw) && strlen($raw)) {
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['items']) && is_array($j['items'])) {
      $inputItems = $j['items'];
    }
  }
}

$collected = [];

if (is_array($inputItems)) {
  // 1) Uporabi točen seznam iz UI (inqList)
  $collected = normalize_items($inputItems, $unit, $all);
} else {
  // 2) Fallback: skeniraj datotečno drevo (stara metoda)
  if (!is_dir($INQ_ROOT)) {
    respond(false, ['error'=>'inquiries_dir_missing', 'INQ_ROOT'=>$INQ_ROOT, 'APP_ROOT'=>$APP_ROOT], 500);
  }
  $years = @scandir($INQ_ROOT) ?: [];
  foreach ($years as $yy) {
    if (!preg_match('/^\d{4}$/', $yy)) continue;
    $yyPath = $INQ_ROOT . '/' . $yy;
    $months = @scandir($yyPath) ?: [];
    foreach ($months as $mm) {
      if (!preg_match('/^\d{2}$/', $mm)) continue;
      $pendingDir = "$yyPath/$mm/pending";
      if (!is_dir($pendingDir)) continue;
      foreach (glob($pendingDir . '/*.json') ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = clean_json_decode($raw);
        if (!$j) continue;
        $j['__file'] = $f; // za unit fallback
        $collected[] = $j;
      }
    }
  }
  $collected = normalize_items($collected, $unit, $all);
}

// --- zapis pending_requests.json ---
$targetDir = dirname($PENDING_FILE);
if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
  respond(false, ['error'=>'mkdir_failed', 'path'=>$targetDir], 500);
}
$tmp = $PENDING_FILE . '.tmp';
if (@file_put_contents($tmp, json_encode($collected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)) === false) {
  respond(false, ['error'=>'write_failed', 'path'=>$tmp], 500);
}
@chmod($tmp, 0664);
if (!@rename($tmp, $PENDING_FILE)) {
  respond(false, ['error'=>'rename_failed', 'from'=>$tmp, 'to'=>$PENDING_FILE], 500);
}

$payload = [
  'unit'   => $unit,
  'all'    => $all,
  'count'  => count($collected),
  'file'   => $PENDING_FILE
];
if ($debug) {
  $payload['sample'] = array_slice($collected, 0, 5);
  $payload['paths']  = [
    'APP_ROOT'  => $APP_ROOT,
    'DATA_ROOT' => $DATA_ROOT,
    'INQ_ROOT'  => $INQ_ROOT
  ];
}

respond(true, $payload, 200);
