<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/admin_reserve.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/admin_reserve.php
declare(strict_types=1);
// NOTE: merge-first contract: rollback + 500 ONLY when regen_merged returns false.


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_lib/json_io.php';      // cm_json_read, cm_json_write, json_ok/json_err
require_once __DIR__ . '/_lib/paths.php';       // data_root(), inquiries_root() ...
require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/site_settings.php';
require_once __DIR__ . '/../../common/lib/email.php';


// Canonical roots (same pattern as finalize_reservation.php)
$APP        = '/var/www/html/app';
$DATA_ROOT  = $APP . '/common/data/json';
$ROOT_UNITS = $APP . '/common/data/json/units';


$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

function respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function read_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = (string)@file_get_contents($path);
    if ($raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function read_admin_key(string $path): string {
    if (!is_file($path)) return '';
    return trim((string)@file_get_contents($path));
}

function http_get_json(string $url, int $timeoutSec = 20): array {
    // Prefer cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_TIMEOUT        => $timeoutSec,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $body === '') {
            return ['ok'=>false, 'error'=>'curl_failed', 'http'=>$code, 'detail'=>$err, 'url'=>$url];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['ok'=>false, 'error'=>'bad_json', 'http'=>$code, 'url'=>$url, 'raw'=>substr($body, 0, 200)];
        }
        $j['_http'] = $code;
        return $j;
    }

    // Fallback: allow_url_fopen
    $ctx = stream_context_create(['http'=>['timeout'=>$timeoutSec]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return ['ok'=>false, 'error'=>'http_failed', 'url'=>$url];
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return ['ok'=>false, 'error'=>'bad_json', 'url'=>$url, 'raw'=>substr($body, 0, 200)];
    }
    return $j;
}

function ranges_overlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool {
    // all in YYYY-MM-DD; lexicographic compare is OK
    return ($aStart < $bEnd) && ($bStart < $aEnd);
}

/**
 * Read merged occupancy and return conflicts for the requested [from, toExclusive) range.
 * Supports both schemas: start/end and from/to.
 */
function merged_conflicts(string $mergedPath, string $reqFrom, string $reqToEx): array {
    $j = read_json_file($mergedPath);
    if (!is_array($j)) return ['_ok'=>false, '_error'=>'merged_missing_or_bad_json', '_path'=>$mergedPath];

    $conf = [];
    foreach ($j as $seg) {
        if (!is_array($seg)) continue;
        $s = (string)($seg['start'] ?? $seg['from'] ?? '');
        $e = (string)($seg['end']   ?? $seg['to']   ?? '');
        if ($s === '' || $e === '') continue;

        // Treat ANY merged segment as blocking availability (hard/soft/system/admin/ics)
        if (ranges_overlap($reqFrom, $reqToEx, $s, $e)) {
            $conf[] = [
                'start'  => $s,
                'end'    => $e,
                'lock'   => $seg['lock']   ?? null,
                'source' => $seg['source'] ?? null,
                'id'     => $seg['id']     ?? null,
                'meta'   => $seg['meta']   ?? null,
            ];
            if (count($conf) >= 20) break; // avoid huge payloads
        }
    }

    return ['_ok'=>true, 'count'=>count($conf), 'items'=>$conf];
}

/**
 * Pošlje potrditev rezervacije za ADMIN hard rezervacijo, če je na voljo e-mail.
 * Vrne ['sent'=>bool, 'detail'=>..., 'to'=>..., 'subject'=>...] ali 'skipped'.
 */
