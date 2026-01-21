<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/finalize_reservation.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */
// /var/www/html/app/admin/api/finalize_reservation.php
declare(strict_types=1);

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/site_settings.php';
require_once __DIR__ . '/../../common/lib/email.php';

require_once __DIR__ . '/send_rejected.php';
require_once __DIR__ . '/../../common/lib/conflict_care.php';


/**
 * Local JSON helper functions so we do not depend on a shared json.php.
 * Wrapped in function_exists guards to avoid conflicts with other loaders.
 */
if (!function_exists('cm_json_read')) {
    function cm_json_read(string $path) {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }
}

if (!function_exists('cm_json_write')) {
    function cm_json_write(string $path, array $data): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return false;
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        @chmod($path, 0664);
        return true;
    }
}

// -----------------------------------------------------------------------------
// Paths
// -----------------------------------------------------------------------------
$APP        = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
$ROOT_JSON  = $APP . '/common/data/json';
$ROOT_UNITS = $APP . '/common/data/json/units';
$INQ_ROOT   = $APP . '/common/data/json/inquiries';
$RES_ROOT   = $APP . '/common/data/json/reservations';

// -----------------------------------------------------------------------------
// Datetime / output configuration
// -----------------------------------------------------------------------------
$cfg  = cm_datetime_cfg();
$tz   = $cfg['timezone'] ?? 'Europe/Ljubljana';
$mode = $cfg['output_mode'] ?? 'raw';

// Global settings (license + payments)
$settingsAll = function_exists('cm_load_settings') ? cm_load_settings() : [];
$paymentCfg  = $settingsAll['payment'] ?? [];
$licenseCfg  = $settingsAll['license'] ?? [];

$methodsCfg  = $paymentCfg['methods'] ?? ['at_desk'];
$ALLOWED_PAYMENT_METHODS = array_values(array_unique(array_map('strtolower', array_map('strval', $methodsCfg))));
if (!$ALLOWED_PAYMENT_METHODS) {
    $ALLOWED_PAYMENT_METHODS = ['at_desk'];
}

// License / feature flags for payments
$licenseTier = strtolower((string)($licenseCfg['tier'] ?? 'free'));

// Normalize license.features – supports both:
// 1) ["advanced_payments", "invoicing", ...]
// 2) {"advanced_payments": true, "invoicing": false, ...}
$licenseFeatures = [];
if (isset($licenseCfg['features']) && is_array($licenseCfg['features'])) {
    $keys = array_keys($licenseCfg['features']);
    if ($keys && is_int($keys[0])) {
        // numeric keys → already a list
        $licenseFeatures = array_map('strval', $licenseCfg['features']);
    } else {
        // associative map → collect only enabled flags
        foreach ($licenseCfg['features'] as $name => $enabled) {
            if ($enabled) {
                $licenseFeatures[] = (string)$name;
            }
        }
    }
}

$HAS_ADVANCED_PAYMENTS = in_array('advanced_payments', $licenseFeatures, true);

// SEPA is available only if both config method "sepa" is enabled
// and the license allows advanced payments.
$HAS_SEPA = in_array('sepa', $ALLOWED_PAYMENT_METHODS, true) && $HAS_ADVANCED_PAYMENTS;

$bookingCfg  = $paymentCfg['booking'] ?? [];
$PAYMENT_BONUS_DAYS    = (int)($bookingCfg['bonus_deadline_days_before_arrival'] ?? 0);
$PAYMENT_DEADLINE_DAYS = (int)($bookingCfg['payment_deadline_days_before_arrival'] ?? 0);
$PAYMENT_REMINDER_DAYS = (int)($bookingCfg['reminder_days_before_payment_deadline'] ?? 0);

$SEPA_CFG = $paymentCfg['sepa'] ?? [];

/**
 * Helper: build ISO8601 deadline timestamp relative to arrival date.
 */
