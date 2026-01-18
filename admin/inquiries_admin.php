<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/inquiries_admin.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/inquiries_admin.php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin · Inquiries</title>

  <!-- osnovni admin CSS (isti kot calendar / reservations) -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <!-- NOVO: dedicated CSS za Inquiries admin -->
  <link rel="stylesheet" href="/app/admin/ui/css/inquiries_admin.css" />
</head>
<body class="adm-shell theme-dark inquiries-page">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Inquiries</h1>
    </div>
    <div class="hdr-right">
      <!-- glavna admin navigacija -->
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Calendar">
        Calendar
      </a>
      <a class="btn small" href="/app/admin/manage_reservations.php" title="Reservations">
        Reservations
      </a>
      <a class="btn small primary" href="/app/admin/inquiries_admin.php" title="Inquiries">
        Inquiries
      </a>
      <a class="btn small" href="/app/admin/integrations.php" title="Integrations">
        Integrations
      </a>
    </div>
  </header>

  <main id="admin-inquiries-main">
    <section id="tab-inquiries" class="inq-section">
      <div class="inq-wrap">
        <!-- LEVO: filter + seznam povpraševanj -->
        <aside class="inq-left">
          <div class="inq-toolbar">
            <label class="inq-unit-select">
              <span>Unit:</span>
              <select id="inq-unit-select">
                <option value="">All</option>
                <!-- JS lahko kasneje doda per-unit -->
              </select>
            </label>
            <button type="button" id="inq-refresh-btn" class="btn small">
              Refresh
            </button>
          </div>

          <div id="inqList" class="inq-list">
            Loading inquiries…
          </div>
        </aside>

        <!-- DESNO: kartica + surovi JSON -->
        <section id="inqDetail" class="inq-detail">
          <div class="inq-card">
            <div class="inq-card-row">
              <span>Status:</span>
              <b>Izberi povpraševanje na levi.</b>
            </div>
          </div>
        </section>
      </div>
    </section>
  </main>

  <script type="module" src="/app/admin/ui/js/inquiries_admin.js"></script>
</body>
</html>
