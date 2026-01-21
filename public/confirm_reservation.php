<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/confirm_reservation.php
 * Author: Viljem Dvojmoč, Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */
// /var/www/html/app/public/confirm_reservation.php
declare(strict_types=1);

require_once __DIR__ . '/../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../common/lib/site_settings.php';

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

/**
 * Global payment config – same model like in finalize_reservation.php
 */
$settingsAll = function_exists('cm_load_settings') ? cm_load_settings() : [];
$paymentCfg  = $settingsAll['payment'] ?? [];
$licenseCfg  = $settingsAll['license'] ?? [];

/**
 * Allowed payment methods from config.
 * Free version default is "at_desk".
 */
$methodsCfg = $paymentCfg['methods'] ?? ['at_desk'];
$ALLOWED_PAYMENT_METHODS = array_values(array_unique(
    array_map('strtolower', array_map('strval', $methodsCfg))
));
if (!$ALLOWED_PAYMENT_METHODS) {
    $ALLOWED_PAYMENT_METHODS = ['at_desk'];
}

// Normalize license.features like in finalize_reservation.php
$licenseFeatures = [];
if (isset($licenseCfg['features']) && is_array($licenseCfg['features'])) {
    $keys = array_keys($licenseCfg['features']);
    if ($keys && is_int($keys[0])) {
        $licenseFeatures = array_map('strval', $licenseCfg['features']);
    } else {
        foreach ($licenseCfg['features'] as $name => $enabled) {
            if ($enabled) {
                $licenseFeatures[] = (string)$name;
            }
        }
    }
}

$HAS_ADVANCED_PAYMENTS = in_array('advanced_payments', $licenseFeatures, true);

// Is SEPA allowed in this package (CM Plus)?
$HAS_SEPA = in_array('sepa', $ALLOWED_PAYMENT_METHODS, true) && $HAS_ADVANCED_PAYMENTS;



function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format YYYY-MM-DD -> DD.MM.YYYY (EU style).
 */
function cm_fmt_eu_date(string $d): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
}

/**
 * Simple localised text helper for confirm_reservation UI (sl/en, fallback EN→SL).
 */
