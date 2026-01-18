<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/list_reservations.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * List reservations / blocks for admin "Manage reservations" view.
 *
 * Viri:
 *  - /common/data/json/reservations/YYYY/UNIT/*.json       (hard + soft TEST)
 *  - /common/data/json/cancellations/YYYY/UNIT/*.json      (cancelled)
 *  - /common/data/json/inquiries/YYYY/MM/accepted/*.json   (soft-hold – accepted)
 *  - /common/data/json/units/UNIT/local_bookings.json      (local blocks)
 *
 * Filtri (GET):
 *  - unit    = A1 | A2 | "" (vse)
 *  - ym      = YYYY-MM (mesec bivanja, po from/to)
 *  - year    = YYYY (celotno leto bivanja; če je podan, ima prednost pred ym)
 *  - status  = confirmed | cancelled | all
 *  - source  = direct | ics | local_block | "" (vse)
 *  - q       = iskanje po id / guest.email / guest.name
 *  - include_soft_hold = 1|0
 *
 * Posebno:
 *  - če NI podan ne ym ne year → "radar" način:
 *      prikažejo se le zapisi v teku in prihodnji (eff_to >= danes),
 *      za vse vire (reservations, cancellations, inquiries/accepted, local_blocks).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// -----------------------------
// Config paths
// -----------------------------

$ROOT = realpath(__DIR__ . '/../../common/data/json');
if ($ROOT === false) {
  echo json_encode(['ok' => false, 'error' => 'data_root_not_found']);
  exit;
}

$RES   = $ROOT . '/reservations';
$CANC  = $ROOT . '/cancellations';
$INQ   = $ROOT . '/inquiries';
$UNITS = $ROOT . '/units';

// -----------------------------
// Helpers
// -----------------------------

function mr_read_json(string $path): ?array {
  if (!is_file($path)) return null;
  $json = file_get_contents($path);
  if ($json === false || $json === '') return null;
  $d = json_decode($json, true);
  return is_array($d) ? $d : null;
}

/**
 * Vrne YYYY-MM iz YYYY-MM-DD, ali ''.
 */
function mr_ym(string $date): string {
  if (strlen($date) < 7) return '';
  return substr($date, 0, 7);
}

/**
 * Vrne YYYY iz YYYY-MM-DD, ali ''.
 */
function mr_year(string $date): string {
  if (strlen($date) < 4) return '';
  return substr($date, 0, 4);
}

/**
 * Ali interval [from,to] (YYYY-MM-DD) prečka mesec ym (YYYY-MM)?
 */
function mr_overlaps_ym(?string $from, ?string $to, ?string $ym): bool {
  if (!$ym) return true; // brez filtra
  if (!$from && !$to) return false;
  $fym = $from ? mr_ym($from) : '';
  $tym = $to ? mr_ym($to) : '';
  if ($fym === '' && $tym === '') return false;
  if ($fym === '') $fym = $tym;
  if ($tym === '') $tym = $fym;
  return ($fym <= $ym && $ym <= $tym);
}

/**
 * Ali interval [from,to] prečka leto $year (YYYY)?
 */
function mr_overlaps_year(?string $from, ?string $to, ?string $year): bool {
  if (!$year) return true; // brez filtra
  if (!$from && !$to) return false;
  $fy = $from ? mr_year($from) : '';
  $ty = $to ? mr_year($to) : '';
  if ($fy === '' && $ty === '') return false;
  if ($fy === '') $fy = $ty;
  if ($ty === '') $ty = $fy;
  return ($fy <= $year && $year <= $ty);
}

/**
 * Format "YYYY-MM-DD" -> "dd.mm.YYYY"
 */
function mr_fmt_date(?string $date): string {
  if (!$date || strlen($date) < 10) return '';
  [$y, $m, $d] = explode('-', substr($date, 0, 10));
  if (!ctype_digit($y.$m.$d)) return $date;
  return sprintf('%02d.%02d.%04d', (int)$d, (int)$m, (int)$y);
}

/**
 * Iskanje po id + guest.email + guest.name
 */
function mr_matches_q(array $d, string $q): bool {
  $needle = strtolower(trim($q));
  if ($needle === '') return true;
  $id    = strtolower((string)($d['id'] ?? ''));
  $email = strtolower((string)($d['guest']['email'] ?? ''));
  $name  = strtolower((string)($d['guest']['name'] ?? ''));
  $hay   = trim($id . ' ' . $email . ' ' . $name);
  if ($hay === '') return false;
  return strpos($hay, $needle) !== false;
}

/**
 * Učinkovit "to" datum za interval (če manjka, vzame from).
 * Vrne "YYYY-MM-DD" ali null, če ni nič uporabnega.
 */
function mr_effective_to(?string $from, ?string $to): ?string {
  $from = $from ? substr($from, 0, 10) : '';
  $to   = $to   ? substr($to, 0, 10)   : '';
  if ($to !== '') return $to;
  if ($from !== '') return $from;
  return null;
}

// -----------------------------
// Params
// -----------------------------

