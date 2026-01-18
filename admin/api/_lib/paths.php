<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/_lib/paths.php
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

function data_root(): string {
  return '/var/www/html/app/common/data/json';
}

function inquiries_root(): string {
  return data_root() . '/inquiries';
}

// $stage: 'pending' | 'accepted' | 'rejected' | 'confirmed' | 'canceled'
function inquiry_path(string $id, string $stage): string {
  // id format: YYYYMMDDHHMMSS-xxxx-UNIT
  if (!preg_match('~^(\d{4})(\d{2})~', $id, $m)) return '';
  $y = $m[1]; $mo = $m[2];
  return inquiries_root() . "/$y/$mo/$stage/$id.json";
}

function list_stage_dir(string $year, string $month, string $stage): string {
  return inquiries_root() . "/$year/$month/$stage";
}

function is_overlap(string $a_from, string $a_to, string $b_from, string $b_to): bool {
  // all dates ISO (YYYY-MM-DD), end-exclusive
  return ($a_from < $b_to) && ($b_from < $a_to);
}
