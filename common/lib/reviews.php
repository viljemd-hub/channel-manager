<?php
/**
 * File: common/lib/reviews.php
 * PRO-only stub for public repository.
 *
 * Real implementation is available in the PRO edition.
 */
declare(strict_types=1);

function cm_reviews_pro_only(): void {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'pro_only',
    'message' => 'Reviews module is available in the PRO edition.',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// If someone hits this file directly, respond:
if (php_sapi_name() !== 'cli') {
  cm_reviews_pro_only();
}
