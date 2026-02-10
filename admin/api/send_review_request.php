<?php
/**
 * File: admin/api/send_review_request.php
 * PRO-only stub for public repository (prevents 404 and protects PRO feature).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_key();

http_response_code(403);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => false,
  'error' => 'pro_only',
  'message' => 'Available in PRO edition.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;
