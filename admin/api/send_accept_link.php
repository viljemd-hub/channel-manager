<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/send_accept_link.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * send_accept_link.php
 *
 * Sends (or re-sends) the reservation pickup link to the guest.
 *
 * Responsibilities:
 * - Locate the accepted (soft-hold) inquiry JSON by inquiry ID.
 * - Build the guest confirmation URL (confirm_reservation.php) and preserve language (lang=sl|en).
 * - Send the email via the shared msmtp/sendmail helper.
 * - Optionally send an admin copy.
 *
 * Notes:
 * - The pickup link is time-limited (token_expires_at), so the email should clearly show the expiry.
 * - This email belongs to the “manual confirmation” flow (Autopilot OFF).
 * - When Autopilot auto-accepts immediately, this mail may be skipped because the guest receives confirmation sooner.
 */


header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/email.php';

$APP      = '/var/www/html/app';
$INQ_ROOT = $APP . '/common/data/json/inquiries';

$cfg  = cm_datetime_cfg();
$mode = $cfg['output_mode'] ?? 'raw';

function cm__h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safe getter for nested paths: "pricing.calc_tt"
 */
function cm__get_path(array $arr, string $path) {
    $cur = $arr;
    foreach (explode('.', $path) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    return $cur;
}

/**
 * Pick first numeric value from candidate paths.
 */
function cm__pick_num(array $arr, array $paths, float $default = 0.0): float {
    foreach ($paths as $p) {
        $v = cm__get_path($arr, $p);
        if (is_numeric($v)) return (float)$v;
    }
    return $default;
}

/**
 * Pick first non-empty string value from candidate paths.
 */
function cm__pick_str(array $arr, array $paths, string $default = ''): string {
    foreach ($paths as $p) {
        $v = cm__get_path($arr, $p);
        if ($v === null) continue;
        $s = trim((string)$v);
        if ($s !== '') return $s;
    }
    return $default;
}

/**
 * Format EUR, consistent with the rest of public mails.
 */
function cm__eur($v): string {
    if (!is_numeric($v)) return '—';
    return number_format((float)$v, 2, ',', '.') . ' €';
}

/**
 * Jedro: poišče accepted soft-hold povpraševanje in pošlje mail.
 *
 * @param string      $id
 * @param bool        $dryRun     Če true, NE pošlje maila, ampak vrne predogled.
 * @param string|null $langParam  Opcijsko: "sl" ali "en". Če null → prebere iz inquiry meta.
 *
 * @return array
 */
function cm_send_accept_link(string $id, bool $dryRun = false, ?string $langParam = null): array
{
    global $INQ_ROOT, $cfg, $mode;

    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'error' => 'missing_id'];
    }

    // Najdi /accepted/ datoteko po ID-ju (nova struktura)
    $glob = glob("{$INQ_ROOT}/*/*/accepted/{$id}.json", GLOB_NOSORT) ?: [];
    if (!$glob) {
        return ['ok' => false, 'error' => 'accepted_not_found', 'id' => $id];
    }
    $file = $glob[0];

    $inq = cm_json_read($file);
    if (!is_array($inq)) {
        return ['ok' => false, 'error' => 'invalid_json', 'path' => $file];
    }

    $status = $inq['status'] ?? '';
    $stage  = $inq['stage']  ?? '';
    $resId = (string)($inq['id'] ?? $id); // reservation/inquiry ID for subject + body


    // Nova shema: status = "accepted", stage = "accepted_soft_hold"
    if ($status !== 'accepted' || $stage !== 'accepted_soft_hold') {
        return [
            'ok'    => false,
            'error' => 'status_not_soft_hold',
            'status'=> $status,
            'stage' => $stage
        ];
    }

    $token = (string)($inq['secure_token'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'error' => 'missing_secure_token'];
    }

    // Nastavitve iz site_settings.json
    $settings   = cm_load_settings();
    $emailCfg   = $settings['email'] ?? [];
    $enabled    = (bool)($emailCfg['enabled'] ?? false);
    $fromEmail  = (string)($emailCfg['from_email'] ?? 'info@localhost');
    $fromName   = (string)($emailCfg['from_name']  ?? 'Rezervacije');
    $adminEmail = (string)($emailCfg['admin_email'] ?? ($settings['admin_email'] ?? $fromEmail));

    if (!$enabled) {
        return ['ok' => false, 'error' => 'email_disabled'];
    }

    $guestEmail = (string)($inq['guest']['email'] ?? ($inq['email'] ?? ''));
    if (trim($guestEmail) === '') {
        return ['ok' => false, 'error' => 'missing_guest_email'];
    }

    // ---- language ----
    $lang = strtolower(trim((string)($langParam ?? ($inq['meta']['lang'] ?? ($inq['lang'] ?? 'sl')))));
    if (!in_array($lang, ['sl','en'], true)) $lang = 'sl';

    // Link (+lang)
    $base = cm_public_base_url(); // iz site_settings.json → public_base_url
    $link = $base . '/public/confirm_reservation.php?token=' . urlencode($token) . '&lang=' . urlencode($lang);

    // Formatiraj datume za subject/body
    cm_add_formatted_fields($inq, [
        'from'            => 'date',
        'to'              => 'date',
        'created'         => 'datetime',
        'accepted_at'     => 'datetime',
        'token_expires_at'=> 'datetime',
    ], $cfg);

    $unit = (string)($inq['unit'] ?? '');

    // Raw ISO dates (YYYY-MM-DD)
    $fromRaw = (string)($inq['from'] ?? '');
    $toRaw   = (string)($inq['to'] ?? '');

