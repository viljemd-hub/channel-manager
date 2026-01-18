<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/checkin_tt.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Purpose:
 * - Printable check-in helper for tourist tax (TT) and KEYCARD benefit.
 * - Reads a reservation JSON by ID and shows:
 *   - stay details (unit, dates, guests),
 *   - TT base (adults + children with discount),
 *   - KEYCARD coverage and remaining TT to pay,
 *   - accommodation amount & final amount to pay at desk.
 * - Admin can adjust TT rate, KEYCARD count and accommodation before printing.
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

/**
 * Small HTML escaper (local, to avoid name collisions).
 */
function ct_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Minimal JSON reader for local files.
 */
function ct_read_json(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Find a reservation JSON by id.
 *
 * Layout: /common/data/json/reservations/YYYY/UNIT/ID.json
 * ID format: YYYYMMDDHHMMSS-xxxx-UNIT
 */
function ct_find_reservation(string $id): ?array {
    $id = trim($id);
    if ($id === '') return null;

    $root = realpath(__DIR__ . '/../common/data/json/reservations');
    if ($root === false) {
        return null;
    }

    // Try to parse year and unit from id format
    $year = substr($id, 0, 4);
    $unit = '';
    if (preg_match('~-[0-9a-f]{4}-([A-Za-z0-9_-]+)$~', $id, $m)) {
        $unit = $m[1];
    }

    $candidates = [];

    if ($unit !== '') {
        $path = sprintf('%s/%s/%s/%s.json', $root, $year, $unit, $id);
        $candidates[] = $path;
    }

    // Fallback: if unit could not be parsed, scan all units for that year
    if ($unit === '') {
        $yearDir = $root . '/' . $year;
        if (is_dir($yearDir)) {
            foreach (glob($yearDir . '/*/' . $id . '.json') as $p) {
                $candidates[] = $p;
            }
        }
    }

    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $raw = @file_get_contents($p);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!is_array($j)) continue;
        $j['_file'] = $p;
        return $j;
    }

    return null;
}

/**
 * EU date formatting helper: 2026-03-16 -> 16.03.2026
 */
function ct_fmt_date(?string $iso): string {
    if (!$iso) return '';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $iso, new DateTimeZone('Europe/Ljubljana'));
    if (!$dt) return $iso;
    return $dt->format('d.m.Y');
}

// --- Input & reservation load -------------------------------------------------

$id = isset($_GET['id']) ? preg_replace('~[^0-9A-Za-z_-]~', '', (string)$_GET['id']) : '';
$error = '';
$res   = null;

if ($id === '') {
    $error = 'Manjka ID rezervacije.';
} else {
    $res = ct_find_reservation($id);
    if (!$res) {
        $error = 'Rezervacije ni mogoče najti (ali še ni potrjena).';
    }
}

// --- Tourist tax defaults & site_settings.json --------------------------------

// Defaults if site_settings.json is missing or incomplete
$defaultTtRate = 2.50; // EUR per adult per night
$childFactor   = 0.50; // children pay 50 % of adult TT by default
$childLabel    = 'Otroci 7–18 let: 50 % turistične takse.';
$keycardNote   = 'Key-card (Apartma Matevž vizitko/ključ) gostu krije plačilo celotne ali delne turistične takse (po veljavnem ceniku).';

// Global site settings: /common/data/json/units/site_settings.json
$siteSettingsPath = __DIR__ . '/../common/data/json/units/site_settings.json';
$siteSettings = ct_read_json($siteSettingsPath);

if (is_array($siteSettings) && isset($siteSettings['tourist_tax']) && is_array($siteSettings['tourist_tax'])) {
    $tt = $siteSettings['tourist_tax'];

    if (isset($tt['adult_per_day_eur']) && is_numeric($tt['adult_per_day_eur'])) {
        $defaultTtRate = (float)$tt['adult_per_day_eur'];
    } elseif (isset($tt['estimate_per_adult_per_day_eur']) && is_numeric($tt['estimate_per_adult_per_day_eur'])) {
        $defaultTtRate = (float)$tt['estimate_per_adult_per_day_eur'];
    }

    if (isset($tt['child_discount_factor']) && is_numeric($tt['child_discount_factor'])) {
        $childFactor = (float)$tt['child_discount_factor'];
    }

    if (isset($tt['child_discount_label']) && is_string($tt['child_discount_label'])) {
        $childLabel = trim($tt['child_discount_label']);
    }

    if (isset($tt['keycard_note']) && is_string($tt['keycard_note']) && trim($tt['keycard_note']) !== '') {
        $keycardNote = trim($tt['keycard_note']);
    }
}