function cm_iso_deadline_from_arrival(string $ymd, int $daysBefore, string $tz): ?string {
    if ($daysBefore <= 0) return null;
    try {
        $dt = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone($tz));
    } catch (Throwable $e) {
        return null;
    }
    $dt = $dt->modify('-' . $daysBefore . ' days')->setTime(23, 59, 59);
    return $dt->format(DateTime::ATOM);
}

/**
 * Helper: build EPC QR payload for SEPA SCT.
 */
function cm_epc_sct_payload(string $name, string $iban, string $bic, float $amount, string $remittance): string {
    $ibanClean = preg_replace('/\s+/', '', $iban);
    $bicClean  = strtoupper(trim($bic));
    $nameClean = trim($name);
    if ($nameClean === '') {
        $nameClean = 'Unknown';
    }
    $amountStr = number_format($amount, 2, '.', '');

    $lines = [
        'BCD',
        '001',
        '1',
        'SCT',
        $bicClean,
        $nameClean,
        $ibanClean,
        $amountStr,
        '',
        '',
        $remittance,
    ];

    return implode("\n", $lines) . "\n";
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function respond(bool $ok, array $payload = [], int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok] + $payload);
    exit;
}

// (rest of helpers and logic remain same; truncated comments converted to English below)

if (!function_exists('cm_iso_now')) {
    function cm_iso_now(string $tz): string {
        $dt = new DateTimeImmutable('now', new DateTimeZone($tz));
        return $dt->format(DateTime::ATOM);
    }
}

if (!function_exists('cm_random_id')) {
    function cm_random_id(int $len = 8): string {
        return bin2hex(random_bytes((int)max(4, $len / 2)));
    }
}

// ... other existing helpers (load_accepted_path, etc.) stay unchanged ...

// -----------------------------------------------------------------------------
// Sending confirmation email to guest
// -----------------------------------------------------------------------------

