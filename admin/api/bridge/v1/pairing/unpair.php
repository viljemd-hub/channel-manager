<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/pairing/unpair.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Self-service "forget this device" (Connection screen). Auth is the
 * device's own presented token - proving it currently holds valid access
 * is exactly the proof needed to ask for that same access to be revoked.
 * No scope check beyond a valid token: unpairing is not a capability a
 * pairing can be granted or denied, it's a standing right every paired
 * device has over itself.
 */

require_once __DIR__ . '/../../../../../common/lib/cm_bridge_pairing.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$presentedToken = '';
foreach (['HTTP_X_BRIDGE_KEY', 'HTTP_X_BRIDGE_TOKEN'] as $h) {
    if (!empty($_SERVER[$h])) { $presentedToken = trim((string)$_SERVER[$h]); break; }
}

$ok = $presentedToken !== '' && cm_bridge_devices_revoke_by_token($presentedToken);
echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
