<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/test_fetch_ics.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Test helper endpoint for fetching and inspecting an ICS feed.
 *
 * Responsibilities:
 * - Fetch a given ICS URL and parse it (for debugging).
 * - Return a small JSON summary (event count, sample dates, errors).
 *
 * Used by:
 * - admin/ui/js/integrations.js when debugging ICS URLs.
 * - Manual testing during development.
 *
 * Notes:
 * - Should not modify any JSON state; this is a diagnostic tool only.
 */

// /var/www/html/app/admin/api/test_fetch_ics.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$url = (string)($in['url'] ?? '');

if (!filter_var($url, FILTER_VALIDATE_URL)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_url']); exit;
}

$ctx = stream_context_create([
  'http' => ['method'=>'GET','timeout'=>6,'ignore_errors'=>true]
]);
$body = @file_get_contents($url, false, $ctx);
$bytes = $body === false ? 0 : strlen($body);
$ok = $bytes > 0;

echo json_encode(['ok'=>$ok,'bytes'=>$bytes, 'http_response_header'=>$http_response_header ?? []]);
