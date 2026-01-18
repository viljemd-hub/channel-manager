<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/offers_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/offers_get.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$unit = isset($_GET['unit']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['unit']) : '';
if ($unit === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing unit']);
    exit;
}

$DATA_ROOT  = '/var/www/html/app/common/data/json';
$UNIT_DIR   = $DATA_ROOT . '/units/' . $unit;
$OFFERS_FILE = $UNIT_DIR . '/special_offers.json';

function respond($ok, $payload = [], $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ensure dir
if (!is_dir($UNIT_DIR)) {
    @mkdir($UNIT_DIR, 0775, true);
}

if (!file_exists($OFFERS_FILE)) {
    $data = ['offers' => []];
    @file_put_contents($OFFERS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    respond(true, ['unit' => $unit, 'data' => $data]);
}

$json = @file_get_contents($OFFERS_FILE);
if ($json === false) {
    respond(false, ['error' => 'Cannot read special_offers.json for ' . $unit], 500);
}

$data = json_decode($json, true);

// backward compat: if file is plain array → wrap
if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
    $data = ['offers' => $data];
}

if (!is_array($data)) {
    $data = ['offers' => []];
}
if (!isset($data['offers']) || !is_array($data['offers'])) {
    $data['offers'] = [];
}

respond(true, ['unit' => $unit, 'data' => $data]);
