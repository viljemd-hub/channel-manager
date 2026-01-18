<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/site_settings.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// Per-unit -> global -> defaults
function _merge_deep(array $a, array $b): array {
  foreach ($b as $k=>$v) {
    if (is_array($v) && isset($a[$k]) && is_array($a[$k])) $a[$k] = _merge_deep($a[$k], $v);
    else $a[$k] = $v;
  }
  return $a;
}
function load_site_settings(string $unit): array {
  $root = "/var/www/html/app/common/data/json/units";
  $defaults = [
    "display"    => ["cleaning_separate"=>false],
    "pricing"    => ["cleaning_fee_eur"=>0],
    "auto_block" => ["before_arrival"=>false, "after_departure"=>false],
  ];
  $global = []; $unitCfg = [];
  $gp = "$root/site_settings.json";
  if (is_file($gp)) { $j=@file_get_contents($gp); $d=json_decode($j,true); if (is_array($d)) $global=$d; }
  $up = "$root/$unit/site_settings.json";
  if (is_file($up)) { $j=@file_get_contents($up); $d=json_decode($j,true); if (is_array($d)) $unitCfg=$d; }
  return _merge_deep(_merge_deep($defaults,$global), $unitCfg);
}
