<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/pull_now.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Manually pull ICS data for a unit/platform (multi-platform, ICS LAB standard).
 *
 * Responsibilities:
 * - Read stored inbound ICS URL for given unit/platform from integrations/<UNIT>.json
 * - Fetch the ICS feed
 * - Store raw .ics under units/<UNIT>/external/<platform>_raw.ics
 * - Store normalized events under units/<UNIT>/external/<platform>_ics.json
 * - Regenerate occupancy_merged.json (canonical) AND publish occupancy.json (public-compatible)
 *
 * Used by:
 * - admin/ui/js/integrations.js (Manual "pull now" button)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jexit(array $o, int $code = 200): void {
  http_response_code($code);
  echo json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

$unit     = (string)($_GET['unit'] ?? '');
$platform = (string)($_GET['platform'] ?? 'booking');
$key      = (string)($_GET['key'] ?? '');

$platform = trim($platform);
if ($unit === '') jexit(['ok' => false, 'error' => 'missing unit'], 400);
if ($platform === '') jexit(['ok' => false, 'error' => 'missing platform'], 400);

// minimal safety: platform token
if (!preg_match('/^[a-z0-9_]{2,32}$/i', $platform)) {
  jexit(['ok' => false, 'error' => 'bad platform token', 'platform' => $platform], 400);
}

/* --- ADMIN KEY -------------------------------------------------------- */
$adminKeyFile = '/var/www/html/app/common/data/admin_key.txt';
if (!is_file($adminKeyFile)) jexit(['ok' => false, 'error' => 'admin key file not found'], 403);
$expected = trim((string)@file_get_contents($adminKeyFile));
if ($expected === '' || !hash_equals($expected, $key)) {
  jexit(['ok' => false, 'error' => 'admin key mismatch'], 403);
}

/* --- LOAD CONFIG ------------------------------------------------------ */
$cfgFile = "/var/www/html/app/common/data/json/integrations/{$unit}.json";
if (!is_file($cfgFile)) jexit(['ok' => false, 'error' => 'unit config not found', 'path' => $cfgFile], 404);

$cfg = json_decode((string)@file_get_contents($cfgFile), true);
if (!is_array($cfg)) jexit(['ok' => false, 'error' => 'bad unit config json'], 500);

// expected style:
// connections.<platform>.in.enabled
// connections.<platform>.in.ics_url
$enabled = (bool)($cfg['connections'][$platform]['in']['enabled'] ?? false);
$icsUrl  = (string)($cfg['connections'][$platform]['in']['ics_url'] ?? '');

if (!$enabled) jexit(['ok' => false, 'error' => 'IN disabled in config', 'platform' => $platform], 403);
if ($icsUrl === '') jexit(['ok' => false, 'error' => 'in.ics_url missing', 'platform' => $platform], 400);

/* --- SELF-IMPORT GUARD ----------------------------------------------- */
/** Ne dovoli “zanke”: lastni public ICS (na isti domeni / poti) */
$publicApiPath = '/app/public/api/ics.php';
$u = @parse_url($icsUrl);
$selfHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$looksLikeSelf = false;

if (is_array($u)) {
  if (!empty($u['host']) && $selfHost !== '' && $u['host'] === $selfHost) $looksLikeSelf = true;
  if (!empty($u['path']) && str_starts_with((string)$u['path'], $publicApiPath)) $looksLikeSelf = true;
}

if ($looksLikeSelf) {
  jexit(['ok' => false, 'error' => 'refusing self-import url', 'ics_url' => $icsUrl], 400);
}

/* --- FETCH ICS -------------------------------------------------------- */
$ch = curl_init($icsUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_USERAGENT      => 'ChannelManager-ICS/1.0'
]);

$data = curl_exec($ch);
$err  = (string)curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($data === false || $http < 200 || $http >= 300) {
  $cfg['connections'][$platform]['status']['last_err'] = gmdate('c');
  $cfg['connections'][$platform]['status']['last_err_msg'] = 'fetch failed';
  @file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  jexit(['ok' => false, 'error' => 'fetch failed', 'http' => $http, 'detail' => $err, 'platform' => $platform], 502);
}

if (strpos((string)$data, 'BEGIN:VCALENDAR') === false) {
  $cfg['connections'][$platform]['status']['last_err'] = gmdate('c');
  $cfg['connections'][$platform]['status']['last_err_msg'] = 'not an ICS';
  @file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  jexit(['ok' => false, 'error' => 'not an ICS (no BEGIN:VCALENDAR)', 'platform' => $platform], 422);
}

/* --- STORE RAW -------------------------------------------------------- */
$extDir = "/var/www/html/app/common/data/json/units/{$unit}/external";
@mkdir($extDir, 0775, true);

$rawPath = "{$extDir}/{$platform}_raw.ics";
if (@file_put_contents($rawPath, (string)$data) === false) {
  jexit(['ok' => false, 'error' => 'write failed (raw .ics)', 'path' => $rawPath], 500);
}

