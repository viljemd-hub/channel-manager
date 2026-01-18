<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/api/submit_inquiry.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/public/api/submit_inquiry.php
declare(strict_types=1);

// Minimal sanitize
function clean_email($v){ $v = trim((string)$v); return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : ''; }
function clean_str($v){ return trim((string)$v); }
function as_date($v){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : ''; }

// In
$unit       = clean_str($_POST['unit'] ?? '');
$from       = as_date($_POST['from'] ?? '');
$to         = as_date($_POST['to'] ?? '');
$nights     = (int)($_POST['nights'] ?? 0);

// lang (public UI)
$allowedLangs = ['sl','en'];
$lang = clean_str($_POST['lang'] ?? '');
if (!in_array($lang, $allowedLangs, true)) { $lang = 'sl'; }

$guestName    = clean_str($_POST['guest_name'] ?? '');
$guestEmail   = clean_email($_POST['guest_email'] ?? '');
$guestPhone   = clean_str($_POST['guest_phone'] ?? '');

if ($guestEmail === '') {
    http_response_code(400);
    echo "error: guest_email_required";
    exit;
}

$guestMessage = clean_str($_POST['guest_message']  ?? ($_POST['message'] ?? ''));

// finance (sprejemamo nova imena polj)
$calc_base           = (float)($_POST['calc_base']           ?? 0);
$calc_discounts      = (float)($_POST['calc_discounts']      ?? 0);
$calc_promo          = (float)($_POST['calc_promo']          ?? 0);
$calc_cleaning       = (float)($_POST['calc_cleaning']       ?? 0);
$calc_final          = (float)($_POST['calc_final']          ?? 0);
$calc_special_offers = (float)($_POST['calc_special_offers'] ?? 0);

// TT + keycard info iz offer.php
$calc_tt                = (float)($_POST['calc_tt'] ?? 0);
$keycard_count          = (int)($_POST['keycard_count'] ?? 0);
$calc_keycard           = (float)($_POST['calc_keycard'] ?? 0);
$calc_keycard_breakdown = clean_str($_POST['calc_keycard_breakdown'] ?? '');
$special_offer_name     = clean_str($_POST['special_offer_name'] ?? '');
$special_offer_pct      = (float)($_POST['special_offer_pct'] ?? 0);

// skupina gostov (skrita polja iz offer.php)
$adults         = (int)($_POST['adults']  ?? 0);
$kids06         = (int)($_POST['kids06']  ?? 0);
$kids712        = (int)($_POST['kids712'] ?? 0);

// weekly toggle (za info, če ga boš rabil v analitiki)
$weekly_toggle  = (int)($_POST['weekly'] ?? 0);

// promo koda (če je gost vpisal kupon na offer.php)
$promo_code = clean_str($_POST['promo_code'] ?? '');

// ID in datoteka
$stamp      = date('YmdHis');
$rand       = substr(bin2hex(random_bytes(2)), 0, 4);
$inquiryId  = "{$stamp}-{$rand}-{$unit}";

$root   = __DIR__ . '/../../common/data/json';
$folder = $root . '/inquiries/' . date('Y') . '/' . date('m') . '/pending';
@mkdir($folder, 0775, true);

$payload = [
  'id'           => $inquiryId,
  'status'       => 'pending',
  'created'      => date('c'),
  'unit'         => $unit,
  'lang'         => $lang,
  'from'         => $from,
  'to'           => $to,
  'nights'       => $nights,

  // skupina gostov
  'adults'       => $adults,
  'kids06'       => $kids06,
  'kids712'      => $kids712,

  'guest'        => [
    'name'  => $guestName,
    'phone' => $guestPhone,
    'email' => $guestEmail,
    'note'  => $guestMessage,
  ],

  // kupon / promo
  'promo_code'   => $promo_code,
  'promo'        => [
    'code'   => $promo_code,
    'amount' => $calc_promo,
  ],

  // finančni blok
  'calc'         => [
    'base'           => $calc_base,
    'discounts'      => $calc_discounts,
    'promo'          => $calc_promo,
    'special_offers' => $calc_special_offers,
    'cleaning'       => $calc_cleaning,
    'final'          => $calc_final,
  ],

  // TT + KEYCARD
  'tt'           => [
    'total'          => $calc_tt,
    'keycard_count'  => $keycard_count,
    'keycard_saving' => $calc_keycard,
    'keycard_note'   => $calc_keycard_breakdown,
  ],

  // dodatni meta podatki o special offerju (ime + %)
  'special_offer_meta' => [
    'name'    => $special_offer_name,
    'percent' => $special_offer_pct,
  ],

  // 🔹 meta blok – vir povpraševanja
  'meta'         => [
    'source' => 'public',
    'lang'   => $lang,
  ],
];

$file = $folder . '/' . $inquiryId . '.json';
file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Log
@mkdir($root.'/inquiries/logs', 0775, true);
@file_put_contents($root.'/inquiries/logs/submit_inquiry.log', '['.date('c')."] STORE $file\n", FILE_APPEND);

// POST-forward na thankyou.php
$thankyou = '/app/public/thankyou.php';
$fields = [
  'inquiry_id'        => $inquiryId,
  'unit'              => $unit,
  'from'              => $from,
  'to'                => $to,
  'nights'            => (string)$nights,

  // kontakt
  'guest_name'        => $guestName,
  'guest_email'       => $guestEmail,
  'guest_phone'       => $guestPhone,
  'guest_message'     => $guestMessage,

  // skupina
  'adults'            => (string)$adults,
  'kids06'            => (string)$kids06,
  'kids712'           => (string)$kids712,
  'keycard_count'     => (string)$keycard_count,

  'lang'              => $lang,

  // finance
  'calc_base'         => (string)$calc_base,
  'calc_discounts'    => (string)$calc_discounts,
  'calc_promo'        => (string)$calc_promo,
  'calc_special_offers' => (string)$calc_special_offers,
  'calc_cleaning'     => (string)$calc_cleaning,
  'calc_final'        => (string)$calc_final,
  'calc_tt'           => (string)$calc_tt,
  'calc_keycard'      => (string)$calc_keycard,
  'calc_keycard_breakdown'=> $calc_keycard_breakdown,

  // meta o special offerju + promo
  'special_offer_name'=> $special_offer_name,
  'special_offer_pct' => (string)$special_offer_pct,
  'promo_code'        => $promo_code,
  // 'offers_txt' lahko dodaš kasneje, če ga boš sestavljal v offer.php
];

?>
<!doctype html><html><body onload="document.f.submit()">
<form name="f" method="post" action="<?=$thankyou?>">
<?php foreach($fields as $k=>$v): ?>
  <input type="hidden" name="<?=$k?>" value="<?=htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
<?php endforeach; ?>
<noscript><button type="submit">Continue</button></noscript>
</form>
</body></html>