// Helper: YYYY-MM-DD -> DD.MM.YYYY
$fmtEu = function (string $d): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
};

// Vedno formatiraj iz raw ISO (ignoriramo *_fmt iz JSON)
$fromFmt = $fmtEu($fromRaw);
$toFmt   = $fmtEu($toRaw);


    // Pricing / KEYCARD / TT (robust)
    $calc_tt       = cm__pick_num($inq, ['calc_tt','pricing.calc_tt','pricing.tt_total','pricing.tt'], 0);
    $keycard_count = (int)cm__pick_num($inq, ['keycard_count','pricing.keycard_count','meta.keycard_count'], 0);
    $calc_keycard  = cm__pick_num($inq, ['calc_keycard','pricing.calc_keycard','pricing.keycard_saving','pricing.keycard_savings'], 0);
    $tt_net_to_pay = cm__pick_num($inq, ['pricing.tt_net_to_pay','calc_tt_net_to_pay'], 0);

    // i18n strings
    $T = [
        'sl' => [
            'subject'      => 'Prevzem rezervacije #{ID} – {UNIT} {FROM}–{TO}',
            'hello'        => 'Pozdravljeni,',
            'line1'        => 'Rezervacija <strong>#{ID}</strong>, termin <strong>{FROM} – {TO}</strong> je za vas <strong>začasno rezerviran</strong>.',
            'important'    => 'Pomembno:',
            'expires'      => 'povezava za prevzem rezervacije velja do <strong>{EXPIRES}</strong>.',
            'expires_fallback' => 'povezava za prevzem rezervacije ima omejeno veljavnost.',
            'cta'          => 'Za dokončno potrditev rezervacije kliknite spodnji gumb in izberite način plačila:',
            'btn'          => 'Prevzemi rezervacijo',
            'fallback'     => 'Če gumb ne deluje, odprite to povezavo:',
            'unit'         => 'Enota',
            'keycard_title'=> 'KEYCARD prihranek',
            'tt_title'     => 'Turistična taksa',
            'tt_pay'       => 'TT za plačilo po prihranku',
            'keycard_tip'  => 'Namig: KEYCARD zmanjša TT za odrasle.',
            'thanks'       => 'Hvala in lep pozdrav,',
            'brand'        => 'Apartma Matevž',
            'copy_tag'     => '[KOPIJA] ',
        ],
        'en' => [
            'subject'      => 'Reservation pickup #{ID} – {UNIT} {FROM}–{TO}',
            'hello'        => 'Hello,',
            'line1'        => 'Reservation <strong>#{ID}</strong>, stay <strong>{FROM} – {TO}</strong> is <strong>temporarily reserved</strong> for you.',
            'important'    => 'Important:',
            'expires'      => 'this pickup link is valid until <strong>{EXPIRES}</strong>.',
            'expires_fallback' => 'this pickup link is time-limited.',
            'cta'          => 'To confirm, click the button below and choose the payment method:',
            'btn'          => 'Confirm reservation',
            'fallback'     => 'If the button doesn’t work, open this link:',
            'unit'         => 'Unit',
            'keycard_title'=> 'KEYCARD savings',
            'tt_title'     => 'Tourist tax',
            'tt_pay'       => 'TT to pay after savings',
            'keycard_tip'  => 'Tip: KEYCARD reduces tourist tax for adults.',
            'thanks'       => 'Thank you and best regards,',
            'brand'        => 'Apartma Matevž',
            'copy_tag'     => '[COPY] ',
        ],
    ];
    $tr = fn(string $k): string => $T[$lang][$k] ?? $k;

    $subject = str_replace(
        ['{ID}','{UNIT}','{FROM}','{TO}'],
        [cm__h($resId), cm__h($unit), cm__h($fromFmt), cm__h($toFmt)],
        $tr('subject')
    );


    $line1 = str_replace(
        ['{ID}','{FROM}','{TO}'],
        [cm__h($resId), cm__h($fromFmt), cm__h($toFmt)],
        $tr('line1')
    );


    $expiresLine = '';
