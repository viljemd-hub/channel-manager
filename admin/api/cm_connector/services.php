<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/services.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Saves the 4 independent ecosystem service opt-in switches
 * (Connectivity / Community / Sharing-referrals / AI discovery).
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/json_io.php';
require_once __DIR__ . '/../../_common.php';
require_once APP_COMMON . '/lib/cm_connector.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Method not allowed', 'METHOD_NOT_ALLOWED');
}

$services = [
    'ota_connectivity' => !empty($_POST['service_ota_connectivity']),
    'community' => !empty($_POST['service_community']),
    'community_referrals' => !empty($_POST['service_community_referrals']),
    'ai_discovery' => !empty($_POST['service_ai_discovery']),
];

if (!cm_connector_save_services($services)) {
    json_err('Could not save services', 'SAVE_FAILED');
}

json_ok(cm_connector_status());