// --- Pre-calc reservation values ---------------------------------------------

$unit      = $res['unit']          ?? '';
$fromIso   = $res['from']          ?? '';
$toIso     = $res['to']            ?? '';
$nights    = (int)($res['nights']  ?? 0);
$adults    = (int)($res['adults']  ?? 0);
$kids06    = (int)($res['kids06']  ?? ($res['kids_0_6']  ?? 0));
$kids712   = (int)($res['kids712'] ?? ($res['kids_7_12'] ?? 0));
$keycards  = (int)($res['keycards'] ?? 0);

$guestName  = '';
$guestEmail = '';
$guestPhone = '';

if (isset($res['guest']) && is_array($res['guest'])) {
    $guestName  = (string)($res['guest']['name']  ?? '');
    $guestEmail = (string)($res['guest']['email'] ?? '');
    $guestPhone = (string)($res['guest']['phone'] ?? '');
} else {
    $guestName  = (string)($res['guest_name']  ?? '');
    $guestEmail = (string)($res['guest_email'] ?? '');
    $guestPhone = (string)($res['guest_phone'] ?? '');
}

// Persons subject to TT (adults + children with discounted TT)
$personsForTt = max(0, $adults + $kids712);

// Accommodation amount – best-effort guess with safe fallback.
// Primary source: calc.final (final price without TT from pricing engine).
// Fallback: accommodation_total if present (legacy).
$accommodationDefault = 0.0;

if (isset($res['calc']) && is_array($res['calc']) && isset($res['calc']['final']) && is_numeric($res['calc']['final'])) {
    $accommodationDefault = (float)$res['calc']['final'];
} elseif (isset($res['accommodation_total']) && is_numeric($res['accommodation_total'])) {
    $accommodationDefault = (float)$res['accommodation_total'];
}


