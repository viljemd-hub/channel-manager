<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/admin/_common.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

$DATA_ROOT  = '/var/www/html/app/common/data/json';
$STORE_ROOT = $DATA_ROOT . '/inquiries';
$ADMIN_KEY_FILE = $DATA_ROOT . '/admin_key.txt';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function load_json(string $p){ if(!is_file($p)) return null; $j=json_decode(file_get_contents($p), true); return is_array($j)?$j:null; }
function save_json(string $p, array $data): bool { return file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX)!==false; }

function require_key(): void {
  global $ADMIN_KEY_FILE;
  $key = is_file($ADMIN_KEY_FILE) ? trim((string)file_get_contents($ADMIN_KEY_FILE)) : '';
  if ($key === '') { return; } // če key ni nastavljen, ne blokiramo (začasno)
  $got = $_GET['key'] ?? $_POST['key'] ?? '';
  if ($got !== $key) {
    http_response_code(401);
    echo "Unauthorized (manjka ?key=...)";
    exit;
  }
}

function ensure_dirs(string $y,string $m): void {
  global $STORE_ROOT;
  foreach (['pending','accepted','rejected'] as $st) {
    $d = sprintf('%s/%s/%s/%s', $STORE_ROOT, $y, $m, $st);
    if (!is_dir($d)) @mkdir($d, 0755, true);
  }
}

function find_inquiry_path(string $id): ?string {
  global $STORE_ROOT;
  if (!preg_match('/^[0-9]{14}-[0-9a-f]{4}-[A-Za-z0-9_-]+$/', $id)) return null;
  $y = substr($id,0,4); $m = substr($id,4,2);
  foreach (['pending','accepted','rejected'] as $st) {
    $p = sprintf('%s/%s/%s/%s/%s.json', $STORE_ROOT, $y, $m, $st, $id);
    if (is_file($p)) return $p;
  }
  return null;
}

function list_inquiries(string $y,string $m,string $status='pending'): array {
  global $STORE_ROOT;
  $dir = sprintf('%s/%s/%s/%s', $STORE_ROOT, $y, $m, $status);
  if (!is_dir($dir)) return [];
  $out = [];
  foreach (glob($dir.'/*.json') as $f) {
    $j = load_json($f);
    if ($j) { $j['_path']=$f; $out[]=$j; }
  }
  usort($out, fn($a,$b)=>strcmp($b['created_at']??'', $a['created_at']??''));
  return $out;
}
