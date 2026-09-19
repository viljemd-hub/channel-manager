<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/consent.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Saves the 3 separate opt-in consent checkboxes and generates the local
 * installation_uuid. Does NOT contact any Relay - see cm_connector.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/json_io.php';
require_once __DIR__ . '/../../_common.php';
require_once APP_COMMON . '/lib/cm_connector.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Method not allowed', 'METHOD_NOT_ALLOWED');
}

$consent = [
    'data_scope' => !empty($_POST['consent_data_scope']),
    'ecosystem_inclusion' => !empty($_POST['consent_ecosystem_inclusion']),
    'terms_price' => !empty($_POST['consent_terms_price']),
];

if (!cm_connector_save_consent($consent)) {
    json_err('All three consents are required', 'CONSENT_INCOMPLETE');
}

json_ok(cm_connector_status());
