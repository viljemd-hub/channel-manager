<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/reservation_by_pin.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST + JSON body, not GET + query string, even though this is a
 * read-only lookup - a door_pin in a query string ends up in Apache's
 * access log, same reasoning as keeping the auth key out of the URL.
 *
 * Date-window params (before_days/after_days) come from the caller - this
 * server has no opinion on any one module's PIN validity policy.
 */

require_once __DIR__ . '/../../../../common/lib/cm_bridge_server.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

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

$pin = trim((string)($body['pin'] ?? ''));
if ($pin === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_pin'], JSON_UNESCAPED_UNICODE);
    exit;
}

$beforeDays = max(0, (int)($body['before_days'] ?? 1));
$afterDays = max(0, (int)($body['after_days'] ?? 1));

$res = cm_bridge_find_reservation_by_pin($pin, $beforeDays, $afterDays);
if ($res === null) {
    echo json_encode(['ok' => true, 'found' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'found' => true,
    'reservation' => cm_bridge_project_reservation($res, $auth['scopes']),
], JSON_UNESCAPED_UNICODE);
