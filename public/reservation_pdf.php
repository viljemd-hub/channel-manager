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
 * Generates a PDF confirmation for a reservation with SEPA data + optional QR.
 * Primarno za CM Plus (SEPA + časovnice). V Free načinu je PDF neobvezen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/lib/datetime_fmt.php';

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Pretvori ISO8601 timestamp (bonus_deadline_at, payment_deadline_at)
 * v lokalni zapis d.m.Y H:i za izpis v PDF.
 */
function cm_fmt_iso_local(?string $iso, string $tz, string $fmt = 'd.m.Y H:i'): string {
    if ($iso === null || $iso === '') return '';
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($fmt);
    } catch (Throwable $e) {
        return '';
    }
}

$APP      = '/var/www/html/app';
$RES_ROOT = $APP . '/common/data/json/reservations';

if (!function_exists('cm_json_read')) {
    function cm_json_read(string $path) {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

$id    = trim($_GET['id']    ?? '');
$token = trim($_GET['token'] ?? '');

if ($id === '' || $token === '') {
    http_response_code(400);
    echo 'Missing id or token.';
    exit;
}

// poiščemo rezervacijo po ID-ju v vseh letih / enotah
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

// dodamo *_fmt polja za datume, če še niso prisotna
// dodamo *_fmt polja za datume, če še niso prisotna (ok, a v PDF ne zaupamo *_fmt)
cm_add_formatted_fields($res, [
    'from'        => 'date',
    'to'          => 'date',
    'created'     => 'datetime',
    'accepted_at' => 'datetime',
    'confirmed_at'=> 'datetime'
], $cfg);

// surovi datumi iz JSON (ISO / Y-m-d)
$fromRaw      = (string)($res['from'] ?? '');
$toRaw        = (string)($res['to'] ?? '');
$createdRaw   = (string)($res['created'] ?? '');
$confirmedRaw = (string)($res['confirmed_at'] ?? '');

// lokalni formatterji za PDF (EU zapis, da se izognemo Y-0-d)
$fmtDate = function (string $d): string {
    if ($d === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
};
$fmtDateTime = function (string $iso) use ($tz): string {
    if ($iso === '') return '';
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

// gost in skupina gostov (kot v mailu)
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
if ($adults)  $gParts[] = $adults  . ' odraslih';
if ($kids712) $gParts[] = $kids712 . ' otrok (7–12)';
if ($kids06)  $gParts[] = $kids06  . ' otrok (0–6)';
$groupStr = $gParts ? implode(', ', $gParts) : '—';

$payment   = is_array($res['payment'] ?? null) ? $res['payment'] : [];
$method    = strtolower((string)($payment['method'] ?? $res['payment_method'] ?? ''));
$sepa      = is_array($payment['sepa'] ?? null) ? $payment['sepa'] : null;

// časovnice za CM Plus (če obstajajo)
$bonusDeadlineIso   = (string)($payment['bonus_deadline_at'] ?? '');
$paymentDeadlineIso = (string)($payment['payment_deadline_at'] ?? '');
$bonusDeadlineFmt   = cm_fmt_iso_local($bonusDeadlineIso,   $tz);
$paymentDeadlineFmt = cm_fmt_iso_local($paymentDeadlineIso, $tz);

$eur = function($n): string {
    return number_format((float)$n, 2, ',', '.') . ' €';
};
$getNum = function(array $arr, string $keyPath, float $default=0.0) {
    $cur = $arr;
    foreach (explode('.', $keyPath) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
        $cur = $cur[$k];
    }
    return is_numeric($cur) ? (float)$cur : $default;
};

// vrednosti iz nested calc/tt struktur (kot v inquiry JSON)
$calc_base      = $getNum($res, 'calc.base', 0.0);
$calc_discounts = $getNum($res, 'calc.discounts', 0.0);
$calc_promo     = $getNum($res, 'calc.promo', 0.0);
$calc_special   = $getNum($res, 'calc.special_offers', 0.0);
$calc_cleaning  = $getNum($res, 'calc.cleaning', 0.0);
$calc_final     = $getNum($res, 'calc.final', 0.0);
$calc_tt        = $getNum($res, 'tt.total', 0.0);

$keycard_count  = (int)$getNum($res, 'tt.keycard_count', 0.0);
$calc_keycard   = $getNum($res, 'tt.keycard_saving', 0.0);

// TT po prihranku (če jo imamo; drugače 0)
if ($calc_tt > 0.0 && $calc_keycard > 0.0) {
    $tt_net_to_pay = max($calc_tt - $calc_keycard, 0.0);
} else {
    $tt_net_to_pay = 0.0;
}

$offers_txt = (string)($res['special_offers_txt'] ?? $res['offers_txt'] ?? '');
$promoCode  = (string)($res['promo']['code'] ?? $res['promo_code'] ?? '');

/* --------- Optional QR (phpqrcode, if present) --------- */
$qrDataUri   = null;
$epcPayload  = '';
if ($method === 'sepa' && is_array($sepa) && !empty($sepa['epc_payload'])) {
    $epcPayload = (string)$sepa['epc_payload'];

    $qrLibLoaded = false;
    if (!class_exists('QRcode')) {
        $candidates = [
            __DIR__ . '/../vendor/phpqrcode/qrlib.php',
            __DIR__ . '/../common/lib/phpqrcode/qrlib.php',
        ];
        foreach ($candidates as $cPath) {
            if (is_file($cPath)) {
                require_once $cPath;
                $qrLibLoaded = true;
                break;
            }
        }
    } else {
        $qrLibLoaded = true;
    }

    if ($qrLibLoaded && class_exists('QRcode')) {
        ob_start();
        QRcode::png($epcPayload, null, QR_ECLEVEL_M, 4, 1);
        $pngData = ob_get_clean();
        if ($pngData !== false) {
            $qrDataUri = 'data:image/png;base64,' . base64_encode($pngData);
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
    margin:0 0 4px 0; /* malo manj spodnjega odmika */
  }
  .muted{
    color:#555;
    font-size:11px;
  }
  .res-id{
    font-size:13px;
    font-weight:bold;
    margin:2px 0 12px 0; /* malo prostora pod naslovom in pred tabelo */
  }

  /* glavni 2-stolpčni layout za Osnovne podatke + Cenovni povzetek */
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
  /* dodatni "presledki" med opisom in vrednostjo */
  .table td:first-child{
    padding-right:20px;   /* približno kot 4 presledki */
    white-space:nowrap;   /* da labela ostane v eni vrstici */
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
    padding:8px;
    text-align:center;
  }
</style>';

$html .= '</head><body>';

$html .= '<h1>Potrditev rezervacije</h1>';
$html .= '<p class="res-id"><strong>Št. rezervacije:</strong> '.h($id).'</p>';

$html .= '<h2>Osnovni podatki</h2>';
$html .= '<table>';
$html .= '<tr><td style="padding-right:14px;"><b>Gost</b></td><td>'.h($guestName).'</td></tr>';
// če imaš že skupino gostov, jo dodaj tako:
if (isset($groupStr)) {
    $html .= '<tr><td style="padding-right:14px;"><b>Skupina gostov</b></td><td>'.h($groupStr).'</td></tr>';
}
$html .= '<tr><td style="padding-right:14px;"><b>Enota</b></td><td>'.h($unit).'</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Termin</b></td><td>'.h($fromFmt).' – '.h($toFmt).'</td></tr>';
if ($created !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Ustvarjeno</b></td><td>'.h($created).'</td></tr>';
}
if ($confirmed !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Potrjeno</b></td><td>'.h($confirmed).'</td></tr>';
}
$html .= '</table>';

$html .= '<h2>Cenovni povzetek</h2>';
$html .= '<table>';
$html .= '<tr><td style="padding-right:14px;"><b>Osnovna cena</b></td><td>'.h($eur($calc_base)).'</td></tr>';

$discTotal = $calc_discounts + $calc_promo + $calc_special;
$html .= '<tr><td style="padding-right:14px;"><b>Popusti (vključno s promo/akcijami)</b></td><td>';
if ($discTotal != 0.0) {
    $html .= '- '.h($eur(abs($discTotal)));
} else {
    $html .= h($eur(0.0));
}
$html .= '</td></tr>';

$html .= '<tr><td style="padding-right:14px;"><b>Čiščenje</b></td><td>'.h($eur($calc_cleaning)).'</td></tr>';
$html .= '<tr><td style="padding-right:14px;"><b>Skupaj nastanitev (brez TT)</b></td><td><b>'.h($eur($calc_final)).'</b></td></tr>';

$ttCell = $calc_tt > 0.0 ? $eur($calc_tt) : 'po veljavnem ceniku občine';
$html .= '<tr><td style="padding-right:14px;"><b>Turistična taksa</b></td><td>'.h($ttCell).'</td></tr>';

if ($keycard_count > 0) {
    $html .= '<tr><td style="padding-right:14px;"><b>KEYCARD prihranek ('.(int)$keycard_count.')</b></td><td>- '.h($eur($calc_keycard)).'</td></tr>';
    if ($tt_net_to_pay > 0) {
        $html .= '<tr><td style="padding-right:14px;"><b>TT za plačilo po prihranku</b></td><td>'.h($eur($tt_net_to_pay)).'</td></tr>';
    }
}
if ($offers_txt !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Akcije</b></td><td>'.h($offers_txt).'</td></tr>';
}
if ($promoCode !== '') {
    $html .= '<tr><td style="padding-right:14px;"><b>Promo koda</b></td><td>'.h($promoCode).'</td></tr>';
}
$html .= '</table>';


$html .= '<h2>Plačilo</h2>';
if ($method === 'sepa' && $sepa) {
    // CM Plus SEPA prikaz
    $html .= '<div class="box">';
    $html .= '<p><b>SEPA plačilo (CM Plus – nakazilo / QR)</b></p>';
    $html .= '<table>';
    $html .= '<tr><td><b>Prejemnik</b></td><td>'.h((string)($sepa['name'] ?? '')).'</td></tr>';
    $html .= '<tr><td><b>IBAN</b></td><td>'.h((string)($sepa['iban'] ?? '')).'</td></tr>';
    $html .= '<tr><td><b>BIC</b></td><td>'.h((string)($sepa['bic'] ?? '')).'</td></tr>';
    $html .= '<tr><td><b>Znesek</b></td><td>'.h((string)($sepa['amount_eur'] ?? '')).'</td></tr>';
    $html .= '<tr><td><b>Sklic / namen</b></td><td>'.h((string)($sepa['remittance'] ?? '')).'</td></tr>';
    $html .= '</table>';

    if ($bonusDeadlineFmt !== '' || $paymentDeadlineFmt !== '') {
        $html .= '<div class="box" style="margin-top:6px;">';
        if ($bonusDeadlineFmt !== '') {
            $html .= '<div><b>Rok za zgodnje plačilo (bonus):</b> '.h($bonusDeadlineFmt).'</div>';
        }
        if ($paymentDeadlineFmt !== '') {
            $html .= '<div><b>Končni rok plačila:</b> '.h($paymentDeadlineFmt).'</div>';
        }
        $html .= '<div class="muted" style="margin-top:4px;">Po preteku roka za zgodnje plačilo se bonus ne upošteva več. Po preteku končnega roka si pridržujemo pravico do samodejne odpovedi rezervacije.</div>';
        $html .= '</div>';
    }

    if ($qrDataUri) {
        $html .= '<div style="margin-top:8px;" class="qrbox">';
        $html .= '<div class="muted" style="margin-bottom:4px;">SEPA QR (EPC):</div>';
        $html .= '<img src="'.$qrDataUri.'" alt="SEPA QR" />';
        $html .= '</div>';
    } else {
        $html .= '<div class="box" style="margin-top:8px;">';
        $html .= '<b>Opomba:</b> QR knjižnica na strežniku ni na voljo. Za skeniranje SEPA podatkov lahko uporabite EPC vsebino spodaj.';
        $html .= '</div>';
    }

    if ($epcPayload !== '') {
        $html .= '<h2>EPC vsebina</h2>';
        $html .= '<div class="mono">'.h($epcPayload).'</div>';
    }

    $html .= '</div>';
} elseif ($method === 'at_desk') {
    // Free / klasičen način plačila ob prihodu
    $html .= '<div class="box"><b>Plačilo ob prihodu (na recepciji).</b><br><span class="muted">Ta PDF ne vsebuje SEPA podatkov – plačilo izvedete ob prihodu.</span></div>';
} else {
    $html .= '<div class="box"><b>Način plačila:</b> '.h($method ?: 'ni nastavljen').'</div>';
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
    // fallback: navaden HTML (debug)
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

$filename = 'Reservation-' . preg_replace('/[^A-Za-z0-9_\-]/','',$id) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
