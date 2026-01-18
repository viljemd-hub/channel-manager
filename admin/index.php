<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/index.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

// Admin entry router
$root = '/var/www/html/app';
$instanceFile = $root . '/common/data/json/instance.json';

function is_mobile_ua(): bool {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  if ($ua === '') return false;

  // conservative mobile detect (OK for our purpose)
  return (bool)preg_match('/Android|iPhone|iPad|iPod|Windows Phone|webOS|BlackBerry|Opera Mini|IEMobile/i', $ua);
}

$initialized = false;
if (is_file($instanceFile)) {
  $json = json_decode((string)file_get_contents($instanceFile), true);
  if (is_array($json) && ($json['initialized'] ?? false) === true) {
    $initialized = true;
  }
}

if ($initialized) {
  // normal operation → phone gets mobile hub, desktop gets full calendar
  if (is_mobile_ua()) {
    header('Location: /app/admin/mobile.php');
    exit;
  }
  header('Location: /app/admin/admin_calendar.php');
  exit;
}

// first use → show wizard
require __DIR__ . '/opening_wizard.php';
