<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/mobile_calendar.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/mobile_calendar.php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

$tz = new DateTimeZone('Europe/Ljubljana');

// --- paths ---
$appRoot  = realpath(__DIR__ . '/..') ?: '/var/www/html/app';
$dataRoot = $appRoot . '/common/data/json';
$unitsDir = $dataRoot . '/units';
$manifestFile = $unitsDir . '/manifest.json';

// --- tiny helpers (unique names, to avoid collisions) ---
function mc_str(string $s): string { return trim($s); }

function mc_get_from(array $x): string {
  return (string)($x['from'] ?? $x['start'] ?? '');
}
function mc_get_to(array $x): string {
  return (string)($x['to'] ?? $x['end'] ?? '');
}
function mc_get_status(array $x): string {
  return (string)($x['status'] ?? $x['type'] ?? '');
}

function mc_overlap_year(string $from, string $to, int $year, DateTimeZone $tz): bool {
  if ($from === '' || $to === '') return false;
  try {
    $a = new DateTimeImmutable($from, $tz);
    $b = new DateTimeImmutable($to, $tz);
  } catch (Throwable $e) {
    return false;
  }
  $ys = new DateTimeImmutable(sprintf('%04d-01-01', $year), $tz);
  $ye = new DateTimeImmutable(sprintf('%04d-12-31', $year), $tz);
  // ranges are [from, to) in CM; overlap if from <= ye+1 and to > ys
  $yePlus1 = $ye->modify('+1 day');
  return ($a < $yePlus1) && ($b > $ys);
}

function mc_fmt_date(string $ymd, DateTimeZone $tz): string {
  if ($ymd === '') return '';
  try {
    $d = new DateTimeImmutable($ymd, $tz);
    return $d->format('d.m.Y');
  } catch (Throwable $e) {
    return $ymd;
  }
}

function mc_read_json_file(string $path) {
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE) ? $j : null;
}

function mc_units_from_manifest(string $manifestFile, string $unitsDir): array {
  $out = [];

  $m = mc_read_json_file($manifestFile);
  if (is_array($m)) {
    $list = null;

    // common shapes: {units:[{id,name,...}]}, or {public_units:[...]}
    if (isset($m['units']) && is_array($m['units'])) $list = $m['units'];
    elseif (isset($m['public_units']) && is_array($m['public_units'])) $list = $m['public_units'];
    elseif (array_is_list($m)) $list = $m;

    if (is_array($list)) {
      foreach ($list as $u) {
        if (is_string($u)) {
          $id = trim($u);
          if ($id !== '') $out[] = ['id'=>$id, 'label'=>$id];
          continue;
        }
        if (!is_array($u)) continue;
        $id = (string)($u['id'] ?? $u['unit'] ?? $u['code'] ?? '');
        $id = trim($id);
        if ($id === '') continue;
        $label = (string)($u['name'] ?? $u['label'] ?? $u['title'] ?? $id);
        $label = trim($label);
        $out[] = ['id'=>$id, 'label'=>($label !== '' ? $label : $id)];
      }
    }
  }

  // fallback: scan units directory
  if (!$out && is_dir($unitsDir)) {
    foreach (scandir($unitsDir) ?: [] as $d) {
      if ($d === '.' || $d === '..') continue;
      if ($d === 'manifest.json') continue;
      $p = $unitsDir . '/' . $d;
      if (is_dir($p) && preg_match('/^[A-Z0-9_]+$/', $d)) {
        $out[] = ['id'=>$d, 'label'=>$d];
      }
    }
  }

  usort($out, fn($a,$b)=>strcmp($a['id'],$b['id']));
  return $out;
}

// --- request params ---
$key = mc_str((string)($_GET['key'] ?? '')); // if you use key in URLs, we preserve it
$year = (int)($_GET['y'] ?? (int)(new DateTimeImmutable('now', $tz))->format('Y'));
if ($year < 2000 || $year > 2100) $year = (int)(new DateTimeImmutable('now', $tz))->format('Y');

$units = mc_units_from_manifest($manifestFile, $unitsDir);
$unit = mc_str((string)($_GET['unit'] ?? ''));

