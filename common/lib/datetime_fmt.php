<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/datetime_fmt.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

// Mini util: branje nastavitev, formatiranje ISO datumov v nastavljiv prikaz (DD.MM.YYYY, ipd.),
// in injekcija *_fmt polj glede na output_mode. TZ: Europe/Ljubljana (ali po nastavitvah).
//
// Permissions discipline (contract):
// - JSON writes must be atomic (tmp + rename)
// - keep group-writable + stable group (0664 + chgrp apartma) to avoid ownership drift.


function cm_load_settings(): array {
  $path = __DIR__ . "/../data/json/units/site_settings.json";
  if (!is_file($path)) return [];
  $json = file_get_contents($path);
  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}

function cm_get_product_tier(): string
{
    // Read global site_settings.json via existing helper
    $settings = cm_load_settings();
    $tier = strtolower((string)($settings['product']['tier'] ?? 'free'));

    // Normalise and guard against typos
    $allowed = ['free', 'plus', 'pro'];
    if (!in_array($tier, $allowed, true)) {
        $tier = 'free';
    }

    return $tier;
}

/**
 * Returns true when CM Plus (or higher) features should be enabled.
 * For now both "plus" and "pro" are treated as "Plus" for UI gating.
 */
function cm_is_plus_enabled(): bool
{
    $tier = cm_get_product_tier();
    return $tier === 'plus' || $tier === 'pro';
}


function cm_datetime_cfg(): array {
  $s = cm_load_settings();
  $cfg = $s['datetime'] ?? [];
  return [
    'timezone'        => $cfg['timezone']        ?? 'Europe/Ljubljana',
    'locale'          => $cfg['locale']          ?? 'sl-SI',
    'date_format'     => $cfg['date_format']     ?? 'DD.MM.YYYY',
    'datetime_format' => $cfg['datetime_format'] ?? 'DD.MM.YYYY HH:mm',
    'output_mode'     => $cfg['output_mode']     ?? 'both',
  ];
}

// Preprost formatter: podpira DD, D, MM, M, YYYY, YY, HH, H, mm, m
function cm_format_from_iso(string $iso, string $pattern, string $tz): ?string {
  if ($iso === '') return null;

  try {
    $dt = new DateTimeImmutable($iso);
  } catch (Exception $e) {
    // Poskusi “brez časa” (YYYY-MM-DD)
    try {
      $dt = new DateTimeImmutable($iso . 'T00:00:00');
    } catch (Exception $e2) {
      return null;
    }
  }

  try {
    $dt = $dt->setTimezone(new DateTimeZone($tz));
  } catch (Exception $e) {
    // fallback
  }

  $map = [
    'DD'   => $dt->format('d'),
    'D'    => (string)intval($dt->format('d')),
    'MM'   => $dt->format('m'),
    'M'    => (string)intval($dt->format('m')),
    'YYYY' => $dt->format('Y'),
    'YY'   => $dt->format('y'),
    'HH'   => $dt->format('H'),
    'H'    => (string)intval($dt->format('H')),
    'mm'   => $dt->format('i'),
    'm'    => (string)intval($dt->format('i')),
  ];

  // Zamenjaj tokene (najprej daljše)
  $order = ['YYYY','DD','MM','HH','YY','D','M','H','mm','m'];
  foreach ($order as $tok) {
    $pattern = str_replace($tok, $map[$tok], $pattern);
  }
  return $pattern;
}

// Doda *_fmt ključe glede na mapiranje ["from"=>"date", "created"=>"datetime", ...]
function cm_add_formatted_fields(array &$payload, array $fieldMap, array $cfg): void {
  $tz  = $cfg['timezone'];
  $df  = $cfg['date_format'];
  $dtf = $cfg['datetime_format'];

  foreach ($fieldMap as $field => $kind) {
    if (!array_key_exists($field, $payload)) continue;
    $iso = (string)$payload[$field];
    $fmt = ($kind === 'datetime') ? cm_format_from_iso($iso, $dtf, $tz)
                                  : cm_format_from_iso($iso, $df, $tz);
    $payload[$field . '_fmt'] = $fmt;
  }
}

