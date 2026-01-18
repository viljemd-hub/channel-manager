<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/list_inquiries.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * List pending (and possibly accepted/rejected) inquiries for admin.
 *
 * Responsibilities:
 * - Scan the inquiries directory tree
 *   (/common/data/json/inquiries/YYYY/MM/...) and collect inquiries.
 * - Optionally filter by status (pending/accepted/rejected) and unit.
 * - Return a compact JSON list for the admin inbox/pending view.
 *
 * Used by:
 * - admin/ui/js/manage_reservations.js
 * - admin_shell.js pending/inbox panel.
 *
 * Notes:
 * - This endpoint is read-only; accepting/rejecting inquiries happens
 *   in separate endpoints.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';

$cfg  = cm_datetime_cfg();
$mode = $cfg['output_mode'];

$APP = '/var/www/html/app';
$INQ = $APP . '/common/data/json/inquiries';

$statusFilter = $_GET['status'] ?? ''; // "", "pending", "accepted_soft_hold", "rejected", ...
$globPatterns = [];

if ($statusFilter) {
  $globPatterns[] = "{$INQ}/*/*/{$statusFilter}/*.json";
} else {
  // default: pending + accepted_soft_hold
  $globPatterns[] = "{$INQ}/*/*/pending/*.json";
  $globPatterns[] = "{$INQ}/*/*/accepted_soft_hold/*.json";
}

$files = [];
foreach ($globPatterns as $pat) {
  $m = glob($pat, GLOB_NOSORT);
  if ($m) $files = array_merge($files, $m);
}

$out = [];
foreach ($files as $f) {
  $d = cm_json_read($f);
  if (!$d) continue;
  cm_add_formatted_fields($d, [
    'from'        => 'date',
    'to'          => 'date',
    'created'     => 'datetime',
    'accepted_at' => 'datetime'
  ], $cfg);
  $out[] = cm_filter_output_mode($d, $mode);
}

echo json_encode(['ok'=>true,'count'=>count($out),'items'=>$out]);