if ($unit === '' && $units) $unit = $units[0]['id'];
$unitOk = false;
foreach ($units as $u) { if ($u['id'] === $unit) { $unitOk = true; break; } }
if (!$unitOk && $units) $unit = $units[0]['id'];

// --- collect items ---
$items = [];

// (A) reservations/<year>/<unit>/*.json
$resDir = $dataRoot . '/reservations/' . $year . '/' . $unit;
if (is_dir($resDir)) {
  foreach (glob($resDir . '/*.json') ?: [] as $file) {
    $j = mc_read_json_file($file);
    if (!is_array($j)) continue;

    $from = mc_get_from($j);
    $to   = mc_get_to($j);
    if (!mc_overlap_year($from, $to, $year, $tz)) continue;

    $items[] = [
      'kind' => 'reservation',
      'from' => $from,
      'to'   => $to,
      'id'   => (string)($j['id'] ?? basename($file, '.json')),
      'status' => (string)($j['status'] ?? ''),
      'source' => (string)($j['source'] ?? ''),
      'guest'  => is_array($j['guest'] ?? null) ? $j['guest'] : [],
      'calc'   => is_array($j['calc'] ?? null) ? $j['calc'] : [],
      'raw'    => $j,
    ];
  }
}

// (B) units/<unit>/occupancy_merged.json (ICS + merged blocks)
$occFile = $unitsDir . '/' . $unit . '/occupancy_merged.json';
$occ = mc_read_json_file($occFile);
if (is_array($occ)) {
  foreach ($occ as $seg) {
    if (!is_array($seg)) continue;
    $from = mc_get_from($seg);
    $to   = mc_get_to($seg);
    if (!mc_overlap_year($from, $to, $year, $tz)) continue;

    $items[] = [
      'kind'   => 'occupancy',
      'from'   => $from,
      'to'     => $to,
      'status' => mc_get_status($seg),
      'lock'   => (string)($seg['lock'] ?? ''),
      'source' => (string)($seg['source'] ?? ''),
      'meta'   => is_array($seg['meta'] ?? null) ? $seg['meta'] : [],
      'raw'    => $seg,
    ];
  }
}

// (C) units/<unit>/local_bookings.json (hardlocks / internal blocks)
$lbFile = $unitsDir . '/' . $unit . '/local_bookings.json';
$lbs = mc_read_json_file($lbFile);
if (is_array($lbs)) {
  foreach ($lbs as $seg) {
    if (!is_array($seg)) continue;
    $from = mc_get_from($seg);
    $to   = mc_get_to($seg);
    if (!mc_overlap_year($from, $to, $year, $tz)) continue;

    $items[] = [
      'kind'   => 'local',
      'from'   => $from,
      'to'     => $to,
      'status' => mc_get_status($seg),
      'lock'   => (string)($seg['lock'] ?? 'hard'),
      'source' => (string)($seg['source'] ?? 'admin'),
      'note'   => (string)($seg['note'] ?? ''),
      'raw'    => $seg,
    ];
  }
}

usort($items, function($a, $b) {
  $ta = strtotime((string)$a['from']) ?: 0;
  $tb = strtotime((string)$b['from']) ?: 0;
  if ($ta === $tb) return strcmp((string)$a['kind'], (string)$b['kind']);
  return $ta <=> $tb;
});

// --- urls ---
$baseQs = [];
if ($key !== '') $baseQs['key'] = $key;

function mc_build_url(string $path, array $qs): string {
  if (!$qs) return $path;
  return $path . '?' . http_build_query($qs);
}

$backUrl = mc_build_url('/app/admin/mobile.php', $baseQs);