// Filtrira payload glede na output_mode: iso|formatted|both
function cm_filter_output_mode(array $payload, string $mode): array {
  if ($mode === 'both') return $payload;

  $out = [];
  foreach ($payload as $k => $v) {
    $isFmt = str_ends_with($k, '_fmt');
    if ($mode === 'formatted' && $isFmt) {
      $out[$k] = $v;
    } elseif ($mode === 'iso' && !$isFmt) {
      $out[$k] = $v;
    }
  }
  return $out;
}

// JSON helpers (obstoječi API – pustimo zaradi kompatibilnosti)
function cm_json_read(string $path): ?array {
  if (!is_file($path)) return null;
  $j = file_get_contents($path);
  if ($j === false) return null;
  $d = json_decode($j, true);
  return is_array($d) ? $d : null;
}
function cm_json_write(string $path, array $data): bool {

  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $json .= "\n";

  // Atomic write to avoid partial files
  $tmp = @tempnam($dir, basename($path) . '.tmp.');
  if (!$tmp) return false;

  $ok = @file_put_contents($tmp, $json) !== false;
  if (!$ok) {
    @unlink($tmp);
    return false;
  }

  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }

  // Permissions discipline: keep files group-writable for www-data + apartma workflow
  @chmod($path, 0664);
  // Group should be the shared writer group (matches your current layout)
  @chgrp($path, 'apartma');

  return true;

}

// Utility: poišči inquiry datoteko z ID-jem v strukturi inquiries/*/*/<status>/
function cm_find_inquiry_file(string $baseDir, string $id, string $status): ?string {
  $pattern = rtrim($baseDir,'/') . "/*/*/{$status}/{$id}.json";
  $matches = glob($pattern, GLOB_NOSORT);
  return $matches && count($matches) > 0 ? $matches[0] : null;
}

// Utility: random token + ISO now helper
function cm_iso_now(string $tz): string {
  $dt = new DateTimeImmutable('now', new DateTimeZone($tz));
  return $dt->format('c');
}
function cm_random_token(int $bytes = 16): string {
  return bin2hex(random_bytes($bytes));
}

// --- DODATEK: base URL za public del (za linke v mailih/straneh)
function cm_public_base_url(): string {
  $s = cm_load_settings();
  $u = $s['public_base_url'] ?? 'http://localhost/app';
  return rtrim((string)$u, '/');
}

// --- DODATEK: preprost append log (JSON line)
function cm_append_log(string $path, array $row): void {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0775, true);
  $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents($path, $line, FILE_APPEND);
}

// --- DODATEK: odstrani occupancy vnos po ID-ju rezervacije
function cm_remove_occupancy_by_id(string $unitsRoot, string $unit, string $reservationId): bool {
  $occPath = rtrim($unitsRoot,'/') . "/{$unit}/occupancy.json";
  $occ = cm_json_read($occPath);
  if (!is_array($occ)) return false;

  $changed = false;
  $new = [];
  foreach ($occ as $row) {
    if (isset($row['id']) && $row['id'] === $reservationId) {
      $changed = true;
      continue; // skip → remove
    }
    $new[] = $row;
  }
  if ($changed) {
    return cm_json_write($occPath, $new);
  }
  return false;
}

// ----------------------------------------------------------------------
// ---- OCCUPANCY NORMALIZATION + MERGE/PUBLISH (ICS LAB standard) -------
// ----------------------------------------------------------------------

/**
 * Normalizira segment v kanonično shemo: start/end/status (end-exclusive).
 * Sprejme tudi legacy shemo: from/to/type.
 */