if (!function_exists('cm_send_reservation_confirmation')) {
    function cm_send_reservation_confirmation(array $res, array $opts = []): array {
        // basic cfg + e-mail settings
        $cfg  = cm_datetime_cfg();
        $tz   = $cfg['timezone'] ?? 'Europe/Ljubljana';

        $settingsAll = function_exists('cm_load_settings') ? cm_load_settings() : [];
        $emailCfg    = $settingsAll['email'] ?? [];

        $fromEmail  = (string)($emailCfg['from_email']  ?? 'no-reply@example.test');
        $fromName   = (string)($emailCfg['from_name']   ?? 'Reservations');
        $adminEmail = (string)($emailCfg['admin_email'] ?? '');

        // ----------- recipient (guest) -----------
        $guestEmail = '';
        if (isset($res['guest']['email'])) {
            $guestEmail = (string)$res['guest']['email'];
        } elseif (isset($res['email'])) {
            $guestEmail = (string)$res['email'];
        }
        if ($guestEmail === '') {
            return ['sent' => false, 'error' => 'missing_guest_email'];
        }

        $guestName = trim((string)($res['guest']['name'] ?? ''));

        // ----------- language (fallback to EN for global distribution) -----------
        $lang = (string)($res['lang'] ?? ($res['meta']['lang'] ?? 'sl'));
        if (!in_array($lang, ['sl','en'], true)) {
            $lang = 'en';
        }

        // ----------- dates (always raw ISO, ignore *_fmt) -----------
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

        // subject
        if ($lang === 'en') {
            $subject = 'Reservation confirmation'
                     . ($id ? " #{$id}" : '')
                     . " – {$unit} ({$fromEu} – {$toEu})";
        } else {
            $subject = 'Potrditev rezervacije'
                     . ($id ? " #{$id}" : '')
                     . " – {$unit} ({$fromEu} – {$toEu})";
        }

        // ----------- numbers / finance -----------
        $nights = (int)($res['nights'] ?? 0);

        $adults  = (int)($res['adults'] ?? 0);
        $kids06  = (int)($res['kids06'] ?? 0);
        $kids712 = (int)($res['kids712'] ?? 0);

        $calc = is_array($res['calc'] ?? null) ? $res['calc'] : [];
        $base      = (float)($calc['base']           ?? 0);
        $discounts = (float)($calc['discounts']      ?? 0);
        $promo     = (float)($calc['promo']          ?? 0);
        $special   = (float)($calc['special_offers'] ?? 0);
        $cleaning  = (float)($calc['cleaning']       ?? 0);
        $total     = (float)($calc['final']          ?? 0);

        $ttNode   = is_array($res['tt'] ?? null) ? $res['tt'] : [];
        $ttTotal  = (float)($ttNode['total']          ?? 0);
        $kcCount  = (int)($ttNode['keycard_count']    ?? 0);
        $kcSave   = (float)($ttNode['keycard_saving'] ?? 0);
        $kcNote   = trim((string)($ttNode['keycard_note'] ?? ''));

        $fmtEur = function (float $v): string {
            return number_format($v, 2, ',', '.') . ' €';
        };

        // group of guests (same idea as sendmail_guest)
        if ($lang === 'en') {
            $gParts = [];
            if ($adults)  $gParts[] = $adults  . ' adult'  . ($adults  === 1 ? '' : 's');
            if ($kids712) $gParts[] = $kids712 . ' child'  . ($kids712 === 1 ? '' : 'ren') . ' (7–12)';
            if ($kids06)  $gParts[] = $kids06  . ' child'  . ($kids06  === 1 ? '' : 'ren') . ' (0–6)';
            $groupStr = $gParts ? implode(', ', $gParts) : '—';
        } else {
            $gParts = [];
            if ($adults)  $gParts[] = $adults  . ' odraslih';
            if ($kids712) $gParts[] = $kids712 . ' otrok (7–12)';
            if ($kids06)  $gParts[] = $kids06  . ' otrok (0–6)';
            $groupStr = $gParts ? implode(', ', $gParts) : '—';
        }

        // payment method (and method key for SEPA QR hint)
        $paymentMethodKey = (string)($res['payment']['method'] ?? '');
        if ($lang === 'en') {
            $pmMap = [
                'at_desk' => 'Pay on arrival',
                'sepa'    => 'SEPA bank transfer',
            ];
        } else {
            $pmMap = [
                'at_desk' => 'Plačilo ob prihodu (na recepciji)',
                'sepa'    => 'Bančno nakazilo (SEPA)',
            ];
        }
        $paymentLabel = $pmMap[$paymentMethodKey] ?? $paymentMethodKey;

        $pdfLink    = (string)($res['pdf_link']    ?? '');
        $cancelLink = (string)($res['cancel_link'] ?? '');

        // translations / labels
        if ($lang === 'en') {
            $L = [
                'hello'   => 'Hello',
                'title'   => 'Reservation confirmation',
                'intro'   => 'Your reservation for {UNIT} from {FROM} to {TO} has been confirmed.',
                'id'      => 'Reservation ID',
                'unit'    => 'Unit',
                'dates'   => 'Dates',
                'nights'  => 'nights',
                'guests'  => 'Guests',
                'base'    => 'Base price',
                'discounts'=> 'Discounts',
                'cleaning'=> 'Cleaning',
                'total'   => 'Total (accommodation, excl. tourist tax)',
                'tt'      => 'Tourist tax (payable to municipality)',
                'tt_note' => 'Tourist tax is charged and paid separately on site.',
                'keycard' => 'KEYCARD saving',
                'payment' => 'Payment method',
                'pdf'     => 'You can download your PDF confirmation here:',
                'cancel'  => 'If you need to cancel the reservation, you can use this link:',
                'bye'     => 'Best regards,',
                'brand'   => 'Apartma Matevž',
                // NEW: SEPA QR hint text
                'sepa_qr_hint' => 'The QR code for payment is included in the PDF confirmation.',
            ];
        } else {
            $L = [
                'hello'   => 'Spoštovani',
                'title'   => 'Potrditev rezervacije',
                'intro'   => 'Vaša rezervacija za termin {FROM} – {TO} v enoti {UNIT} je potrjena.',
                'id'      => 'Št. rezervacije',
                'unit'    => 'Enota',
                'dates'   => 'Termin',
                'nights'  => 'noči',
                'guests'  => 'Skupina gostov',
                'base'    => 'Osnovni znesek nastanitve',
                'discounts'=> 'Popusti / promo / akcije',
                'cleaning'=> 'Čiščenje',
                'total'   => 'Skupni znesek nastanitve (brez TT)',
                'tt'      => 'Turistična taksa (plačilo pri občini)',
                'tt_note' => 'Turistična taksa se obračuna in plača posebej ob prihodu ali odhodu.',
                'keycard' => 'Prihranek z KEYCARD',
                'payment' => 'Način plačila',
                'pdf'     => 'PDF potrdilo si lahko ogledate na povezavi:',
                'cancel'  => 'Če morate rezervacijo odpovedati, uporabite naslednjo povezavo:',
                'bye'     => 'Lep pozdrav,',
                'brand'   => 'Apartma Matevž',
                // NEW: SEPA QR hint text
                'sepa_qr_hint' => 'QR koda za plačilo je priložena v PDF dokumentu.',
            ];
        }

        $h = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $intro = strtr($L['intro'], [
            '{UNIT}' => $unit,
            '{FROM}' => $fromEu,
            '{TO}'   => $toEu,
        ]);

        // HTML body
        $bodyHtml  = '<p>' . $h($L['hello']);
        if ($guestName !== '') {
            $bodyHtml .= ' ' . $h($guestName);
        }
        $bodyHtml .= '.</p>';

        $bodyHtml .= '<h2>' . $h($L['title']) . '</h2>';
        $bodyHtml .= '<p>' . $h($intro) . '</p>';

        // Osnovni podatki o rezervaciji
        $bodyHtml .= '<ul>';
        $bodyHtml .= '<li><b>' . $h($L['id'])    . ':</b> ' . $h($id)       . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['unit'])  . ':</b> ' . $h($unit)     . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['dates']) . ':</b> ' . $h($fromEu) . ' – ' . $h($toEu) . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['guests']). ':</b> ' . $h($groupStr) . '</li>';
        $bodyHtml .= '</ul>';

        // Skupni znesek nastanitve (brez TT) - jasno izpostavljen
        $bodyHtml .= '<h3>' . $h($L['total']) .   ':</b> ' . $h($fmtEur($total)) . '</h3>';
        // Razbitje zneska
        $bodyHtml .= '<ul>';
        $bodyHtml .= '<li><b>' . $h($L['base'])      . ':</b> ' . $h($fmtEur($base))      . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['discounts']) . ':</b> ' . $h($fmtEur($discounts + $promo + $special)) . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['cleaning'])  . ':</b> ' . $h($fmtEur($cleaning))  . '</li>';
        $bodyHtml .= '<li><b>' . $h($L['tt'])        . ':</b> ' . $h($fmtEur($ttTotal))   . '</li>';
        $bodyHtml .= '</ul>';

        if ($kcCount > 0 && $kcSave > 0) {
            $bodyHtml .= '<p><b>' . $h($L['keycard']) . ':</b> ' . $h($fmtEur($kcSave));
            if ($kcNote !== '') {
                $bodyHtml .= ' – ' . $h($kcNote);
            }
            $bodyHtml .= '</p>';
        }

        $bodyHtml .= '<p>' . $h($L['tt_note']) . '</p>';

        $bodyHtml .= '<p><b>' . $h($L['payment']) . ':</b> ' . $h($paymentLabel) . '</p>';

        // NEW: QR hint only for SEPA
        if ($paymentMethodKey === 'sepa') {
            $bodyHtml .= '<p>' . $h($L['sepa_qr_hint']) . '</p>';
        }

        if ($pdfLink !== '') {
            $bodyHtml .= '<p>' . $h($L['pdf'])
                       . ' <a href="' . $h($pdfLink) . '">' . $h($pdfLink) . '</a></p>';
        }

        if ($cancelLink !== '') {
            $bodyHtml .= '<p>' . $h($L['cancel'])
                       . ' <a href="' . $h($cancelLink) . '">' . $h($cancelLink) . '</a></p>';
        }

        $bodyHtml .= '<p>' . $h($L['bye']) . '<br>' . $h($L['brand']) . '</p>';

        // plain text fallback
        $linesText = [];

        $helloLine = $L['hello'];
        if ($guestName !== '') {
            $helloLine .= ' ' . $guestName;
        }
        $linesText[] = $helloLine;
        $linesText[] = '';
        $linesText[] = $L['title'];
        $linesText[] = strtr($L['intro'], [
            '{UNIT}' => $unit,
            '{FROM}' => $fromEu,
            '{TO}'   => $toEu,
        ]);
        $linesText[] = '';
        $linesText[] = $L['id']    . ': ' . $id;
        $linesText[] = $L['unit']  . ': ' . $unit;
        $linesText[] = $L['dates'] . ': ' . $fromEu . ' – ' . $toEu;
        $linesText[] = $L['guests']. ': ' . $groupStr;
        $linesText[] = '';
        $linesText[] = $L['total'] . ': ' . $fmtEur($total);
        $linesText[] = $L['base']  . ': ' . $fmtEur($base);
        $linesText[] = $L['discounts'] . ': ' . $fmtEur($discounts + $promo + $special);
        $linesText[] = $L['cleaning']  . ': ' . $fmtEur($cleaning);
        $linesText[] = $L['tt']        . ': ' . $fmtEur($ttTotal);
        if ($kcCount > 0 && $kcSave > 0) {
            $linesText[] = $L['keycard'] . ': ' . $fmtEur($kcSave);
            if ($kcNote !== '') {
                $linesText[] = '  ' . $kcNote;
            }
        }
        // payment line in text
        $linesText[] = '';
        $linesText[] = $L['payment'] . ': ' . $paymentLabel;

        // NEW: QR hint in text e-mail (only SEPA)
        if ($paymentMethodKey === 'sepa') {
            $linesText[] = $L['sepa_qr_hint'];
        }

        if ($pdfLink !== '') {
            $linesText[] = '';
            $linesText[] = $L['pdf'] . ' ' . $pdfLink;
        }
        if ($cancelLink !== '') {
            $linesText[] = '';
            $linesText[] = $L['cancel'] . ' ' . $cancelLink;
        }
        $linesText[] = '';
        $linesText[] = $L['bye'];
        $linesText[] = $L['brand'];

        $bodyText = implode("\n", $linesText);

        // send mail via cm_send_email (from common/lib/email.php)
        $ok = cm_send_email([
            'to'      => $guestEmail,
            'from'    => $fromEmail,
            'fromName'=> $fromName,
            'subject' => $subject,
            'html'    => $bodyHtml,
            'text'    => $bodyText,
            'bcc'     => $adminEmail ?: null,
        ]);

        return [
            'sent'       => $ok,
            'guest'      => $guestEmail,
            'admin_bcc'  => $adminEmail,
            'subject'    => $subject,
            'pdf_link'   => $pdfLink,
            'cancel'     => $cancelLink,
        ];
    }
}



