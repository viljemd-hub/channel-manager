<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/pricing/delete_prices.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Deletes prices for a date range in a unit's prices.json - the "Set
 * price" button's 0 case in the admin calendar (calendar_shell.js
 * handleClearPrice()), instead of storing a literal 0 price.
 *
 * Used by:
 * - Admin pricing UI (calendar-based price editor).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

$unit = $data['unit'] ?? null;
$from = $data['from'] ?? null;
$to   = $data['to'] ?? null; // exclusive, same convention as set_prices.php

if (!$unit || !$from || !$to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid params']);
    exit;
}

$root = realpath(__DIR__ . '/../../../common/data/json/units/' . $unit);
if (!$root) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unit not found']);
    exit;
}

$pricesFile = $root . '/prices.json';

$prices = [];
if (file_exists($pricesFile)) {
    $raw = file_get_contents($pricesFile);
    $prices = json_decode($raw, true);
    if (!is_array($prices)) {
        $prices = [];
    }
}

try {
    $start = new DateTime((string)$from);
    $end = new DateTime((string)$to);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_date_format']);
    exit;
}

$backup = $pricesFile . '.bak.' . date('Ymd_His');
@copy($pricesFile, $backup);

$changed = 0;
$deletedDates = [];
$iter = clone $start;
while ($iter < $end) {
    $d = $iter->format('Y-m-d');
    if (array_key_exists($d, $prices)) {
        unset($prices[$d]);
        $deletedDates[] = $d;
        $changed++;
    }
    $iter->modify('+1 day');
}

file_put_contents($pricesFile, json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if ($changed > 0 && is_file(__DIR__ . '/../../../common/lib/cm_connector.php')) {
    require_once __DIR__ . '/../../../common/lib/cm_connector.php';
    if (function_exists('cm_connector_notify_unit_changed')) {
        cm_connector_notify_unit_changed((string)$unit, 'price', ['deleted_dates' => $deletedDates]);
    }
}

echo json_encode([
    'ok' => true,
    'changed' => $changed,
    'prices_file' => $pricesFile,
    'backup' => $backup,
]);
