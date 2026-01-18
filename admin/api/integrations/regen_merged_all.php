<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/regen_merged_all.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/scripts/regen_merged_all.php
declare(strict_types=1);

/**
 * Regenerira occupancy_merged.json za vse enote v units/.
 *
 * Uporaba:
 *   php /var/www/html/app/scripts/regen_merged_all.php
 */

$root      = '/var/www/html/app';
$unitsRoot = $root . '/common/data/json/units';

require_once $root . '/common/lib/datetime_fmt.php';

if (!function_exists('cm_regen_merged_for_unit')) {
  fwrite(STDERR, "[ERR] cm_regen_merged_for_unit not found in datetime_fmt.php\n");
  exit(1);
}

$dirs = @scandir($unitsRoot) ?: [];
$units = [];
foreach ($dirs as $d) {
  if ($d === '.' || $d === '..') continue;
  if (is_dir($unitsRoot . '/' . $d)) $units[] = $d;
}
sort($units, SORT_NATURAL);

if (!$units) {
  fwrite(STDERR, "[WARN] No units found in {$unitsRoot}\n");
  exit(0);
}

echo "[INFO] Regenerating occupancy_merged.json for units: " . implode(', ', $units) . "\n";

$okCount = 0;
foreach ($units as $u) {
  $ok = cm_regen_merged_for_unit($unitsRoot, $u);
  echo sprintf("[%-3s] %s\n", $ok ? 'OK' : 'ERR', $u);
  if ($ok) $okCount++;
}

echo "[DONE] {$okCount}/" . count($units) . " units regenerated.\n";
