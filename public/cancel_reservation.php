<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/cancel_reservation.php
 * Author: Viljem Dvojmoč, Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../common/lib/site_settings.php';

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

$APP   = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
$RES   = $APP . '/common/data/json/reservations';
$UNITS = $APP . '/common/data/json/units';
$CANC  = $APP . '/common/data/json/cancellations';
$LOG   = $APP . '/common/data/json/logs/cancellations.log';

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Basic JSON response helper for POST (AJAX).
 */
function cm_cancel_respond(bool $ok, array $extra = [], int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload = array_merge(['ok' => $ok], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Localised text helper for cancel_reservation UI (sl/en, fallback EN→SL).
 */
function cm_cancel_text(string $key, string $lang): string {
    static $MAP = [
        'title' => [
            'sl' => 'Odpoved rezervacije',
            'en' => 'Cancel reservation',
        ],
        'error_invalid' => [
            'sl' => 'Napaka: povezava ni veljavna ali rezervacije ni bilo mogoče najti.',
            'en' => 'Error: the link is not valid or the reservation could not be found.',
        ],
        'error_not_direct' => [
            'sl' => 'To rezervacijo je bilo ustvarjeno preko zunanjega kanala (npr. Booking.com, Airbnb). Odpoved ni možna preko te povezave – prosimo, uporabite kanal, preko katerega je bila rezervacija opravljena.',
            'en' => 'This reservation was created via an external channel (e.g. Booking.com, Airbnb). It cannot be cancelled using this link. Please use the channel where the booking was made.',
        ],
        'already_cancelled_title' => [
            'sl' => 'Rezervacija je že bila odpovedana.',
            'en' => 'This reservation has already been cancelled.',
        ],
        'already_cancelled_body' => [
            'sl' => 'Če menite, da je prišlo do napake, kontaktirajte gostitelja.',
            'en' => 'If you believe this is a mistake, please contact the host.',
        ],
        'summary_title' => [
            'sl' => 'Povzetek rezervacije',
            'en' => 'Reservation summary',
        ],
        'label_guest' => [
            'sl' => 'Gost:',
            'en' => 'Guest:',
        ],
        'label_unit' => [
            'sl' => 'Enota:',
            'en' => 'Unit:',
        ],
        'label_dates' => [
            'sl' => 'Termin:',
            'en' => 'Stay dates:',
        ],
        'label_group' => [
            'sl' => 'Število oseb:',
            'en' => 'Guests:',
        ],
        'label_price_without_tt' => [
            'sl' => 'Cena nastanitve (brez TT):',
            'en' => 'Accommodation price (without tourist tax):',
        ],
        'label_tt' => [
            'sl' => 'Turistična taksa:',
            'en' => 'Tourist tax:',
        ],
        'tt_fallback' => [
            'sl' => 'po veljavnem ceniku občine',
            'en' => 'according to the municipality price list',
        ],
        'reason_label' => [
            'sl' => 'Razlog odpovedi (neobvezno):',
            'en' => 'Reason for cancellation (optional):',
        ],
        'reason_placeholder' => [
            'sl' => 'Kratek opis razloga (npr. sprememba načrtov, bolezen ...)',
            'en' => 'Short description (e.g. change of plans, illness ...)',
        ],
        'button_cancel' => [
            'sl' => 'Odpovej rezervacijo',
            'en' => 'Cancel reservation',
        ],
        'info_irreversible' => [
            'sl' => 'Odpoved je dokončna in je ni mogoče razveljaviti.',
            'en' => 'This cancellation is final and cannot be undone.',
        ],
        'info_contact_host' => [
            'sl' => 'V primeru vprašanj ali spremembe načrtov se obrnite neposredno na gostitelja.',
            'en' => 'If you have questions or your plans change, please contact the host directly.',
        ],
        'js_sending' => [
            'sl' => 'Pošiljam zahtevo za odpoved ...',
            'en' => 'Sending cancellation request...',
        ],
        'js_cancel_ok' => [
            'sl' => 'Rezervacija je bila uspešno odpovedana.',
            'en' => 'The reservation has been successfully cancelled.',
        ],
        'js_cancel_ok_already' => [
            'sl' => 'Rezervacija je bila že prej odpovedana.',
            'en' => 'The reservation was already cancelled before.',
        ],
        'js_cancel_failed' => [
            'sl' => 'Odpoved ni uspela. Poskusite znova ali kontaktirajte gostitelja.',
            'en' => 'Cancellation failed. Please try again or contact the host.',
        ],
        'js_network_error' => [
            'sl' => 'Napaka v omrežju. Preverite povezavo ali poskusite znova.',
            'en' => 'Network error. Please check your connection or try again.',
        ],
        'js_button_cancelled' => [
            'sl' => 'Odpovedano',
            'en' => 'Cancelled',
        ],
    ];

    $lang = in_array($lang, ['sl','en'], true) ? $lang : 'en';

    if (!isset($MAP[$key])) {
        // fallback: key itself
        return $key;
    }

    $entry = $MAP[$key];

    if (is_string($entry)) {
        return $entry;
    }

    if (isset($entry[$lang])) {
        return $entry[$lang];
    }

    // Fallback EN→SL ali obratno
    return $entry['en'] ?? ($entry['sl'] ?? $key);
}

/**
 * Simple EU-style date formatting (YYYY-MM-DD → DD. MM. YYYY).
 */
function cm_fmt_eu_date(?string $iso): string {
    if (!$iso) return '';
    $parts = explode('-', substr($iso, 0, 10));
    if (count($parts) !== 3) return $iso;
    return sprintf('%02d.%02d.%04d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
}

/**
 * Format EUR amount with 2 decimals and € sign.
 */
function cm_fmt_eur(float $amount): string {
    return number_format($amount, 2, ',', '.') . ' €';
}

/**
 * Find reservation by cancel_token in /reservations/YYYY/UNIT/*.json
 */
function cm_find_reservation_by_cancel_token(string $root, string $token): ?array {
    if ($token === '') return null;

    $patternYears = rtrim($root, '/') . '/*/*/*.json'; // /reservations/YYYY/UNIT/ID.json
    $files = glob($patternYears, GLOB_NOSORT) ?: [];

    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        if (($data['cancel_token'] ?? '') !== $token) {
            continue;
        }

        // Enrich with file path
        $data['_file'] = $f;
        return $data;
    }

    return null;
}

/**
 * Ensure folder exists (mkdir -p).
 */
function cm_mkdir_p(string $dir): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0775, true) || is_dir($dir);
}