function cm_confirm_text(string $key, string $lang): string {
    static $MAP = [
        'title' => [
            'sl' => 'Potrditev rezervacije',
            'en' => 'Reservation confirmation',
        ],
        'error_invalid' => [
            'sl' => 'Napaka: povezava ni veljavna ali rezervacije ni bilo mogoče najti.',
            'en' => 'Error: the link is not valid or the reservation could not be found.',
        ],
        'error_expired_title' => [
            'sl' => 'Žal je veljavnost povezave potekla.',
            'en' => 'Unfortunately, this confirmation link has expired.',
        ],
        'error_expired_body' => [
            'sl' => 'Rezervacija ni bila potrjena v predvidenem času. Prosimo, pošljite novo povpraševanje ali nas kontaktirajte neposredno.',
            'en' => 'The reservation was not confirmed in time. Please send us a new inquiry or contact us directly.',
        ],
        'summary_title' => [
            'sl' => 'Pregled podatkov',
            'en' => 'Summary of your stay',
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
            'sl' => 'Turistična taksa (plačilo pri občini):',
            'en' => 'Tourist tax (payable to the municipality):',
        ],
        'label_payment_method' => [
            'sl' => 'Način plačila',
            'en' => 'Payment method',
        ],
        'opt_choose' => [
            'sl' => '— izberite —',
            'en' => '— please choose —',
        ],
        'opt_sepa_disabled' => [
            'sl' => 'SEPA plačilo (na voljo v CM Plus)',
            'en' => 'SEPA payment (available in CM Plus)',
        ],

        'opt_sepa' => [
            'sl' => 'Bančno nakazilo (SEPA)',
            'en' => 'SEPA bank transfer',
        ],

        'opt_at_desk' => [
            'sl' => 'Plačilo ob prihodu (na recepciji)',
            'en' => 'Pay on arrival (at reception)',
        ],
        'payment_info_has_sepa' => [
            'sl' => 'SEPA: plačilo z bančnim nakazilom po navodilih v PDF potrdilu. Plačilo ob prihodu: znesek poravnate ob check-inu.',
            'en' => 'SEPA: pay by bank transfer using the instructions in the PDF confirmation. Pay on arrival: you settle the balance at check-in.',
        ],
        'payment_info_free_only' => [
            'sl' => 'Plačilo z nakazilom (SEPA + bonusi za zgodnje plačilo) je na voljo v različici <b>CM Plus</b>. V Free različici je na voljo plačilo ob prihodu.',
            'en' => 'Payment by bank transfer (SEPA + early-payment bonuses) is available in the <b>CM Plus</b> edition. In the Free edition only pay-on-arrival is available.',
        ],
        'button_confirm' => [
            'sl' => 'Potrdi rezervacijo',
            'en' => 'Confirm reservation',
        ],
        'hardlock_note' => [
            'sl' => 'S klikom se rezervacija spremeni v <b>hard-lock</b> in potrditveno e-pošto (s PDF prilogo) prejmete na vpisani e-mail naslov.',
            'en' => 'By clicking you turn this into a <b>hard-lock</b> reservation and you will receive a confirmation email (with PDF attachment) to your email address.',
        ],
        'label_sms_code' => [
            'sl' => 'SMS koda za potrditev:',
            'en' => 'SMS confirmation code:',
        ],
        'hint_sms_code' => [
            'sl' => 'Kodo boste prejeli v SMS sporočilu od ponudnika nastanitve.',
            'en' => 'You will received this code via SMS from the accommodation provider.',
        ],

        // JS messages
        'js_sending' => [
            'sl' => 'Pošiljanje ...',
            'en' => 'Sending ...',
        ],
        'js_confirm_failed' => [
            'sl' => 'Rezervacije ni bilo mogoče potrditi.',
            'en' => 'The reservation could not be confirmed.',
        ],
        'js_confirm_ok_title' => [
            'sl' => 'Rezervacija je bila uspešno potrjena.',
            'en' => 'The reservation has been confirmed.',
        ],
        'js_confirm_ok_body' => [
            'sl' => 'Potrditveno e-pošto z vsemi podatki in PDF prilogo boste prejeli na svoj e-poštni naslov.',
            'en' => 'You will receive a confirmation email with all details and the PDF attachment to your email address.',
        ],
        'js_button_confirmed' => [
            'sl' => 'Potrjeno',
            'en' => 'Confirmed',
        ],
        'js_network_error' => [
            'sl' => 'Napaka: ni se uspelo povezati s strežnikom.',
            'en' => 'Error: could not connect to the server.',
        ],
    ];

    // if key not exist → return key (that dev get quick spot to missing translat)
    if (!isset($MAP[$key])) {
        return $key;
    }

    $entry = $MAP[$key];

    // 1) 1st ty choosen language (could be else in future)
    if (isset($entry[$lang])) {
        return $entry[$lang];
    }

    // 2) global fallback → EN
    if (isset($entry['en'])) {
        return $entry['en'];
    }

    // 3) last fallback → SL (“home” version)
    if (isset($entry['sl'])) {
        return $entry['sl'];
    }

    // 4) if all wrong → return key
    return $key;
}

/**
 * Find accepted_soft_hold inquiry by token
 * (new strukture: /inquiries/YYYY/MM/accepted/ID.json,
 *  status = "accepted", stage = "accepted_soft_hold")
 */
function find_accepted_by_token(string $root, string $token): ?array {
    if ($token === '') return null;
    $files = glob(rtrim($root,'/') . "/*/*/accepted/*.json", GLOB_NOSORT) ?: [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $d   = json_decode($raw, true);
        if (!is_array($d)) continue;
        if (($d['secure_token'] ?? '') !== $token) continue;
        if (($d['status'] ?? '') !== 'accepted') continue;
        if (($d['stage']  ?? '') !== 'accepted_soft_hold') continue;
        $d['_file'] = $f;
        return $d;
    }
    return null;
}

/**
 * check token expireance (if token_expires_at set).
 */
function is_token_expired(array $inq, string $tz): bool {
    $expires = $inq['token_expires_at'] ?? '';
    if ($expires === '') return false;

    try {
        $dt = new DateTimeImmutable($expires);
    } catch (Throwable $e) {
        return false;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($tz));
    return $dt < $now;
}

/**
 * Compute simple SMS verification code from inquiry id.
 *
 * Expected id format: "YYYYMMDDHHMMSS-rand-UNIT"
 * We take the "HHMMSS" part (last 6 digits of the 14-digit timestamp).
 * Example:
 *   id = "20260106112701-7b09-S1"
 *   timestamp = "20260106112701"
 *   code = "112701"
 */
function cm_confirm_sms_code_from_id(string $id): string {
    if (!preg_match('/^(\d{14})-/', $id, $m)) {
        return '';
    }
    $ts = $m[1]; // YmdHis
    return substr($ts, 8, 6); // His
}


