<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/promo_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/promo_get.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$DATA_ROOT = '/var/www/html/app/common/data/json';
$PROMO_FILE = $DATA_ROOT . '/units/promo_codes.json';

function respond($ok, $payload = [], $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ensure directory exists
$dir = dirname($PROMO_FILE);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

if (!file_exists($PROMO_FILE)) {
    // default structure z auto_reject nastavitvami
    $data = [
        'settings' => [
            'auto_reject_discount_percent' => 15,
            'auto_reject_valid_days'       => 180,
            'auto_reject_code_prefix'      => 'RETRY-',
        ],
        'codes'    => []
    ];
    @file_put_contents($PROMO_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    respond(true, ['data' => $data]);
}


$json = @file_get_contents($PROMO_FILE);
if ($json === false) {
    respond(false, ['error' => 'Cannot read promo_codes.json'], 500);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    // fallback: reset structure, but DO NOT overwrite file silently
    $data = [
        'settings' => [],
        'codes'    => []
    ];
}

if (!isset($data['settings']) || !is_array($data['settings'])) {
    $data['settings'] = [];
}
if (!isset($data['codes']) || !is_array($data['codes'])) {
    $data['codes'] = [];
}

respond(true, ['data' => $data]);
