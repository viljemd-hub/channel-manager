<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/index_router.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

// Admin entry router
$root = '/var/www/html/app';
$instanceFile = $root . '/common/data/json/instance.json';

$initialized = false;
if (is_file($instanceFile)) {
    $json = json_decode((string)file_get_contents($instanceFile), true);
    if (is_array($json) && ($json['initialized'] ?? false) === true) {
        $initialized = true;
    }
}

if ($initialized) {
    // normal operation → go to main admin screen
    header('Location: /app/admin/admin_calendar.php');
    exit;
}

// first use → show wizard
require __DIR__ . '/opening_wizard.php';

