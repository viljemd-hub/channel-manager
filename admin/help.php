<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/help.php
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CM Help / User Guide</title>

  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css">
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css">
  <link rel="stylesheet" href="/app/admin/ui/css/header.css">

  <style>
    .help-root{
      max-width:1100px;
      margin:0 auto;
      padding:24px;
    }
    .help-hero{
      border:1px solid rgba(255,255,255,.12);
      background:rgba(11,33,66,.45);
      border-radius:18px;
      padding:22px;
      margin-bottom:18px;
    }
    .help-hero h1{
      margin:0 0 8px;
      font-size:28px;
    }
    .help-hero p{
      margin:0;
      opacity:.85;
      line-height:1.5;
    }
    .help-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
      gap:14px;
      margin:18px 0;
    }
    .help-card{
      border:1px solid rgba(255,255,255,.12);
      background:rgba(10,26,51,.75);
      border-radius:16px;
      padding:16px;
    }
    .help-card h2{
      margin:0 0 8px;
      font-size:18px;
    }
    .help-card p,
    .help-card li{
      font-size:14px;
      line-height:1.5;
      opacity:.88;
    }
    .help-card ul{
      margin:8px 0 0 18px;
      padding:0;
    }
    .help-section{
      margin-top:22px;
      border-top:1px solid rgba(255,255,255,.12);
      padding-top:18px;
    }
    .help-section h2{
      margin:0 0 10px;
    }
    .help-section h3{
      margin:18px 0 6px;
      font-size:16px;
    }
    .help-section p,
    .help-section li{
      line-height:1.55;
      opacity:.88;
    }
    .help-actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:14px;
    }
    .help-note{
      border-left:4px solid #57a6ff;
      background:rgba(87,166,255,.08);
      padding:12px 14px;
      border-radius:12px;
      margin:14px 0;
      font-size:14px;
      line-height:1.5;
    }
  </style>
</head>
<body class="adm-shell theme-dark">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">CM Help / User Guide</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="/app/admin/admin_calendar.php">Calendar</a>
      <a class="btn small" href="/app/admin/manage_reservations.php">Reservations</a>
      <a class="btn small" href="/app/admin/inquiries_admin.php">Inquiries</a>
      <a class="btn small" href="/app/admin/integrations.php">Integrations</a>
    </div>
  </header>

  <main class="help-root">
    <section class="help-hero">
      <h1>Welcome to CM</h1>
      <p>
        CM is a simple self-hosted Channel Manager for small accommodation owners.
        It helps you manage availability, prices, inquiries, reservations and calendar integrations from one place.
      </p>

      <div class="help-actions">
        <a class="btn primary" href="/app/admin/admin_calendar.php">Start with Calendar</a>
        <a class="btn" href="/app/admin/integrations.php">Open Integrations</a>
      </div>
    </section>

    <section class="help-grid">
      <article class="help-card">
        <h2>1. Calendar</h2>
        <p>Your main working screen for availability, prices, blocks and visual overview.</p>
      </article>

      <article class="help-card">
        <h2>2. Reservations</h2>
        <p>Review confirmed, cancelled, external and soft-hold reservations.</p>
      </article>

      <article class="help-card">
        <h2>3. Inquiries</h2>
        <p>Handle guest requests before they become confirmed reservations.</p>
      </article>

      <article class="help-card">
        <h2>4. Integrations</h2>
        <p>Connect external calendars and prepare the system for real-world sync.</p>
      </article>
    </section>

    <section class="help-section" id="calendar">
      <h2>Calendar</h2>

      <p>
        The admin calendar is the central view of your unit availability.
        Start here when you want to check dates, select a range, set prices, create blocks or add an admin reservation.
      </p>

      <h3>Basic workflow</h3>
      <ul>
        <li>Select the unit at the top.</li>
        <li>Use previous / next / today to move through months.</li>
        <li>Drag or click dates to select a range.</li>
        <li>Use the action buttons to block, unblock, set price, set offer or create an admin reservation.</li>
      </ul>

      <h3>Layers</h3>
      <p>
        Calendar layers let you decide what you want to see: occupancy, local blocks, prices, offers and pending inquiries.
        This makes the calendar useful both for quick overview and for detailed administration.
      </p>

      <div class="help-note">
        Prices are important: dates without usable price data may not behave as expected in the public calendar or offer flow.
      </div>
    </section>

    <section class="help-section" id="reservations">
      <h2>Reservations</h2>

      <p>
        The Reservations page shows existing reservations across units, years, statuses and sources.
        Use it when you want to inspect confirmed bookings, cancelled reservations, external reservations or soft-holds.
      </p>

      <h3>What to check</h3>
      <ul>
        <li>Unit and date range of the reservation.</li>
        <li>Guest name, email and phone if available.</li>
        <li>Status: confirmed, cancelled, soft-hold, external or ICS.</li>
        <li>Available actions such as cancel or re-send accept link.</li>
      </ul>

      <h3>External reservations</h3>
      <p>
        External reservations are useful for direct bookings or reservations that were created outside the public inquiry flow.
      </p>
    </section>

    <section class="help-section" id="inquiries">
      <h2>Inquiries</h2>

      <p>
        Inquiries are guest requests that have not yet become final reservations.
        This page is where you review requests, accept them, reject them, or mark them for visual tracking.
      </p>

      <h3>Inquiry flow</h3>
      <ul>
        <li>A guest sends an inquiry from the public offer flow.</li>
        <li>The inquiry appears in the admin list.</li>
        <li>You review guest data, dates, nights and price information.</li>
        <li>If you accept it, CM creates a soft-hold and sends the guest a confirmation link.</li>
        <li>When the guest confirms, the reservation becomes a hard reservation.</li>
      </ul>

      <h3>Calendar connection</h3>
      <p>
        Pending and marked inquiries can be reflected on the admin calendar.
        This helps you visually track important requests before they become final bookings.
      </p>
    </section>

    <section class="help-section" id="integrations">
      <h2>Integrations</h2>

      <p>
        Integrations connect CM with external platforms and calendars.
        The main idea is simple: CM can export its availability and import external availability.
      </p>

      <h3>Units and Base URL</h3>
      <p>
        Integrations are configured per unit. The Base URL must point to your public CM installation,
        because it is used to generate external links such as ICS URLs.
      </p>

      <h3>ICS Export</h3>
      <p>
        ICS export links allow external systems to read availability from CM.
        You can copy these links into platforms that support calendar import.
      </p>

      <h3>Channels / ICS Import</h3>
      <p>
        Channels let CM read external calendars.
        Imported external bookings are merged into the internal availability layer.
      </p>

      <h3>Autopilot</h3>
      <p>
        Autopilot is a Plus feature. It can automatically confirm safe inquiries when rules and availability checks pass.
        In CM Free it can be shown as a locked feature, so users understand the upgrade path.
      </p>

      <div class="help-note">
        Soft-holds are internal and should not be exported to external platforms. Confirmed reservations and hard locks are the safe export layer.
      </div>
    </section>

    <section class="help-section" id="first-use">
      <h2>Recommended first setup</h2>

      <ol>
        <li>Open Calendar and learn the unit selector, date selection and layers.</li>
        <li>Add or check prices for your first unit.</li>
        <li>Open Reservations and understand where confirmed bookings appear.</li>
        <li>Open Inquiries and review how guest requests become reservations.</li>
        <li>Open Integrations and set your Base URL.</li>
        <li>Copy ICS export links only after the system URL is correct.</li>
        <li>Add another unit only after the first unit is clear and working.</li>
      </ol>
    </section>
  </main>
</body>
</html>