function cm_occ_normalize_segment(array $seg): array {
  $start  = $seg['start']  ?? $seg['from'] ?? null;
  $end    = $seg['end']    ?? $seg['to']   ?? null;
  $status = $seg['status'] ?? $seg['type'] ?? null;

  if (!$start || !$end || !$status) return [];

  // Map legacy "type" -> kanonični "status"
  $map = [
    'booking'  => 'reserved',
    'reserved' => 'reserved',
    'block'    => 'blocked',
    'blocked'  => 'blocked',
    'busy'     => 'blocked',
  ];
  if (isset($map[$status])) $status = $map[$status];

  $out = [
    'start'  => (string)$start,
    'end'    => (string)$end,
    'status' => (string)$status,
  ];

  // Preserve common extra fields if present
  foreach (['id','source','lock','export','meta','note','platform'] as $k) {
    if (array_key_exists($k, $seg)) $out[$k] = $seg[$k];
  }

  // Normalize meta platform hint (common for ICS LAB)
  if (!isset($out['platform']) && isset($out['meta']['platform'])) {
    $out['platform'] = $out['meta']['platform'];
  }

  return $out;
}

function cm_json_read_array(string $path): array {
  $d = cm_json_read($path);
  return is_array($d) ? $d : [];
}
function cm_json_read_object(string $path): array {
  $d = cm_json_read($path);
  return is_array($d) ? $d : [];
}

 function cm_json_write_pretty(string $path, $data): bool {
   if (is_array($data)) return cm_json_write($path, $data);
  // fallback: if someone passes a non-array payload, still write atomically + normalize perms
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $json .= "\n";

  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;

  $tmp = @tempnam($dir, basename($path) . '.tmp.');
  if (!$tmp) return false;

  $ok = @file_put_contents($tmp, $json) !== false;
  if (!$ok) {
    @unlink($tmp);
     return false;
   }

  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }

  @chmod($path, 0664);
  @chgrp($path, 'apartma');

  return true;
}

/**
 * Vrne seznam enabled inbound platform za enoto iz integrations/<UNIT>.json.
 * Podpira oba stila:
 *   - connections.<plat>.enabled = true
 *   - connections.<plat>.status in ['enabled','active','ok']
 * + (ICS LAB) connections.<plat>.in.enabled = true
 */
function cm_integrations_enabled_platforms(string $root, string $unit): array {
  $cfgPath = $root . "/common/data/json/integrations/{$unit}.json";
  $cfg = cm_json_read_object($cfgPath);
  if (!$cfg) return [];

  $connections = $cfg['connections'] ?? [];
  if (!is_array($connections)) return [];

  $plats = [];
  foreach ($connections as $platform => $conn) {
    if (!is_array($conn)) continue;

    // LAB style: connections.booking.in.enabled
    if (isset($conn['in']) && is_array($conn['in']) && ($conn['in']['enabled'] ?? null) === true) {
      $plats[] = (string)$platform;
      continue;
    }

// legacy style: connections.booking.enabled OR status (string) OR status meta (array)
$enabled = ($conn['enabled'] ?? null);

$stRaw   = $conn['status'] ?? '';
$status  = is_string($stRaw) ? $stRaw : '';

// LAB status meta: { last_ok, last_error }
$statusOk = false;
if (is_array($stRaw)) {
  $lastOk  = $stRaw['last_ok'] ?? null;
  $lastErr = $stRaw['last_error'] ?? null;

  $hasOk   = is_string($lastOk) && $lastOk !== '';
  $hasErr  = is_string($lastErr) && $lastErr !== '';
  $statusOk = $hasOk && !$hasErr;
}

if ($enabled === true || $statusOk || in_array($status, ['enabled','active','ok'], true)) {
  $plats[] = (string)$platform;
  continue;
}

  }

  return array_values(array_unique($plats));
}

/**
 * Prebere ICS LAB external sloj: units/<UNIT>/external/<platform>_ics.json in vrne segmente.
 * Pričakovan format: { unit, platform, fetched_at, count, events:[...] }.
 */
