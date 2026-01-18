<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/ics.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * /app/admin/api/integrations/ics.php
 *
 * ICS OUT — CM standard (current schema: start/end/status)
 *
 * Only 2 feeds:
 *   - mode=booked  : hard reservations only
 *   - mode=blocked : hard reservations + hard blocks (+ optional extras)
 *
 * Data source (SSOT):
 *   /app/common/data/json/units/<UNIT>/occupancy_merged.json
 *
 * Auth keys (per unit), priority:
 *   1) integrations/<UNIT>.json export.ics.booked.key / export.ics.blocked.key
 *   2) integrations/<UNIT>.json keys.reservations_out / keys.calendar_out
 *
 * Notes:
 * - No google_calendar legacy keys.
 * - No PII in ICS.
 */

declare(strict_types=1);

use App\ICS\IcsBuilder;

require_once __DIR__ . '/../../../common/lib/ics_builder.php';

header('Content-Type: text/calendar; charset=utf-8');

function bad(int $code, string $msg): void {
  http_response_code($code);
  $b = new IcsBuilder('-//ChannelManager//ICS Error//EN', 'ICS error');
  $b->begin();
  $b->addAllDayEvent([
    'summary' => 'ICS error',
    'start'   => '2000-01-01',
    'end'     => '2000-01-02',
    'uid'     => sha1('ics-error-'.$msg).'@cm.local',
    'description' => $msg,
  ]);
  $b->end();
  echo $b->render();
  exit;
}

function read_json(string $path): ?array {
  if (!is_file($path)) return null;
  $t = @file_get_contents($path);
  if ($t === false) return null;
  $j = json_decode($t, true);
  return is_array($j) ? $j : null;
}

function pick_ics_key(array $cfg, string $mode): ?string {
  $exp = $cfg['export']['ics'] ?? null;
  if (is_array($exp)) {
    if ($mode === 'booked') {
      $k = $exp['booked']['key'] ?? null;
      if (is_string($k) && $k !== '') return $k;
    } else {
      $k = $exp['blocked']['key'] ?? null;
      if (is_string($k) && $k !== '') return $k;
    }
  }

  // fallback (still CM standard)
  $keys = $cfg['keys'] ?? [];
  if (!is_array($keys)) $keys = [];

  if ($mode === 'booked') {
    $k = $keys['reservations_out'] ?? null;
    return (is_string($k) && $k !== '') ? $k : null;
  } else {
    $k = $keys['calendar_out'] ?? null;
    if (!is_string($k) || $k === '') $k = $keys['reservations_out'] ?? null;
    return (is_string($k) && $k !== '') ? $k : null;
  }
}

function default_extras(array $cfg): int {
  // default: 0 (explicit is better)
  $exp = $cfg['export']['ics'] ?? null;
  if (is_array($exp) && array_key_exists('include_extras_default', $exp)) {
    return ($exp['include_extras_default'] ? 1 : 0);
  }
  return 0;
}

function is_valid_ymd(?string $s): bool {
  return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
}

function stable_uid(string $unit, array $seg): string {
  $id = $seg['id'] ?? null;
  if (is_string($id) && $id !== '') {
    return 'cm:' . $unit . ':' . sha1($id) . '@cm.local';
  }
  $st = (string)($seg['status'] ?? '');
  $f  = (string)($seg['start'] ?? '');
  $to = (string)($seg['end'] ?? '');
  $src= (string)($seg['source'] ?? '');
  return 'cm:' . $unit . ':' . sha1($st.'|'.$f.'|'.$to.'|'.$src) . '@cm.local';
}

function classify_block_kind(array $seg): string {
  // We keep this intentionally simple and non-guessy:
  // - if reason explicitly says cleaning/maintenance -> treat as extra
  // - otherwise it's a normal block
  $reason = strtolower((string)($seg['reason'] ?? $seg['kind'] ?? ''));
  if ($reason === 'cleaning' || str_starts_with($reason, 'clean-')) return 'cleaning';
  if ($reason === 'maintenance' || str_contains($reason, 'maint')) return 'maintenance';
  return 'blocked';
}