$unit   = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$ym     = isset($_GET['ym']) ? trim((string)$_GET['ym']) : '';
$year   = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
$status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'confirmed';
$source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : '';
$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$includeSoft = !empty($_GET['include_soft_hold']);

if ($status !== 'confirmed' && $status !== 'cancelled' && $status !== 'all') {
  $status = 'confirmed';
}

// sanity za ym in year
if ($ym !== '' && !preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $ym)) {
  $ym = '';
}
if ($year !== '' && !preg_match('~^\d{4}$~', $year)) {
  $year = '';
}

$out = [];

// helper za date filter – preferiramo year; če ni, uporabimo ym
$useYear = ($year !== '');

// "Radar" filter: če ni ym in ni year → prikaži samo zapise v teku / prihodnje
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$filterOnlyUpcoming = (!$useYear && $ym === '');

// -----------------------------
// 1) RESERVATIONS (hard + soft lock/test)
// -----------------------------
if (is_dir($RES) && ($status === 'confirmed' || $status === 'all')) {
  foreach (glob($RES . '/*/*/*.json') as $f) {
    $d = mr_read_json($f);
    if (!$d) continue;

    $id    = (string)($d['id'] ?? basename($f, '.json'));
    $u     = (string)($d['unit'] ?? '');
    $from  = (string)($d['from'] ?? '');
    $to    = (string)($d['to'] ?? '');
    $lock  = strtolower((string)($d['lock'] ?? ''));
    $src   = strtolower((string)($d['source'] ?? ''));
    $stat  = strtolower((string)($d['status'] ?? ''));
    $guest = is_array($d['guest'] ?? null) ? $d['guest'] : [];

    // Radar: če ni ym/year → prikažemo samo, če je eff_to >= danes
    if ($filterOnlyUpcoming) {
      $effTo = mr_effective_to($from, $to);
      if ($effTo !== null && $effTo < $today) continue;
    }

    if ($unit && $u !== $unit) continue;

    // datum filter (year ima prednost pred ym)
    if ($useYear) {
      if (!mr_overlaps_year($from, $to, $year)) continue;
    } else {
      if (!mr_overlaps_ym($from, $to, $ym)) continue;
    }

    // Source filter (direct / ics / local_block)
    if ($source && $src !== $source) continue;

    if (!mr_matches_q(['id'=>$id,'guest'=>$guest], $q)) continue;

    // soft-hold ali hard?
    $isSoftByLock = ($lock === 'soft');
    $isSoftById   = (strpos($id, 'SOFT-TEST-') === 0 || strpos($id, 'SOFT-') === 0);
    $bucket = 'hard';

    if ($isSoftByLock || $isSoftById) {
      if (!$includeSoft) continue; // sploh ne vračamo soft-hold rezervacij
      $bucket = 'soft_hold';
      $stat   = $stat ?: 'soft';
    } else {
      $stat = $stat ?: 'confirmed';
    }

    // Če status filter = cancelled, rezervacij ne dodajamo; cancellations imajo svoj del
    if ($status === 'cancelled') continue;

    $item = [
      'id'             => $id,
      'status'         => $stat,
      'lock'           => $lock ?: ($bucket === 'hard' ? 'hard' : 'soft'),
      'unit'           => $u,
      'from'           => $from,
      'to'             => $to,
      'from_fmt'       => mr_fmt_date($from),
      'to_fmt'         => mr_fmt_date($to),
      'source'         => $src ?: 'direct',
      'guest'          => $guest,
      'payment_method' => $d['payment_method'] ?? null,
      'calc'           => is_array($d['calc'] ?? null) ? $d['calc'] : null,
      'cancel_link'    => $d['cancel_link'] ?? null,
      '_bucket'        => $bucket,
    ];

    $out[] = $item;
  }
}

// -----------------------------
// 2) CANCELLATIONS
// -----------------------------
if (is_dir($CANC) && ($status === 'cancelled' || $status === 'all')) {
  foreach (glob($CANC . '/*/*/*.json') as $f) {
    $d = mr_read_json($f);
    if (!$d) continue;

    $id    = (string)($d['id'] ?? basename($f, '.json'));
    $u     = (string)($d['unit'] ?? '');
    $from  = (string)($d['from'] ?? '');
    $to    = (string)($d['to'] ?? '');
    $lock  = strtolower((string)($d['lock'] ?? ''));
    $src   = strtolower((string)($d['source'] ?? ''));
    $stat  = strtolower((string)($d['status'] ?? 'cancelled'));
    $guest = is_array($d['guest'] ?? null) ? $d['guest'] : [];

    // Radar filter
    if ($filterOnlyUpcoming) {
      $effTo = mr_effective_to($from, $to);
      if ($effTo !== null && $effTo < $today) continue;
    }

    if ($unit && $u !== $unit) continue;

    if ($useYear) {
      if (!mr_overlaps_year($from, $to, $year)) continue;
    } else {
      if (!mr_overlaps_ym($from, $to, $ym)) continue;
    }

    if ($source && $src !== $source) continue;
    if (!mr_matches_q(['id'=>$id,'guest'=>$guest], $q)) continue;

    if ($stat !== 'cancelled') {
      if ($status === 'cancelled') continue;
    }

    $item = [
      'id'             => $id,
      'status'         => 'cancelled',
      'lock'           => $lock ?: 'hard',
      'unit'           => $u,
      'from'           => $from,
      'to'             => $to,
      'from_fmt'       => mr_fmt_date($from),
      'to_fmt'         => mr_fmt_date($to),
      'source'         => $src ?: 'direct',
      'guest'          => $guest,
      'payment_method' => $d['payment_method'] ?? null,
      'calc'           => is_array($d['calc'] ?? null) ? $d['calc'] : null,
      'cancel_link'    => $d['cancel_link'] ?? null,
      '_bucket'        => 'cancelled',
    ];

    $out[] = $item;
  }
}