// Token expiry formatting (used in email templates)
// NOTE: $inq and $cfg are expected to be available in this scope.
$expiresRaw = (string)($inq['token_expires_at'] ?? ($inq['expires_at'] ?? ''));
$expiresFmt = '';
if ($expiresRaw !== '') {
    try {
        $tz = new DateTimeZone($cfg['timezone'] ?? 'Europe/Ljubljana');
        $dt = new DateTimeImmutable($expiresRaw);
        $expiresFmt = $dt->setTimezone($tz)->format('d.m.Y H:i');
    } catch (Throwable $e) {
        // Fallback: keep raw value if parsing fails
        $expiresFmt = $expiresRaw;
    }
}

    if ($expiresFmt !== '') {
        $expiresLine = str_replace('{EXPIRES}', cm__h($expiresFmt), $tr('expires'));
    } else {
        $expiresLine = $tr('expires_fallback');
    }

    // HTML
    $html = '<div style="font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.55; color:#222;">';
    $html .= '<p style="margin:0 0 12px 0;">'.$tr('hello').'</p>';
    $html .= '<p style="margin:0 0 12px 0;">'.$line1.'</p>';

    // KEYCARD / TT highlight (tvoj “hook”)
    $html .= '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px 14px;margin:0 0 14px 0;">';
    if ($calc_tt > 0) {
        $html .= '<div style="margin:0 0 6px 0;"><b>🧾 '.$tr('tt_title').':</b> '.cm__h(cm__eur($calc_tt)).'</div>';
    }
    if ($keycard_count > 0 && $calc_keycard > 0) {
        $html .= '<div style="margin:0 0 6px 0;"><b>💳 '.$tr('keycard_title').' ('.$keycard_count.'):</b> <span style="font-size:16px;"><b>'.cm__h(cm__eur($calc_keycard)).'</b></span></div>';
        if ($tt_net_to_pay > 0) {
            $html .= '<div style="margin:0;"><b>'.$tr('tt_pay').':</b> '.cm__h(cm__eur($tt_net_to_pay)).'</div>';
        }
    } else {
        $html .= '<div style="margin:0;color:#7c2d12;">'.$tr('keycard_tip').'</div>';
    }
    $html .= '</div>';

    // CTA box
    $html .= '<div style="background:#f6f7f9; border:1px solid #e2e6ea; border-radius:10px; padding:12px 14px; margin:0 0 14px 0;">';
    $html .= '<p style="margin:0 0 10px 0;">⏳ <strong>'.$tr('important').'</strong> '.$expiresLine.'</p>';
    $html .= '<p style="margin:0 0 10px 0;">'.$tr('cta').'</p>';
    $html .= '<p style="margin:0;">'
          .  '<a href="'.cm__h($link).'" style="background:#0a7a2a; color:#fff; padding:11px 16px; border-radius:9px; text-decoration:none; display:inline-block; font-weight:bold;">'
          .  $tr('btn')
          .  '</a></p>';
    $html .= '</div>';

    $html .= '<p style="margin:0 0 10px 0;">'.$tr('fallback').'<br>'
          .  '<a href="'.cm__h($link).'" style="color:#0b57d0; word-break:break-all;">'.cm__h($link).'</a></p>';

    $html .= '<p style="margin:0 0 14px 0;">'.$tr('unit').': <strong>'.cm__h($unit).'</strong></p>';
    $html .= '<p style="margin:0;">'.$tr('thanks').'<br><strong>'.$tr('brand').'</strong></p>';
    $html .= '</div>';

    // Plain text
    $text  = strip_tags($tr('hello')) . "\n\n";
    if ($lang === 'en') {
        $text .= "Reservation #{$resId}, dates {$fromFmt} – {$toFmt} are temporarily reserved for you.\n\n";
    } else {
        $text .= "Rezervacija #{$resId}, termin {$fromFmt} – {$toFmt} je za vas začasno rezerviran.\n\n";
    }


    if ($calc_tt > 0) {
        $text .= ($lang === 'en' ? "Tourist tax: " : "Turistična taksa: ") . cm__eur($calc_tt) . "\n";
    }
    if ($keycard_count > 0 && $calc_keycard > 0) {
        $text .= ($lang === 'en' ? "KEYCARD savings ({$keycard_count}): " : "KEYCARD prihranek ({$keycard_count}): ")
              . cm__eur($calc_keycard) . "\n";
        if ($tt_net_to_pay > 0) {
            $text .= ($lang === 'en' ? "TT to pay after savings: " : "TT za plačilo po prihranku: ")
                  . cm__eur($tt_net_to_pay) . "\n";
        }
    } else {
        $text .= $tr('keycard_tip') . "\n";
    }

    $text .= "\n";
    if ($expiresFmt !== '') {
        $text .= ($lang === 'en' ? "Important: this link is valid until {$expiresFmt}.\n" : "Pomembno: povezava velja do {$expiresFmt}.\n");
    } else {
        $text .= ($lang === 'en' ? "Important: this link is time-limited.\n" : "Pomembno: povezava ima omejeno veljavnost.\n");
    }

    $text .= ($lang === 'en'
        ? "To confirm, open this link:\n{$link}\n\n"
        : "Za dokončno potrditev odprite povezavo:\n{$link}\n\n");

    $text .= ($lang === 'en' ? "Unit: {$unit}\n" : "Enota: {$unit}\n");
    $text .= "\n" . strip_tags($tr('thanks')) . "\n" . strip_tags($tr('brand')) . "\n";

    if ($dryRun) {
        $payload = [
            'id'           => $inq['id'] ?? $id,
            'to'           => $guestEmail,
            'lang'         => $lang,
            'subject'      => $subject,
            'link'         => $link,
            'expires_at'   => $inq['token_expires_at'] ?? null,
            'html_preview' => $html,
            'text_preview' => $text
        ];
        $payload = cm_filter_output_mode($payload, $mode);

        return ['ok' => true, 'preview' => $payload, 'dry_run' => true];
    }

    // Pošlji gostu
    // Prefer the unified wrapper when available.
    if (function_exists('cm_send_email_ex')) {
        $res = cm_send_email_ex([
            'to'       => $guestEmail,
            'from'     => $fromEmail,
            'fromName' => $fromName,
            'subject'  => $subject,
            'html'     => $html,
            'text'     => $text,
        ]);
    } else {
        $res = cm_send_email_msmtp($guestEmail, $subject, $html, $text, $fromEmail, $fromName);
    }

    // Opcijsko kopija adminu (tag i18n)
    if (!empty($adminEmail) && $adminEmail !== $guestEmail) {
        if (function_exists('cm_send_email')) {
            cm_send_email([
                'to'       => $adminEmail,
                'from'     => $fromEmail,
                'fromName' => $fromName,
                'subject'  => $tr('copy_tag') . strip_tags($subject),
                'html'     => $html,
                'text'     => $text,
            ]);
        } else {
            cm_send_email_msmtp($adminEmail, $tr('copy_tag') . strip_tags($subject), $html, $text, $fromEmail, $fromName);
        }
    }

    if (!($res['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'send_failed', 'detail' => $res];
    }

    $payload = [
        'id'      => $inq['id'] ?? $id,
        'to'      => $guestEmail,
        'lang'    => $lang,
        'subject' => strip_tags($subject),
        'link'    => $link,
        'expires_at' => $inq['token_expires_at'] ?? null,
    ];
    $payload = cm_filter_output_mode($payload, $mode);

    return ['ok' => true, 'sent' => $payload];
}

// ---------------------------------------------------------------------
// HTTP endpoint del
// ---------------------------------------------------------------------

if (php_sapi_name() !== 'cli' &&
    basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {

    $id     = trim($_POST['id'] ?? $_GET['id'] ?? '');
    $dryRun = filter_var($_POST['dry_run'] ?? $_GET['dry_run'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    // optional lang for endpoint usage
    $lang   = trim((string)($_POST['lang'] ?? $_GET['lang'] ?? ''));
    $lang   = $lang !== '' ? strtolower($lang) : null;

    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_id']);
        exit;
    }

    $res = cm_send_accept_link($id, $dryRun, $lang);

    if (!($res['ok'] ?? false)) {
        $code = ($res['error'] ?? '') === 'accepted_not_found' ? 404 : 400;
        http_response_code($code);
    }

    echo json_encode($res);
}
