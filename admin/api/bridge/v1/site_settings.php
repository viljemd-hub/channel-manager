<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/site_settings.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Minimal settings projection (public_base_url + email block only) -
 * requires the settings.public scope, same field-scope model as the
 * reservation endpoints.
 */

require_once __DIR__ . '/../../../../common/lib/cm_bridge_server.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

if (!in_array('settings.public', $auth['scopes'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'scope_forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'settings' => cm_bridge_public_settings(),
], JSON_UNESCAPED_UNICODE);