function cm_external_ics_segments(string $unitDir, string $platform): array {
  $path = $unitDir . "/external/{$platform}_ics.json";
  $obj = cm_json_read_object($path);
  if (!$obj) return [];

  $events = $obj['events'] ?? [];
  if (!is_array($events)) return [];

  $out = [];
  foreach ($events as $ev) {
    if (!is_array($ev)) continue;

    // standardiziraj minimalno: source=ics + platform hint
    if (!isset($ev['source'])) $ev['source'] = 'ics';
    if (!isset($ev['meta']) || !is_array($ev['meta'])) $ev['meta'] = [];
    if (!isset($ev['meta']['platform'])) $ev['meta']['platform'] = $platform;

    $norm = cm_occ_normalize_segment($ev);
    if ($norm) $out[] = $norm;
  }
  return $out;
}

/**
 * Publish view za public: occupancy.json iz occupancy_merged.json (kanonični start/end/status).
 */
function cm_publish_occupancy_for_unit(string $unitsRoot, string $unit): bool {
  $unitDir = rtrim($unitsRoot,'/') . '/' . $unit;
  $mergedPath = $unitDir . '/occupancy_merged.json';
  $arr = cm_json_read_array($mergedPath);

  $out = [];
  foreach ($arr as $seg) {
    if (!is_array($seg)) continue;
    $norm = cm_occ_normalize_segment($seg);
    if (!$norm) continue;
    $out[] = $norm;
  }

  usort($out, function($a, $b) {
    return strcmp($a['start'], $b['start'])
      ?: strcmp($a['end'], $b['end'])
      ?: strcmp($a['status'], $b['status']);
  });

  return cm_json_write_pretty($unitDir . '/occupancy.json', $out);
}

/**
 * Regenerira occupancy_merged.json za eno enoto.
 * Standard: output je vedno kanoničen (start/end/status).
 * Viri:
 *   - local_bookings.json (hard locks)
 *   - occupancy.json (admin/local reservations/blocks – legacy ali kanonično)
 *   - external/<platform>_ics.json (ICS LAB inbound), filtrirano po integrations/<UNIT>.json (enabled)
 *
 * Po regen: vedno publish occupancy.json (public kompatibilno).
 */