/**
 * Helper: format EUR
 */
function cm_fmt_eur(float $amount): string {
    return number_format($amount, 2, ',', '.') . ' €';
}

/**
 * Minimal helper for HTTP POST call on finalize_reservation.php
 * (in same foder /public).
 */
function cm_call_finalize(string $baseUrl, array $payload): array {
// $baseUrl is usually ".../app/public" – normalize to ".../app" and call admin/api finalize endpoint.
$root = rtrim($baseUrl, '/');
$root = preg_replace('#/public$#', '', $root);
$url  = $root . '/admin/api/finalize_reservation.php';


    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ]
    ];
    $ctx  = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
    }

    if ($resp === false) {
        return ['ok'=>false,'error'=>'no_response','http_code'=>$code];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok'=>false,'error'=>'invalid_json','http_code'=>$code,'raw'=>$resp];
    }

    $data['_http_code'] = $code;
    return $data;
}

/* --------------------------------------------------------------------
 *  POST: AJAX submit (finalize reservation)
 * -------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $tokenPost = trim($_POST['token'] ?? '');
    $payment   = strtolower(trim($_POST['payment_method'] ?? ''));
    $idPost    = trim($_POST['id'] ?? '');
    $smsCode  = trim($_POST['sms_code'] ?? '');


    if ($tokenPost === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_token']);
        exit;
    }

    if ($idPost === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_id']);
        exit;
    }

    // 🔐 Simple SMS code check based on inquiry id (HHMMSS of timestamp part)
    $expectedCode = cm_confirm_sms_code_from_id($idPost);   // 👈 uporabi helper
    if ($expectedCode !== '') {
        if ($smsCode === '') {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'missing_sms_code']);
            exit;
        }
        if ($smsCode !== $expectedCode) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'invalid_sms_code']);
            exit;
        }
    }

    // is payment metod alloverd in this instance
    if ($payment === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_payment_method']);
        exit;
    }

    if (!in_array($payment, $ALLOWED_PAYMENT_METHODS, true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'payment_method_not_allowed']);
        exit;
    }

    // CM Free: if SEPA disabled, preventive blocking
    if ($payment === 'sepa' && !$HAS_SEPA) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'sepa_not_available_in_free']);
        exit;
    }

    // Call on finalize_reservation.php (same logic)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

    $res = cm_call_finalize($baseUrl, [
        'token'          => $tokenPost,
        'id'             => $idPost,
        'payment_method' => $payment,
    ]);

    $ok = (bool)($res['ok'] ?? false);
    if (!$ok) {
        $code = (int)($res['_http_code'] ?? 500);
        if ($code < 400) $code = 500;
        http_response_code($code);
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/* --------------------------------------------------------------------
 *  GET: render HTML for confirm
 * -------------------------------------------------------------------- */

$ROOT  = realpath(__DIR__ . '/../common/data/json') ?: __DIR__ . '/../common/data/json';
$INQ   = $ROOT . '/inquiries';

$token = trim($_GET['token'] ?? '');
$acc   = $token ? find_accepted_by_token($INQ, $token) : null;
$expired = $acc ? is_token_expired($acc, $tz) : false;



// language: try inquiry / reservation language, allow ?lang override, fallback to sl
$lang = 'sl';
if ($acc) {
    $lang = (string)($acc['lang'] ?? ($acc['meta']['lang'] ?? 'sl'));
}
$langParam = isset($_GET['lang']) ? strtolower((string)$_GET['lang']) : '';
if ($langParam !== '' && in_array($langParam, ['sl','en'], true)) {
    $lang = $langParam;
}
if (!in_array($lang, ['sl','en'], true)) {
    $lang = 'sl';
}

$guestName = '';
if (isset($acc['guest']) && is_array($acc['guest'])) {
    $guestName = (string)($acc['guest']['name'] ?? '');
}
if ($guestName === '' && isset($acc['guest_name'])) {
    $guestName = (string)$acc['guest_name'];
}
if ($guestName === '' && isset($acc['name'])) {
    $guestName = (string)$acc['name'];
}

$unit      = $acc['unit']        ?? '';
$fromRaw = (string)($acc['from'] ?? '');
$toRaw   = (string)($acc['to']   ?? '');
$from    = cm_fmt_eu_date($fromRaw);
$to      = cm_fmt_eu_date($toRaw);

