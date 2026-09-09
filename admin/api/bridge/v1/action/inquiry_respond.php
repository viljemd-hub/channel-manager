<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/action/inquiry_respond.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * The one write-triggering Bridge v1 action (see file header note in
 * cm_bridge_server.php). Never writes directly - proxies to the existing
 * admin/api/accept_inquiry.php / reject_inquiry.php endpoints over an
 * internal loopback call, so it inherits every safety gate those already
 * enforce (ICS conflict check, local occupancy conflict check).
 */

require_once __DIR__ . '/../../../../../common/lib/cm_bridge_server.php';
require_once __DIR__ . '/../../../../../common/lib/cm_bridge_dashboard.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

if (!in_array('action.inquiry_respond', $auth['scopes'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'scope_forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json_body'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = trim((string)($body['id'] ?? ''));
$decision = trim((string)($body['decision'] ?? ''));
$reason = isset($body['reason']) ? trim((string)$body['reason']) : null;

if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = cm_bridge_action_inquiry_respond($id, $decision, $reason);
if (empty($result['ok'])) {
    http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