function cm_send_reservation_confirmation_admin(array $res): array
{
    // Nastavitve (site_settings.json)
    $settingsAll = function_exists('cm_load_settings') ? cm_load_settings() : [];
    $emailCfg    = is_array($settingsAll['email'] ?? null) ? $settingsAll['email'] : [];

    $enabled = (bool)($emailCfg['enabled'] ?? true);
    if (!$enabled) {
        return ['sent' => false, 'skipped' => 'email_disabled'];
    }

    $to = trim((string)($res['guest']['email'] ?? ''));
    if ($to === '') {
        return ['sent' => false, 'skipped' => 'no_guest_email'];
    }

    $fromEmail = trim((string)($emailCfg['from_email'] ?? ''));
    if ($fromEmail === '') {
        $fromEmail = 'no-reply@localhost';
    }
    $fromName  = trim((string)($emailCfg['from_name'] ?? 'Apartma Matevž'));

    $lang = (string)($res['lang'] ?? ($res['meta']['lang'] ?? 'sl'));
    if (!in_array($lang, ['sl','en'], true)) {
        $lang = 'sl';
    }

    $id   = (string)($res['id'] ?? '');
    $unit = (string)($res['unit'] ?? '');
    $from = (string)($res['from'] ?? '');
    $toDt = (string)($res['to']   ?? '');

    $fmtDate = function (string $d): string {
        if ($d === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $d;
    };

    $fromFmt = $fmtDate($from);
    $toFmt   = $fmtDate($toDt);

    // Cena – vzemi calc.final, fallback na pricing.total
    $totalFinal = 0.0;
    if (isset($res['calc']) && is_array($res['calc'])) {
        $totalFinal = (float)($res['calc']['final'] ?? $res['calc']['base'] ?? 0.0);
    } elseif (isset($res['pricing']) && is_array($res['pricing'])) {
        $totalFinal = (float)($res['pricing']['total'] ?? 0.0);
    }

    $eur = function (float $v): string {
        return number_format($v, 2, ',', '.') . ' €';
    };

    $pdfLink = (string)($res['pdf_link'] ?? '');

    if ($lang === 'en') {
        $subject = "Reservation confirmation #{$id} – {$unit} ({$fromFmt} – {$toFmt})";
        $lines = [
            "Dear guest,",
            "",
            "your reservation is confirmed.",
            "",
            "Unit: {$unit}",
            "Dates: {$fromFmt} – {$toFmt}",
            "Total accommodation amount (excl. tourist tax): " . $eur($totalFinal),
            "Payment method: pay at desk.",
            "",
        ];
        $ttLine = "Tourist tax is not included in the price and will be charged on arrival according to the municipal price list.";
        $pdfText = "PDF confirmation";
        $byeLine = "Kind regards,\n{$fromName}";
    } else {
        $subject = "Potrditev rezervacije #{$id} – {$unit} ({$fromFmt} – {$toFmt})";
        $lines = [
            "Spoštovani,",
            "",
            "vaša rezervacija je potrjena.",
            "",
            "Enota: {$unit}",
            "Termin: {$fromFmt} – {$toFmt}",
            "Skupni znesek nastanitve (brez TT): " . $eur($totalFinal),
            "Način plačila: plačilo ob prihodu (na recepciji).",
            "",
        ];
        $ttLine  = "Turistična taksa ni vključena v ceno in jo poravnate ob prihodu, po veljavnem ceniku občine.";
        $pdfText = "PDF potrdilo";
        $byeLine = "Lep pozdrav,\n{$fromName}";
    }

    if ($pdfLink !== '') {
        $lines[] = $pdfText . ": " . $pdfLink;
        $lines[] = "";
    }

    $lines[] = $ttLine;
    $lines[] = "";
    $lines[] = $byeLine;

    $textBody = implode("\n", $lines);

    $htmlBody = '<html><body><pre style="font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
              . htmlspecialchars($textBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
              . '</pre></body></html>';

    // Optional admin copy (BCC) so the admin knows the guest email was sent.
    $adminEmail = trim((string)($emailCfg['admin_email'] ?? ''));
    $bcc = [];
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) && $adminEmail !== $to) {
        $bcc[] = $adminEmail;
    }

    // Prefer the unified wrapper when available (supports BCC via envelope recipients).
    if (function_exists('cm_send_email_ex')) {
        $result = cm_send_email_ex([
            'to'       => $to,
            'bcc'      => $bcc,
            'from'     => $fromEmail,
            'fromName' => $fromName,
            'subject'  => $subject,
            'html'     => $htmlBody,
            'text'     => $textBody,
        ]);
    } else {
        // Fallback: legacy single-recipient sender (no BCC support).
        $result = cm_send_email_msmtp($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
    }

    return [
        'sent'    => (bool)($result['ok'] ?? false),
        'detail'  => $result,
        'to'      => $to,
        'subject' => $subject,
    ];
}


/**
 * Read JSON body.
 */
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    respond(false, ['error' => 'NO_BODY'], 400);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(false, ['error' => 'BAD_JSON'], 400);
}

