<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Save integrations configuration changes from admin UI.
 *
 * Responsibilities:
 * - Receive updated integration config payload (per unit/channel).
 * - Validate basic structure and write it back to
 *   /common/data/json/integrations/*.json.
 *
 * Used by:
 * - admin/ui/js/integrations.js (Save / Apply buttons).
 *
 * Notes:
 * - Should not directly modify occupancy; ingestion of ICS data is handled
 *   by dedicated scripts or endpoints.
 */

// /var/www/html/app/admin/api/integrations_save.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = '/var/www/html/app';
$connsFile = $root.'/common/data/json/integrations/connections.json';

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['settings'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_request']); exit;
}

$dir = dirname($connsFile);
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$tmp = $connsFile.'.tmp';
file_put_contents($tmp, json_encode($in['settings'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
@chmod($tmp, 0664);
@rename($tmp, $connsFile);

echo json_encode(['ok'=>true]);
