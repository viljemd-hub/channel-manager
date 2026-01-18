<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/resend_accept_email.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/send_accept_link.php';
require_once __DIR__ . '/../../common/lib/datetime_fmt.php';

// Accept both JSON body and classic form-encoded POST
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);

$id = '';

if (isset($_POST['id'])) {
    $id = trim((string)$_POST['id']);
} elseif (is_array($body) && isset($body['id'])) {
    $id = trim((string)$body['id']);
}

if ($id === '') {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'missing_id',
    ]);
    exit;
}

try {
    // IMPORTANT:
    // Second argument is $dryRun – we want to SEND the e-mail,
    // so it must be false (or simply omitted).
    $res = cm_send_accept_link($id, false);

    $ok      = (bool)($res['ok'] ?? false);
    $errCode = $res['error'] ?? null;

    if (!$ok) {
        // Map known errors to HTTP status codes so fetch/postJSON can react
        $code = 400;

        if ($errCode === 'accepted_not_found') {
            $code = 404; // no accepted soft-hold found
        } elseif ($errCode === 'email_disabled') {
            $code = 503; // email disabled in site_settings
        }

        http_response_code($code);
    }

    echo json_encode([
        'ok'         => $ok,
        'mail_error' => $errCode,
        'res'        => $res,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
