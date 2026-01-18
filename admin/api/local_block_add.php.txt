<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/local_block_add.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/local_block_add.php
declare(strict_types=1);

require __DIR__ . '/_lib/json_io.php'; // read_json, write_json, require_post_json
require_once '/var/www/html/app/common/lib/datetime_fmt.php'; // cm_regen_merged_for_unit

header('Content-Type: application/json; charset=utf-8');

try {
    // Expect JSON body with { unit, from, to } (dates are inclusive on input)
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

// Input from JS is already END-exclusive [from, to)
// Example for 1 night: from=2026-02-13, to=2026-02-14
$fromIn = $from;
$toIn   = $to;

// Basic YYYY-MM-DD validation
if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn)
) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_date_format']);
    exit;
}

// We treat input as [from, to) → require from < to (non-empty range)
if ($fromIn >= $toIn) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_range']);
    exit;
}

// Store exactly what JS sends as END-exclusive range
$from = $fromIn;
$to   = $toIn;

$root      = '/var/www/html/app';
$unitsRoot = $root . '/common/data/json/units';
$unitDir   = $unitsRoot . '/' . $unit;
$localFile = $unitDir . '/local_bookings.json';

if (!is_dir($unitDir)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unit_not_found']);
    exit;
}

 
// Read existing local_bookings (if missing, start with an empty array)
$data = read_json($localFile);
if (!is_array($data)) {
    $data = [];
}

// Append new admin_block entry (stored as END-exclusive [start, end))
$entry = [
  'id'         => 'admin_block:' . $unit . ':' . gmdate('YmdHis') . ':' . $from . ':' . $to,
  'type'       => 'admin_block',
  'lock'       => 'soft',
  'source'     => 'admin',
  'start'      => $from,
  'end'        => $to,
  'created_at' => date('c'),
  'export'     => false,
  'meta'       => [ 'reason' => 'admin-block' ],
];


$data[] = $entry;

// Persist updated local_bookings.json
if (!write_json($localFile, $data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}

// After succes write regenerate occupancy_merged.json
$unitsRoot = $root . '/common/data/json/units';
if (function_exists('cm_regen_merged_for_unit')) {
    cm_regen_merged_for_unit($unitsRoot, $unit);
}


// Respond with original inclusive range (for UI / logs)
echo json_encode([
    'ok'    => true,
    'unit'  => $unit,
    'from'  => $fromIn,  // inclusive
    'to'    => $toIn,    // inclusive
    'count' => count($data),
]);
