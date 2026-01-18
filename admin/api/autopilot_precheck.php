<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/autopilot_precheck.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/autopilot_precheck.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../common/lib/autopilot.php';

$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

if ($id === '') {
    echo json_encode([
        'ok'    => false,
        'error' => 'missing_id',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = cm_autopilot_apply_precheck_for_inquiry_id($id);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'exception',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