/**
 * Append a simple log line to cancellations log.
 */
function cm_log_cancellation(string $logFile, array $res, string $reason, string $tz): void {
    $now = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('c');
    $id   = $res['id']   ?? '';
    $unit = $res['unit'] ?? '';
    $from = $res['from'] ?? '';
    $to   = $res['to']   ?? '';
    $guest = '';
    if (isset($res['guest']['name'])) {
        $guest = (string)$res['guest']['name'];
    } elseif (isset($res['guest_name'])) {
        $guest = (string)$res['guest_name'];
    }

    $line = sprintf(
        "[%s] CANCEL id=%s unit=%s %s→%s guest=%s reason=%s\n",
        $now,
        $id,
        $unit,
        $from,
        $to,
        str_replace(["\n", "\r"], ' ', $guest),
        str_replace(["\n", "\r"], ' ', $reason)
    );

    @cm_mkdir_p(dirname($logFile));
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Send a simple cancellation notification email to the guest (and BCC admin).
 * Includes only reservation ID and dates (no pricing).
 */
function cm_send_cancellation_email(array $res, string $reason): void {
    // We need the unified email helper
    if (!function_exists('cm_send_email')) {
        return;
    }

    $settingsAll = function_exists('cm_load_settings') ? cm_load_settings() : [];
    $emailCfg    = $settingsAll['email'] ?? [];
    $enabled     = (bool)($emailCfg['enabled'] ?? false);
    if (!$enabled) {
        return;
    }

    $fromEmail  = (string)($emailCfg['from_email']  ?? 'no-reply@example.test');
    $fromName   = (string)($emailCfg['from_name']   ?? 'Reservations');
    $adminEmail = (string)($emailCfg['admin_email'] ?? '');

    // Guest e-mail
    $guestEmail = (string)($res['guest']['email'] ?? ($res['email'] ?? ''));
    $guestEmail = trim($guestEmail);
    if ($guestEmail === '') {
        // No e-mail to send to
        return;
    }

    // Language: sl/en
    $lang = (string)($res['lang'] ?? ($res['meta']['lang'] ?? 'sl'));
    if (!in_array($lang, ['sl','en'], true)) {
        $lang = 'en';
    }

    // Dates (EU format)
    $fromRaw = (string)($res['from'] ?? '');
    $toRaw   = (string)($res['to']   ?? '');
    $fmtEu = function (string $d): string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $d;
    };
    $fromEu = $fmtEu($fromRaw);
    $toEu   = $fmtEu($toRaw);

    $unit = (string)($res['unit'] ?? '');
    $id   = (string)($res['id']   ?? '');

    if ($lang === 'en') {
        $subject = 'Reservation cancelled'
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' || ($fromEu && $toEu) ? ' – ' : '')
                 . ($unit !== '' ? "{$unit} " : '')
                 . ($fromEu && $toEu ? "({$fromEu} – {$toEu})" : '');

        $hello   = 'Hello';
        $line    = "Your reservation"
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' ? " for {$unit}" : '')
                 . ($fromEu && $toEu ? " from {$fromEu} to {$toEu}" : '')
                 . ' has been cancelled.';
        $reasonLabel = 'Reason for cancellation';
        $footer = "If you have any questions, please contact us.\n\nBest regards,\nApartma Matevž";
    } else {
        $subject = 'Odpoved rezervacije'
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' || ($fromEu && $toEu) ? ' – ' : '')
                 . ($unit !== '' ? "{$unit} " : '')
                 . ($fromEu && $toEu ? "({$fromEu} – {$toEu})" : '');

        $hello   = 'Spoštovani';
        $line    = "Vaša rezervacija"
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' ? " za enoto {$unit}" : '')
                 . ($fromEu && $toEu ? " za termin {$fromEu} – {$toEu}" : '')
                 . ' je bila odpovedana.';
        $reasonLabel = 'Razlog odpovedi';
        $footer = "Če imate kakršnakoli vprašanja, nas prosim kontaktirajte.\n\nLep pozdrav,\nApartma Matevž";
    }

    $safe = function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">';
    $html .= '<p>' . $safe($hello) . '.</p>';
    $html .= '<p>' . $safe($line) . '</p>';
    if ($reason !== '') {
        $html .= '<p><b>' . $safe($reasonLabel) . ':</b> ' . $safe($reason) . '</p>';
    }
    $html .= '<p>' . nl2br($safe($footer)) . '</p>';
    $html .= '</div>';

    $text  = $hello . ".\n\n" . $line . "\n\n";
    if ($reason !== '') {
        $text .= $reasonLabel . ': ' . $reason . "\n\n";
    }
    $text .= $footer . "\n";

    cm_send_email([
        'to'      => $guestEmail,
        'from'    => $fromEmail,
        'fromName'=> $fromName,
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
        'bcc'     => $adminEmail ?: null,
    ]);
}


