<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/ics_bak.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Legacy/backup version of the ICS endpoint.
 *
 * Responsibilities:
 * - Older implementation of outgoing ICS feed generation.
 *
 * Used by:
 * - Kept for reference and rollback purposes.
 *
 * Notes:
 * - New integrations should use integrations/ics.php.
 * - This file should not be modified unless you understand why it exists.
 */

declare(strict_types=1);

header('Content-Type: text/calendar; charset=utf-8');

$unit   = $_GET['unit'] ?? '';
$mode   = $_GET['mode'] ?? 'booked';
$mode   = ($mode === 'blocked') ? 'blocked' : 'booked';

/** PRIVZETJE: za mode=blocked privzeto vključi vse (extras=1),
 *             za mode=booked extras ignoriramo. */
if (array_key_exists('extras', $_GET)) {
  $extras = ($_GET['extras'] === '1') ? 1 : 0;
} else {
  $extras = ($mode === 'blocked') ? 1 : 0;
}

if ($unit === '') {
  http_response_code(400);
  echo "BEGIN:VCALENDAR\r\nPRODID:-//AdminV4//ICS//EN\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
  exit;
}

$root = dirname(__FILE__, 4); // /app
$occPath = "$root/common/data/json/units/$unit/occupancy.json";
if (!is_file($occPath)) {
  http_response_code(404);
  echo "BEGIN:VCALENDAR\r\nPRODID:-//AdminV4//ICS//EN\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
  exit;
}

$segments = json_decode(file_get_contents($occPath), true);
if (!is_array($segments)) { $segments = []; }

// ... (ostanek datoteke ostane nespremenjen)

$nl = "\r\n";
$now = gmdate('Ymd\THis\Z');

$cal = [];
$cal[] = 'BEGIN:VCALENDAR';
$cal[] = 'PRODID:-//AdminV4//ICS//EN';
$cal[] = 'VERSION:2.0';
$cal[] = 'CALSCALE:GREGORIAN';
$cal[] = 'METHOD:PUBLISH';
$cal[] = 'X-WR-CALNAME:' . icsText($unit . ' ' . strtoupper($mode));

// --- FILTER --------------------------------------------------------------
$items = array_filter($segments, function($s) use ($mode, $extras) {
  $type = strtolower((string)($s['type'] ?? $s['status'] ?? ''));
  if ($mode === 'booked') {
    return $type === 'reserved';
  }
  // mode=blocked
  if ($type === 'reserved') return true; // vedno vključimo
  $allowed = ['blocked','clean_before','clean_after','maintenance'];
  if (!in_array($type, $allowed, true)) return false;

  if ($extras === 1) return true; // vključi vse
  // extras=0 -> vključimo le, če je eksplicitno export:true
  $export = $s['export'] ?? null;
  return ($export === true || $export === 1 || $export === '1');
});

// --- EVENTS --------------------------------------------------------------
foreach ($items as $s) {
  $from = $s['from'] ?? $s['start'] ?? null;
  $to   = $s['to']   ?? $s['end']   ?? null;
  if (!$from || !$to) continue;

  $dtStart = dateToICS($from);
  $dtEnd   = dateToICS($to);

  $type   = strtolower((string)($s['type'] ?? $s['status'] ?? ''));
  $source = strtolower((string)($s['source'] ?? ''));
  $guestName = '';
  if (!empty($s['guest']['name'])) $guestName = (string)$s['guest']['name'];
  elseif (!empty($s['guest_name'])) $guestName = (string)$s['guest_name'];

  if ($mode === 'booked') {
    $summary = 'Booked – ' . $unit . ($guestName ? (' (Gost: ' . $guestName . ')') : '');
    $cat = 'BOOKED';
  } else {
    $label = $type === 'reserved' ? 'booked' : $type;
    $summary = 'Blocked – ' . $unit . ' (' . $label . ($source ? '/'.$source : '') . ')';
    $cat = 'BLOCKED';
  }

  $uidSeed = ($s['id'] ?? ($unit.'|'.$from.'|'.$to.'|'.$source.'|'.$type));
  $uid = substr(hash('sha256', (string)$uidSeed), 0, 24) . '@adminv4';

  $cal[] = 'BEGIN:VEVENT';
  $cal[] = 'UID:' . $uid;
  $cal[] = 'DTSTAMP:' . $now;
  $cal[] = 'DTSTART;VALUE=DATE:' . $dtStart;
  $cal[] = 'DTEND;VALUE=DATE:' . $dtEnd;
  $cal[] = foldICS('SUMMARY:' . icsText($summary));
  $cal[] = 'CATEGORIES:' . $cat;
  $descBits = [];
  if ($guestName) $descBits[] = 'Guest: ' . $guestName;
  if (!empty($s['guest']['email'])) $descBits[] = 'Email: ' . $s['guest']['email'];
  if (!empty($s['guest']['phone'])) $descBits[] = 'Phone: ' . $s['guest']['phone'];
  if (!empty($s['id'])) $descBits[] = 'ID: ' . $s['id'];
  if ($source) $descBits[] = 'Source: ' . $source;
  if ($descBits) $cal[] = foldICS('DESCRIPTION:' . icsText(implode("\n", $descBits)));
  $cal[] = 'END:VEVENT';
}

$cal[] = 'END:VCALENDAR';
echo implode($nl, $cal);

// --- Helpers -------------------------------------------------------------
function dateToICS(string $ymd): string {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
    return str_replace('-', '', $ymd);
  }
  $t = strtotime($ymd);
  if ($t === false) return gmdate('Ymd');
  return gmdate('Ymd', $t);
}
function icsText(string $s): string {
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace(',', '\,', $s);
  $s = str_replace(';', '\;', $s);
  $s = str_replace("\r\n", '\n', $s);
  $s = str_replace("\n", '\n', $s);
  return $s;
}
function foldICS(string $line, int $limit=75): string {
  if (strlen($line) <= $limit) return $line;
  $out = '';
  while (strlen($line) > $limit) {
    $out .= substr($line, 0, $limit) . "\r\n" . ' ';
    $line = substr($line, $limit);
  }
  return $out . $line;
}
