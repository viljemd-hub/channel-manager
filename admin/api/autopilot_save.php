<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/autopilot_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/autopilot_save.php
declare(strict_types=1);

require __DIR__ . '/_lib/json_io.php';

json_header();

try {
    $input = require_post_json(); // vrne array ali vrže exception
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$scope = isset($input['scope']) ? (string)$input['scope'] : 'global';
$unit  = isset($input['unit']) ? trim((string)$input['unit']) : '';
$ap    = $input['autopilot'] ?? null;

// 1) Nova oblika: pričakujemo $input['autopilot'] kot array
if (!is_array($ap)) {
    // 2) Backward-compat: mogoče še vedno prihaja "flat" payload
    $flatKeys = [
        'enabled',
        'mode',
        'test_mode',
        'min_days_before_arrival',
        'max_nights',
        'allowed_sources',
        'check_ics_on_accept',
        'check_ics_on_guest_confirm',
    ];

    $flatAp   = [];
    $hasFlat  = false;

    foreach ($flatKeys as $k) {
        if (array_key_exists($k, $input)) {
            $flatAp[$k] = $input[$k];
            $hasFlat    = true;
        }
    }

    if ($hasFlat) {
        // pretvori legacy payload v "autopilot" blok
        $ap = $flatAp;
    } else {
        json_err('invalid_autopilot_payload', 'BAD_REQUEST');
    }
}

$root = '/var/www/html/app/common/data/json/units';

if ($scope === 'unit') {
    if ($unit === '') {
        json_err('missing_unit', 'BAD_REQUEST');
    }
    $file = $root . '/' . $unit . '/site_settings.json';
} else {
    $scope = 'global';
    $file  = $root . '/site_settings.json';
}

$data = read_json($file);
if (!is_array($data)) {
    $data = [];
}

$data['autopilot'] = $ap;

if (!write_json($file, $data)) {
    json_err('write_failed', 'IO_ERROR');
}

json_ok([
    'scope'     => $scope,
    'unit'      => $unit,
    'autopilot' => $ap,
]);