// -----------------------------
// 3) SOFT-HOLD iz inquiries/accepted
// -----------------------------
if ($includeSoft && is_dir($INQ) && ($status === 'confirmed' || $status === 'all')) {
  foreach (glob($INQ . '/*/*/accepted/*.json') as $f) {
    $d = mr_read_json($f);
    if (!$d) continue;

    $id    = (string)($d['id'] ?? basename($f, '.json'));
    $u     = (string)($d['unit'] ?? '');
    $from  = (string)($d['from'] ?? '');
    $to    = (string)($d['to'] ?? '');
    $src   = strtolower((string)($d['source'] ?? 'direct'));
    $stat  = strtolower((string)($d['status'] ?? 'accepted'));
    $guest = is_array($d['guest'] ?? null) ? $d['guest'] : [];

    // Radar filter
    if ($filterOnlyUpcoming) {
      $effTo = mr_effective_to($from, $to);
      if ($effTo !== null && $effTo < $today) continue;
    }

    if ($unit && $u !== $unit) continue;

    if ($useYear) {
      if (!mr_overlaps_year($from, $to, $year)) continue;
    } else {
      if (!mr_overlaps_ym($from, $to, $ym)) continue;
    }

    if ($source && $src !== $source) continue;
    if (!mr_matches_q(['id'=>$id,'guest'=>$guest], $q)) continue;

    $item = [
      'id'             => $id,
      'status'         => $stat, // "accepted"
      'lock'           => 'soft',
      'unit'           => $u,
      'from'           => $from,
      'to'             => $to,
      'from_fmt'       => mr_fmt_date($from),
      'to_fmt'         => mr_fmt_date($to),
      'source'         => $src,
      'guest'          => $guest,
      'payment_method' => $d['payment_method'] ?? null,
      'calc'           => is_array($d['calc'] ?? null) ? $d['calc'] : null,
      'cancel_link'    => $d['cancel_link'] ?? null,
      '_bucket'        => 'soft_hold',
    ];

    $out[] = $item;
  }
}

// -----------------------------
// 4) LOCAL BLOCKS iz units/*/local_bookings.json
// -----------------------------
if (is_dir($UNITS) && ($status === 'confirmed' || $status === 'all')) {
  foreach (glob($UNITS . '/*/local_bookings.json') as $f) {
    $unitDir = basename(dirname($f)); // A1, A2, ...
    if ($unit && $unitDir !== $unit) continue;

    $arr = mr_read_json($f);
    if (!is_array($arr)) continue;

    foreach ($arr as $row) {
      if (!is_array($row)) continue;
      $start  = (string)($row['start'] ?? '');
      $end    = (string)($row['end'] ?? '');
      $statusRow = strtolower((string)($row['status'] ?? 'blocked'));

      // Radar filter
      if ($filterOnlyUpcoming) {
        $effTo = mr_effective_to($start, $end);
        if ($effTo !== null && $effTo < $today) continue;
      }

      if ($useYear) {
        if (!mr_overlaps_year($start, $end, $year)) continue;
      } else {
        if (!mr_overlaps_ym($start, $end, $ym)) continue;
      }

      // Source filter – local_block
      if ($source && $source !== 'local_block') continue;
      // q filter v praksi ne bo našel local block (nima id/email/name)

      $id = sprintf('LOCAL-%s-%s-%s', $unitDir, $start, $end);

      $item = [
        'id'             => $id,
        'status'         => $statusRow ?: 'blocked',
        'lock'           => 'hard',
        'unit'           => $unitDir,
        'from'           => $start,
        'to'             => $end,
        'from_fmt'       => mr_fmt_date($start),
        'to_fmt'         => mr_fmt_date($end),
        'source'         => 'local_block',
        'guest'          => null,
        'payment_method' => null,
        'calc'           => null,
        'cancel_link'    => null,
        '_bucket'        => 'local_block',
        'reason'         => $row['reason'] ?? null,
      ];

      $out[] = $item;
    }
  }
}

// -----------------------------
// Sortiranje po datumu from
// -----------------------------
usort($out, function (array $a, array $b): int {
  $af = $a['from'] ?? '';
  $bf = $b['from'] ?? '';
  if ($af === $bf) {
    return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
  }
  return strcmp($af, $bf);
});

echo json_encode([
  'ok'    => true,
  'count' => count($out),
  'items' => $out,
]);
