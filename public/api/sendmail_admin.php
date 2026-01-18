<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/api/sendmail_admin.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/mail_common.php';

/**
 * Admin notification email (single-language).
 * - Language: $data['lang'] or $data['meta']['lang'] → sl/en (fallback en)
 * - Reply-To: guest email (if present)
 */
function sendmail_admin(array $data): bool {
  $admin      = sanitize_email($data['admin_to'] ?? '');
  $fromEmail  = sanitize_email($data['from'] ?? '');
  $fromName   = (string)($data['from_name'] ?? '');
  $guestEmail = sanitize_email($data['email'] ?? '');

  if (!$admin) return false;
  if (!$fromEmail) $fromEmail = $admin;

  // ---- language (single) ----
  // Default UI language is "sl", but for unsupported language codes we fall back to "en"
  $lang = strtolower((string)($data['lang'] ?? ($data['meta']['lang'] ?? 'sl')));
  if (!in_array($lang, ['sl', 'en'], true)) {
    $lang = 'en'; // global fallback for unknown languages
  }


  // ---- core fields ----
  $id       = (string)($data['inquiry_id'] ?? '');
  $unit     = (string)($data['unit'] ?? '');
  $fromDate = (string)($data['fromDate'] ?? '');
  $toDate   = (string)($data['toDate'] ?? '');
  $nights   = (int)($data['nights'] ?? 0);
  $note     = trim((string)($data['note'] ?? ''));

  $name  = trim((string)($data['name'] ?? ''));
  $phone = trim((string)($data['phone'] ?? ''));

  $adults  = (int)($data['adults']  ?? 0);
  $kids06  = (int)($data['kids06']  ?? 0);
  $kids712 = (int)($data['kids712'] ?? 0);

  $base      = $data['calc_base']      ?? 0;
  $discounts = $data['calc_discounts'] ?? 0;
  $promo     = $data['calc_promo']     ?? 0;
  $cleaning  = $data['calc_cleaning']  ?? 0;
  $final     = $data['calc_final']     ?? 0;
  $tt        = $data['calc_tt']        ?? 0;

  $keycardCount = (int)($data['keycard_count'] ?? 0);
  $keycardSave  = $data['calc_keycard'] ?? 0;

  $promoCode = trim((string)($data['promo_code'] ?? ''));
  $offersTxt = trim((string)($data['special_offers_txt'] ?? ''));

  $ts = date('Y-m-d H:i:s');

  // ---- i18n strings ----
  $T = [
    'sl' => [
      'subj'      => 'NOVO POVPRAŠEVANJE{ID} – {UNIT} {FROM}→{TO} / {N}n',
      'type'      => 'OBVESTILO ADMINU',
      'time'      => 'Čas',
      'lang'      => 'Jezik',
      'id'        => 'ID',
      'unit'      => 'Enota',
      'dates'     => 'Termin',
      'guests'    => 'Skupina',
      'base'      => 'Osnova',
      'discounts' => 'Popusti',
      'promo'     => 'Promo',
      'promo_code'=> 'Promo koda',
      'offers'    => 'Izbrane akcije',
      'cleaning'  => 'Čiščenje',
      'total'     => 'SKUPAJ',
      'tt'        => 'TT',
      'keycard'   => 'KEYCARD',
      'guest'     => 'Gost',
      'message'   => 'Sporočilo gosta',
      'nights'    => 'noči',
    ],
    'en' => [
      'subj'      => 'NEW INQUIRY{ID} – {UNIT} {FROM}→{TO} / {N}n',
      'type'      => 'ADMIN NOTICE',
      'time'      => 'Time',
      'lang'      => 'Language',
      'id'        => 'ID',
      'unit'      => 'Unit',
      'dates'     => 'Dates',
      'guests'    => 'Guests',
      'base'      => 'Base price',
      'discounts' => 'Discounts',
      'promo'     => 'Promo',
      'promo_code'=> 'Promo code',
      'offers'    => 'Selected offers',
      'cleaning'  => 'Cleaning',
      'total'     => 'TOTAL',
      'tt'        => 'Tourist tax',
      'keycard'   => 'KEYCARD',
      'guest'     => 'Guest',
      'message'   => 'Guest message',
      'nights'    => 'nights',
    ],
  ];
  $tr = fn(string $k): string => $T[$lang][$k] ?? $k;

  $subj = $tr('subj');
  $subj = str_replace(
    ['{ID}','{UNIT}','{FROM}','{TO}','{N}'],
    [$id ? " [{$id}]" : '', $unit, $fromDate, $toDate, (string)$nights],
    $subj
  );

  $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $row = fn(string $k, string $v) => "<tr><td style='padding:4px 8px;white-space:nowrap;vertical-align:top;'><b>{$k}:</b></td><td style='padding:4px 8px;vertical-align:top;'>{$v}</td></tr>";

  $html  = '<html><body style="font-family:system-ui,Segoe UI,Roboto,Arial;line-height:1.5;color:#111;">';
  $html .= "<div style='font-size:12px;color:#666;'>".$h($tr('type'))." • ".$h($tr('time')).": ".$h($ts)." • ".$h($tr('lang')).": ".$h($lang)."</div>";
  if ($id) $html .= "<div style='font-size:12px;color:#666;'>".$h($tr('id')).": ".$h($id)."</div>";

  $html .= '<h3 style="margin:10px 0 6px 0;">'.$h($subj).'</h3>';
  $html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';

  $html .= $row($h($tr('unit')), $h($unit));
  $html .= $row($h($tr('dates')), $h("{$fromDate} → {$toDate}") . " ({$nights} " . $h($tr('nights')) . ")");
  $html .= $row($h($tr('guests')), $h("{$adults} + {$kids06} (0–6) + {$kids712} (7–12)"));

  if ($offersTxt !== '') $html .= $row($h($tr('offers')), $h($offersTxt));
  if ($promoCode !== '') $html .= $row($h($tr('promo_code')), $h($promoCode));

  $html .= $row($h($tr('base')), _eur($base));
  $html .= $row($h($tr('discounts')), _eur($discounts));
  $html .= $row($h($tr('promo')), _eur($promo));
  $html .= $row($h($tr('cleaning')), _eur($cleaning));
  $html .= $row($h($tr('total')), "<b>"._eur($final)."</b>");
  $html .= $row($h($tr('tt')), _eur($tt));

  if ($keycardCount > 0) {
    $html .= $row($h($tr('keycard')), $h((string)$keycardCount).' ('.$h(strip_tags(_eur($keycardSave))).')');
  }

  $guestLine = $h($name) . ($guestEmail ? " &lt;".$h($guestEmail)."&gt;" : "") . ($phone ? ", ".$h($phone) : "");
  $html .= $row($h($tr('guest')), $guestLine);

  if ($note !== '') $html .= $row($h($tr('message')), nl2br($h($note)));

  $html .= '</table></body></html>';

  // Reply-To = guest (so "Reply" goes directly to the guest)
  $replyTo = $guestEmail ?: $fromEmail;

  return send_via_sendmail(
    $admin,
    $fromEmail,
    $subj,
    $html,
    $replyTo,
    $fromName
  );
}
