<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/manage_reservations.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/manage_reservations.php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Reservations</title>

  <!-- osnovni admin CSS -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <!-- manage reservations styles (light + dark) -->
  <link rel="stylesheet" href="/app/admin/ui/css/manage_reservations.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/manage_reservations.dark.css" />
</head>
<body class="adm-shell theme-dark">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Reservations</h1>
    </div>
    <div class="hdr-right">
      <!-- Navigacija med glavnimi admin moduli -->
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Calendar">
        ← Calendar
      </a>
      <a class="btn small" href="/app/admin/inquiries_admin.php" title="Inquiries">
        Inquiries
      </a>
      <a class="btn small" href="/app/admin/integrations.php" title="Integrations">
        Integrations
      </a>
      <a class="btn small" href="/app/admin/logs.php" title="Logs">
        Logs
      </a>
    </div>
  </header>

  <main id="manage-reservations-root">
    <!-- JS app entrypoint – enak ID kot prej v pubcal_admin.php -->
    <div id="manage-reservations"></div>
  </main>

  <!-- JS aplikacija za manage reservations (obstoječa logika) -->
  <script src="/app/admin/ui/js/manage_reservations.js"></script>
</body>
</html>