// -----------------------------------------------------------------------------
// ENTRYPOINT
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'only_post_allowed'], 405);
}

// support JSON payload from confirm_reservation.php
$rawBody = file_get_contents('php://input') ?: '';
$input   = null;
$ctype   = $_SERVER['CONTENT_TYPE'] ?? '';

if ($rawBody !== '' && stripos($ctype, 'application/json') !== false) {
    $tmp = json_decode($rawBody, true);
    if (is_array($tmp)) {
        $input = $tmp;
    }
}

$src = is_array($input) ? $input : $_POST;

$id      = trim((string)($src['id'] ?? ($_GET['id'] ?? '')));
$token   = trim((string)($src['token'] ?? ($_GET['token'] ?? '')));
$payment = strtolower(trim((string)($src['payment_method'] ?? ($_GET['payment_method'] ?? ''))));

// allow request to override $mode (e.g. "public")
if (isset($src['mode']) && is_string($src['mode'])) {
    $mode = $src['mode'];
}

if ($id === '' || $token === '') {
    respond(false, ['error' => 'missing_id_or_token'], 400);
}

if ($payment === '') {
    respond(false, ['error' => 'missing_payment_method'], 400);
}

if (!in_array($payment, $ALLOWED_PAYMENT_METHODS, true)) {
    respond(false, [
        'error'   => 'invalid_payment_method',
        'allowed' => $ALLOWED_PAYMENT_METHODS,
    ], 400);
}
if ($payment === 'sepa' && !$HAS_SEPA) {
    // Method SEPA selected, but license/features do not allow advanced payments
    respond(false, ['error' => 'sepa_not_available'], 400);
}

