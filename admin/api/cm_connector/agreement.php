<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/agreement.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Saves the manually-entered business agreement template (plan, price,
 * contract reference, ...) - see cm_connector_save_agreement(). Pure
 * local storage, no billing/Relay system is contacted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/json_io.php';
require_once __DIR__ . '/../../_common.php';
require_once APP_COMMON . '/lib/cm_connector.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Method not allowed', 'METHOD_NOT_ALLOWED');
}

$agreement = [
    'plan' => (string)($_POST['agreement_plan'] ?? ''),
    'price_eur_month' => (string)($_POST['agreement_price_eur_month'] ?? ''),
    'contract_ref' => (string)($_POST['agreement_contract_ref'] ?? ''),
    'relay_account_email' => (string)($_POST['agreement_relay_account_email'] ?? ''),
    'notes' => (string)($_POST['agreement_notes'] ?? ''),
];

if (!cm_connector_save_agreement($agreement)) {
    json_err('Could not save agreement', 'SAVE_FAILED');
}

json_ok(cm_connector_status());
