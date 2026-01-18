<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/promo_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/promo_save.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$DATA_ROOT = '/var/www/html/app/common/data/json';
$PROMO_FILE = $DATA_ROOT . '/units/promo_codes.json';
$PROMO_BAK  = $PROMO_FILE . '.bak';

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

$settings = $body['settings'] ?? [];
$codes    = $body['codes'] ?? [];

if (!is_array($settings) || !is_array($codes)) {
    respond(false, ['error' => 'settings and codes must be arrays'], 400);
}

// basic validation: each code must be object-like
foreach ($codes as $idx => $code) {
    if (!is_array($code)) {
        respond(false, ['error' => "Invalid promo at index $idx"], 400);
    }
}

$data = [
    'settings' => $settings,
    'codes'    => $codes,
];

// ensure dir
$dir = dirname($PROMO_FILE);
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(false, ['error' => 'Cannot create directory for promo_codes.json'], 500);
    }
}

// backup (single .bak)
if (file_exists($PROMO_FILE)) {
    @copy($PROMO_FILE, $PROMO_BAK);
}

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    respond(false, ['error' => 'JSON encode failed'], 500);
}

if (@file_put_contents($PROMO_FILE, $json) === false) {
    respond(false, ['error' => 'Failed to write promo_codes.json'], 500);
}

respond(true, ['message' => 'Promo codes saved']);