/**
 * Required fields from admin calendar
 */
$unit   = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['unit'] ?? ''));
$from   = (string)($data['from'] ?? '');
$to     = (string)($data['to']   ?? '');
$guestName  = trim((string)($data['guest_name']  ?? ''));
$guestEmail = trim((string)($data['guest_email'] ?? ''));
$guestPhone = trim((string)($data['guest_phone'] ?? ''));
$note       = trim((string)($data['note'] ?? ''));

if ($unit === '' || $from === '' || $to === '') {
    respond(false, ['error' => 'MISSING_UNIT_OR_RANGE'], 400);
}
if ($guestName === '') {
    if ($guestName === '') $guestName = 'ADMIN';
}

/**
 * Simple date validation (YYYY-MM-DD)
 */
$reDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reDate, $from) || !preg_match($reDate, $to)) {
    respond(false, ['error' => 'BAD_DATE_FORMAT'], 400);
}

try {
    $dtFrom = new DateTimeImmutable($from, new DateTimeZone($tz));
    $dtTo   = new DateTimeImmutable($to,   new DateTimeZone($tz));

    // ADMIN UI sends last NIGHT (inclusive) → convert to end-exclusive
    $dtToExclusive = $dtTo->modify('+1 day');

    $nights = (int)$dtFrom->diff($dtToExclusive)->days;
    $toExclusive = $dtToExclusive->format('Y-m-d');

} catch (Throwable $e) {
    respond(false, ['error' => 'BAD_DATE'], 400);
}

$diff   = $dtFrom->diff($dtToExclusive);
$nights = (int)$diff->days;


if ($nights <= 0) {
    respond(false, ['error' => 'NON_POSITIVE_NIGHTS'], 400);
}

// ---------------------------------------------------------------------
// PRECHECK: (optional) refresh merged via integrations pull_now, same as autopilot policy
// - If autopilot is enabled and NOT test_mode -> force ICS checks ON (like autopilot_get.php does)
// - If check_ics_on_accept is true -> pull_now for each enabled inbound connection, then merged guard
// ---------------------------------------------------------------------
$unitSettingsPath = $ROOT_UNITS . '/' . $unit . '/site_settings.json';
$unitSettings = read_json_file($unitSettingsPath) ?? [];
$ap = (isset($unitSettings['autopilot']) && is_array($unitSettings['autopilot'])) ? $unitSettings['autopilot'] : [];

$apEnabled  = !empty($ap['enabled']);
$apTestMode = !empty($ap['test_mode']);

// Mirror enforced policy (seen in autopilot_get.php): enabled + !test_mode => checks forced ON
$checkIcs = (bool)($ap['check_ics_on_accept'] ?? false);
if ($apEnabled && !$apTestMode) {
    $checkIcs = true;
}

$icsInfo = [
    'enabled' => $checkIcs,
    'pulled'  => false,
    'results' => [],
];

if ($checkIcs) {
    $adminKey = read_admin_key('/var/www/html/app/common/data/admin_key.txt');
    if ($adminKey === '') {
        respond(false, ['error'=>'ADMIN_KEY_MISSING_FOR_ICS_PULL', 'unit'=>$unit], 500);
    }

    $cfgPath = $DATA_ROOT . '/integrations/' . $unit . '.json';
    $cfg = read_json_file($cfgPath);
    if (!is_array($cfg)) {
        respond(false, ['error'=>'INTEGRATIONS_CFG_MISSING_OR_BAD', 'unit'=>$unit, 'path'=>$cfgPath], 500);
    }

    $connections = (isset($cfg['connections']) && is_array($cfg['connections'])) ? $cfg['connections'] : [];
    $toPull = [];
    foreach ($connections as $platform => $pCfg) {
        if (!is_array($pCfg)) continue;
        $in = (isset($pCfg['in']) && is_array($pCfg['in'])) ? $pCfg['in'] : [];
        if (empty($in['enabled'])) continue;
        $toPull[] = (string)$platform;
    }

    // If nothing enabled, treat as "no-op" (still safe: we will guard against current merged)
    foreach ($toPull as $platform) {
        $url = "http://localhost/app/admin/api/integrations/pull_now.php"
             . "?unit=" . rawurlencode($unit)
             . "&platform=" . rawurlencode($platform)
             . "&key=" . rawurlencode($adminKey);

        $r = http_get_json($url, 30);
        $ok = (bool)($r['ok'] ?? false);

        $icsInfo['results'][] = [
            'platform' => $platform,
            'ok'       => $ok,
            'http'     => $r['_http'] ?? null,
            'error'    => $r['error'] ?? null,
        ];

        if (!$ok) {
            error_log("[admin_reserve] ICS pull_now FAILED unit={$unit} platform={$platform} resp=" . json_encode($r));
            respond(false, [
                'error'    => 'ICS_PULL_FAILED',
                'unit'     => $unit,
                'platform' => $platform,
                'details'  => $r,
            ], 502);
        }
    }

    $icsInfo['pulled'] = true;

    // pull_now already regenerates merged, but we also regenerate once more for safety
    if (function_exists('cm_regen_merged_for_unit')) {
        cm_regen_merged_for_unit($ROOT_UNITS, $unit);
    }
}

