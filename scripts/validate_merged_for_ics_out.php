<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: scripts/validate_merged_for_ics_out.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

$unit = $argv[1] ?? '';
if ($unit === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $unit)) {
  fwrite(STDERR, "Usage: php validate_merged_for_ics_out.php <UNIT>\n");
  exit(2);
}

$path = "/var/www/html/app/common/data/json/units/{$unit}/occupancy_merged.json";
if (!is_file($path)) { fwrite(STDERR, "Missing: {$path}\n"); exit(2); }

$j = json_decode((string)file_get_contents($path), true);
if (!is_array($j)) { fwrite(STDERR, "Invalid JSON: {$path}\n"); exit(2); }

function is_valid_ymd($s): bool {
  return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
}

$bad = 0; $cnt = 0;

foreach ($j as $i => $seg) {
  if (!is_array($seg)) { $bad++; continue; }

  $start  = $seg['start'] ?? null;
  $end    = $seg['end'] ?? null;
  $status = $seg['status'] ?? null;
  $lock   = $seg['lock'] ?? null;

  $ok =
    is_valid_ymd($start) &&
    is_valid_ymd($end) &&
    is_string($status) && $status !== '' &&
    is_string($lock) && $lock !== '';

  $cnt++;
  if (!$ok) {
    $bad++;
    $id = $seg['id'] ?? "(no id)";
    fwrite(STDOUT, "BAD #{$i} id={$id} start=".json_encode($start)." end=".json_encode($end)." status=".json_encode($status)." lock=".json_encode($lock)."\n");
  }
}

fwrite(STDOUT, "OK segments: ".($cnt-$bad)." / {$cnt}\n");
exit($bad ? 1 : 0);