// group sizes
$adults  = (int)($acc['adults'] ?? 0);
$kids06  = (int)($acc['kids06'] ?? 0);
$kids712 = (int)($acc['kids712'] ?? 0);
$groupStr = "{$adults} + {$kids06} (0–6) + {$kids712} (7–12)";

// price breakdown (accommodation only, without TT)
$calc = is_array($acc['calc'] ?? null) ? $acc['calc'] : [];
$base      = (float)($calc['base']      ?? 0);
$discounts = (float)($calc['discounts'] ?? 0);
$promo     = (float)($calc['promo']     ?? 0);
$special   = (float)($calc['special_offers'] ?? 0);
$cleaning  = (float)($calc['cleaning']  ?? 0);
$final     = (float)($calc['final']     ?? 0);

$totalStr  = $final > 0 ? cm_fmt_eur($final) : '';

// tourist tax (from inquiry TT block), pay at municipality
$ttTotal = 0.0;
if (isset($acc['tt']) && is_array($acc['tt'])) {
    $ttTotal = (float)($acc['tt']['total'] ?? 0);
}
$ttStr = $ttTotal > 0 ? cm_fmt_eur($ttTotal) : 'po veljavnem ceniku občine';


?><!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(cm_confirm_text('title', $lang)) ?></title>
  <style>
    body{
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:#f6f7fb;
      margin:0;
      color:#111827;
    }
    .wrap{
      max-width:720px;
      margin:40px auto;
      padding:0 16px 40px;
    }
    .title{
      text-align:center;
      font-size:26px;
      margin:0 0 20px;
    }
    .card{
      background:#fff;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(15,23,42,0.12);
      padding:20px 20px 22px;
      margin-bottom:16px;
    }
    .card h2{
      font-size:17px;
      margin:0 0 12px;
    }
    .row{
      margin:4px 0;
      font-size:15px;
    }
    .row b{
      font-weight:600;
    }
    .warn{
      color:#b91c1c;
      font-size:15px;
    }
    .muted{
      color:#6b7280;
      font-size:14px;
      line-height:1.5;
    }
    .btn{
      display:inline-block;
      background:#2563eb;
      color:#fff;
      border:none;
      border-radius:999px;
      padding:9px 20px;
      font-size:15px;
      font-weight:500;
      cursor:pointer;
    }
    .btn:disabled{
      opacity:0.6;
      cursor:default;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1 class="title"><?= h(cm_confirm_text('title', $lang)) ?></h1>

    <?php if (!$token || !$acc): ?>
      <div class="card">
        <div class="warn"><?= h(cm_confirm_text('error_invalid', $lang)) ?></div>
      </div>
    <?php elseif ($expired): ?>
      <div class="card">
        <div class="warn"><b><?= h(cm_confirm_text('error_expired_title', $lang)) ?></b><br>
        <?= h(cm_confirm_text('error_expired_body', $lang)) ?></div>
      </div>
    <?php else: ?>
      <div class="card">
        <h2><?= h(cm_confirm_text('summary_title', $lang)) ?></h2>
        <div class="row"><b><?= h(cm_confirm_text('label_guest', $lang)) ?></b> <?=h($guestName)?></div>
        <div class="row"><b><?= h(cm_confirm_text('label_unit', $lang)) ?></b> <?=h($unit)?></div>
        <div class="row"><b><?= h(cm_confirm_text('label_dates', $lang)) ?></b> <?=h($from)?> → <?=h($to)?></div>
        <div class="row"><b><?= h(cm_confirm_text('label_group', $lang)) ?></b> <?=h($groupStr)?></div>
        <?php if ($totalStr !== ''): ?>
          <div class="row"><b><?= h(cm_confirm_text('label_price_without_tt', $lang)) ?></b> <?=h($totalStr)?></div>
        <?php endif; ?>
        <div class="row"><b><?= h(cm_confirm_text('label_tt', $lang)) ?></b> <?=h($ttStr)?></div>
      </div>

      <div class="card">
<form id="confirmForm" onsubmit="return submitFinalize(event);">
  <input type="hidden" name="token" value="<?=h($token)?>">
  <input type="hidden" name="id"    value="<?=h($acc['id'] ?? '')?>">
  <label for="pm"><?= h(cm_confirm_text('label_payment_method', $lang)) ?></label>
  <select id="pm" name="payment_method" required>
    <option value=""><?= h(cm_confirm_text('opt_choose', $lang)) ?></option>

    <?php if ($HAS_SEPA): ?>
      <option value="sepa"><?= h(cm_confirm_text('opt_sepa', $lang)) ?></option>
    <?php else: ?>
      <option value="sepa" disabled><?= h(cm_confirm_text('opt_sepa_disabled', $lang)) ?></option>
    <?php endif; ?>

    <option value="at_desk" selected><?= h(cm_confirm_text('opt_at_desk', $lang)) ?></option>
  </select>



<?php if ($HAS_SEPA): ?>
  <div class="muted" style="margin-top:8px;">
    <?= cm_confirm_text('payment_info_has_sepa', $lang) ?>
  </div>
<?php else: ?>
  <div class="muted" style="margin-top:8px;">
    <?= cm_confirm_text('payment_info_free_only', $lang) ?>
  </div>
<?php endif; ?>

  <div style="margin-top:16px;">
    <label for="smsCode"><?= h(cm_confirm_text('label_sms_code', $lang)) ?></label>
    <input
      type="text"
      id="smsCode"
      name="sms_code"
      inputmode="numeric"
      pattern="[0-9]{4,8}"
      maxlength="8"
      required
      style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;"
    >
    <div class="muted" style="margin-top:4px;font-size:0.9em;">
      <?= h(cm_confirm_text('hint_sms_code', $lang)) ?>
    </div>
  </div>


<div style="margin-top:12px;">
  <button type="submit" class="btn"><?= h(cm_confirm_text('button_confirm', $lang)) ?></button>
</div>
<div class="muted" style="margin-top:8px;">
  <?= cm_confirm_text('hardlock_note', $lang) ?>
</div>

        </form>
      </div>

      <div id="resultBox" class="card" style="display:none;"></div>
    <?php endif; ?>
  </div>

<script>
const CM_TXT_SENDING         = <?= json_encode(cm_confirm_text('js_sending', $lang)) ?>;
const CM_TXT_CONFIRM_FAILED  = <?= json_encode(cm_confirm_text('js_confirm_failed', $lang)) ?>;
const CM_TXT_CONFIRM_OK_TITLE = <?= json_encode(cm_confirm_text('js_confirm_ok_title', $lang)) ?>;
const CM_TXT_CONFIRM_OK_BODY = <?= json_encode(cm_confirm_text('js_confirm_ok_body', $lang)) ?>;
const CM_TXT_BUTTON_CONFIRMED = <?= json_encode(cm_confirm_text('js_button_confirmed', $lang)) ?>;
const CM_TXT_NETWORK_ERROR   = <?= json_encode(cm_confirm_text('js_network_error', $lang)) ?>;

async function submitFinalize(ev) {
  ev.preventDefault();
  const form = document.getElementById('confirmForm');
  const box  = document.getElementById('resultBox');
  const btn  = form.querySelector('button[type="submit"]');
  const originalText = btn ? btn.textContent : null;
  let success = false; // 👈 NEW  

  const formData = new FormData(form);
  const payload  = new URLSearchParams(formData).toString();

  box.style.display = 'none';
  box.innerHTML = '';

  if (btn) {
    btn.disabled = true;
    btn.textContent = CM_TXT_SENDING;
  }

  try {
    const res = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
      },
      body: payload
    });

    const text = await res.text();
    let data = null;
    try {
      data = JSON.parse(text);
    } catch (e) {
      data = null;
    }

    box.style.display = 'block';

    if (!res.ok || !data || data.ok === false) {
      let msg = CM_TXT_CONFIRM_FAILED;
      if (data && data.error) {
        msg += ' (' + data.error + ')';
      }
      box.innerHTML = '<span class="warn">' + msg + '</span>';
      return false;
    }

    box.innerHTML =
      '<b>' + CM_TXT_CONFIRM_OK_TITLE + '</b><br>' +
      '<span class="muted">' + CM_TXT_CONFIRM_OK_BODY + '</span>';

    // ✅ success -> disable form completely
    success = true;
    if (btn) {
      btn.disabled = true;
      btn.textContent = CM_TXT_BUTTON_CONFIRMED;
    }
    form.querySelectorAll('input, select').forEach(el => el.disabled = true);

  } catch (e) {
    console.error(e);
    box.style.display = 'block';
    box.innerHTML =
      '<span class="warn">' + CM_TXT_NETWORK_ERROR + '</span>';
 } finally {
    // ✅ re-enable button ONLY if not successful
    if (btn && !success) {
      btn.disabled = false;
      if (originalText !== null) btn.textContent = originalText;
    }
  }

  return false;
}
</script>
</body>
</html>
