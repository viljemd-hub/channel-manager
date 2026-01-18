<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/local_block_remove.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/local_block_remove.php
declare(strict_types=1);

require __DIR__ . '/_lib/json_io.php';
require_once '/var/www/html/app/common/lib/datetime_fmt.php'; // cm_regen_merged_for_unit

header('Content-Type: application/json; charset=utf-8');

try {
    $input = require_post_json();
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$unit = isset($input['unit']) ? trim((string)$input['unit']) : '';
$from = isset($input['from']) ? trim((string)$input['from']) : '';
$to   = isset($input['to'])   ? trim((string)$input['to'])   : '';

if ($unit === '' || $from === '' || $to === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_params']);
    exit;
}

if ($from >= $to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_range']);
    exit;
}

$root       = '/var/www/html/app';
$unitsRoot  = $root . '/common/data/json/units';
$unitDir    = $unitsRoot . '/' . $unit;
$localFile  = $unitDir . '/local_bookings.json';

if (!is_dir($unitDir)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unit_not_found']);
    exit;
}

$data = read_json($localFile);
if (!is_array($data) || !$data) {
    // Ni nič za brisat → OK
    echo json_encode(['ok' => true, 'removed' => 0]);
    exit;
}

/**
 * Izračun prekrivanja [s,e) z [from,to)
 * Datumi so v obliki YYYY-MM-DD → leksikografsko primerljivi.
 */
$removed = 0;
$out = [];

foreach ($data as $row) {

    // delamo samo z admin_block
    if (($row['type'] ?? '') !== 'admin_block') {
        $out[] = $row;
        continue;
    }

    $s = $row['start'] ?? null;
    $e = $row['end']   ?? null;
    if (!$s || !$e) {
        $out[] = $row;
        continue;
    }

    // 1) razširi admin block v seznam nočitev
    $blockNights = [];
    $d = $s;
    while ($d < $e) {
        $blockNights[] = $d;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    // 2) razširi selection v seznam nočitev
    $removeNights = [];
    $d = $from;
    while ($d < $to) {
        $removeNights[] = $d;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    // 3) odštej nočitve
    $remain = array_values(array_diff($blockNights, $removeNights));

    // nič ne ostane → celoten block odstranjen
    if (!$remain) {
        $removed++;
        continue;
    }

    // 4) preostale nočitve združi v segmente
    sort($remain);
    $chunks = [];
    $cs = $remain[0];
    $prev = $cs;

    for ($i = 1; $i < count($remain); $i++) {
        $cur = $remain[$i];
        $expected = date('Y-m-d', strtotime($prev . ' +1 day'));
        if ($cur !== $expected) {
            $chunks[] = [$cs, date('Y-m-d', strtotime($prev . ' +1 day'))];
            $cs = $cur;
        }
        $prev = $cur;
    }
    $chunks[] = [$cs, date('Y-m-d', strtotime($prev . ' +1 day'))];

    // 5) zapiši nazaj segmente
    foreach ($chunks as [$ns, $ne]) {
        $n = $row;
        $n['start'] = $ns;
        $n['end']   = $ne;
        $out[] = $n;
    }

    $removed++;
}

// write updated local_bookings.json
write_json($localFile, $out);

// regenerate merged
$unitsRoot = $root . '/common/data/json/units';
if (function_exists('cm_regen_merged_for_unit')) {
    write_json($localFile, $out);
    clearstatcache(true, $localFile);

    cm_regen_merged_for_unit($unitsRoot, $unit);
}


echo json_encode([
    'ok'      => true,
    'unit'    => $unit,
    'from'    => $from,
    'to'      => $to,
    'removed' => $removed,
    'refresh' => ['ts' => gmdate('c')]
]);
