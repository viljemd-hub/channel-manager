<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/promo_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/promo_get.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$DATA_ROOT  = '/var/www/html/app/common/data/json';
$PROMO_FILE = $DATA_ROOT . '/units/promo_codes.json';

function respond(bool $ok, array $payload = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Default structure for promo_codes.json
 */
function cm_default_promo_data(): array {
    return [
        'settings' => [
            'auto_reject_discount_percent' => 15,
            'auto_reject_valid_days'       => 180,
            'auto_reject_code_prefix'      => 'RETRY-',
        ],
        'codes' => [],
    ];
}

// ensure directory exists
$dir = dirname($PROMO_FILE);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

if (!file_exists($PROMO_FILE)) {
    // create file with defaults
    $default = cm_default_promo_data();
    @file_put_contents(
        $PROMO_FILE,
        json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    respond(true, [
        'settings' => $default['settings'],
        'codes'    => $default['codes'],
    ]);
}

$json = @file_get_contents($PROMO_FILE);
if ($json === false) {
    // cannot read – fall back to defaults but don't fail the UI
    $default = cm_default_promo_data();
    respond(true, [
        'settings' => $default['settings'],
        'codes'    => $default['codes'],
    ]);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    $data = cm_default_promo_data();
} else {
    // backward-compat: stare verzije { ok, data: { settings, codes } }
    if (isset($data['data']) && is_array($data['data']) &&
        !isset($data['settings']) && !isset($data['codes'])) {
        $data = $data['data'];
    }

    $defaults = cm_default_promo_data();

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = $defaults['settings'];
    } else {
        // poskrbi, da vsi ključi obstajajo, vrednosti uporabnika imajo prednost
        $data['settings'] = array_merge($defaults['settings'], $data['settings']);
    }

    if (!isset($data['codes']) || !is_array($data['codes'])) {
        $data['codes'] = [];
    }
}

respond(true, [
    'settings' => $data['settings'],
    'codes'    => $data['codes'],
]);