/* --- PARSE ALL-DAY EVENTS -------------------------------------------- */
[$ranges, $uids] = parseAllDayRangesWithUid((string)$data); // ranges: [['start','end','summary'], ...]
$events = [];

for ($i = 0; $i < count($ranges); $i++) {
  $r = $ranges[$i];
  if (!is_array($r)) continue;

  $start = (string)($r['start'] ?? '');
  $end   = (string)($r['end'] ?? '');
  if ($start === '' || $end === '') continue;

  $uid = (string)($uids[$i] ?? '');
  $id  = $uid !== '' ? ("ics:{$platform}:" . $uid) : ("ics:{$platform}:" . substr(sha1($unit.'|'.$platform.'|'.$start.'|'.$end.'|'.$i), 0, 16));

  $events[] = [
    'id'     => $id,
    'start'  => $start,
    'end'    => $end,          // end-exclusive (DTEND)
    'status' => 'reserved',
    'lock'   => 'hard',
    'source' => 'ics',
    'meta'   => [
      'platform' => $platform,
      'summary'  => (string)($r['summary'] ?? ''),
    ],
    // convenience (stara koda v UI včasih bere to)
    'platform' => $platform,
  ];
}

$norm = [
  'unit'       => $unit,
  'platform'   => $platform,
  'fetched_at' => gmdate('c'),
  'count'      => count($events),
  'events'     => $events,
];

$jsonPath = "{$extDir}/{$platform}_ics.json";
if (@file_put_contents($jsonPath, json_encode($norm, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n") === false) {
  jexit(['ok' => false, 'error' => 'write failed (platform_ics.json)', 'path' => $jsonPath], 500);
}

/* --- REGENERATE MERGED + PUBLISH (PER UNIT) -------------------------- */
$root      = '/var/www/html/app';
$unitsRoot = $root . '/common/data/json/units';

$regenOk = null;
$regenErr = null;

require_once $root . '/common/lib/datetime_fmt.php';
if (function_exists('cm_regen_merged_for_unit')) {
  try {
    $regenOk = cm_regen_merged_for_unit($unitsRoot, $unit);
  } catch (Throwable $t) {
    $regenOk = false;
    $regenErr = $t->getMessage();
  }
}

/* --- TOUCH STATUS ----------------------------------------------------- */
$cfg['connections'][$platform]['status']['last_ok'] = gmdate('c');
unset($cfg['connections'][$platform]['status']['last_err'], $cfg['connections'][$platform]['status']['last_err_msg']);
@file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n");

/* --- RESPONSE --------------------------------------------------------- */
jexit([
  'ok'           => true,
  'unit'         => $unit,
  'platform'     => $platform,
  'ics_url'      => $icsUrl,
  'stored_raw'   => $rawPath,
  'stored_json'  => $jsonPath,
  'events'       => count($events),
  'merged_regen' => $regenOk,
  'merged_error' => $regenErr,
]);

/* ==================== Helpers ==================== */

function parseAllDayRangesWithUid(string $ics): array {
  $events = [];
  $uids = [];

  $in = false;
  $cur = [];

  foreach (preg_split("/\r\n|\n|\r/", $ics) as $line) {
    $line = rtrim((string)$line);

    if ($line === 'BEGIN:VEVENT') { $in = true; $cur = []; continue; }

    if ($line === 'END:VEVENT') {
      if (!empty($cur['DTSTART']) && !empty($cur['DTEND'])) {
        $s = toYmd($cur['DTSTART']);
        $e = toYmd($cur['DTEND']);
        if ($s && $e) {
          $events[] = [
            'start'   => $s,
            'end'     => $e,
            'summary' => (string)($cur['SUMMARY'] ?? ''),
          ];
          $uids[] = (string)($cur['UID'] ?? '');
        }
      }
      $in = false;
      $cur = [];
      continue;
    }

    if (!$in) continue;

    // NOTE: minimal parser (ICS unfold not handled); OK for LAB feeds
    if (stripos($line, 'DTSTART') === 0) {
      $cur['DTSTART'] = extractDateValue($line);
    } elseif (stripos($line, 'DTEND') === 0) {
      $cur['DTEND'] = extractDateValue($line);
    } elseif (stripos($line, 'UID:') === 0) {
      $cur['UID'] = trim(substr($line, 4));
    } elseif (stripos($line, 'SUMMARY:') === 0) {
      $cur['SUMMARY'] = trim(substr($line, 8));
    }
  }

  return [$events, $uids];
}

function extractDateValue(string $line): ?string {
  $parts = explode(':', $line, 2);
  if (count($parts) < 2) return null;
  $val = trim($parts[1]);
  return substr($val, 0, 8); // YYYYMMDD
}

function toYmd(?string $yyyymmdd): ?string {
  if (!$yyyymmdd || !preg_match('/^\d{8}$/', $yyyymmdd)) return null;
  return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
}
