<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/pricing/set_prices.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Update per-day pricing JSON for a unit.
 *
 * Responsibilities:
 * - Receive a pricing payload (typically a map of date → price).
 * - Validate and write the data into /common/data/json/units/<UNIT>/prices.json.
 *
 * Used by:
 * - Admin pricing UI (calendar-based price editor).
 *
 * Notes:
 * - Special offers and discounts live in separate JSON (special_offers.json);
 *   this endpoint should focus on base prices only.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Only POST allowed ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'POST required']);
    exit;
}

// --- Read incoming JSON ---
$body = file_get_contents('php://input');
$data = json_decode($body, true);

$unit  = $data['unit']  ?? null;
$from  = $data['from']  ?? null;
$to    = $data['to']    ?? null;
$price = $data['price'] ?? null;

if (!$unit || !$from || !$to || !is_numeric($price)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Missing or invalid params']);
    exit;
}

$price = (int)$price; // CENA = INT (kot si rekel)

// --- Paths ---
$root = realpath(__DIR__ . '/../../../common/data/json/units/'.$unit);
if (!$root) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Unit not found']);
    exit;
}

$pricesFile = $root . '/prices.json';

// --- Load prices.json (create empty if missing) ---
$prices = [];
if (file_exists($pricesFile)) {
    $raw = file_get_contents($pricesFile);
    $prices = json_decode($raw, true);
    if (!is_array($prices)) $prices = [];
}

// --- Backup before write ---
$backup = $pricesFile.'.bak.'.date('Ymd_His');
@copy($pricesFile, $backup);

// --- Helper for date iteration ---
$start = new DateTime($from);
$end   = new DateTime($to);

// end is exclusive → nothing changes here
$changed = 0;
$iter = clone $start;

while ($iter < $end) {
    $d = $iter->format('Y-m-d');
    if (!isset($prices[$d]) || $prices[$d] !== $price) {
        $prices[$d] = $price;
        $changed++;
    }
    $iter->modify('+1 day');
}

// --- Write back ---
file_put_contents($pricesFile, json_encode($prices, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

// --- Done ---
echo json_encode([
    'ok' => true,
    'changed' => $changed,
    'prices_file' => $pricesFile,
    'backup' => $backup
]);