// Potential future flag: paid online (CM Plus).
$paidOnlineDefault = false;
if (isset($res['payment']) && is_array($res['payment'])) {
    $status = (string)($res['payment']['status'] ?? '');
    if (in_array($status, ['paid_online', 'paid_sepa'], true)) {
        $paidOnlineDefault = true;
    }
}

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Check-in · TT &amp; KEYCARD</title>
  <style>
    :root {
      --bg: #020617;
      --panel: #020617;
      --panel-soft: #0b1120;
      --border: #1f2937;
      --accent: #38bdf8;
      --accent-soft: rgba(56,189,248,0.18);
      --danger: #f97373;
      --text: #e5e7eb;
      --muted: #9ca3af;
    }
    * { box-sizing: border-box; }

    body {
      margin: 0;
      padding: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: radial-gradient(circle at top, #1f2937 0, #020617 55%, #020617 100%);
      color: var(--text);
    }

    body.checkin-page {
      min-height: 100vh;
      display: flex;
      align-items: stretch;
      justify-content: center;
    }

    .wrap {
      width: 100%;
      max-width: 840px;
      margin: 18px auto;
      padding: 18px 16px 24px;
    }

    .card {
      border-radius: 16px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(15,23,42,0.96), rgba(2,6,23,0.98));
      padding: 18px 18px 16px;
      box-shadow: 0 18px 50px rgba(0,0,0,0.45);
    }

    h1 {
      margin: 0 0 4px;
      font-size: 20px;
      letter-spacing: 0.02em;
    }
    .subtitle {
      margin: 0 0 14px;
      font-size: 13px;
      color: var(--muted);
    }

    .meta-grid {
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(0, 2fr);
      gap: 14px 18px;
      margin-bottom: 18px;
      font-size: 13px;
    }

    .meta-block {
      padding: 10px 10px;
      border-radius: 12px;
      background: rgba(15,23,42,0.9);
      border: 1px solid rgba(148,163,184,0.3);
    }

    .meta-title {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--muted);
      margin-bottom: 6px;
    }

    .meta-row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      margin: 2px 0;
    }
    .meta-label {
      color: var(--muted);
    }
    .meta-value {
      font-weight: 500;
    }

    .tt-card {
      margin-top: 6px;
      padding: 12px 12px 10px;
      border-radius: 14px;
      border: 1px solid rgba(56,189,248,0.45);
      background: radial-gradient(circle at top left, var(--accent-soft) 0, rgba(8,47,73,0.3) 40%, rgba(15,23,42,0.95) 100%);
      font-size: 13px;
    }

    .tt-title {
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 2px 6px;
      border-radius: 999px;
      font-size: 11px;
      background: rgba(15,23,42,0.8);
      border: 1px solid rgba(148,163,184,0.45);
      color: var(--muted);
    }

    .tt-grid {
      display: grid;
      grid-template-columns: minmax(0, 2.2fr) minmax(0, 1.4fr);
      gap: 10px 16px;
      margin-top: 8px;
      align-items: flex-start;
    }

    .tt-inputs label {
      display: block;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .tt-inputs input {
      width: 100%;
      padding: 7px 9px;
      border-radius: 10px;
      border: 1px solid rgba(148,163,184,0.55);
      background: rgba(15,23,42,0.95);
      color: var(--text);
      font-size: 13px;
      font-family: inherit;
    }
    .tt-inputs input:focus {
      outline: 2px solid rgba(56,189,248,0.7);
      outline-offset: 1px;
      border-color: transparent;
    }

    .tt-summary {
      font-size: 13px;
      line-height: 1.4;
      padding: 6px 8px;
      border-radius: 10px;
      background: rgba(15,23,42,0.9);
      border: 1px dashed rgba(148,163,184,0.6);
    }
    .tt-summary p {
      margin: 2px 0;
    }
    .tt-summary strong {
      font-weight: 600;
    }
    .tt-summary .highlight {
      color: #bbf7d0;
      font-weight: 600;
    }
    .tt-summary .danger {
      color: var(--danger);
      font-weight: 600;
    }

    .note {
      margin-top: 10px;
      font-size: 11px;
      color: var(--muted);
    }

    .invoice-card {
      margin-top: 12px;
      padding: 10px 12px 10px;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,0.45);
      background: rgba(15,23,42,0.96);
      font-size: 13px;
    }
    .inv-title {
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .inv-grid {
      display: grid;
      grid-template-columns: minmax(0, 2.2fr) minmax(0, 1.4fr);
      gap: 10px 16px;
      align-items: flex-start;
    }
    .inv-inputs label {
      display: block;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .inv-inputs input[type="number"] {
      width: 100%;
      padding: 7px 9px;
      border-radius: 10px;
      border: 1px solid rgba(148,163,184,0.55);
      background: rgba(15,23,42,0.95);
      color: var(--text);
      font-size: 13px;
      font-family: inherit;
    }
    .inv-inputs input[type="number"]:focus {
      outline: 2px solid rgba(56,189,248,0.7);
      outline-offset: 1px;
      border-color: transparent;
    }
    .inv-check {
      margin-top: 8px;
      font-size: 12px;
      color: var(--muted);
      display: flex;
      align-items: flex-start;
      gap: 6px;
    }
    .inv-check input {
      margin-top: 2px;
    }
    .inv-summary {
      font-size: 13px;
      line-height: 1.4;
      padding: 6px 8px;
      border-radius: 10px;
      background: rgba(15,23,42,0.9);
      border: 1px dashed rgba(148,163,184,0.7);
    }
    .inv-summary p {
      margin: 2px 0;
    }
    .inv-summary .inv-total {
      margin-top: 4px;
      font-weight: 700;
      color: #fbbf24;
    }

    .footer-actions {
      margin-top: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      font-size: 11px;
      color: var(--muted);
    }
    .footer-actions .btn-row {
      display: flex;
      gap: 8px;
    }
    .btn {
      border-radius: 999px;
      border: 0;
      padding: 7px 12px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
    }
    .btn-primary {
      background: var(--accent);
      color: #020617;
    }
    .btn-ghost {
      background: rgba(15,23,42,0.95);
      color: var(--text);
      border: 1px solid rgba(148,163,184,0.5);
    }

    @media (max-width: 720px) {
      .meta-grid { grid-template-columns: minmax(0,1fr); }
      .tt-grid { grid-template-columns: minmax(0,1fr); }
      .inv-grid { grid-template-columns: minmax(0,1fr); }
    }

    /* Print styling */
    @media print {
      body {
        background: #ffffff;
        color: #111827;
      }
      .wrap {
        margin: 0;
        padding: 0;
      }
      .card {
        border-radius: 0;
        border: 0;
        box-shadow: none;
        background: #ffffff;
      }
      .subtitle,
      .note,
      .footer-actions .btn-row button {
        color: #6b7280;
      }
      .footer-actions .btn-row {
        display: none;
      }
    }
  </style>
</head>
<body class="checkin-page">
  <div class="wrap">
    <div class="card">
      <h1>Check-in – TT &amp; KEYCARD</h1>
      <p class="subtitle">
        Povzetek turistične takse, prihranka s KEYCARD in zneska nastanitve za izbrano rezervacijo.
      </p>

      <?php if ($error !== ''): ?>
        <p style="color:#f97373; font-size:13px; margin-top:10px;">
          <?=ct_h($error)?>
        </p>
      <?php else: ?>
        <div class="meta-grid">
          <div class="meta-block">
            <div class="meta-title">Gost</div>
            <div class="meta-row">
              <span class="meta-label">Ime in priimek:</span>
              <span class="meta-value"><?=ct_h($guestName !== '' ? $guestName : '—')?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">E-pošta:</span>
              <span class="meta-value"><?=ct_h($guestEmail !== '' ? $guestEmail : '—')?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Telefon:</span>
              <span class="meta-value"><?=ct_h($guestPhone !== '' ? $guestPhone : '—')?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Rezervacija ID:</span>
              <span class="meta-value mono"><?=ct_h($id)?></span>
            </div>
          </div>

          <div class="meta-block">
            <div class="meta-title">Bivanje</div>
            <div class="meta-row">
              <span class="meta-label">Enota:</span>
              <span class="meta-value"><?=ct_h($unit !== '' ? $unit : '—')?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Termin:</span>
              <span class="meta-value">
                <?=ct_h(ct_fmt_date($fromIso))?> – <?=ct_h(ct_fmt_date($toIso))?>
              </span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Noči:</span>
              <span class="meta-value"><?=ct_h((string)$nights)?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Skupina:</span>
              <span class="meta-value">
                Odrasli: <?=$adults?> · Otroci 7–12: <?=$kids712?> · Otroci 0–6: <?=$kids06?>
              </span>
            </div>
          </div>
        </div>

        <div class="tt-card"
             data-adults="<?=$adults?>"
             data-kids712="<?=$kids712?>"
             data-child-factor="<?=htmlspecialchars(number_format($childFactor, 3, '.', ''), ENT_QUOTES, 'UTF-8')?>"
             data-nights="<?=$nights?>"
             data-default-rate="<?=htmlspecialchars(number_format($defaultTtRate, 2, '.', ''), ENT_QUOTES, 'UTF-8')?>"
             data-default-keycards="<?=$keycards?>">
          <div class="tt-title">
            <span>Turistična taksa &amp; KEYCARD prihranek</span>
            <span class="chip">1 KEYCARD = TT za eno odraslo osebo za celotno bivanje</span>
          </div>

          <div class="tt-grid">
            <div class="tt-inputs">
              <label for="ttRate">
                TT stopnja na odraslo osebo / noč (EUR)
              </label>
              <input type="number" step="0.01" min="0" id="ttRate" value="<?=htmlspecialchars(number_format($defaultTtRate, 2, '.', ''), ENT_QUOTES, 'UTF-8')?>" />

              <label for="ttKeycards" style="margin-top:8px;">
                KEYCARD (št. kartic ob prihodu)
              </label>
              <input type="number" step="1" min="0" id="ttKeycards" value="<?=$keycards?>" />
            </div>

            <div class="tt-summary">
              <p>
                Osnovna TT za plačilo brez ugodnosti:<br>
                <strong id="ttBase"></strong>
              </p>
              <p>
                Prihranek s KEYCARD:<br>
                <span id="ttSaved" class="highlight"></span>
              </p>
              <p>
                TT za plačilo po prihranku:<br>
                <span id="ttToPay" class="danger"></span>
              </p>
              <p style="margin-top:6px; font-size:11px;">
                Razčlenitev:<br>
                <span id="ttBreakdown"></span>
              </p>
            </div>
          </div>

          <p class="note">
            <?= ct_h($keycardNote) ?><br>
            <?php if ($childLabel !== ''): ?>
              <?= ct_h($childLabel) ?>
            <?php else: ?>
              Otroci 0–6 let so oproščeni plačila TT; otroci 7–18 let plačajo znižano TT.
            <?php endif; ?>
          </p>
        </div>

        <div class="invoice-card"
             data-acc-default="<?=htmlspecialchars(number_format($accommodationDefault, 2, '.', ''), ENT_QUOTES, 'UTF-8')?>"
             data-paid-online="<?= $paidOnlineDefault ? '1' : '0' ?>">
          <div class="inv-title">Plačilo nastanitve &amp; končni znesek</div>
          <div class="inv-grid">
            <div class="inv-inputs">
              <label for="invAccommodation">
                Znesek nastanitve (EUR)
              </label>
              <input type="number" step="0.01" min="0" id="invAccommodation"
                     value="<?=htmlspecialchars(number_format($accommodationDefault, 2, '.', ''), ENT_QUOTES, 'UTF-8')?>" />

              <label class="inv-check">
                <input type="checkbox" id="invPaidOnline" <?=$paidOnlineDefault ? 'checked' : ''?>>
                <span>Nastanitev je že v celoti plačana (SEPA / kartica). Ob prihodu se plača le še TT.</span>
              </label>
            </div>
            <div class="inv-summary">
              <p>
                Nastanitev za plačilo ob prihodu:<br>
                <strong id="invAccToPay"></strong>
              </p>
              <p>
                TT za plačilo po prihranku:<br>
                <strong id="invTtToPay"></strong>
              </p>
              <p class="inv-total">
                Skupaj za plačilo ob prihodu:<br>
                <span id="invTotal"></span>
              </p>
            </div>
          </div>
        </div>

        <div class="footer-actions">
          <div>
            <span style="font-size:11px; color:var(--muted);">
              Dokument za interni check-in, prijazno razlago gosta in osnovni račun pri plačilu na mestu.
            </span>
          </div>
          <div class="btn-row">
            <button type="button" class="btn btn-ghost" onclick="window.close();">
              Zapri
            </button>
            <button type="button" class="btn btn-primary" onclick="window.print();">
              Natisni / Shrani v PDF
            </button>
          </div>
        </div>

        <script>
          (function () {
            const card = document.querySelector('.tt-card');
            if (!card) return;

            const adults      = parseInt(card.getAttribute('data-adults') || '0', 10) || 0;
            const kids712     = parseInt(card.getAttribute('data-kids712') || '0', 10) || 0;
            const childFactor = parseFloat(card.getAttribute('data-child-factor') || '0.5') || 0;
            const nights      = parseInt(card.getAttribute('data-nights') || '0', 10) || 0;
            const defaultRate = parseFloat(card.getAttribute('data-default-rate') || '0') || 0;
            const defaultKeycards = parseInt(card.getAttribute('data-default-keycards') || '0', 10) || 0;

            const invCard = document.querySelector('.invoice-card');
            const accDefault = invCard
              ? (parseFloat(invCard.getAttribute('data-acc-default') || '0') || 0)
              : 0;
            const paidOnlineDefault = invCard
              ? invCard.getAttribute('data-paid-online') === '1'
              : false;

            const rateInput = document.getElementById('ttRate');
            const keyInput  = document.getElementById('ttKeycards');
            const baseEl    = document.getElementById('ttBase');
            const savedEl   = document.getElementById('ttSaved');
            const payEl     = document.getElementById('ttToPay');
            const brEl      = document.getElementById('ttBreakdown');

            const accInput      = document.getElementById('invAccommodation');
            const paidOnlineChk = document.getElementById('invPaidOnline');
            const accToPayEl    = document.getElementById('invAccToPay');
            const ttToPayEl     = document.getElementById('invTtToPay');
            const totalEl       = document.getElementById('invTotal');

            if (paidOnlineChk && paidOnlineDefault) {
              paidOnlineChk.checked = true;
            }

            function fmtEUR(v) {
              return (v || 0).toFixed(2).replace('.', ',') + ' €';
            }

            function recalc() {
              const rateRaw = rateInput && rateInput.value !== '' ? rateInput.value : String(defaultRate);
              const keyRaw  = keyInput && keyInput.value !== ''  ? keyInput.value  : String(defaultKeycards);

              const rate = Math.max(0, parseFloat(rateRaw.replace(',', '.')) || 0);
              let keycards = Math.max(0, parseInt(keyRaw, 10) || 0);

              const nightsCount = Math.max(0, nights);
              const adultsCount = Math.max(0, adults);
              const kidsCount   = Math.max(0, kids712);
              const personsForTt = adultsCount + kidsCount;

              const childRate = rate * childFactor;

              const baseAdults = adultsCount * nightsCount * rate;
              const baseKids   = kidsCount * nightsCount * childRate;
              const baseTt     = baseAdults + baseKids;

              // Each KEYCARD covers TT for exactly one adult-equivalent person.
              if (keycards > personsForTt) keycards = personsForTt;

              const saved = keycards * nightsCount * rate;
              const toPay = Math.max(0, baseTt - saved);

              if (baseEl)  baseEl.textContent  = fmtEUR(baseTt);
              if (savedEl) savedEl.textContent = fmtEUR(saved);
              if (payEl)   payEl.textContent   = fmtEUR(toPay);

              if (brEl) {
                if (personsForTt <= 0 || nightsCount <= 0 || rate <= 0) {
                  brEl.textContent = 'Ni dovolj podatkov za razčlenitev.';
                } else {
                  const parts = [];
                  if (adultsCount > 0) {
                    const aPart = baseAdults;
                    parts.push(
                      adultsCount + ' × ' + nightsCount + ' × ' +
                      rate.toFixed(2).replace('.', ',') + ' € = ' + fmtEUR(aPart) +
                      ' (odrasli)'
                    );
                  }
                  if (kidsCount > 0 && childRate > 0) {
                    const kPart = baseKids;
                    parts.push(
                      kidsCount + ' × ' + nightsCount + ' × ' +
                      childRate.toFixed(2).replace('.', ',') + ' € = ' + fmtEUR(kPart) +
                      ' (otroci 7–12)'
                    );
                  }
                  let txt = parts.join(' · ');
                  txt += ' → skupaj ' + fmtEUR(baseTt);
                  if (keycards > 0) {
                    txt += ' · pokritih s KEYCARD: ' + keycards + ' oseb';
                  }
                  brEl.textContent = txt;
                }
              }

              // --- Invoice section (accommodation + final total) --------------
              if (invCard) {
                const accRaw = accInput && accInput.value !== ''
                  ? accInput.value
                  : String(accDefault);

                let acc = Math.max(0, parseFloat(accRaw.replace(',', '.')) || 0);
                const paidOnline = !!(paidOnlineChk && paidOnlineChk.checked);
                const accPayable = paidOnline ? 0 : acc;

                if (accToPayEl) accToPayEl.textContent = fmtEUR(accPayable);
                if (ttToPayEl)  ttToPayEl.textContent  = fmtEUR(toPay);
                if (totalEl)    totalEl.textContent    = fmtEUR(accPayable + toPay);
              }
            }

            rateInput && rateInput.addEventListener('input', recalc);
            keyInput  && keyInput.addEventListener('input', recalc);

            accInput      && accInput.addEventListener('input', recalc);
            paidOnlineChk && paidOnlineChk.addEventListener('change', recalc);

            recalc();
          })();
        </script>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
