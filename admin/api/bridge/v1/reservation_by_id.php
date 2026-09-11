<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/reservation_by_id.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Looks up one reservation by id. The year-prefix-vs-stay-year fallback
 * scan lives once in cm_bridge_find_reservation_by_id() - see
 * cm_bridge_server.php for why this endpoint exists at all.
 *
 * Response fields are projected down to whatever the caller's key scopes
 * unlock (see cm_bridge_project_reservation()) - a reservation.basic-only
 * key never sees payment/calc/cancel_token even though they're on disk.
 */

require_once __DIR__ . '/../../../../common/lib/cm_bridge_server.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = cm_bridge_find_reservation_by_id($id);
if ($res === null) {
    echo json_encode(['ok' => true, 'found' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'found' => true,
    'reservation' => cm_bridge_project_reservation($res, $auth['scopes']),
], JSON_UNESCAPED_UNICODE);
