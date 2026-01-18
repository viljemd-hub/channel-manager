<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/inquiry_action.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_key();

$act    = $_GET['act']    ?? '';
$id     = $_POST['id']    ?? '';
$reason = trim($_POST['reason'] ?? '');

if (!in_array($act, ['accept','reject'], true)) {
  http_response_code(400);
  echo "Neznano dejanje.";
  exit;
}

$p = find_inquiry_path($id);
if (!$p) {
  http_response_code(404);
  echo "Ni zapisa.";
  exit;
}
if (!str_contains($p, '/pending/')) {
  http_response_code(400);
  echo "Dejanje možno le na pending.";
  exit;
}

$j = load_json($p);
if (!$j) {
  http_response_code(500);
  echo "Corrupt JSON.";
  exit;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
$j['status']    = $act === 'accept' ? 'accepted' : 'rejected';
$j['decided_at'] = $now;
if ($act === 'reject') {
  $j['reject_reason'] = $reason;
}

$y = substr($id, 0, 4);
$m = substr($id, 4, 2);

// …/inquiries/Y/M/accepted|rejected
$newDir = sprintf('%s/%s/%s/%s', dirname(dirname($p)), $y, $m, $j['status']);
if (!is_dir($newDir)) {
  @mkdir($newDir, 0755, true);
}
$newPath = $newDir . '/' . $id . '.json';

if (!save_json($newPath, $j)) {
  http_response_code(500);
  echo "Zapis ni uspel.";
  exit;
}
@unlink($p);

$q = '';
if (isset($_GET['key'])) {
  $q = '?key=' . rawurlencode($_GET['key']);
}

// prej: /app/public/admin/inquiries.php
header('Location: /app/admin/inquiries.php' . $q);
exit;
