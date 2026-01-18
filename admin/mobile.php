<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/mobile.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/mobile.php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin · Mobile</title>

  <!-- reuse admin look -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <style>
    /* lightweight page-local mobile styling */
    body.adm-shell.theme-dark.mobile-page { background:#0b1220; }
    .m-wrap { padding: 12px 14px 18px; max-width: 720px; margin: 0 auto; }
    .m-grid { display:grid; grid-template-columns: 1fr; gap: 10px; }
    .m-card {
      background: linear-gradient(180deg, rgba(19,28,42,.96), rgba(14,21,32,.96));
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 14px;
      padding: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    .m-title { font-weight: 700; font-size: 16px; margin: 0 0 8px; }
    .m-muted { color: rgba(233,238,245,.70); font-size: 13px; margin: 0 0 10px; }
    .m-actions { display:grid; grid-template-columns: 1fr; gap: 10px; }
    .m-btn {
      display:flex; align-items:center; justify-content:space-between; gap: 10px;
      text-decoration:none;
      padding: 12px 12px;
      border-radius: 12px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.10);
      color: #e9eef5;
      font-weight: 700;
      -webkit-tap-highlight-color: transparent;
    }
    .m-btn:active { transform: translateY(1px); }
    .m-btn .sub { font-weight: 600; font-size: 12px; color: rgba(233,238,245,.70); }
    .m-btn.primary { background: rgba(59,130,246,.22); border-color: rgba(59,130,246,.35); }
    .m-btn.warn    { background: rgba(245,197,66,.16); border-color: rgba(245,197,66,.30); }
    .m-btn.good    { background: rgba(34,197,94,.14); border-color: rgba(34,197,94,.26); }
    .m-row { display:flex; align-items:baseline; justify-content:space-between; gap: 10px; }
    .m-kpi { font-size: 28px; font-weight: 800; letter-spacing: .3px; }
    .m-kpi-label { font-size: 12px; color: rgba(233,238,245,.65); }
    .m-kpis { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px; }
  </style>
</head>

<body class="adm-shell theme-dark mobile-page">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Admin · Mobile</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Desktop UI">Desktop</a>
    </div>
  </header>

  <main class="m-wrap">
    <section class="m-card">
      <h2 class="m-title">Hitri dostop</h2>
      <p class="m-muted">Mobilni “hub” za osnovna opravila. Za napredne sloje odpri Desktop.</p>

      <div class="m-actions">
        <a class="m-btn primary" href="/app/admin/inquiries_admin.php">
          <div>
            Inquiries
            <div class="sub">pregled + accept/reject</div>
          </div>
          <span>→</span>
        </a>

        <a class="m-btn good" href="/app/admin/manage_reservations.php">
          <div>
            Reservations
            <div class="sub">potrjene rezervacije + akcije</div>
          </div>
          <span>→</span>
        </a>

        <a class="m-btn" href="/app/admin/mobile_calendar.php">
          <div>
            Calendar
            <div class="sub">full UI (na mobitelu bo bolj “dense”)</div>
          </div>
          <span>→</span>
        </a>

        <a class="m-btn warn" href="/app/admin/integrations.php">
          <div>
            Integrations
            <div class="sub">ICS / Channels / Promo / Autopilot</div>
          </div>
          <span>→</span>
        </a>
      </div>
    </section>

    <section class="m-card" style="margin-top:10px">
      <h2 class="m-title">Priporočilo</h2>
      <p class="m-muted">
        Za “profi” prikaz pri stranki je to dovolj: hitro prideš do Inquiries/Reservations,
        in po potrebi odpreš Desktop (gumb zgoraj).
      </p>
    </section>
  </main>
</body>
</html>
