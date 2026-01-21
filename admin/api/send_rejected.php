<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/send_rejected.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * Skupni mail helper za “zavrnjeno povpraševanje” (ali odpoved rezervacije).
 *
 * Uporaba (iz drugih admin endpointov):
 *
 *   require_once __DIR__ . '/send_rejected.php';
 *   send_rejected_email($inquiryArray, $couponArrayOrNull, 'manual_reject');
 */

// root do app-a (admin/api → app)
$appRoot = dirname(__DIR__, 2); // /var/www/html/app

// mail_common (sanitize_email, send_via_sendmail)
require_once $appRoot . '/public/api/mail_common.php';

// Pretvori ISO YYYY-MM-DD v DD.MM.YYYY
function formatEuDate(string $iso): string {
    $iso = trim($iso);
    if ($iso === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if ($dt instanceof DateTime) {
        return $dt->format('d.m.Y');
    }
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y', $ts) : $iso;
}


/**
 * Pošlji e-mail za zavrnjeno povpraševanje z opcijskim kuponom.
 *
 * $inq    = celoten inquiry array (kot v .json)
 * $coupon = array z info o kuponu ali null
 * $reasonCode = "manual_reject" | "auto_overbook" | ...
 */
function send_rejected_email(array $inq, ?array $coupon = null, string $reasonCode = 'manual_reject'): bool
{
    global $appRoot; // iz zgornje definicije

    $guest    = $inq['guest'] ?? [];
    $email    = sanitize_email($guest['email'] ?? '');
    $name     = (string)($guest['name']  ?? '');
    if (!$email) {
        error_log('[send_rejected_email] missing guest email');
        return false;
    }

    $unit   = (string)($inq['unit'] ?? '');
    $from   = (string)($inq['from'] ?? '');
    $to     = (string)($inq['to']   ?? '');
    $nights = (string)($inq['nights'] ?? '');
    $id     = (string)($inq['id'] ?? '');

    $fromEu = formatEuDate($from);
    $toEu   = formatEuDate($to);


    // preberi e-mail nastavitve iz site_settings.json
    $settingsFile = $appRoot . '/common/data/json/units/site_settings.json';
    $settings = [];
    if (is_file($settingsFile)) {
        $tmp = json_decode((string)file_get_contents($settingsFile), true);
        if (is_array($tmp)) $settings = $tmp;
    }
    $emailCfg   = $settings['email'] ?? [];
    $enabled    = (bool)($emailCfg['enabled'] ?? false);
    $fromEmail  = sanitize_email($emailCfg['from_email'] ?? 'info@localhost');
    $fromName   = (string)($emailCfg['from_name'] ?? 'Apartma');
    $adminTo    = sanitize_email($emailCfg['admin_email'] ?? '');

    if (!$enabled) {
        error_log('[send_rejected_email] email disabled in site_settings');
        return false;
    }
    if (!$fromEmail) {
        error_log('[send_rejected_email] missing from_email in site_settings');
        return false;
    }

    // kupon blok
    $couponHtml = '';
    if ($coupon) {
        $discount = (int)($coupon['discount_percent'] ?? 0);
        $code     = htmlspecialchars((string)($coupon['code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $validTo  = htmlspecialchars((string)($coupon['valid_to'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($discount > 0 && $code !== '') {
            $couponHtml = sprintf(
                '<p>Kot zahvalo za razumevanje vam ponujamo <strong>%d%% popusta</strong> s kodo ' .
                '<strong>%s</strong>, veljavno do <strong>%s</strong>.</p>',
                $discount,
                $code,
                $validTo
            );
        }
    }

    $unitHtml   = htmlspecialchars($unit,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $fromHtml   = htmlspecialchars($fromEu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $toHtml     = htmlspecialchars($toEu,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nightsHtml = htmlspecialchars((string)$nights, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nameHtml   = htmlspecialchars($name,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $subject = "Zavrnjeno povpraševanje {$id} ({$fromEu} – {$toEu}) – kupon za popust";


    $html  = '<!DOCTYPE html><html><body style="font-family:sans-serif;font-size:14px;">';
    $html .= "<p>Spoštovani {$nameHtml},</p>";
    $html .= "<p>zahvaljujemo se vam za povpraševanje za enoto <strong>{$unitHtml}</strong> v terminu ";
    $html .= "<strong>{$fromHtml} – {$toHtml}</strong> ({$nightsHtml} noči).</p>";
    $html .= "<p>Žal vašega termina ne moremo potrditi, ker je nastanitev v tem času zasedena ali ni več na voljo.</p>";
    $html .= "<p>Veseli bomo, če si boste izbrali drug termin na našem koledarju.</p>";
    // --- kuponski del: če imamo veljaven kupon, ga prilepimo v telo maila ----
    $hasCoupon = is_array($coupon);
    if ($hasCoupon) {
        $code    = htmlspecialchars((string)($coupon['code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $percent = (float)($coupon['discount_percent'] ?? $coupon['value'] ?? 0);
    // ISO (2026-07-19) → EU (19.07.2026) za izpis v e-mailu
    $validToEu = formatEuDate((string)($coupon['valid_to'] ?? ''));
    $validTo   = htmlspecialchars($validToEu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($code !== '' && $percent > 0.0) {
            $html .= "<p>V zahvalo za vaš interes smo vam pripravili kupon za popust:</p>";
            $html .= "<p><strong>Koda kupona:</strong> {$code}<br>";
            $html .= "<strong>Popust:</strong> {$percent}%</p>";

            if ($validTo !== '') {
                $html .= "<p><strong>Kupon velja do:</strong> {$validTo}</p>";
            }

            $html .= "<p>Kupon lahko vnesete v polje »Promo koda« pri naslednjem povpraševanju na naši ponudbeni strani.</p>";
        }
    }

    // zaključno besedilo (ostane isto kot prej)
    $html .= "<p>Veseli bomo vašega ponovnega povpraševanja.</p>";
    $html .= "<p>Lep pozdrav,<br>Apartma {$unitHtml}</p>";
    $html .= '</body></html>';


    try {
        $ok = send_via_sendmail(
            $email,        // To
            $fromEmail,    // From
            $subject,
            $html,
            $fromEmail,    // Reply-To
            $fromName,     // From ime
            $adminTo,      // Cc (kopija adminu)
            $adminTo       // Bcc (varnostna kopija adminu)
        );
    } catch (\Throwable $e) {
        error_log('[send_rejected_email] send_via_sendmail exception: ' . $e->getMessage());
        return false;
    }

    if (!$ok) {
        error_log('[send_rejected_email] send_via_sendmail returned false for inquiry ' . $id);
    }

    return (bool)$ok;
}
