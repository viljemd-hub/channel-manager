<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/apply_booking_now.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Apply ICS data (generic) to occupancy immediately.
 *
 * NOTE:
 * - In novi pipeline je pull_now.php že dovolj (pull + parsed + regen merged/publish).
 * - Ta endpoint je legacy/optional; obdrži ga za UI "Apply" gumb.
 */

declare(strict_types=1);

require_once __DIR__ . '/_lib/paths.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $o, int $code=200){ http_response_code($code); echo json_encode($o, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); exit; }

$unit     = (string)($_GET['unit'] ?? '');
$platform = (string)($_GET['platform'] ?? 'booking');
$key      = (string)($_GET['key']  ?? '');

$platform = trim($platform);
if ($unit==='') jexit(['ok'=>false,'error'=>'missing unit'], 400);
if ($platform==='') jexit(['ok'=>false,'error'=>'missing platform'], 400);
if (!preg_match('/^[a-z0-9_]{2,32}$/i', $platform)) jexit(['ok'=>false,'error'=>'bad platform token'], 400);

/* --- auth ------------------------------------------------------------- */
$adminKeyFile = admin_key_path();
if (!is_file($adminKeyFile)) jexit(['ok'=>false,'error'=>'admin key file not found'], 403);
$expected = trim((string)@file_get_contents($adminKeyFile));
if ($expected === '' || !hash_equals($expected, (string)$key)) {
  jexit(['ok'=>false,'error'=>'admin key invalid'], 403);
}

/* --- paths ------------------------------------------------------------- */
$root       = app_root();
$unitDir    = units_root() . "/$unit";
$occPath    = "$unitDir/occupancy.json";
$rawPath    = "$unitDir/external/{$platform}_raw.ics";
$parsedPath = "$unitDir/external/{$platform}_ics.json";

require_once $root . '/common/lib/datetime_fmt.php';

/* --- load events (prefer parsed JSON; else parse raw) ------------------ */
$events = [];
if (is_file($parsedPath)) {
  $json = json_decode((string)@file_get_contents($parsedPath), true);
  if (is_array($json) && !empty($json['events'])) {
    foreach($json['events'] as $e){
      $events[] = [
        'start'   => $e['start'] ?? $e['from'] ?? null,
        'end'     => $e['end']   ?? $e['to']   ?? null,
        'summary' => $e['meta']['summary'] ?? $e['summary'] ?? '',
        'id'      => $e['id'] ?? '',
      ];
    }
  }
} elseif (is_file($rawPath)) {
  // legacy parser (če kdaj rabiš)
  require_once("$root/common/lib/ics_import.php");
  if (!class_exists('\App\ICS\IcsImport')) jexit(['ok'=>false,'error'=>'IcsImport missing'], 500);
  $ics = (string)@file_get_contents($rawPath);
  $ranges = \App\ICS\IcsImport::parseAllDayRanges($ics);
  foreach($ranges as $r){
    $events[] = ['start'=>$r['start'], 'end'=>$r['end'], 'summary'=>'', 'id'=>''];
  }
} else {
  jexit(['ok'=>false,'error'=>'no ICS source found (raw or parsed)','platform'=>$platform], 404);
}
$events = array_values(array_filter($events, fn($e)=>!empty($e['start']) && !empty($e['end'])));

/* --- load current occupancy ------------------------------------------- */
$occ = [];
if (is_file($occPath)) {
  $occ = json_decode((string)@file_get_contents($occPath), true);
  if (!is_array($occ)) $occ = [];
}

/*
 * --- sync: drop stale ICS rows for this platform -----------------------
 *
 * Če Booking.com (ali druga platforma) iz ICS odstrani nek CLOSED event,
 * moramo odstraniti tudi ustrezne vrstice iz occupancy.json, sicer ostane
 * koledar za vedno blokiran.
 *
 * Logika:
 *  - zberemo vse [start|end] pare iz trenutnega ICS ( $events )
 *  - obdržimo vse ne-ICS vrstice in ICS drugih platform
 *  - za ICS+hard vrstice te platforme obdržimo samo tiste, katerih range
 *    še obstaja v zadnjem ICS importu.
 */
$currentKeys = [];
foreach ($events as $e) {
  $k = $e['start'] . '|' . $e['end'];
  $currentKeys[$k] = true;
}

$removedIcs = 0;
if (!empty($occ)) {
  $occ = array_values(array_filter($occ, function ($seg) use (&$removedIcs, $platform, $currentKeys) {
    if (!is_array($seg)) return false;

    $src   = (string)($seg['source'] ?? '');
    $lock  = (string)($seg['lock'] ?? '');
    $start = $seg['start'] ?? $seg['from'] ?? null;
    $end   = $seg['end']   ?? $seg['to']   ?? null;
    $pl    = $seg['platform'] ?? ($seg['meta']['platform'] ?? '');

    // obravnavamo samo ICS hard-lock vrstice za to platformo
    if ($src === 'ics' && $lock === 'hard' && $pl === $platform && $start && $end) {
      $key = $start . '|' . $end;
      if (!isset($currentKeys[$key])) {
        // ta range ni več v ICS -> pobriši ga iz occupancy
        $removedIcs++;
        return false;
      }
    }

    return true;
  }));
}


/* --- merge: skip exact duplicates only ------------------------------- */
$added=0; $skipped_same=0;

foreach($events as $e){
  $seg = [
    'start'  => $e['start'],
    'end'    => $e['end'],
    'status' => 'reserved',
    'lock'   => 'hard',
    'source' => 'ics',
    'export' => true,
    'id'     => ($e['id'] !== '' ? $e['id'] : ("ics:{$platform}:" . substr(sha1($unit.'|'.$platform.'|'.$e['start'].'|'.$e['end']),0,16))),
    'meta'   => [
      'platform' => $platform,
      'summary'  => (string)($e['summary'] ?? ''),
    ],
    'platform' => $platform,
  ];

  $dupe = false;
  foreach($occ as $ex){
    // allow both old and new keys
    $exS = $ex['start'] ?? $ex['from'] ?? '';
    $exE = $ex['end']   ?? $ex['to']   ?? '';
    $exSrc = $ex['source'] ?? '';
    $exLock = $ex['lock'] ?? '';
    if ($exS===$seg['start'] && $exE===$seg['end'] && $exSrc==='ics' && $exLock==='hard') { $dupe = true; break; }
  }
  if ($dupe) { $skipped_same++; continue; }

  $occ[] = $seg;
  $added++;
}

/* --- sort -------------------------------------------------------------- */
usort($occ, function($a,$b){
  $as = ($a['start'] ?? $a['from'] ?? '') . '|' . ($a['end'] ?? $a['to'] ?? '');
  $bs = ($b['start'] ?? $b['from'] ?? '') . '|' . ($b['end'] ?? $b['to'] ?? '');
  return strcmp($as, $bs);
});

/* --- save occupancy + regen merged ------------------------------------ */
if (@file_put_contents($occPath, json_encode($occ, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) {
  jexit(['ok'=>false,'error'=>'write failed','path'=>$occPath], 500);
}

$unitsRoot = units_root();
$regenOk = function_exists('cm_regen_merged_for_unit') ? cm_regen_merged_for_unit($unitsRoot, $unit) : null;

jexit([
  'ok' => true,
  'unit' => $unit,
  'platform' => $platform,
  'added' => $added,
  'skipped_same' => $skipped_same,
  'removed_ics' => $removedIcs,
  'occupancy_path' => $occPath,
  'merged_regen' => $regenOk,
  'total_segments' => count($occ)
]);