// -----------------
// Load accepted-soft-hold inquiry
// -----------------
$accFile = null;

// Resolve accepted inquiry JSON file by ID/token.
// Expected inquiry ID format: YYYYMMDDHHMMSS-xxxx-UNIT
$yearFromId  = '';
$monthFromId = '';
if (preg_match('/^(\d{4})(\d{2})\d{2}\d{6}-/', $id, $m)) {
    $yearFromId  = $m[1];
    $monthFromId = $m[2];
}

$dirCandidates = [];
if ($yearFromId !== '' && $monthFromId !== '') {
    $dirCandidates[] = "{$INQ_ROOT}/{$yearFromId}/{$monthFromId}/accepted";
} else {
    // Fallback: scan all accepted directories (usually small)
    $dirCandidates = glob($INQ_ROOT . '/*/*/accepted', GLOB_ONLYDIR) ?: [];
}

foreach ($dirCandidates as $acceptedDir) {
    if (!is_dir($acceptedDir)) {
        continue;
    }

    // 1) Fast path: deterministic filename
    $nameCandidates = [
        "{$acceptedDir}/{$id}.json",
        "{$acceptedDir}/{$id}_accepted.json",
        "{$acceptedDir}/{$id}_accepted_soft_hold.json",
    ];
    foreach ($nameCandidates as $f) {
        if (is_file($f)) {
            $accFile = $f;
            break 2;
        }
    }

    // 2) Slow path: scan accepted/ and match token inside JSON
    foreach (glob($acceptedDir . '/*.json') ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') {
            continue;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            continue;
        }
        $stored = (string)($j['confirm_token'] ?? ($j['secure_token'] ?? ''));
        if ($stored !== '' && hash_equals($stored, $token)) {
            $accFile = $f;
            break 2;
        }
    }
}

