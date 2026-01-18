<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/autopilot_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/autopilot_get.php
declare(strict_types=1);

require __DIR__ . '/_lib/json_io.php';
require_once __DIR__ . '/../../common/lib/autopilot.php';

json_header();

$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

$root      = '/var/www/html/app/common/data/json/units';
$globalFile = $root . '/site_settings.json';

$globalJson = read_json($globalFile);
if (!is_array($globalJson)) {
    $globalJson = [];
}
$globalAp = [];
if (isset($globalJson['autopilot']) && is_array($globalJson['autopilot'])) {
    $globalAp = $globalJson['autopilot'];
}

$unitAp   = null;
$effective = null;

if ($unit !== '') {
    $unitFile = $root . '/' . $unit . '/site_settings.json';
    $unitJson = read_json($unitFile);
    if (is_array($unitJson) && isset($unitJson['autopilot']) && is_array($unitJson['autopilot'])) {
        $unitAp = $unitJson['autopilot'];
    }

    // merged nastavitve (default + global + per-unit)
    $effective = cm_autopilot_load_settings($unit);
}
// PRODUCTION POLICY ENFORCEMENT
// Če je autopilot enabled in nismo v test_mode,
// potem morajo biti ICS checki prisilno ON tudi v "effective" izpisu
if (is_array($effective)) {
    $enabled  = !empty($effective['enabled']);
    $testMode = !empty($effective['test_mode']);

    if ($enabled && !$testMode) {
        $effective['check_ics_on_accept']        = true;
        $effective['check_ics_on_guest_confirm'] = true;
    }
}



echo json_encode([
    'ok'           => true,
    'scope'        => $unit === '' ? 'global' : 'unit',
    'unit'         => $unit,
    'global'       => $globalAp,
    'unit_settings'=> $unitAp,
    'effective'    => $effective,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
