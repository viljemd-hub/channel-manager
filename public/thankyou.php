<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/thankyou.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/public/thankyou.php
declare(strict_types=1);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function h(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_str(string $k, string $default=''): string {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

function post_int(string $k, int $default=0): int {
  return isset($_POST[$k]) ? (int)$_POST[$k] : $default;
}

function post_float(string $k, float $default=0): float {
  return isset($_POST[$k]) ? (float)$_POST[$k] : $default;
}

function eur($v): string {
  if (!is_numeric($v)) return '—';
  return number_format((float)$v, 2, ',', '.') . ' €';
}

function jlog(string $line): void {
  $log = __DIR__ . '/../common/data/json/inquiries/logs/thankyou.log';
  @mkdir(dirname($log), 0775, true);
  @file_put_contents($log, '['.date('c')."] $line\n", FILE_APPEND);
}

/**
 * Format YYYY-MM-DD -> DD.MM.YYYY (EU style).
 */
function fmt_eu_date(string $d): string {
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
    return $m[3] . '.' . $m[2] . '.' . $m[1];
  }
  return $d;
}

// ---------------------------------------------------------------------
// PRG (Post/Redirect/Get) helpers
//   - Prevents duplicate inquiry e-mails when guest refreshes thankyou.php
//   - Ensures a repeated POST for the same inquiry_id redirects to the
//     original GET view instead of sending e-mails again.
// ---------------------------------------------------------------------

function ty_valid_token(string $t): bool {
  return (bool)preg_match('/^[a-f0-9]{32}$/', $t);
}

function ty_safe_id(string $id): string {
  // Keep file names safe and predictable.
  return preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
}

function ty_json_read(string $path): ?array {
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function ty_json_write(string $path, array $data): bool {
  @mkdir(dirname($path), 0775, true);
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function ty_redirect303(string $url): void {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Location: ' . $url, true, 303);
  exit;
}

function ty_error_page(int $code, string $lang = 'sl'): void {
  http_response_code($code);
  $msg = $lang === 'en'
    ? 'This link is invalid or has expired. Please return to the calendar.'
    : 'Povezava ni veljavna ali je potekla. Vrnite se na koledar.';
  $back = '/app/public/pubcal.php';
  echo "<!doctype html><html lang=\"".h($lang)."\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
  echo "<title>Thank you</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;background:#0f172a;color:#e5e7eb}.wrap{max-width:780px;margin:0 auto;padding:28px 16px}.card{background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 16px 14px;box-shadow:0 8px 24px rgba(0,0,0,.25)}.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:10px;background:#2563eb;color:white;text-decoration:none;font-weight:600}</style></head><body><div class=\"wrap\"><div class=\"card\">";
  echo "<h1>" . ($lang==='en'?'Thank you':'Hvala') . "</h1><p>" . h($msg) . "</p>";
  echo "<a class=\"btn\" href=\"".h($back)."\">" . ($lang==='en'?'Back to calendar':'Nazaj na koledar') . "</a>";
  echo "</div></div></body></html>";
  exit;
}

// ---------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------

$allowedLangs = ['sl','en'];

$T = [
  'sl' => [
    'title' => 'Hvala za povpraševanje',
    'h1' => 'Hvala!',
    'lead_pending' => 'Vaše povpraševanje je bilo uspešno oddano. Odgovorili vam bomo v najkrajšem možnem času.',
    'lead_auto' => 'Vaša rezervacija je bila potrjena samodejno. Preverite e-pošto za potrditev.',
    'summary' => 'Povzetek',
    'id' => 'ID',
    'unit' => 'Enota',
    'dates' => 'Termin',
    'nights' => 'Noči',
    'guests' => 'Gostje',
    'base' => 'Osnova',
    'discounts' => 'Popusti',
    'promo' => 'Promo',
    'cleaning' => 'Čiščenje',
    'total' => 'Skupaj',
    'message' => 'Sporočilo',
    'back' => 'Nazaj na koledar',
    'mail_ok' => 'E-pošta je bila poslana.',
    'mail_no' => 'E-pošta ni bila poslana (preveri nastavitve).',
  ],
  'en' => [
    'title' => 'Thank you',
    'h1' => 'Thank you!',
    'lead_pending' => 'Your inquiry has been submitted. We will get back to you as soon as possible.',
    'lead_auto' => 'Your reservation was auto-confirmed. Please check your email for confirmation.',
    'summary' => 'Summary',
    'id' => 'ID',
    'unit' => 'Unit',
    'dates' => 'Dates',
    'nights' => 'Nights',
    'guests' => 'Guests',
    'base' => 'Base',
    'discounts' => 'Discounts',
    'promo' => 'Promo',
    'cleaning' => 'Cleaning',
    'total' => 'Total',
    'message' => 'Message',
    'back' => 'Back to calendar',
    'mail_ok' => 'Email has been sent.',
    'mail_no' => 'Email was not sent (check settings).',
  ],
];

// ---------------------------------------------------------------------
// Common paths
// ---------------------------------------------------------------------

$TY_DIR     = __DIR__ . '/../common/data/json/inquiries/thankyou_views';
$TY_MAP_DIR = __DIR__ . '/../common/data/json/inquiries/thankyou_map';

// Always prevent caching (personal data on the page)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---------------------------------------------------------------------
// POST: process once, persist view, redirect to GET
// ---------------------------------------------------------------------

// Variables used by the template (initialized for both branches)
$lang = 'sl';
$skipThankyouGuestMail = false;
$sentGuest = false;
$sentAdmin = false;

$inquiryId = '';
$unit = '';
$from = '';
$to = '';
$nights = 0;
$from_eu = '';
$to_eu = '';

$guestMessage = '';
$adults = 0;
$kids06 = 0;
$kids712 = 0;

$calc_base = 0.0;
$calc_discounts = 0.0;
$calc_promo = 0.0;
$calc_cleaning = 0.0;
$calc_final = 0.0;

$promo_code = '';
$backHref = '/app/public/pubcal.php';

if ($method === 'POST') {
  // lang from POST/GET
  $lang = post_str('lang', '');
  if ($lang === '' && isset($_GET['lang'])) $lang = trim((string)$_GET['lang']);
  $lang = strtolower($lang);
  if (!in_array($lang, $allowedLangs, true)) $lang = 'sl';

  // input from POST (submit_inquiry -> thankyou)
  $inquiryId    = post_str('inquiry_id');
  $unit         = post_str('unit');
  $from         = post_str('from');
  $to           = post_str('to');
  $nights       = post_int('nights', 0);

  $from_eu = fmt_eu_date($from);
  $to_eu   = fmt_eu_date($to);

  $guestName    = post_str('guest_name');
  $guestEmail   = post_str('guest_email');
  $guestPhone   = post_str('guest_phone');
  $guestMessage = post_str('guest_message');

  $adults       = post_int('adults', 0);
  $kids06       = post_int('kids06', 0);
  $kids712      = post_int('kids712', 0);

  $calc_base      = post_float('calc_base', 0);
  $calc_discounts = post_float('calc_discounts', 0);
  $calc_promo     = post_float('calc_promo', 0);
  $calc_cleaning  = post_float('calc_cleaning', 0);
  $calc_final     = post_float('calc_final', 0);

  // optional (kept for mailData compatibility)
  $calc_tt            = post_float('calc_tt', 0);
  $keycard_count      = post_int('keycard_count', 0);
  $calc_keycard       = post_float('calc_keycard', 0);
  $calc_special_offers = post_float('calc_special_offers', 0);

  $promo_code = post_str('promo_code', '');
  $offers_txt = post_str('offers_txt', '');

  // Ensure dirs for PRG persistence
  @mkdir($TY_DIR, 0775, true);
  @mkdir($TY_MAP_DIR, 0775, true);

  // If this inquiry_id already has a token + stored view, redirect to it (idempotency)
  $inquiryIdSafe = ty_safe_id($inquiryId);
  if ($inquiryIdSafe !== '') {
    $mapPath = $TY_MAP_DIR . '/' . $inquiryIdSafe . '.json';
    $map = ty_json_read($mapPath);
    $prev = is_array($map) ? (string)($map['ty'] ?? '') : '';
    if ($prev !== '' && ty_valid_token($prev) && is_file($TY_DIR . '/' . $prev . '.json')) {
      jlog("THANKYOU_PRG duplicate POST -> redirect inquiry={$inquiryId} ty={$prev}");
      ty_redirect303('/app/public/thankyou.php?ty=' . $prev);
    }
  }

  // Site settings (email cfg)
  $siteSettingsPath = __DIR__ . '/../common/data/json/units/site_settings.json';
  $site = [];
  if (is_file($siteSettingsPath)) {
    $site = json_decode((string)file_get_contents($siteSettingsPath), true) ?: [];
  }
  $emailCfg     = $site['email'] ?? [];
  $emailEnabled = (bool)($emailCfg['enabled'] ?? false);
  $fromEmail    = (string)($emailCfg['from_email'] ?? '');
  $fromName     = (string)($emailCfg['from_name'] ?? 'Apartma');
  $adminEmail   = (string)($emailCfg['admin_email'] ?? '');

  // Autopilot trigger (CM Free: disabled)
  $skipThankyouGuestMail = false;
  jlog('AUTOPILOT disabled in thankyou.php (CM Free mode)');

  // Send mail (via api/sendmail_*.php)
  $sentGuest = false;
  $sentAdmin = false;

  if ($emailEnabled && $fromEmail) {
    require_once __DIR__ . '/api/sendmail_guest.php';
    require_once __DIR__ . '/api/sendmail_admin.php';

    $mailData = [
      'lang'        => $lang,
      'inquiry_id'  => $inquiryId,
      'unit'        => $unit,
      'fromDate'    => $from,
      'toDate'      => $to,
      'nights'      => $nights,

      'name'        => $guestName,
      'email'       => $guestEmail,
      'phone'       => $guestPhone,
      'note'        => $guestMessage,

      'adults'      => $adults,
      'kids06'      => $kids06,
      'kids712'     => $kids712,

      'calc_base'         => $calc_base,
      'calc_discounts'    => $calc_discounts,
      'calc_promo'        => $calc_promo,
      'calc_cleaning'     => $calc_cleaning,
      'calc_final'        => $calc_final,
      'calc_tt'           => $calc_tt,
      'keycard_count'     => $keycard_count,
      'calc_keycard'      => $calc_keycard,
      'calc_special_offers' => $calc_special_offers,

      'promo_code'         => $promo_code,
      'special_offers_txt' => $offers_txt,

      'from'        => $fromEmail,
      'from_name'   => $fromName,
      'admin_to'    => $adminEmail,
    ];

    // Guest mail (do not send if autopilot auto-accepted)
    if ($guestEmail !== '' && !$skipThankyouGuestMail && function_exists('sendmail_guest')) {
      $sentGuest = (bool)sendmail_guest($mailData);
    } else {
      if ($skipThankyouGuestMail) jlog("MAIL guest skipped (autopilot auto_accepted) inquiry={$inquiryId}");
    }

    // Admin mail
    if ($adminEmail !== '' && function_exists('sendmail_admin')) {
      $sentAdmin = (bool)sendmail_admin($mailData);
    }

  } else {
    jlog("MAIL disabled or fromEmail missing inquiry={$inquiryId}");
  }

  jlog("THANKYOU inquiry={$inquiryId} guest=" . ($sentGuest?'SENT':'NO') . " admin=" . ($sentAdmin?'SENT':'NO'));

  // Back link
  $backHref = '/app/public/pubcal.php';
  $q = http_build_query(['unit'=>$unit, 'from'=>$from, 'to'=>$to, 'lang'=>$lang]);
  if ($q) $backHref .= '?' . $q;

  // Persist view and redirect to GET
  $ty = bin2hex(random_bytes(16));
  $view = [
    'created_at' => date('c'),
    'lang' => $lang,

    'skipThankyouGuestMail' => $skipThankyouGuestMail,
    'sentGuest' => $sentGuest,
    'sentAdmin' => $sentAdmin,

    'inquiryId' => $inquiryId,
    'unit' => $unit,
    'from' => $from,
    'to' => $to,
    'nights' => $nights,

    'guestMessage' => $guestMessage,
    'adults' => $adults,
    'kids06' => $kids06,
    'kids712' => $kids712,

    'calc_base' => $calc_base,
    'calc_discounts' => $calc_discounts,
    'calc_promo' => $calc_promo,
    'calc_cleaning' => $calc_cleaning,
    'calc_final' => $calc_final,

    'promo_code' => $promo_code,

    'backHref' => $backHref,
  ];

  $viewPath = $TY_DIR . '/' . $ty . '.json';
  $okView = ty_json_write($viewPath, $view);
  if ($okView && ($inquiryIdSafe ?? '') !== '') {
    ty_json_write($TY_MAP_DIR . '/' . $inquiryIdSafe . '.json', [
      'created_at' => date('c'),
      'ty' => $ty,
    ]);
  }

  jlog("THANKYOU_PRG inquiry={$inquiryId} ty={$ty} view=" . ($okView?'OK':'FAIL'));

  if ($okView) {
    ty_redirect303('/app/public/thankyou.php?ty=' . $ty);
  }

  // If persistence fails for any reason, fall back to rendering inline (old behavior).
}

// ---------------------------------------------------------------------
// GET: load stored view by token
// ---------------------------------------------------------------------

if ($method !== 'POST') {
  $langGuess = '';
  if (isset($_GET['lang'])) $langGuess = strtolower(trim((string)$_GET['lang']));
  if (!in_array($langGuess, $allowedLangs, true)) $langGuess = 'sl';

  $ty = trim((string)($_GET['ty'] ?? ''));
  if ($ty === '' || !ty_valid_token($ty)) {
    ty_error_page(400, $langGuess);
  }

  $view = ty_json_read($TY_DIR . '/' . $ty . '.json');
  if (!is_array($view)) {
    ty_error_page(404, $langGuess);
  }

  $lang = strtolower((string)($view['lang'] ?? 'sl'));
  if (!in_array($lang, $allowedLangs, true)) $lang = 'sl';

  $skipThankyouGuestMail = (bool)($view['skipThankyouGuestMail'] ?? false);
  $sentGuest = (bool)($view['sentGuest'] ?? false);
  $sentAdmin = (bool)($view['sentAdmin'] ?? false);

  $inquiryId = (string)($view['inquiryId'] ?? '');
  $unit      = (string)($view['unit'] ?? '');
  $from      = (string)($view['from'] ?? '');
  $to        = (string)($view['to'] ?? '');
  $nights    = (int)($view['nights'] ?? 0);

  $from_eu = fmt_eu_date($from);
  $to_eu   = fmt_eu_date($to);

  $guestMessage = (string)($view['guestMessage'] ?? '');
  $adults  = (int)($view['adults'] ?? 0);
  $kids06  = (int)($view['kids06'] ?? 0);
  $kids712 = (int)($view['kids712'] ?? 0);

  $calc_base      = (float)($view['calc_base'] ?? 0);
  $calc_discounts = (float)($view['calc_discounts'] ?? 0);
  $calc_promo     = (float)($view['calc_promo'] ?? 0);
  $calc_cleaning  = (float)($view['calc_cleaning'] ?? 0);
  $calc_final     = (float)($view['calc_final'] ?? 0);

  $promo_code = (string)($view['promo_code'] ?? '');
  $backHref   = (string)($view['backHref'] ?? '/app/public/pubcal.php');

  // Log each GET view (PRG: safe refresh should hit only GET)
  // Keep it lightweight and non-invasive.
  jlog(sprintf(
    'THANKYOU_VIEW ty=%s inquiry=%s unit=%s from=%s to=%s',
    $ty, $inquiryId, $unit, $from, $to
  ));
}

// Translation closure (must be defined AFTER $lang is final)
$t = function(string $k) use (&$T, &$lang): string {
  return $T[$lang][$k] ?? $k;
};

?><!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <title><?=h($t('title'))?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#0f172a; color:#e5e7eb;}
    .wrap{max-width:780px;margin:0 auto;padding:28px 16px;}
    .card{background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 16px 14px;box-shadow:0 8px 24px rgba(0,0,0,.25);}
    h1{margin:0 0 8px 0;font-size:28px;}
    .lead{margin:0 0 14px 0;color:#cbd5e1}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.07);vertical-align:top}
    td:first-child{color:#93c5fd;white-space:nowrap;width:1%}
    .muted{color:#94a3b8;font-size:13px}
    .btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:10px;background:#2563eb;color:white;text-decoration:none;font-weight:600}
    .ok{color:#34d399}
    .no{color:#fbbf24}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1><?=h($t('h1'))?></h1>
      <p class="lead"><?=h($skipThankyouGuestMail ? $t('lead_auto') : $t('lead_pending'))?></p>

      <div class="grid">
        <div>
          <div class="muted" style="margin-bottom:6px;"><?=h($t('summary'))?></div>
          <table>
            <tr><td><?=h($t('id'))?></td><td><?=h($inquiryId)?></td></tr>
            <tr><td><?=h($t('unit'))?></td><td><?=h($unit)?></td></tr>
            <tr><td><?=h($t('dates'))?></td><td><?=h($from_eu)?> → <?=h($to_eu)?></td></tr>
            <tr><td><?=h($t('nights'))?></td><td><?=h((string)$nights)?></td></tr>
            <tr><td><?=h($t('guests'))?></td><td><?=h((string)$adults)?> + <?=h((string)$kids06)?> (0–6) + <?=h((string)$kids712)?> (7–12)</td></tr>
            <tr><td><?=h($t('base'))?></td><td><?=h(eur($calc_base))?></td></tr>
            <tr><td><?=h($t('discounts'))?></td><td><?=h(eur($calc_discounts))?></td></tr>
            <tr><td><?=h($t('promo'))?></td><td><?=h(eur($calc_promo))?><?=($promo_code!=='' ? ' <span class="muted">('.h($promo_code).')</span>' : '')?></td></tr>
            <tr><td><?=h($t('cleaning'))?></td><td><?=h(eur($calc_cleaning))?></td></tr>
            <tr><td><?=h($t('total'))?></td><td><b><?=h(eur($calc_final))?></b></td></tr>
            <?php if ($guestMessage !== ''): ?>
              <tr><td><?=h($t('message'))?></td><td><?=nl2br(h($guestMessage))?></td></tr>
            <?php endif; ?>
          </table>

          <div class="muted" style="margin-top:10px;">
            <span class="<?=($sentGuest||$sentAdmin)?'ok':'no'?>">
              <?=h(($sentGuest||$sentAdmin) ? $t('mail_ok') : $t('mail_no'))?>
            </span>
          </div>

          <a class="btn" href="<?=h($backHref)?>"><?=h($t('back'))?></a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
