<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/offers_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/offers_save.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$DATA_ROOT = '/var/www/html/app/common/data/json';

function respond($ok, $payload = [], $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    respond(false, ['error' => 'Empty body'], 400);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    respond(false, ['error' => 'Invalid JSON body'], 400);
}

$unit   = isset($body['unit']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$body['unit']) : '';
$offers = $body['offers'] ?? null;

if ($unit === '' || !is_array($offers)) {
    respond(false, ['error' => 'Missing or invalid unit/offers'], 400);
}

// basic validation: each offer should be object
foreach ($offers as $idx => $offer) {
    if (!is_array($offer)) {
        respond(false, ['error' => "Invalid offer at index $idx"], 400);
    }
}

$UNIT_DIR    = $DATA_ROOT . '/units/' . $unit;
$OFFERS_FILE = $UNIT_DIR . '/special_offers.json';
$OFFERS_BAK  = $OFFERS_FILE . '.bak';

// ensure dir
if (!is_dir($UNIT_DIR)) {
    if (!@mkdir($UNIT_DIR, 0775, true) && !is_dir($UNIT_DIR)) {
        respond(false, ['error' => 'Cannot create unit dir'], 500);
    }
}

$data = ['offers' => $offers];

// backup
if (file_exists($OFFERS_FILE)) {
    @copy($OFFERS_FILE, $OFFERS_BAK);
}

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    respond(false, ['error' => 'JSON encode failed'], 500);
}

if (@file_put_contents($OFFERS_FILE, $json) === false) {
    respond(false, ['error' => 'Failed to write special_offers.json'], 500);
}

respond(true, ['message' => 'Offers saved', 'unit' => $unit]);