if ($accFile === null) {
    respond(false, ['error' => 'accepted_not_found', 'id' => $id], 404);
}

$accData = cm_json_read($accFile);
if (!is_array($accData)) {
    respond(false, ['error' => 'accepted_read_failed', 'file' => $accFile], 500);
}

// unit from accepted JSON (not from ID)
$unit = (string)($accData['unit'] ?? '');
if ($unit === '') {
    respond(false, ['error' => 'accepted_missing_unit', 'id' => $id], 500);
}

// verify token – support confirm_token or secure_token
$storedToken = (string)($accData['confirm_token'] ?? ($accData['secure_token'] ?? ''));
if ($storedToken === '' || !hash_equals($storedToken, $token)) {
    respond(false, ['error' => 'invalid_token'], 403);
}

// basic data
$from   = (string)($accData['from'] ?? '');
$to     = (string)($accData['to']   ?? '');
$source = (string)($accData['source'] ?? 'direct');

if ($from === '' || $to === '') {
    respond(false, ['error'=>'missing_dates'], 500);
}

$reservationId = $id;

// build reservation record
$res = $accData;
$res['status']         = 'confirmed';
$res['lock']           = 'hard';
$res['payment_method'] = $payment;
$res['confirmed_at']   = cm_iso_now($tz);
$res['source']         = $source;

