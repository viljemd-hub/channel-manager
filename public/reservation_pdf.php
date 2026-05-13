<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/reservation_pdf.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * public/reservation_pdf.php
 *
 * Generates a PDF confirmation for a reservation with SEPA data.
 * QR rendering is intentionally disabled for now and replaced with
 * a placeholder block until a native CM EPC QR generator is implemented.
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../common/lib/sepa_qr.php';

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convert ISO8601 timestamp (bonus_deadline_at, payment_deadline_at)
 * to local d.m.Y H:i format for PDF output.
 */
function cm_fmt_iso_local(?string $iso, string $tz, string $fmt = 'd.m.Y H:i'): string
{
    if ($iso === null || $iso === '') {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($fmt);
    } catch (Throwable $e) {
        return '';
    }
}

$APP_ROOT = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$RES_ROOT = $APP_ROOT . '/common/data/json/reservations';

if (!function_exists('cm_json_read')) {
    function cm_json_read(string $path)
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

$id    = trim($_GET['id'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($id === '' || $token === '') {
    http_response_code(400);
    echo 'Missing id or token.';
    exit;
}

// Find reservation by ID across all years / units.
$pattern = rtrim($RES_ROOT, '/') . '/*/*/' . basename($id) . '.json';
$files   = glob($pattern, GLOB_NOSORT) ?: [];

if (empty($files)) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

$path = $files[0];
$res  = cm_json_read($path);

if (!is_array($res)) {
    http_response_code(500);
    echo 'Invalid reservation file.';
    exit;
}

$pdfToken = (string)($res['pdf_token'] ?? '');
if ($pdfToken === '' || !hash_equals($pdfToken, $token)) {
    http_response_code(403);
    echo 'Invalid token.';
    exit;
}

// Add formatted fields if needed, but do not rely on *_fmt blindly in PDF.
cm_add_formatted_fields($res, [
    'from'         => 'date',
    'to'           => 'date',
    'created'      => 'datetime',
    'accepted_at'  => 'datetime',
    'confirmed_at' => 'datetime',
], $cfg);

// Raw dates from JSON.
$fromRaw      = (string)($res['from'] ?? '');
$toRaw        = (string)($res['to'] ?? '');
$createdRaw   = (string)($res['created'] ?? '');
$confirmedRaw = (string)($res['confirmed_at'] ?? '');

// Local formatters for PDF.
$fmtDate = function (string $d): string {
    if ($d === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
};

$fmtDateTime = function (string $iso) use ($tz): string {
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $iso;
    }
};

$fromFmt   = $fmtDate($fromRaw);
$toFmt     = $fmtDate($toRaw);
$created   = $fmtDateTime($createdRaw);
$confirmed = $fmtDateTime($confirmedRaw);

// Guest name.
$guestName = '';
if (isset($res['guest']) && is_array($res['guest'])) {
    $guestName = (string)($res['guest']['name'] ?? '');
}
if ($guestName === '' && isset($res['guest_name'])) {
    $guestName = (string)$res['guest_name'];
}
if ($guestName === '' && isset($res['name'])) {
    $guestName = (string)$res['name'];
}

$unit    = (string)($res['unit'] ?? '');
$adults  = (int)($res['adults'] ?? 0);
$kids06  = (int)($res['kids06'] ?? 0);
$kids712 = (int)($res['kids712'] ?? 0);

$gParts = [];
if ($adults) {
    $gParts[] = $adults . ' odraslih';
}
if ($kids712) {
    $gParts[] = $kids712 . ' otrok (7–12)';
}
if ($kids06) {
    $gParts[] = $kids06 . ' otrok (0–6)';
}
$groupStr = $gParts ? implode(', ', $gParts) : '—';

$payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
$method  = strtolower((string)($payment['method'] ?? $res['payment_method'] ?? ''));
$sepa    = is_array($payment['sepa'] ?? null) ? $payment['sepa'] : null;

// CM Plus timeline fields.
$bonusDeadlineIso   = (string)($payment['bonus_deadline_at'] ?? '');
$paymentDeadlineIso = (string)($payment['payment_deadline_at'] ?? '');
$bonusDeadlineFmt   = cm_fmt_iso_local($bonusDeadlineIso, $tz);
$paymentDeadlineFmt = cm_fmt_iso_local($paymentDeadlineIso, $tz);

$eur = function ($n): string {
    return number_format((float)$n, 2, ',', '.') . ' €';
};

$getNum = function (array $arr, string $keyPath, float $default = 0.0) {
    $cur = $arr;
    foreach (explode('.', $keyPath) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return is_numeric($cur) ? (float)$cur : $default;
};

// Values from nested calc/tt structures.
$calc_base      = $getNum($res, 'calc.base', 0.0);
$calc_discounts = $getNum($res, 'calc.discounts', 0.0);
$calc_promo     = $getNum($res, 'calc.promo', 0.0);
$calc_special   = $getNum($res, 'calc.special_offers', 0.0);
$calc_cleaning  = $getNum($res, 'calc.cleaning', 0.0);
$calc_final     = $getNum($res, 'calc.final', 0.0);
$calc_tt        = $getNum($res, 'tt.total', 0.0);

$keycard_count = (int)$getNum($res, 'tt.keycard_count', 0.0);
$calc_keycard  = $getNum($res, 'tt.keycard_saving', 0.0);

if ($calc_tt > 0.0 && $calc_keycard > 0.0) {
    $tt_net_to_pay = max($calc_tt - $calc_keycard, 0.0);
} else {
    $tt_net_to_pay = 0.0;
}

$offers_txt = (string)($res['special_offers_txt'] ?? $res['offers_txt'] ?? '');
$promoCode  = (string)($res['promo']['code'] ?? $res['promo_code'] ?? '');

// 1. First, checking if there is SEPA included
// --- PDF SEPA LOGIC (English commented for PRO version) ---
if ($method === 'sepa') {
    $sepaCfg = $settings['payment']['sepa'] ?? [];
    $iban = $sepaCfg['iban'] ?? '';
    
    // Tax (TT) is handled "at desk" per your project rules
    $amount = (float)($res['calc']['final'] ?? 0);

    if ($iban !== '' && $amount > 0) {
        // Attempt to generate the QR code
        if (function_exists('cm_generate_sepa_qr')) {
            $qrData = [
                'name'   => $sepaCfg['name'] ?? 'Apartma Matevž',
                'iban'   => $iban,
                'bic'    => $sepaCfg['bic'] ?? '',
                'amount' => $amount,
                'ref'    => 'SI00' . preg_replace('/[^0-9]/', '', $res['id']),
                'descr'  => 'Booking Ref: ' . $res['id']
            ];
            
            $qrImg = cm_generate_sepa_qr($qrData);

            if ($qrImg) {
                $html .= '<div style="margin-top: 25px; text-align: center;">';
                $html .= '<img src="' . $qrImg . '" style="width: 45mm; height: 45mm; border: 1px solid #ddd;">';
                $html .= '<p style="font-size: 10px; color: #444; margin-top: 5px;">EPC QR – Scan & Pay</p>';
                $html .= '</div>';
            }
        }
    }
}
/* ----------------- Build HTML for PDF ------------------- */
$html  = '<html><head><meta charset="utf-8">';
$html .= '<style>
  body{
    font-family:DejaVu Sans,Helvetica,Arial,sans-serif;
    font-size:12px;
    color:#111;
  }
  h1{
    font-size:18px;
    margin:0 0 4px 0;
  }
  .muted{
    color:#555;
    font-size:11px;
  }
  .res-id{
    font-size:13px;
    font-weight:bold;
    margin:2px 0 12px 0;
  }
  .layout-two-cols{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
  }
  .layout-two-cols td{
    vertical-align:top;
    width:50%;
    padding:0 8px;
  }
  .section-title{
    font-size:14px;
    margin:0 0 4px 0;
    font-weight:bold;
  }
  .info-table td{
    padding:20px 4px;
    vertical-align:top;
  }
  .table td:first-child{
    padding-right:20px;
    white-space:nowrap;
  }
  .box{
    border:1px solid #ccc;
    border-radius:6px;
    padding:8px 10px;
    margin-top:6px;
  }
  .mono{
    font-family:monospace;
    font-size:10px;
    white-space:pre-wrap;
    word-break:break-word;
  }
  .qrbox{
    border:1px solid #ddd;
    border-radius:8px;
    padding:10px;
    text-align:center;
    background:#fafafa;
  }
  .qr-placeholder{
    display:block;
    border:1px dashed #bbb;
    border-radius:8px;
    padding:18px 12px;
    font-size:11px;
    color:#555;
    background:#fff;
  }
</style>';

$html .= '</head><body>';

$html .= '<h1>Potrditev rezervacije</h1>';
$html .= '<p class="res-id"><strong>Št. rezervacije:</strong> ' . h($id) . '</p>';

$html .= '<h2>Osnovni podatki</h2>';
$html .= '<table>';
$html .= '<tr><td style="padding-right:14px;"><b>Gost</b></td><td>' . h($guestName) . '</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Skupina gostov</b></td><td>' . h($groupStr) . '</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Enota</b></td><td>' . h($unit) . '</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Termin</b></td><td>' . h($fromFmt) . ' – ' . h($toFmt) . '</td></tr>';

if ($created !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Ustvarjeno</b></td><td>' . h($created) . '</td></tr>';
}
if ($confirmed !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Potrjeno</b></td><td>' . h($confirmed) . '</td></tr>';
}
$html .= '</table>';

$html .= '<h2>Cenovni povzetek</h2>';
$html .= '<table>';
$html .= '<tr><td style="padding-right:14px;"><b>Osnovna cena</b></td><td>' . h($eur($calc_base)) . '</td></tr>';

$discTotal = $calc_discounts + $calc_promo + $calc_special;
$html .= '<tr><td style="padding-right:14px;"><b>Popusti (vključno s promo/akcijami)</b></td><td>';
$html .= $discTotal != 0.0 ? '- ' . h($eur(abs($discTotal))) : h($eur(0.0));
$html .= '</td></tr>';

$html .= '<tr><td style="padding-right:14px;"><b>Čiščenje</b></td><td>' . h($eur($calc_cleaning)) . '</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Skupaj nastanitev (brez TT)</b></td><td><b>' . h($eur($calc_final)) . '</b></td></tr>';

$ttCell = $calc_tt > 0.0 ? $eur($calc_tt) : 'po veljavnem ceniku občine';
$html .= '<tr><td style="padding-right:14px;"><b>Turistična taksa</b></td><td>' . h($ttCell) . '</td></tr>';

if ($keycard_count > 0) {
    $html .= '<tr><td style="padding-right:14px;"><b>KEYCARD prihranek (' . (int)$keycard_count . ')</b></td><td>- ' . h($eur($calc_keycard)) . '</td></tr>';
    if ($tt_net_to_pay > 0) {
        $html .= '<tr><td style="padding-right:14px;"><b>TT za plačilo po prihranku</b></td><td>' . h($eur($tt_net_to_pay)) . '</td></tr>';
    }
}
if ($offers_txt !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Akcije</b></td><td>' . h($offers_txt) . '</td></tr>';
}
if ($promoCode !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Promo koda</b></td><td>' . h($promoCode) . '</td></tr>';
}
$html .= '</table>';

$html .= '<h2>Plačilo</h2>';

if ($method === 'sepa' && $sepa) {
    $html .= '<div class="box">';
    $html .= '<p><b>SEPA plačilo (CM Plus – nakazilo / EPC)</b></p>';
    $html .= '<table>';
    $html .= '<tr><td><b>Prejemnik</b></td><td>' . h((string)($sepa['name'] ?? '')) . '</td></tr>';
    $html .= '<tr><td><b>IBAN</b></td><td>' . h((string)($sepa['iban'] ?? '')) . '</td></tr>';
    $html .= '<tr><td><b>BIC</b></td><td>' . h((string)($sepa['bic'] ?? '')) . '</td></tr>';
    $html .= '<tr><td><b>Znesek</b></td><td>' . h((string)($sepa['amount_eur'] ?? '')) . '</td></tr>';
    $html .= '<tr><td><b>Sklic / namen</b></td><td>' . h((string)($sepa['remittance'] ?? '')) . '</td></tr>';
    $html .= '</table>';

    if ($bonusDeadlineFmt !== '' || $paymentDeadlineFmt !== '') {
        $html .= '<div class="box" style="margin-top:6px;">';
        if ($bonusDeadlineFmt !== '') {
            $html .= '<div><b>Rok za zgodnje plačilo (bonus):</b> ' . h($bonusDeadlineFmt) . '</div>';
        }
        if ($paymentDeadlineFmt !== '') {
            $html .= '<div><b>Končni rok plačila:</b> ' . h($paymentDeadlineFmt) . '</div>';
        }
        $html .= '<div class="muted" style="margin-top:4px;">Po preteku roka za zgodnje plačilo se bonus ne upošteva več. Po preteku končnega roka si pridržujemo pravico do samodejne odpovedi rezervacije.</div>';
        $html .= '</div>';
    }

    $html .= '<div style="margin-top:8px;" class="qrbox">';
    $html .= '<div class="muted" style="margin-bottom:6px;">SEPA QR (EPC):</div>';

    if ($qrDataUri !== null) {
        $html .= '<img src="' . h($qrDataUri) . '" alt="SEPA QR" style="width:170px;height:170px;" />';
    } else {
        $html .= '<div class="qr-placeholder">';
        $html .= '<b>QR ni na voljo</b><br>';
        $html .= 'Lokalni EPC QR generator trenutno ni na voljo na strežniku.';
        $html .= '</div>';
    }

    $html .= '</div>';

    if ($epcPayload !== '') {
        $html .= '<h2>EPC vsebina</h2>';
        $html .= '<div class="mono">' . h($epcPayload) . '</div>';
    } else {
        $html .= '<div class="box" style="margin-top:8px;">';
        $html .= '<b>Opomba:</b> EPC vsebina ni na voljo v tej rezervaciji.';
        $html .= '</div>';
    }

    $html .= '</div>';
} elseif ($method === 'at_desk') {
    $html .= '<div class="box"><b>Plačilo ob prihodu (na recepciji).</b><br><span class="muted">Ta PDF ne vsebuje SEPA podatkov – plačilo izvedete ob prihodu.</span></div>';
} else {
    $html .= '<div class="box"><b>Način plačila:</b> ' . h($method ?: 'ni nastavljen') . '</div>';
}

$html .= '<p class="muted" style="margin-top:22px;">To potrdilo je bilo generirano avtomatsko s strani sistema Channel Manager. V primeru vprašanj nas prosim kontaktirajte.</p>';
$html .= '</body></html>';

/* ----------------- Render PDF via Dompdf ---------------- */
$dompdf_autoload_candidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoload_ok = false;
foreach ($dompdf_autoload_candidates as $c) {
    if (is_file($c)) {
        require_once $c;
        $autoload_ok = true;
        break;
    }
}

if (!$autoload_ok || !class_exists('\\Dompdf\\Dompdf')) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

use Dompdf\Dompdf;

$dompdf = new Dompdf([
    'isRemoteEnabled' => true,
]);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'Reservation-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $id) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);