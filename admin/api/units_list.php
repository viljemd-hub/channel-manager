<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/units_list.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$manifestPath = '/var/www/html/app/common/data/json/units/manifest.json';

$units = [];

if (is_file($manifestPath)) {
    $raw = @file_get_contents($manifestPath);
    $j = is_string($raw) ? json_decode($raw, true) : null;

    if (is_array($j) && isset($j['units']) && is_array($j['units'])) {
        foreach ($j['units'] as $u) {
            if (!is_array($u)) continue;
            $id = (string)($u['id'] ?? $u['unit'] ?? '');
            if ($id === '') continue;

            $label = (string)($u['label'] ?? $u['name'] ?? $id);
            $units[] = [
                'id'    => $id,
                'label' => $label,
            ];
        }
    }
}

usort($units, function($a, $b){
    return strnatcasecmp($a['id'], $b['id']);
});

echo json_encode([
    'ok'    => true,
    'units' => $units,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
