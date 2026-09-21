<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/feedback.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/json_io.php';
require_once __DIR__ . '/../../_common.php';
require_once APP_COMMON . '/lib/cm_connector.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Method not allowed', 'METHOD_NOT_ALLOWED');
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    json_err('Message required', 'MESSAGE_REQUIRED');
}

$result = cm_connector_send_feedback($message);
if (empty($result['ok'])) {
    json_err((string)($result['error'] ?? 'Could not send feedback'), 'SEND_FAILED');
}

json_ok(['sent' => true]);