// ---------------------------------------------------------------------
// HARD RESERVE GUARD: always precheck against occupancy_merged.json before writing
// ---------------------------------------------------------------------
$mergedPath = $ROOT_UNITS . '/' . $unit . '/occupancy_merged.json';
$guard = merged_conflicts($mergedPath, $from, $toExclusive);

if (!($guard['_ok'] ?? false)) {
    respond(false, [
        'error' => 'MERGED_UNAVAILABLE',
        'unit'  => $unit,
        'path'  => $mergedPath,
        'ics'   => $icsInfo,
        'guard' => $guard,
    ], 500);
}

if (($guard['count'] ?? 0) > 0) {
    respond(false, [
        'error' => 'RANGE_NOT_AVAILABLE',
        'unit'  => $unit,
        'from'  => $from,
        'to'    => $toExclusive,
        'nights'=> $nights,
        'ics'   => $icsInfo,
        'conflicts' => $guard['items'] ?? [],
    ], 409);
}

/**
 * Build ID in the same format as others: YYYYMMDDHHMMSS-xxxx-UNIT
 */
$now     = new DateTimeImmutable('now', new DateTimeZone($tz));
$tsPart  = $now->format('YmdHis');
$randHex = substr(bin2hex(random_bytes(2)), 0, 4);
$id      = sprintf('%s-%s-%s', $tsPart, $randHex, $unit);

/**
 * Reservation – minimal structure compatible with list_reservations.php
 * (per inventory: id, status, created_at, unit, from, to, nights, currency,
 *  pricing, promo_code_used, promo_auto_applied, keycards).
 */
$lang = (string)($data['lang'] ?? 'sl');
if ($lang !== 'sl' && $lang !== 'en') {
    $lang = 'sl';
}

$currency = (string)($data['currency'] ?? 'EUR');
$total    = (float)($data['total_price'] ?? 0.0);

$adults  = (int)($data['adults']  ?? 0);
$kids06  = (int)($data['kids06']  ?? 0);
$kids712 = (int)($data['kids712'] ?? 0);

$reservation = [
    'id'         => $id,
    'status'     => 'confirmed',
    'created_at' => $now->format('Y-m-d H:i:s'),
    'created'    => $now->format(DATE_ATOM),

    'unit'   => $unit,
    'from'   => $from,
    'to'     => $toExclusive,
    'nights' => $nights,

    'adults'  => $adults,
    'kids06'  => $kids06,
    'kids712' => $kids712,
    'lang'    => $lang,

    'currency' => $currency,
    'pricing'  => [
        'total'     => $total,
        'breakdown' => [], // za admin razčlenitev po potrebi
    ],

    'promo_code_used'    => '',
    'promo_auto_applied' => false,
    'promo_code'         => '',
    'promo'              => [
        'code'   => '',
        'amount' => 0,
    ],

    'calc' => [
        'base'           => $total,
        'discounts'      => 0.0,
        'promo'          => 0.0,
        'special_offers' => 0.0,
        'cleaning'       => 0.0,
        'final'          => $total,
    ],

    'tt' => [
        'total'          => 0.0,
        'keycard_count'  => 0,
        'keycard_saving' => 0.0,
        'keycard_note'   => '',
    ],

    'special_offer_meta' => [
        'name'    => '',
        'percent' => 0,
    ],

    'payment_method' => 'at_desk',
    'payment'        => [
        'method' => 'at_desk',
        'status' => 'pay_at_desk',
    ],

    'source'       => 'direct',
    'lock'         => 'hard',
    'confirmed_at' => $now->format(DATE_ATOM),

    'guest' => [
        'name'  => $guestName,
        'email' => $guestEmail,
        'phone' => $guestPhone,
        'note'  => $note,
    ],

    'meta'  => [
        'source'      => 'admin',
        'lang'        => $lang,
        'channel'     => 'direct',
        'admin_note'  => $note,
        'created_by'  => 'admin_panel',
        'created_via' => 'admin_reserve.php',
    ],
];

