<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/disconnect.php
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

if (!cm_connector_disconnect()) {
    json_err('Could not disconnect', 'SAVE_FAILED');
}

json_ok(cm_connector_status());
