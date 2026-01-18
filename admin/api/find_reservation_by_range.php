<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/find_reservation_by_range.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/find_reservation_by_range.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$unit = $_GET['unit'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

if (!preg_match('/^[A-Z0-9]+$/', $unit)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_unit']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_dates']);
    exit;
}

// Rezervacije so v: /app/common/data/json/reservations/YYYY/UNIT/*.json
$root = realpath(__DIR__ . '/../../common/data/json/reservations');
if ($root === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'reservations_root_not_found']);
    exit;
}

$yearFrom = substr($from, 0, 4);
$yearTo   = substr($to,   0, 4);

// tipično bodo from/to v istem letu, vseeno preverimo obe mapi
$years = array_unique([$yearFrom, $yearTo]);

$found = null;

foreach ($years as $year) {
    $dir = $root . '/' . $year . '/' . $unit;
    if (!is_dir($dir)) {
        continue;
    }

    foreach (glob($dir . '/*.json') as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        // pričakovana struktura:
        // { "id": "...", "unit": "A1", "from": "YYYY-MM-DD", "to": "YYYY-MM-DD", ... }
        if (($data['unit'] ?? null) !== $unit) {
            continue;
        }
        if (($data['from'] ?? null) === $from && ($data['to'] ?? null) === $to) {
            $found = $data;
            break 2;
        }
    }
}

if ($found) {
    echo json_encode([
        'ok'          => true,
        'found'       => true,
        'id'          => $found['id'] ?? null,
        'reservation' => $found,
    ]);
} else {
    echo json_encode([
        'ok'    => true,
        'found' => false,
    ]);
}