// Če imamo e-mail gosta, pripravimo cancel/pdf tokene in linke (zadnji vlak).
if ($guestEmail !== '') {
    // Cancel token + link
    if (function_exists('cm_random_token')) {
        $cancelToken = cm_random_token(16);
    } else {
        $cancelToken = bin2hex(random_bytes(16));
    }

    $base = function_exists('cm_public_base_url') ? cm_public_base_url() : '';
    $reservation['cancel_token'] = $cancelToken;
    $reservation['cancel_link']  = $base . '/public/cancel_reservation.php?token=' . $cancelToken;

    // PDF token + link
    if (function_exists('cm_random_token')) {
        $pdfToken = cm_random_token(16);
    } else {
        $pdfToken = bin2hex(random_bytes(16));
    }

    $reservation['pdf_token'] = $pdfToken;
    $reservation['pdf_link']  = $base . '/public/reservation_pdf.php?id='
        . rawurlencode($id) . '&token=' . $pdfToken;
}


/**
 *  Save to /reservations/YYYY/UNIT/ID.json
 */

$RES_ROOT  = $DATA_ROOT . '/reservations';

// Use stay year (from), not creation year
$stayYear = $dtFrom->format('Y');
$yearDir  = $RES_ROOT . '/' . $stayYear;
$unitDir  = $yearDir . '/' . $unit;

if (!is_dir($unitDir) && !@mkdir($unitDir, 0775, true)) {
    respond(false, ['error' => 'MKDIR_RESERVATIONS_FAILED', 'path' => $unitDir], 500);
}

$resPath = $unitDir . '/' . $id . '.json';
if (!cm_json_write($resPath, $reservation)) {
    respond(false, ['error' => 'WRITE_RESERVATION_FAILED', 'path' => $resPath], 500);
}

/**
 * Add occupancy segment to /units/<UNIT>/occupancy.json
 * (admin-made reservation; differs from public flow)
 */
$unitDirRoot  = $DATA_ROOT . '/units/' . $unit;
// occupancy.json path (canonical)
$occPath = $ROOT_UNITS . '/' . $unit . '/occupancy.json';
$occupancyArr = [];

if (is_file($occPath)) {
    $tmp = cm_json_read($occPath);
    if (is_array($tmp)) {
        $occupancyArr = $tmp;
    }
}

$occupancyArr[] = [
    "start"  => $from,
    "end"    => $toExclusive,
    "status" => "reserved",
    "lock"   => "hard",
    "source" => "admin",
    "export" => false,
    "id"     => $id
];



// Sorting is not strictly required, but keeps files stable/readable:
usort($occupancyArr, function($a, $b) {
    return strcmp($a['start'] ?? '', $b['start'] ?? '');
});

if (!cm_json_write($occPath, $occupancyArr)) {
    respond(false, ['error' => 'WRITE_OCCUPANCY_FAILED', 'path' => $occPath], 500);
}

// After writing occupancy.json, regenerate occupancy_merged.json for this unit
$mergedRegenOk = null;