$prevYearUrl = mc_build_url('/app/admin/mobile_calendar.php', $baseQs + ['unit'=>$unit, 'y'=>$year-1]);
$nextYearUrl = mc_build_url('/app/admin/mobile_calendar.php', $baseQs + ['unit'=>$unit, 'y'=>$year+1]);

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Mobile · Listing</title>

  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <style>
	:root{
	  --txt-accent: #8fd3ff;   /* svetlo modra, dobra na temni */
	}

    body.adm-shell.theme-dark.mobile-cal { background:#0b1220; }
    .wrap { padding: 12px 14px 18px; max-width: 820px; margin: 0 auto; }
    .card {
      background: linear-gradient(180deg, rgba(19,28,42,96), rgba(14,21,32,96));
      border: 1px solid rgba(255,255,255,10);
      border-radius: 14px;
      padding: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,35);
      margin-bottom: 10px;
    }
    .toprow { display:flex; align-items:center; justify-content:space-between; gap: 10px; }
    .controls { display:flex; align-items:center; gap: 8px; flex-wrap: wrap; }
    .pill {
      display:inline-flex; align-items:center; gap: 6px;
      border:1px solid rgba(255,255,255,12);
      background: #1a2332;
      padding: 6px 10px; border-radius: 999px;
      font-weight: 700; font-size: 13px;
    }
    select, input {
      background: #1a2332;
      border: 1px solid rgba(255,255,255,12);
      color: #e9eef5;
      border-radius: 10px;
      padding: 8px 10px;
      font-size: 14px;
      outline: none;
    }
    .muted { color: rgba(233,238,245,70); font-size: 13px; }
    .item-h { display:flex; justify-content:space-between; gap: 10px; align-items: baseline; }
    .item-title { font-weight: 800; }
    .badge {
      font-size: 12px; font-weight: 800;
      padding: 4px 8px; border-radius: 999px;
      background: #1a2332;
      border: 1px solid rgba(255,255,255,12);
      white-space: nowrap;
    }
    .k { color: rgba(233,238,245,70); }
    .grid2 { display:grid; grid-template-columns: 1fr; gap: 6px; margin-top: 8px; }
    .line { font-size: 14px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    .empty { padding: 14px; text-align:center; }
    .smallbtn { text-decoration:none; }
	.item-title,
	.line,
	.badge,
	.pill {
	  color: var(--txt-accent);
	}
.item-title,
.mono {
  color: #9fe0ff;
}


  </style>
</head>

<body class="adm-shell theme-dark mobile-cal">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Mobile · Listing</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="<?= h($backUrl) ?>">Nazaj</a>
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Desktop UI">Desktop</a>
    </div>
  </header>

  <main class="wrap">
    <section class="card">
      <div class="toprow">
        <div class="controls">
          <span class="pill">Enota</span>
          <select id="unitSel">
            <?php foreach ($units as $u): ?>
              <option value="<?= h($u['id']) ?>" <?= $u['id']===$unit?'selected':'' ?>>
                <?= h($u['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <span class="pill">Leto</span>
          <a class="btn small smallbtn" href="<?= h($prevYearUrl) ?>">‹</a>
          <span class="pill mono"><?= h((string)$year) ?></span>
          <a class="btn small smallbtn" href="<?= h($nextYearUrl) ?>">›</a>
        </div>

        <div class="muted">
          Zapisi: <b><?= (int)count($items) ?></b>
        </div>
      </div>

      <div style="margin-top:10px">
        <input id="filterInp" type="text" placeholder="Filter (id / ime / email / telefon / source…)" style="width:100%" />
        <div class="muted" style="margin-top:6px">
          Prikaz: reservations + occupancy_merged (ICS) + local_bookings.
        </div>
      </div>
    </section>

    <div id="listRoot">
      <?php if (!$items): ?>
        <section class="card empty">
          <div class="item-title">Ni zapisov za <?= h($unit) ?> v letu <?= h((string)$year) ?>.</div>
          <div class="muted">Če pričakuješ rezervacije: preveri mapo <span class="mono">common/data/json/reservations/<?= h((string)$year) ?>/<?= h($unit) ?>/</span>.</div>
        </section>
      <?php endif; ?>

      <?php foreach ($items as $it): ?>
        <?php
          $from = (string)$it['from'];
          $to   = (string)$it['to'];
          $titleRange = mc_fmt_date($from, $tz) . ' → ' . mc_fmt_date($to, $tz);

          $kind = (string)$it['kind'];
          $badge = $kind;

          if ($kind === 'reservation') {
            $badge = ($it['status'] ?? '') !== '' ? ('reservation · ' . $it['status']) : 'reservation';
          } elseif ($kind === 'occupancy') {
            $st = (string)($it['status'] ?? '');
            $src = (string)($it['source'] ?? '');
            $badge = trim('ics/merged · ' . $st . ($src ? (' · ' . $src) : ''));
          } elseif ($kind === 'local') {
            $st = (string)($it['status'] ?? 'block');
            $badge = 'local · ' . $st;
          }

          // searchable text
          $searchBlob = strtolower(json_encode($it, JSON_UNESCAPED_UNICODE) ?: '');
        ?>
        <section class="card item" data-search="<?= h($searchBlob) ?>">
          <div class="item-h">
            <div class="item-title"><?= h($titleRange) ?></div>
            <span class="badge"><?= h($badge) ?></span>
          </div>

          <?php if ($kind === 'reservation'): ?>
            <?php
              $gid = (string)($it['id'] ?? '');
              $guest = $it['guest'] ?? [];
              $name  = (string)($guest['name'] ?? '');
              $email = (string)($guest['email'] ?? '');
              $phone = (string)($guest['phone'] ?? '');
              $note  = (string)($guest['note'] ?? '');
              $src   = (string)($it['source'] ?? '');
              $calc  = $it['calc'] ?? [];
              $final = (string)($calc['final'] ?? '');
            ?>
            <div class="grid2">
              <div class="line"><span class="k">ID:</span> <span class="mono"><?= h($gid) ?></span></div>
              <div class="line"><span class="k">Gost:</span> <?= h($name) ?></div>
              <div class="line"><span class="k">Kontakt:</span> <?= h(trim($email . ' ' . $phone)) ?></div>
              <?php if ($src !== ''): ?>
                <div class="line"><span class="k">Source:</span> <?= h($src) ?></div>
              <?php endif; ?>
              <?php if ($note !== ''): ?>
                <div class="line"><span class="k">Note:</span> <?= h($note) ?></div>
              <?php endif; ?>
              <?php if ($final !== ''): ?>
                <div class="line"><span class="k">Final:</span> <?= h($final) ?> €</div>
              <?php endif; ?>
            </div>

          <?php elseif ($kind === 'occupancy'): ?>
            <?php
              $st = (string)($it['status'] ?? '');
              $lock = (string)($it['lock'] ?? '');
              $src = (string)($it['source'] ?? '');
              $meta = $it['meta'] ?? [];
              $summary = (string)($meta['summary'] ?? $meta['title'] ?? $meta['note'] ?? '');
            ?>
            <div class="grid2">
              <div class="line"><span class="k">Status:</span> <?= h($st) ?></div>
              <?php if ($lock !== ''): ?><div class="line"><span class="k">Lock:</span> <?= h($lock) ?></div><?php endif; ?>
              <?php if ($src !== ''): ?><div class="line"><span class="k">Source:</span> <?= h($src) ?></div><?php endif; ?>
              <?php if ($summary !== ''): ?><div class="line"><span class="k">Summary:</span> <?= h($summary) ?></div><?php endif; ?>
            </div>

          <?php else: /* local */ ?>
            <?php
              $st = (string)($it['status'] ?? '');
              $src = (string)($it['source'] ?? '');
              $note = (string)($it['note'] ?? '');
            ?>
            <div class="grid2">
              <div class="line"><span class="k">Type:</span> <?= h($st !== '' ? $st : 'block') ?></div>
              <div class="line"><span class="k">Source:</span> <?= h($src !== '' ? $src : 'admin') ?></div>
              <?php if ($note !== ''): ?><div class="line"><span class="k">Note:</span> <?= h($note) ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </main>

  <script>
    (function(){
      const unitSel = document.getElementById('unitSel');
      const filterInp = document.getElementById('filterInp');

      const key = <?= json_encode($key) ?>;
      const year = <?= (int)$year ?>;

      function go(unit){
        const qs = new URLSearchParams();
        if (key) qs.set('key', key);
        qs.set('unit', unit);
        qs.set('y', String(year));
        location.href = '/app/admin/mobile_calendar.php?' + qs.toString();
      }

      unitSel?.addEventListener('change', () => go(unitSel.value));

      filterInp?.addEventListener('input', () => {
        const q = (filterInp.value || '').trim().toLowerCase();
        document.querySelectorAll('.item').forEach(el => {
          const blob = (el.getAttribute('data-search') || '');
          el.style.display = (!q || blob.includes(q)) ? '' : 'none';
        });
      });
    })();
  </script>
</body>
</html>
