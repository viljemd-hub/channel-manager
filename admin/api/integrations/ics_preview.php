<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/ics_preview.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/integrations/ics_preview.php
declare(strict_types=1);

// This preview tries to reuse existing ics.php output via include+buffer.
// It does NOT change your real export endpoint.
$icsFile = __DIR__ . '/ics.php';

if (!is_file($icsFile)) {
  header('Content-Type: text/plain; charset=utf-8');
  http_response_code(404);
  echo "ics.php not found\n";
  exit;
}

ob_start();

// include the real ICS generator (it may set headers; we remove them after)
include $icsFile;

// If ics.php exits early, we won't reach here — but in most implementations it's fine.
$ics = ob_get_clean();

if ($ics === null) $ics = '';

if (function_exists('header_remove')) {
  header_remove();
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="calendar_preview.ics"');

echo $ics;
