<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/channels_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_json']);
    exit;
}

$unit = preg_replace('~[^A-Za-z0-9_-]~', '', (string)($body['unit'] ?? ''));
if ($unit === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_unit']);
    exit;
}

$sources = $body['sources'] ?? null;
if (!is_array($sources)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_sources']);
    exit;
}

$channels = $sources['channels'] ?? [];
$priority = $sources['priority'] ?? [];
$suppressions = $sources['suppressions'] ?? [];

if (!is_array($channels) || !is_array($priority) || !is_array($suppressions)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_structure']);
    exit;
}

// normalize minimal
$out = [
    'channels' => $channels,
    'priority' => array_values(array_filter(array_map('strval', $priority), fn($v)=>$v!=='')),
    'suppressions' => array_values($suppressions),
];

$dir = "/var/www/html/app/common/data/json/units/{$unit}";
if (!is_dir($dir)) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'unit_not_found']);
    exit;
}

$path = $dir . '/occupancy_sources.json';
$tmp  = $path . '.tmp-' . getmypid();

$json = json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'encode_failed','detail'=>json_last_error_msg()]);
    exit;
}

if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'write_failed']);
    exit;
}

echo json_encode(['ok'=>true,'unit'=>$unit], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