// -------------------- INPUT --------------------

$unit = $_GET['unit'] ?? '';
$mode = $_GET['mode'] ?? 'blocked';
$key  = $_GET['key']  ?? '';

if (!is_string($unit) || !preg_match('/^[A-Za-z0-9_-]+$/', $unit)) bad(400, 'Bad or missing unit.');
$mode = ($mode === 'booked') ? 'booked' : 'blocked';
if (!is_string($key) || $key === '') bad(400, 'Missing key.');

$extras = array_key_exists('extras', $_GET) ? (($_GET['extras'] === '1') ? 1 : 0) : -1;

// -------------------- AUTH CONFIG --------------------

$cfgPath = "/var/www/html/app/common/data/json/integrations/{$unit}.json";
$cfg = read_json($cfgPath);
if (!$cfg) bad(404, "Unit not configured: {$unit}");

$expectedKey = pick_ics_key($cfg, $mode);
if (!$expectedKey || !hash_equals($expectedKey, (string)$key)) bad(403, 'Forbidden: bad key.');

if ($mode === 'blocked' && $extras === -1) $extras = default_extras($cfg);
if ($extras === -1) $extras = 0;

// -------------------- LOAD MERGED --------------------

$mergedPath = "/var/www/html/app/common/data/json/units/{$unit}/occupancy_merged.json";
$segments = read_json($mergedPath);
if (!is_array($segments)) $segments = [];

// -------------------- BUILD ICS --------------------

$title = ($mode === 'booked') ? "Booked {$unit}" : "Booked+Blocked {$unit}";
$builder = new IcsBuilder('-//ChannelManager//ICS OUT 2.0//EN', $title);
$builder->begin();

foreach ($segments as $seg) {
  if (!is_array($seg)) continue;

  $start  = $seg['start'] ?? null;
  $end    = $seg['end'] ?? null;
  $status = strtolower((string)($seg['status'] ?? ''));
  $lock   = strtolower((string)($seg['lock'] ?? ''));

  if (!is_valid_ymd($start) || !is_valid_ymd($end)) continue;
  if ($status === '') continue;

  // Contract: export only HARD rows
  if ($lock !== 'hard') continue;

  $exportFlag = $seg['export'] ?? null;
  $isExportFalse = ($exportFlag === false);
  $isExportTrue  = ($exportFlag === true);

  $isReserved = ($status === 'reserved');
  $isBlocked  = ($status === 'blocked');

  if ($mode === 'booked') {
    if (!$isReserved) continue;
    $builder->addAllDayEvent([
      'summary' => 'Reserved',
      'start'   => $start,
      'end'     => $end,
      'uid'     => stable_uid($unit, $seg),
    ]);
    continue;
  }

  // mode=blocked: always include reserved
  if ($isReserved) {
    $builder->addAllDayEvent([
      'summary' => 'Reserved',
      'start'   => $start,
      'end'     => $end,
      'uid'     => stable_uid($unit, $seg),
    ]);
    continue;
  }

  if (!$isBlocked) continue;

  // export:false always excludes
  if ($isExportFalse) continue;

  $kind = classify_block_kind($seg); // blocked | cleaning | maintenance
  $isExtra = ($kind === 'cleaning' || $kind === 'maintenance');

  // extras=0: include extras only if explicitly export:true
  if ($isExtra && $extras === 0 && !$isExportTrue) continue;

  $summary = match ($kind) {
    'cleaning'    => 'Cleaning',
    'maintenance' => 'Maintenance',
    default       => 'Blocked'
  };

  $builder->addAllDayEvent([
    'summary' => $summary,
    'start'   => $start,
    'end'     => $end,
    'uid'     => stable_uid($unit, $seg),
  ]);
}

$builder->end();
echo $builder->render();
