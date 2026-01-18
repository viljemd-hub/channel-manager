<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: ics_lab/ics.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

use App\ICS\IcsBuilder;

require_once __DIR__ . '/../common/lib/ics_builder.php';

const DATA_DIR = __DIR__ . '/data';

function cm_icslab_sanitize_unit(string $unit): string {
    $u = preg_replace('/[^A-Za-z0-9_-]/', '', $unit);
    return $u !== '' ? $u : 'SIM1';
}

function cm_icslab_load_unit(string $unit): array {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $unit = cm_icslab_sanitize_unit($unit);
    $file = DATA_DIR . '/' . $unit . '.json';
    if (!is_file($file)) {
        return ['unit' => $unit, 'segments' => []];
    }
    $raw = file_get_contents($file);
    if ($raw === false) return ['unit' => $unit, 'segments' => []];
    $j = json_decode($raw, true);
    if (!is_array($j)) return ['unit' => $unit, 'segments' => []];
    if (!isset($j['segments']) || !is_array($j['segments'])) {
        $j['segments'] = [];
    }
    $j['unit'] = $unit;
    return $j;
}

function cm_icslab_bad(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/calendar; charset=utf-8');
    echo "BEGIN:VCALENDAR\r\n";
    echo "PRODID:-//ICS LAB//ERROR//EN\r\nVERSION:2.0\r\n";
    echo "BEGIN:VEVENT\r\n";
    echo "SUMMARY:ICS LAB error\r\n";
    echo "DESCRIPTION:" . IcsBuilder::esc($msg) . "\r\n";
    echo "DTSTART;VALUE=DATE:20000101\r\nDTEND;VALUE=DATE:20000102\r\n";
    echo "END:VEVENT\r\nEND:VCALENDAR\r\n";
    exit;
}

function cm_icslab_add_one_day(string $date): ?string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$dt) return null;
    $dt2 = $dt->modify('+1 day');
    return $dt2 ? $dt2->format('Y-m-d') : null;
}

// ---------- main ----------

header('Content-Type: text/calendar; charset=utf-8');

$unit = cm_icslab_sanitize_unit($_GET['unit'] ?? '');
if ($unit === '') {
    cm_icslab_bad(400, 'Missing unit');
}

$mode = $_GET['mode'] ?? 'all';
$mode = strtolower($mode);
if (!in_array($mode, ['booked', 'blocked', 'all'], true)) {
    $mode = 'all';
}

$data = cm_icslab_load_unit($unit);
$segments = $data['segments'] ?? [];

$builder = new IcsBuilder('-//ICS LAB//SIM 1.0//EN', "ICS LAB {$unit}");
$builder->begin();

foreach ($segments as $seg) {
    $from = (string)($seg['from'] ?? '');
    $to   = (string)($seg['to']   ?? '');
    $type = (string)($seg['type'] ?? 'booking');
    $note = (string)($seg['note'] ?? '');

    if ($from === '' || $to === '') continue;

    // filter by mode
    $isBooking = ($type === 'booking');
    $isBlock   = ($type === 'block');

    if ($mode === 'booked' && !$isBooking) continue;
    if ($mode === 'blocked' && !$isBlock)  continue;

    $endExclusive = cm_icslab_add_one_day($to);
    if ($endExclusive === null) continue;

    // simple UID, dovolj za lab
    $uid = sprintf('%s-%s-%s@icslab.local', $unit, $from, $to);

    $summary = $isBooking ? "LAB booking – {$unit}" : "LAB block – {$unit}";
    if ($note !== '') {
        $summary .= " ({$note})";
    }

    $event = [
        'uid'         => $uid,
        'dtstamp'     => gmdate('Ymd\THis\Z'),
        'start'       => $from,
        'end'         => $endExclusive,
        'summary'     => $summary,
        'description' => '',
        'sequence'    => 0,
        'status'      => '',
        'categories'  => $isBooking ? 'RESERVATION' : 'BLOCK',
    ];

    $builder->addAllDayEvent($event);
}

$builder->end();
echo $builder->render();