if (function_exists('cm_regen_merged_for_unit')) {
    $mergedRegenOk = cm_regen_merged_for_unit($ROOT_UNITS, $unit);

     // Contract: merge-first. If regen/publish fails, rollback and fail loudly.
     if ($mergedRegenOk === false) {
         $rbOccOk = false;
         if (function_exists('cm_remove_occupancy_by_id')) {
             $rbOccOk = cm_remove_occupancy_by_id($ROOT_UNITS, $unit, $id);
         }

         // Best-effort: also remove reservation JSON (avoid ghost reservations)
         $rbResOk = null;
         if (is_file($resPath)) {
             $rbResOk = @unlink($resPath);
         }

         // Best-effort: attempt to bring merged/public view back to consistent state after rollback
         $regenAfterRb = null;
         $regenAfterRb = cm_regen_merged_for_unit($ROOT_UNITS, $unit);

         error_log(
             "[admin_reserve] regen_merged FAILED unit={$unit} id={$id} mergedRegenOk=" . ($mergedRegenOk ? "1" : "0") .
             " rollback_occ=" . ($rbOccOk ? "1" : "0") .
             " rollback_res=" . (is_bool($rbResOk) ? ($rbResOk ? "1" : "0") : "null") .
             " regen_after_rb=" . (is_bool($regenAfterRb) ? ($regenAfterRb ? "1" : "0") : "null")
         );

         respond(false, [
            'error' => 'regen_merged_failed',
             'unit'  => $unit,
             'id'    => $id,
             'merged_regen'   => $mergedRegenOk,
             'rollback_occ'   => $rbOccOk,
             'rollback_res'   => $rbResOk,
             'regen_after_rb' => $regenAfterRb,
         ], 500);
     }
    
}
// If helper is missing, we should still fail (merge-first contract).
else {
    error_log("[admin_reserve] cm_regen_merged_for_unit missing unit={$unit} id={$id}");
    // Rollback occupancy/reservation best-effort
    if (function_exists('cm_remove_occupancy_by_id')) {
        cm_remove_occupancy_by_id($ROOT_UNITS, $unit, $id);
    }
    if (is_file($resPath)) {
        @unlink($resPath);
    }
    respond(false, ['error' => 'regen_helper_missing', 'unit' => $unit, 'id' => $id], 500);
}
 
/**
+ * (Optional) also write an inquiry-like record to /inquiries/YYYY/MM/confirmed
+ * so it appears in Manage reservations as inquiry-meta.
 */
$INQ_ROOT = inquiries_root();
$y        = $now->format('Y');
$m        = $now->format('m');
$inqDir   = $INQ_ROOT . "/{$y}/{$m}/confirmed";

if (!is_dir($inqDir)) {
    @mkdir($inqDir, 0775, true);
}

$inqPath = $inqDir . '/' . $id . '.json';

$inquiryLike = [
    'id'         => $id,
    'status'     => 'confirmed',
    'created'    => $now->format('Y-m-d H:i:s'),
    'unit'       => $unit,
    'from'       => $from,
    'to'         => $toExclusive,
    'nights'     => $nights,
    'guest'      => [
        'name'  => $guestName,
        'email' => $guestEmail,
        'phone' => $guestPhone,
        'note'  => $note,
    ],
    'calc'       => [
        'total'    => $total,
        'currency' => $currency,
    ],
    'stage'      => 'confirmed_admin',
    'accepted_at'=> $now->format('Y-m-d H:i:s'),
    'meta'       => [
        'source'      => 'admin',
        'channel'     => 'direct',
        'created_via' => 'admin_reserve.php',
    ],
];

cm_json_write($inqPath, $inquiryLike);

// -------------------------
// E-mail potrditev (če je e-mail)
// -------------------------
$mailInfo = null;
if ($guestEmail !== '') {
    try {
        $mailInfo = cm_send_reservation_confirmation_admin($reservation);
    } catch (Throwable $e) {
        error_log("[admin_reserve] email send failed id={$id} error=" . $e->getMessage());
        $mailInfo = ['sent' => false, 'error' => $e->getMessage()];
    }
} else {
    $mailInfo = ['sent' => false, 'skipped' => 'no_guest_email'];
}

respond(true, [
    'id'          => $id,
    'unit'        => $unit,
    'from'        => $from,
    'to'          => $toExclusive,
    'nights'      => $nights,
    'merged_regen'=> $mergedRegenOk,
    'ics'         => $icsInfo ?? ['enabled'=>false, 'pulled'=>false, 'results'=>[]],
    'mail'        => $mailInfo,
]);