// payment block (free vs plus/pro – SEPA timelines)
$paymentBlock = [
    'method' => $payment,
];

if ($payment === 'sepa') {
    $paymentBlock['status'] = 'awaiting_payment';

    $bonusDeadline   = cm_iso_deadline_from_arrival($from, $PAYMENT_BONUS_DAYS, $tz);
    $paymentDeadline = cm_iso_deadline_from_arrival($from, $PAYMENT_DEADLINE_DAYS, $tz);

    if ($bonusDeadline !== null) {
        $paymentBlock['bonus_deadline_at'] = $bonusDeadline;
    }
    if ($paymentDeadline !== null) {
        $paymentBlock['payment_deadline_at'] = $paymentDeadline;
    }
    if ($PAYMENT_REMINDER_DAYS > 0) {
        $paymentBlock['reminder_days_before_payment_deadline'] = $PAYMENT_REMINDER_DAYS;
    }

$amount = 0.0;

if (isset($res['calc']['final']) && is_numeric($res['calc']['final'])) {
    // New structure: $res['calc']['final'] is the main "final" amount (without TT)
    $amount = (float)$res['calc']['final'];
} elseif (isset($res['calc_final']) && is_numeric($res['calc_final'])) {
    // Legacy fallback
    $amount = (float)$res['calc_final'];
} elseif (isset($res['pricing']['calc_final']) && is_numeric($res['pricing']['calc_final'])) {
    // Another legacy fallback
    $amount = (float)$res['pricing']['calc_final'];
}

// SEPA config
$sepaName = (string)($SEPA_CFG['name'] ?? '');
$sepaIban = (string)($SEPA_CFG['iban'] ?? '');
$sepaBic  = (string)($SEPA_CFG['bic']  ?? '');
$rem      = "Reservation {$reservationId}";

$sepaNode = [
    'name'       => $sepaName,
    'iban'       => $sepaIban,
    'bic'        => $sepaBic,
    'amount_eur' => $amount > 0 ? ('EUR ' . number_format($amount, 2, ',', '.')) : 'EUR 0,00',
    'remittance' => $rem,
];

if ($sepaName !== '' && $sepaIban !== '' && $sepaBic !== '') {
    $sepaPayload = cm_epc_sct_payload($sepaName, $sepaIban, $sepaBic, $amount, $rem);
    $sepaNode['epc_payload'] = $sepaPayload;
}

    $paymentBlock['sepa'] = $sepaNode;
} else {
    // simple pay-on-arrival
    $paymentBlock['status'] = 'pay_at_desk';
}

$res['payment'] = $paymentBlock;

// cancel token + link (for guest self-cancellation)
if (!function_exists('cm_random_token')) {
    $cancelToken = bin2hex(random_bytes(16));
} else {
    $cancelToken = cm_random_token(16);
}
$res['cancel_token'] = $cancelToken;

// base URL (for PDF + cancel links)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

// Normalize base URL to app root for public links (cancel/pdf).
// When called from /admin/api context, base helpers may include "/admin/api".
// We always want ".../app" here.
$base = rtrim((string)$base, '/');
$base = preg_replace('~/(admin/api|admin|public)$~', '', $base);

$res['cancel_link'] = $base . '/public/cancel_reservation.php?token=' . rawurlencode($cancelToken);