function cm_regen_merged_for_unit(string $unitsRoot, string $unit): bool {
  $unitsRoot = rtrim($unitsRoot,'/');
  $unitDir = $unitsRoot . '/' . $unit;

  if (!is_dir($unitDir)) return false;

  // /var/www/html/app/common/lib -> /var/www/html/app
  $root = dirname(dirname(__DIR__));

  $segments = [];

  // 1) local hardlocks
// 1) local_bookings: v merged gre SAMO exported hard (contract)
$local = cm_json_read_array($unitDir . '/local_bookings.json');
foreach ($local as $seg) {
  if (!is_array($seg)) continue;

  $lock = (string)($seg['lock'] ?? '');
  $exp  = $seg['export'] ?? null;

  // STRICT: only hard + export:true
  if ($lock !== 'hard') continue;
  if ($exp !== true) continue;

  // normalize minimal provenance
  if (!isset($seg['source'])) $seg['source'] = 'admin';

  // normalize segment to canonical schema
  $norm = cm_occ_normalize_segment($seg);
  if (!$norm) continue;

  // enforce Airbnb-like separation: exported local blocks are "blocked"
  $norm['status'] = 'blocked';

  // mark provenance (helps later management/cleanup)
  if (!isset($norm['meta']) || !is_array($norm['meta'])) $norm['meta'] = [];
  $norm['meta']['exported_from'] = 'local_bookings';

  $segments[] = $norm;
}

  // 2) existing occupancy (admin/local/current) – lahko legacy
  $occ = cm_json_read_array($unitDir . '/occupancy.json');
  foreach ($occ as $seg) {
    if (!is_array($seg)) continue;
    $norm = cm_occ_normalize_segment($seg);
    if ($norm) $segments[] = $norm;
  }

  // 3) ICS LAB inbound external layer – enabled platforms from integrations config
  $plats = cm_integrations_enabled_platforms($root, $unit);

  // fallback (dev/transition): če ni nič enabled, a obstajajo external fajli, jih vseeno poberi
  if (!$plats) {
    foreach (['booking','airbnb','gcal'] as $p) {
      if (is_file($unitDir . "/external/{$p}_ics.json")) $plats[] = $p;
    }
  }

  foreach ($plats as $p) {
    $icsSegs = cm_external_ics_segments($unitDir, $p);
    foreach ($icsSegs as $s) $segments[] = $s;
  }

  // sort
  usort($segments, function($a, $b) {
    return strcmp($a['start'], $b['start'])
      ?: strcmp($a['end'], $b['end'])
      ?: strcmp($a['status'], $b['status']);
  });

  // dedup (id ali start/end/status/source/platform)
  $dedup = [];
  $out = [];
  foreach ($segments as $s) {
    $key = isset($s['id'])
      ? ('id:' . $s['id'])
      : implode('|', [$s['start'],$s['end'],$s['status'], (string)($s['source'] ?? ''), (string)($s['platform'] ?? '')]);

    if (isset($dedup[$key])) continue;
    $dedup[$key] = true;
    $out[] = $s;
  }

   // ------------------------------------------------------------------
   // GAP-01 fix (Contract v1.0): hard > soft (Variant B: DROP soft overlaps)
   // If any soft segment overlaps any hard segment, remove it from merged.
   // Overlap rule (end-exclusive nights): a.start < b.end && b.start < a.end
   // ------------------------------------------------------------------
    $hard = [];
    $soft = [];
   foreach ($out as $s) {
     $lock = (string)($s['lock'] ?? '');
     if ($lock === 'hard') $hard[] = $s;
     else                 $soft[] = $s;
   }

   if (!empty($hard) && !empty($soft)) {
     $softFiltered = [];
     foreach ($soft as $s) {
       $sStart = (string)($s['start'] ?? '');
       $sEnd   = (string)($s['end'] ?? '');
       if ($sStart === '' || $sEnd === '') continue;

       $overlapsHard = false;
       foreach ($hard as $h) {
         $hStart = (string)($h['start'] ?? '');
         $hEnd   = (string)($h['end'] ?? '');
         if ($hStart === '' || $hEnd === '') continue;

         if ($sStart < $hEnd && $hStart < $sEnd) { // overlap
           $overlapsHard = true;
           break;
         }
       }

       if (!$overlapsHard) $softFiltered[] = $s;
     }
     $out = array_merge($hard, $softFiltered);
   } else {
     // nothing to resolve
     $out = array_merge($hard, $soft);
   }

   // Stable sort (deterministic output)
   usort($out, function($a, $b) {
     return strcmp((string)$a['start'], (string)$b['start'])
       ?: strcmp((string)$a['end'], (string)$b['end'])
       ?: strcmp((string)($a['lock'] ?? ''), (string)($b['lock'] ?? ''))
       ?: strcmp((string)$a['status'], (string)$b['status']);
   });
 

  $mergedOutPath = $unitDir . '/occupancy_merged.json';
  $ok = cm_json_write_pretty($mergedOutPath, $out);
  if (!$ok) {
    $e = function_exists('error_get_last') ? error_get_last() : null;
    error_log("[cm_regen_merged_for_unit] WRITE_MERGED_FAILED unit={$unit} path={$mergedOutPath} err=" . json_encode($e));
    return false;
  }

  // po regen merged, vedno publish occupancy.json v public-format
  $pubOk = cm_publish_occupancy_for_unit($unitsRoot, $unit);
  if (!$pubOk) {
    $e = function_exists('error_get_last') ? error_get_last() : null;
    error_log("[cm_regen_merged_for_unit] PUBLISH_FAILED unit={$unit} err=" . json_encode($e));
    return false;
  }
  return true;


}
