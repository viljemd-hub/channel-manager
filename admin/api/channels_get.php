<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/channels_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$unit = isset($_GET['unit']) ? preg_replace('~[^A-Za-z0-9_-]~', '', (string)$_GET['unit']) : '';
if ($unit === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_unit']);
    exit;
}

$path = "/var/www/html/app/common/data/json/units/{$unit}/occupancy_sources.json";

if (!is_file($path)) {
    // default “safe skeleton”
    $out = [
        'channels' => new stdClass(),
        'priority' => [],
        'suppressions' => [],
    ];
    echo json_encode(['ok'=>true,'unit'=>$unit,'sources'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

$raw = @file_get_contents($path);
$j = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($j)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'invalid_json','path'=>$path]);
    exit;
}

$j['channels'] = (isset($j['channels']) && is_array($j['channels'])) ? $j['channels'] : [];
$j['priority'] = (isset($j['priority']) && is_array($j['priority'])) ? array_values($j['priority']) : [];
$j['suppressions'] = (isset($j['suppressions']) && is_array($j['suppressions'])) ? array_values($j['suppressions']) : [];

echo json_encode([
    'ok'      => true,
    'unit'    => $unit,
    'sources' => $j,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
