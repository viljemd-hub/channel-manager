<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/dashboard/alerts.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

require_once __DIR__ . '/../../../../../common/lib/cm_bridge_server.php';
require_once __DIR__ . '/../../../../../common/lib/cm_bridge_dashboard.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

if (!in_array('dashboard.alerts', $auth['scopes'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'scope_forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(cm_bridge_dashboard_alerts(), JSON_UNESCAPED_UNICODE);