/**
 * Write a copy of the cancelled reservation into /cancellations/YYYY/UNIT/ID.json
 * (this is used as archive; original file will be removed in the POST handler).
 */
function cm_write_cancellation_copy(string $cancRoot, array $res, string $tz): void {
    $id   = $res['id']   ?? '';
    $unit = $res['unit'] ?? '';

    if ($id === '' || $unit === '') {
        return;
    }

    // extract year from check-in date
    $year = 'unknown';
    if (!empty($res['from']) && preg_match('/^(\d{4})-/', (string)$res['from'], $m)) {
        $year = $m[1];
    }

    $dir  = rtrim($cancRoot, '/') . '/' . $year . '/' . $unit;
    $file = $dir . '/' . $id . '.json';

    cm_mkdir_p($dir);

    $copy          = $res;
    unset($copy['_file']);
    $copy['status']        = 'cancelled';
    $copy['cancelled_at']  = $copy['cancelled_at'] ?? (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('c');
    $copy['cancel_source'] = $copy['cancel_source'] ?? 'guest_link';

    @file_put_contents($file, json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Remove all occupancy rows with the given reservation id for a unit
 * and regenerate occupancy_merged.json if supported.
 * This helper is local to cancel_reservation.php to avoid name clashes.
 */
function cm_cancel_remove_occupancy_by_id(string $unitsRoot, string $unit, string $reservationId): void {
    $unit = trim($unit);
    $reservationId = trim($reservationId);
    if ($unit === '' || $reservationId === '') {
        return;
    }

    $path = rtrim($unitsRoot, '/') . '/' . $unit . '/occupancy.json';
    if (!is_file($path)) {
        // Nothing to clean
        return;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return;
    }

    $changed = false;
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            $out[] = $row;
            continue;
        }
        if (($row['id'] ?? null) === $reservationId) {
            // Skip this row – it belongs to the cancelled reservation
            $changed = true;
            continue;
        }
        $out[] = $row;
    }

    if ($changed) {
        @file_put_contents(
            $path,
            json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    // Always try to regenerate merged if helper exists
    if (function_exists('cm_regen_merged_for_unit')) {
        try {
            cm_regen_merged_for_unit($unitsRoot, $unit);
        } catch (Throwable $e) {
            error_log("[cancel_reservation] exception in cm_regen_merged_for_unit for unit={$unit}: " . $e->getMessage());
        }
    }
}

/**
 * Try to regenerate occupancy_merged for the unit using global helper.
 */
function cm_try_regen_merged_for_unit(string $rootUnits, string $unit): void {
    if (!function_exists('cm_regen_merged_for_unit')) {
        return;
    }
    try {
        $r = cm_regen_merged_for_unit($rootUnits, $unit);
        if ($r === false) {
            error_log("[cancel_reservation] cm_regen_merged_for_unit FAILED for unit={$unit}");
        }
    } catch (Throwable $e) {
        error_log("[cancel_reservation] exception in cm_regen_merged_for_unit for unit={$unit}: " . $e->getMessage());
    }
}

/* ----------------------------------------------------------------------
 *  POST: AJAX – perform cancellation
 * ---------------------------------------------------------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token  = trim($_POST['token']  ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($token === '') {
        cm_cancel_respond(false, ['error' => 'missing_token'], 400);
    }

    $res = cm_find_reservation_by_cancel_token($RES, $token);
    if (!$res) {
        cm_cancel_respond(false, ['error' => 'not_found'], 404);
    }

    $source = strtolower((string)($res['source'] ?? ''));
    if ($source !== 'direct') {
        // Only direct bookings can be cancelled via this public link
        cm_cancel_respond(false, ['error' => 'not_cancelable_source'], 403);
    }

    // If already cancelled, be idempotent
    if (($res['status'] ?? '') === 'cancelled') {
        cm_cancel_respond(true, ['already_cancelled' => true]);
    }

    $unit    = (string)($res['unit'] ?? '');
    $resFile = $res['_file'] ?? null;
    $resId   = (string)($res['id'] ?? '');

    // Update reservation: mark as cancelled
    $res['status']        = 'cancelled';
    $res['stage']         = 'cancelled_guest';
    $res['cancelled_at']  = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('c');
    $res['cancel_source'] = 'guest_link';
    if ($reason !== '') {
        $res['cancel_reason_guest'] = $reason;
    }

    // Write a copy into /cancellations tree (archive)
    cm_write_cancellation_copy($CANC, $res, $tz);

    // Log cancellation
    cm_log_cancellation($LOG, $res, $reason, $tz);

    // Remove occupancy rows for this reservation and regenerate merged file
    $unitsRoot = $UNITS;
    if ($unit !== '' && $resId !== '') {
        cm_cancel_remove_occupancy_by_id($unitsRoot, $unit, $resId);
    } else {
        // Fallback: at least try to regenerate merged
        if (function_exists('cm_regen_merged_for_unit') && $unit !== '') {
            try {
                cm_regen_merged_for_unit($unitsRoot, $unit);
            } catch (Throwable $e) {
                error_log("[cancel_reservation] fallback regen exception for unit={$unit}: " . $e->getMessage());
            }
        }
    }

    // Remove original reservations file so live tree contains only active reservations
    if ($resFile && is_file($resFile)) {
        @unlink($resFile);
    }

    // Send cancellation notification e-mail (simple ID + dates, no pricing)
    cm_send_cancellation_email($res, $reason);

    cm_cancel_respond(true, ['cancelled' => true]);
}

/* ----------------------------------------------------------------------
 *  GET: render HTML page
 * ---------------------------------------------------------------------- */

$token = trim($_GET['token'] ?? '');
$res   = $token !== '' ? cm_find_reservation_by_cancel_token($RES, $token) : null;

// Determine language: try reservation lang/meta.lang, allow ?lang override, fallback sl, but text helper falls back to EN
$lang = 'sl';
if ($res) {
    $lang = (string)($res['lang'] ?? ($res['meta']['lang'] ?? 'sl'));
}
$langParam = isset($_GET['lang']) ? strtolower((string)$_GET['lang']) : '';
if ($langParam !== '') {
    $lang = $langParam;
}
if (!in_array($lang, ['sl','en'], true)) {
    $lang = 'sl';
}

$isDirect    = $res ? (strtolower((string)($res['source'] ?? '')) === 'direct') : false;
$isCancelled = $res ? (($res['status'] ?? '') === 'cancelled') : false;

$from = $res ? cm_fmt_eu_date($res['from'] ?? null) : '';
$to   = $res ? cm_fmt_eu_date($res['to']   ?? null) : '';

$guestName = '';
if ($res) {
    if (isset($res['guest']['name'])) {
        $guestName = (string)$res['guest']['name'];
    } elseif (isset($res['guest_name'])) {
        $guestName = (string)$res['guest_name'];
    }
}

// Guests summary
$groupStr = '';
if ($res) {
    $adults  = (int)($res['adults']  ?? 0);
    $kids06  = (int)($res['kids06']  ?? 0);
    $kids712 = (int)($res['kids712'] ?? 0);
    $parts = [];
    if ($adults > 0) {
        $parts[] = $adults . ' ' . ($lang === 'sl' ? 'odrasli' : 'adults');
    }
    if ($kids712 > 0) {
        $parts[] = $kids712 . ' ' . ($lang === 'sl' ? 'otroci 7–12' : 'children 7–12');
    }
    if ($kids06 > 0) {
        $parts[] = $kids06 . ' ' . ($lang === 'sl' ? 'otroci 0–6' : 'children 0–6');
    }
    $groupStr = implode(', ', $parts);
}

// Price data – same idea as other public pages
$calc = is_array($res['calc'] ?? null) ? $res['calc'] : [];
$final  = (float)($calc['final'] ?? 0);
$finalStr = $final > 0 ? cm_fmt_eur($final) : '';

$ttTotal = 0.0;
if (isset($res['tt']) && is_array($res['tt'])) {
    $ttTotal = (float)($res['tt']['total'] ?? 0);
}
$ttStr = $ttTotal > 0
    ? cm_fmt_eur($ttTotal)
    : cm_cancel_text('tt_fallback', $lang);

// Basic HTML output (no JSON here)
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(cm_cancel_text('title', $lang)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      padding: 0;
      background: #f4f5f7;
      color: #222;
    }
    .wrap {
      max-width: 640px;
      margin: 24px auto;
      padding: 0 12px 24px;
    }
    .title {
      font-size: 1.6rem;
      margin-bottom: 16px;
      text-align: center;
    }
    .card {
      background: #fff;
      border-radius: 8px;
      padding: 16px 18px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.06);
      margin-bottom: 16px;
    }
    .row {
      margin: 4px 0;
    }
    .row b {
      display: inline-block;
      width: 48%;
      max-width: 220px;
    }
    .warn {
      color: #b00020;
      font-weight: 600;
    }
    .muted {
      color: #666;
      font-size: 0.9rem;
    }
    textarea {
      width: 100%;
      min-height: 72px;
      resize: vertical;
      padding: 8px;
      box-sizing: border-box;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-family: inherit;
      font-size: 0.95rem;
    }
    .btn {
      margin-top: 12px;
      padding: 10px 18px;
      background: #c0392b;
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 1rem;
    }
    .btn:disabled {
      opacity: 0.6;
      cursor: default;
    }
    @media (max-width: 480px) {
      .row b {
        width: auto;
        display: block;
        margin-bottom: 2px;
      }
    }
  </style>
</head>
<body>
<div class="wrap">
  <h1 class="title"><?= h(cm_cancel_text('title', $lang)) ?></h1>

  <?php if (!$token || !$res): ?>
    <div class="card">
      <div class="warn"><?= h(cm_cancel_text('error_invalid', $lang)) ?></div>
    </div>

  <?php elseif (!$isDirect): ?>
    <div class="card">
      <div class="warn"><?= h(cm_cancel_text('error_not_direct', $lang)) ?></div>
    </div>

  <?php elseif ($isCancelled): ?>
    <div class="card">
      <div class="warn"><?= h(cm_cancel_text('already_cancelled_title', $lang)) ?></div>
      <div class="muted" style="margin-top:6px;">
        <?= cm_cancel_text('already_cancelled_body', $lang) ?>
      </div>
    </div>

  <?php else: ?>
    <div class="card">
      <h2><?= h(cm_cancel_text('summary_title', $lang)) ?></h2>
      <?php if ($guestName !== ''): ?>
        <div class="row"><b><?= h(cm_cancel_text('label_guest', $lang)) ?></b> <?= h($guestName) ?></div>
      <?php endif; ?>
      <div class="row"><b><?= h(cm_cancel_text('label_unit', $lang)) ?></b> <?= h((string)($res['unit'] ?? '')) ?></div>
      <?php if ($from && $to): ?>
        <div class="row"><b><?= h(cm_cancel_text('label_dates', $lang)) ?></b> <?= h($from) ?> → <?= h($to) ?></div>
      <?php endif; ?>
      <?php if ($groupStr !== ''): ?>
        <div class="row"><b><?= h(cm_cancel_text('label_group', $lang)) ?></b> <?= h($groupStr) ?></div>
      <?php endif; ?>
      <?php if ($finalStr !== ''): ?>
        <div class="row"><b><?= h(cm_cancel_text('label_price_without_tt', $lang)) ?></b> <?= h($finalStr) ?></div>
      <?php endif; ?>
      <div class="row"><b><?= h(cm_cancel_text('label_tt', $lang)) ?></b> <?= h($ttStr) ?></div>
    </div>

    <div class="card">
      <form id="cancelForm" onsubmit="return submitCancel(event);">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <label for="reason"><?= h(cm_cancel_text('reason_label', $lang)) ?></label>
        <textarea id="reason" name="reason"
          placeholder="<?= h(cm_cancel_text('reason_placeholder', $lang)) ?>"></textarea>

        <div class="muted" style="margin-top:8px;">
          <?= cm_cancel_text('info_irreversible', $lang) ?><br>
          <?= cm_cancel_text('info_contact_host', $lang) ?>
        </div>

        <button type="submit" class="btn"><?= h(cm_cancel_text('button_cancel', $lang)) ?></button>
      </form>
    </div>

    <div id="resultBox" class="card" style="display:none;"></div>
  <?php endif; ?>
</div>

<script>
const CM_TXT_SENDING          = <?= json_encode(cm_cancel_text('js_sending', $lang)) ?>;
const CM_TXT_CANCEL_OK        = <?= json_encode(cm_cancel_text('js_cancel_ok', $lang)) ?>;
const CM_TXT_CANCEL_OK_ALREADY= <?= json_encode(cm_cancel_text('js_cancel_ok_already', $lang)) ?>;
const CM_TXT_CANCEL_FAILED    = <?= json_encode(cm_cancel_text('js_cancel_failed', $lang)) ?>;
const CM_TXT_NETWORK_ERROR    = <?= json_encode(cm_cancel_text('js_network_error', $lang)) ?>;
const CM_TXT_BUTTON_CANCELLED = <?= json_encode(cm_cancel_text('js_button_cancelled', $lang)) ?>;

async function submitCancel(ev){
  ev.preventDefault();
  const form = document.getElementById('cancelForm');
  const box  = document.getElementById('resultBox');
  const btn  = form.querySelector('button[type="submit"]');
  const originalText = btn ? btn.textContent : null;

  const formData = new FormData(form);
  const body     = new URLSearchParams(formData).toString();

  box.style.display = 'none';
  box.innerHTML = '';

  if (btn) {
    btn.disabled = true;
    btn.textContent = CM_TXT_SENDING;
  }

  try {
    const resp = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
      },
      body
    });

    let payload = null;
    try {
      payload = await resp.json();
    } catch (e) {
      payload = null;
    }

    if (!resp.ok || !payload || payload.ok !== true) {
      const already = payload && payload.already_cancelled;
      box.style.display = 'block';
      box.innerHTML =
        '<span class="warn">' + (already ? CM_TXT_CANCEL_OK_ALREADY : CM_TXT_CANCEL_FAILED) + '</span>';
      return false;
    }

    box.style.display = 'block';
    box.innerHTML =
      '<span class="warn">' + CM_TXT_CANCEL_OK + '</span>';

    if (btn) {
      btn.disabled = true;
      btn.textContent = CM_TXT_BUTTON_CANCELLED;
    }
  } catch (e) {
    box.style.display = 'block';
    box.innerHTML =
      '<span class="warn">' + CM_TXT_NETWORK_ERROR + '</span>';
  }finally{
    if (btn && !btn.disabled && originalText !== null) {
      btn.textContent = originalText;
    }
  }

  return false;
}
</script>
</body>
</html>