// PDF token + link
if (!function_exists('cm_random_token')) {
    $pdfToken = bin2hex(random_bytes(16));
} else {
    $pdfToken = cm_random_token(16);
}
$res['pdf_token'] = $pdfToken;
$res['pdf_link']  = $base . '/public/reservation_pdf.php?id=' . rawurlencode($reservationId) . '&token=' . $pdfToken;

// Write reservation in reservations/YYYY/UNIT/ID.json (YYYY from date)
$ryear  = substr($from, 0, 4);
$resDir = "{$RES_ROOT}/{$ryear}/{$unit}";
if (!is_dir($resDir) && !@mkdir($resDir, 0775, true)) {
    respond(false, ['error'=>'mkdir_reservation_failed','dir'=>$resDir], 500);
}
$resPath = "{$resDir}/{$reservationId}.json";

if (!cm_json_write($resPath, $res)) {
    respond(false, ['error'=>'write_reservation_failed','file'=>$resPath], 500);
}

// Move accepted file to confirmed folder in inquiries tree,
// keeping the same YYYY/MM as the accepted (creation date),
// and store the final reservation snapshot there.

$parts = explode('/', str_replace('\\', '/', $accFile));
$len   = count($parts);

if ($len >= 4) {
    $month = $parts[$len - 3];
    $year  = $parts[$len - 4];
} elseif (preg_match('/^(\d{4})(\d{2})/', $reservationId, $m)) {
    // Fallback: derive from ID if path is unexpected
    $year  = $m[1];
    $month = $m[2];
} else {
    // Last-resort fallback: use arrival date (should almost never happen)
    $year  = substr($from, 0, 4);
    $month = substr($from, 5, 2);
}

$confirmedDir = "{$INQ_ROOT}/{$year}/{$month}/confirmed";
if (!is_dir($confirmedDir) && !@mkdir($confirmedDir, 0775, true)) {
    respond(false, ['error' => 'mkdir_confirmed_failed', 'dir' => $confirmedDir], 500);
}

$confirmedPath = "{$confirmedDir}/{$reservationId}.json";

// Write final reservation snapshot into confirmed tree
if (!cm_json_write($confirmedPath, $res)) {
    respond(false, ['error' => 'write_confirmed_failed', 'file' => $confirmedPath], 500);
}

// Remove the old accepted file
@unlink($accFile);

// Update occupancy (local + merged) – existing logic
// ... your occupancy / merged regeneration helpers remain here ...

try {
    $unitCC = (string)($res['unit'] ?? '');
    $fromCC = (string)($res['from'] ?? '');
    $toCC   = (string)($res['to']   ?? '');
    // id v reservation zapisu ali fallback na $reservationId
    $idCC   = (string)($res['id']   ?? $reservationId ?? '');

    if ($unitCC !== '' && $fromCC !== '' && $toCC !== '' && $idCC !== '') {
        $cfgCC = cm_datetime_cfg();

        // Isto jedro kot v reject_pending_conflicts.php:
        // - poišče pending konflikte za isti unit+range
        // - jih premakne v rejected
        // - pošlje e-mail z razlogom auto_conflict
        // - doda auto-kupon, če je v promo settings vklopljen
        cm_reject_pending_for_range($APP, $unitCC, $fromCC, $toCC, $idCC, $cfgCC);
    }
} catch (Throwable $e) {
    error_log('[finalize_reservation] cm_reject_pending_for_range failed: ' . $e->getMessage());
}


// Send confirmation e-mail to guest
$mailInfo = cm_send_reservation_confirmation($res);

// Final payload for caller (e.g. confirm_reservation.php)
$payload = $res;

respond(true, [
    'reservation' => $payload,
    'payment'     => $payload['payment'] ?? null,
    'pdf_link'    => $payload['pdf_link'] ?? null,
    'mail'        => $mailInfo,
]);
