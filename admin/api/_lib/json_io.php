<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/_lib/json_io.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Shared path configuration for admin API.
 *
 * Responsibilities:
 * - Provide centralised absolute/relative paths to JSON roots:
 *   units, inquiries, reservations, cancellations, integrations, etc.
 * - Avoid hard-coded filesystem paths scattered across API endpoints.
 *
 * Used by:
 * - Most admin API endpoints in /admin/api/*.php.
 *
 * Notes:
 * - Keep this file side-effect free (no I/O on include).
 * - If paths change (e.g. new JSON root), update them here first.
 */

declare(strict_types=1);

function json_header(): void {
  header('Content-Type: application/json; charset=utf-8');
}

function json_ok(array $data = []): void {
  json_header();
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err(string $error, string $code = 'ERROR', array $extra = []): void {
  json_header();
  echo json_encode(['ok' => false, 'error' => $error, 'code' => $code] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json(string $path) {
  if (!is_file($path)) return null;
  $txt = file_get_contents($path);
  if ($txt === false) return null;
  $data = json_decode($txt, true);
  return $data;
}

function write_json(string $path, array $data): bool {
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
  $tmp = $path . '.tmp';
  $ok = file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) !== false;
  if (!$ok) return false;
  return rename($tmp, $path);
}

function move_file(string $src, string $dst): bool {
  $dir = dirname($dst);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
  return rename($src, $dst);
}

function require_post_json(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: 'null', true);
  if (!is_array($data)) json_err('Invalid JSON body', 'BAD_REQUEST');
  return $data;
}
