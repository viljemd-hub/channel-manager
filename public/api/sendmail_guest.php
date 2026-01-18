<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/api/sendmail_guest.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/mail_common.php';

function sendmail_guest(array $data): bool {
    // ---------- language ----------
    // Default UI language is "sl", but for any unsupported code we fall back to "en"
    $lang = (string)($data['lang'] ?? 'sl');
    if (!in_array($lang, ['sl', 'en'], true)) {
        $lang = 'en'; // global fallback for unknown languages
    }

    // ---------- addressing ----------
    $to        = sanitize_email($data['email'] ?? '');
    $fromEmail = sanitize_email($data['from'] ?? '');
    $fromName  = (string)($data['from_name'] ?? '');
    $bccAdmin  = sanitize_email($data['admin_to'] ?? ''); // safety copy to admin

    if (!$to || !$fromEmail) {
        return false;
    }

    // ---------- basic inquiry data ----------
    $id          = (string)($data['inquiry_id'] ?? '');
    $unit        = (string)($data['unit'] ?? '');
    $fromDateRaw = (string)($data['fromDate'] ?? '');
    $toDateRaw   = (string)($data['toDate'] ?? '');
    $nights      = (int)($data['nights'] ?? 0);

    $name   = trim((string)($data['name'] ?? ''));
    $phone  = trim((string)($data['phone'] ?? ''));
    $note   = trim((string)($data['note'] ?? ''));

    $adults = (int)($data['adults'] ?? 0);
    $kids06 = (int)($data['kids06'] ?? 0);
    $kids712 = (int)($data['kids712'] ?? 0);

    // finance (already computed in offer.php)
    $base          = (float)($data['calc_base'] ?? 0);
    $discounts     = (float)($data['calc_discounts'] ?? 0);
    $promo         = (float)($data['calc_promo'] ?? 0);
    $cleaning      = (float)($data['calc_cleaning'] ?? 0);
    $specialOffers = (float)($data['calc_special_offers'] ?? 0);
    $total         = (float)($data['calc_final'] ?? 0);

    $promoCode  = trim((string)($data['promo_code'] ?? ''));
    $specialTxt = trim((string)($data['special_offers_txt'] ?? ''));

    // ---------- EU date formatting ----------
    $fmtEu = function (string $d): string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $d;
    };
    $fromDateEu = $fmtEu($fromDateRaw);
    $toDateEu   = $fmtEu($toDateRaw);

    // ---------- subject ----------
    if ($lang === 'en') {
        $subj = "Inquiry received"
              . ($id ? " #{$id}" : "")
              . " – {$unit} ({$fromDateEu} – {$toDateEu})";
    } else {
        $subj = "Povpraševanje prejeto"
              . ($id ? " #{$id}" : "")
              . " – {$unit} ({$fromDateEu} – {$toDateEu})";
    }

    $h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $row = fn($k,$v) =>
        "<tr>"
      . "<td style='padding:6px 10px;vertical-align:top;white-space:nowrap;'><b>{$k}</b></td>"
      . "<td style='padding:6px 10px;'>{$v}</td>"
      . "</tr>";

    // ---------- labels ----------
    if ($lang === 'en') {
        $L = [
            'hello'      => 'Hello',
            'thanks'     => 'Thank you for your inquiry.',
            'intro'      => 'We have received your inquiry for {UNIT} from {FROM} to {TO}. We will get back to you as soon as possible, within 24 hours.',
            'id'         => 'Inquiry ID',
            'unit'       => 'Unit',
            'dates'      => 'Dates',
            'nights'     => 'nights',
            'guests'     => 'Guests',
            'base'       => 'Base price',
            'discounts'  => 'Discounts',
            'promo'      => 'Promo',
            'promo_code' => 'Promo code',
            'offers'     => 'Selected offers',
            'cleaning'   => 'Cleaning',
            'total'      => 'Total',
            'phone'      => 'Phone (as entered)',
            'msg'        => 'Your message',
            'next'       => 'We will get back to you as soon as possible, within 24 hours.',
            'bye'        => 'Best regards',
            'guests_fmt' => "{$adults} adults, {$kids06} (0–6), {$kids712} (7–12)",
        ];
    } else {
        $L = [
            'hello'      => 'Pozdravljeni',
            'thanks'     => 'Zahvaljujemo se vam za vaše povpraševanje.',
            'intro'      => 'Za termin {FROM} – {TO} v enoti {UNIT} smo prejeli vaše povpraševanje.',
            'id'         => 'ID povpraševanja',
            'unit'       => 'Enota',
            'dates'      => 'Termin',
            'nights'     => 'noči',
            'guests'     => 'Skupina',
            'base'       => 'Osnovna cena',
            'discounts'  => 'Popusti',
            'promo'      => 'Promo',
            'promo_code' => 'Promo koda',
            'offers'     => 'Izbrane akcije',
            'cleaning'   => 'Čiščenje',
            'total'      => 'Skupaj',
            'phone'      => 'Telefon (kot vnesen)',
            'msg'        => 'Sporočilo gosta',
            'next'       => 'Odgovorili vam bomo v najkrajšem možnem času, najkasneje v 24 urah.',
            'bye'        => 'Lep pozdrav',
            'guests_fmt' => "{$adults} odraslih, {$kids06} (0–6) + {$kids712} (7–12)",
        ];
    }

    // ---------- intro text with EU dates ----------
    $introText = strtr($L['intro'], [
        '{UNIT}' => $unit,
        '{FROM}' => $fromDateEu,
        '{TO}'   => $toDateEu,
    ]);

    $titleLine = $L['thanks'];
    $greet     = $L['hello'] . ($name !== '' ? ' ' . $h($name) : '') . ',';

    // ---------- HTML body ----------
    $html  = "<html><body style='font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;line-height:1.5;color:#111;'>";
    if ($id !== '') {
        $html .= "<div style='font-size:12px;color:#666;margin-bottom:8px;'>{$h($id)}</div>";
    }
    $html .= "<p style='margin:0 0 10px 0;'>{$greet}</p>";
    $html .= "<h2 style='margin:0 0 6px 0;'>{$h($titleLine)}</h2>";
    $html .= "<p style='margin:0 0 14px 0;color:#333;'>{$h($introText)}</p>";

    $html .= "<table cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #eee;border-radius:8px;overflow:hidden;'>";
    if ($id !== '') {
        $html .= $row($h($L['id']), $h($id));
    }
    $html .= $row($h($L['unit']),  $h($unit));
    $html .= $row(
        $h($L['dates']),
        $h("{$fromDateEu} – {$toDateEu}") . " ({$nights} " . $h($L['nights']) . ")"
    );
    $html .= $row($h($L['guests']), $h($L['guests_fmt']));
    $html .= $row($h($L['base']),      _eur($base));
    $html .= $row($h($L['discounts']), _eur($discounts));
    $html .= $row($h($L['promo']),     _eur($promo));
    if ($promoCode !== '') {
        $html .= $row($h($L['promo_code']), $h($promoCode));
    }
    if ($specialTxt !== '') {
        $html .= $row($h($L['offers']), $h($specialTxt));
    }
    $html .= $row($h($L['cleaning']), _eur($cleaning));

    // Special offers amount can be shown as part of discounts; if you want separate row, uncomment:
    // if ($specialOffers != 0.0) {
    //     $html .= $row('Special offers', _eur($specialOffers));
    // }

    $html .= $row($h($L['total']), '<b>' . _eur($total) . '</b>');
    if ($phone !== '') {
        $html .= $row($h($L['phone']), $h($phone));
    }
    if ($note !== '') {
        $html .= $row($h($L['msg']), nl2br($h($note)));
    }
    $html .= "</table>";

    $html .= "<p style='margin:14px 0 0 0;'>{$h($L['next'])}<br>{$h($L['bye'])},<br><strong>"
          . $h($fromName ?: $fromEmail)
          . "</strong></p>";

    $html .= "</body></html>";

    // ---------- send via msmtp/sendmail wrapper ----------
    return send_via_sendmail(
        $to,
        $fromEmail,
        $subj,
        $html,
        $fromEmail,   // reply-to
        $fromName,
        '',           // cc
        $bccAdmin     // bcc
    );
}
