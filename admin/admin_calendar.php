<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/admin_calendar.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/admin_calendar.php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Calendar/ Upravljanje koledarja</title>

  <!-- osnovni admin CSS -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/day.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/calendar.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layers.css">

</head>
<body class="adm-shell theme-dark calendar-page">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Admin Calendar/ Upravljanje koledarja</h1>
 <a class="btn small primary" href="/app/admin/admin_calendar.php" title="Calendar">
       Koledar/Calendar
      </a>
      <a class="btn small" href="/app/admin/manage_reservations.php" title="Reservations">
        Rezervacije/Reservations
      </a>
      <a class="btn small" href="/app/admin/inquiries_admin.php" title="Inquiries">
        Povpraševanja/Inquiries
      </a>
      <a class="btn small" href="/app/admin/integrations.php" title="Integrations">
        Integracije/Integrations
      </a>

      <!-- izbor enote (multi-unit) – JS ga napolni iz manifest.json -->
      <label class="hdr-unit-select">
        <span>Unit/Enota:</span>
        <select id="admin-unit-select">
          <option value="">Loading units…</option>
        </select>
      </label>

      <!-- osnovna navigacija po mesecih -->
      <div class="hdr-cal-nav">
        <button type="button" id="cal-btn-prev" class="btn small">&laquo;</button>
        <button type="button" id="cal-btn-next" class="btn small">&raquo;</button>
        <button type="button" id="cal-btn-today" class="btn small">Today</button>
        <span id="cal-current-range" class="cal-current-range"></span>
      </div>
    </div>

    <div class="hdr-right">
      <!-- glavna admin navigacija -->
     
<label class="hdr-opt">
  <input type="checkbox" id="cal-clean-before">
  Clean before/Čiščenje pred
</label>

<label class="hdr-opt">
  <input type="checkbox" id="cal-clean-after">
     Clean after/Čiščenje po
</label>  

<label class="cal-opt">
  <input type="checkbox" id="cal-day-use">
  Day use
</label>
 
  <input 
    type="number"
    id="cal-min-nights"
    class="cal-setting-input"
    min="1"
    max="365"
    step="1"
    value="1"
  />
  <label for="cal-min-nights" class="cal-setting-label">
    Min.nights
  </label>

   </div>

  </header>

  <main id="admin-calendar-root">

    <!-- Command / info vrstica za selection (namesto starega selection bara) -->
    <section id="cal-command-bar" class="cal-command-bar">
    <!-- INFO VRSTICA (drag-select ali klik na block/reservation) -->
    <section id="cal-info-bar">
    </section>

      <div class="cal-selection-info">
        <span id="cal-selection-label">No selection/Ni izbora</span>
        <span id="cal-selection-meta" class="cal-selection-meta"></span>
      </div>
      <div class="cal-command-actions">

        <!-- Akcijski gumbi – JS bo glede na mode odločal, kaj je aktivno -->
        <button type="button" id="cal-btn-block" class="btn small">Block/Blokiraj</button>
        <button type="button" id="cal-btn-unblock" class="btn small">Unblock/Odblokiraj</button>
        <button type="button" id="cal-btn-set-price" class="btn small">Set price/Nastavi ceno</button>
        <button type="button" id="cal-btn-set-offer" class="btn small">Set offer/Dodaj ponudbo</button>
        <button type="button" id="cal-btn-admin-reserve" class="btn small">Admin reserve/rezervacija</button>
        <button type="button" id="cal-btn-clear-selection" class="btn small ghost">Clear/Počisti</button>
      </div>
    </section>

    <!-- Layer toggles: kaj je vidno (vizualne plasti) -->
    <section id="cal-layer-toggles" class="cal-layer-toggles">
      <label>
        <input type="checkbox" id="layer-occupancy" checked />
        Occupancy
      </label>
      <label>
        <input type="checkbox" id="layer-local" checked />
        Local blocks
      </label>
      <label>
        <input type="checkbox" id="layer-prices" checked />
        Prices
      </label>
      <label>
        <input type="checkbox" id="layer-offers" checked />
        Offers
      </label>
      <label>
        <input type="checkbox" id="layer-pending" checked />
        Pending
      </label>
    </section>

  <!-- Main calendar (multi-month grid) -->
  <section id="calendar-shell" class="calendar-shell">
    <div class="calendar-wrapper">
      <div id="calendar" class="calendar">
        <!-- admin_calendar.js will render month cards here -->
      </div>
    </div>
  </section>

  </main>

  <!-- JS moduli za koledar -->
  <script src="/app/admin/ui/js/calendar_shell.js" defer></script>
  <script type="module" src="/app/admin/ui/js/admin_calendar.js"></script>
  <script src="/app/admin/ui/js/range_select_admin.js" defer></script>

</body>
</html>
