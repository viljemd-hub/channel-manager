<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/unit_settings_update.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/unit_settings_update.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- Basic input validation -------------------------------------------------
$unit     = $_POST['unit']  ?? '';
$key      = $_POST['key']   ?? '';
$valueRaw = $_POST['value'] ?? null;

if ($unit === '' || !preg_match('~^[A-Za-z0-9_-]+$~', $unit)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_unit']);
    exit;
}
if ($key === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_key']);
    exit;
}

// For now we only support boolean-style toggles
$trueVals = ['1', 'true', 'on', 'yes', 'y'];
$boolVal  = in_array(strtolower((string)$valueRaw), $trueVals, true);

// ---- Paths ------------------------------------------------------------------
$rootUnits = '/var/www/html/app/common/data/json/units';
$unitDir   = $rootUnits . '/' . $unit;
$unitFile  = $unitDir . '/site_settings.json';

if (!is_dir($unitDir)) {
    if (!mkdir($unitDir, 0755, true) && !is_dir($unitDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'mkdir_failed']);
        exit;
    }
}

// ---- Read existing JSON (with hardening for invalid JSON) -------------------
$current = [];
if (is_file($unitFile)) {
    $raw = @file_get_contents($unitFile);
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);

        // If JSON is invalid, do NOT overwrite it silently – fail loudly.
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'invalid_json',
                'detail'=> json_last_error_msg(),
            ]);
            exit;
        }

        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
}

// ---- Default skeleton to guarantee structure --------------------------------
// This prevents toggles from "shrinking" the JSON when some parts are missing.
$defaults = [
    'auto_block' => [
        'before_arrival'  => false,
        'after_departure' => false,
    ],
    'day_use' => [
        'enabled'        => false,
        'from'           => '14:00',
        'to'             => '20:00',
        'max_persons'    => 1,
        'max_days_ahead' => 0,
    ],
    'month_render' => 12,
    'booking' => [
        'min_nights'              => 1,
        'allow_same_day_departure'=> false,
    ],
];

// Merge defaults with current settings: existing values override defaults.
$current = array_replace_recursive($defaults, $current);

// ---- Apply dot-notation key (e.g. "auto_block.before_arrival") --------------
$parts = explode('.', $key);
$ref   =& $current;
$last  = array_pop($parts);

foreach ($parts as $segment) {
    if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
        $ref[$segment] = [];
    }
    $ref =& $ref[$segment];
}

$ref[$last] = $boolVal;

// ---- Write back to disk -----------------------------------------------------
$json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'encode_failed']);
    exit;
}

if (@file_put_contents($unitFile, $json) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}

// ---- Response: return current auto_block flags (for the UI) -----------------
$before = (bool)($current['auto_block']['before_arrival']  ?? false);
$after  = (bool)($current['auto_block']['after_departure'] ?? false);

echo json_encode([
    'ok'   => true,
    'unit' => $unit,
    'settings' => [
        'auto_block' => [
            'before_arrival'  => $before,
            'after_departure' => $after,
        ],
    ],
]);
