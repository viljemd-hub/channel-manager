<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/accept_inquiry.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * accept_inquiry.php
 *
 * Admin → tab "Povpraševanja" → gumb Confirm.
 *
 * Naloge:
 *  - najde pending povpraševanje po ID-ju,
 *  - ga premakne v /accepted (soft-hold),
 *    status = "accepted", stage = "accepted_soft_hold",
 *    zapiše accepted_at, meta.clean_before/after + soft_hold flag,
 *    generira secure_token + token_expires_at (če še ne obstaja),
 *  - odstrani ID iz pending_requests.json,
 *  - pošlje gostu e-mail z "Prevzemi rezervacijo" linkom
 *    preko cm_send_accept_link($id, false) (i18n: če funkcija podpira lang param).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/send_accept_link.php';

$APP           = '/var/www/html/app';
$INQ_ROOT      = $APP . '/common/data/json/inquiries';
// pending index – še vedno uporabljamo “stari” globalni path
$PENDING_INDEX = $APP . '/common/data/json/pending_requests.json';

$cfg  = cm_datetime_cfg();
$tz   = $cfg['timezone'] ?? 'Europe/Ljubljana';
$mode = $cfg['output_mode'] ?? 'raw';

/* ----------------------------------------------------------------------
 * Input (JSON ali form-urlencoded)
 * -------------------------------------------------------------------- */

$raw   = file_get_contents('php://input') ?: '';
$bodyJ = json_decode($raw, true);

$id = '';
if (isset($_POST['id'])) {
    $id = trim((string)$_POST['id']);
} elseif (is_array($bodyJ) && isset($bodyJ['id'])) {
    $id = trim((string)$bodyJ['id']);
}

if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_id']);
    exit;
}

$softHold    = true;
$cleanBefore = false;
$cleanAfter  = false;

// soft_hold še vedno dovolimo nastavljati prek UI / autopilota,
// clean_before/clean_after pa od tu naprej ignoriramo (legacy)
if (is_array($bodyJ)) {
    $softHold = array_key_exists('soft_hold', $bodyJ)
        ? (bool)$bodyJ['soft_hold']
        : true;
} else {
    $softHold = isset($_POST['soft_hold'])
        ? ((string)$_POST['soft_hold'] === '1')
        : true;
}

/* ----------------------------------------------------------------------
 * Najdi pending/ID.json
 * -------------------------------------------------------------------- */

$pendingGlob = glob("{$INQ_ROOT}/*/*/pending/{$id}.json", GLOB_NOSORT) ?: [];
if (!$pendingGlob) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'pending_not_found', 'id' => $id]);
    exit;
}
$pendingPath = $pendingGlob[0];

// YYYY/MM iz poti
$parts = explode('/', str_replace('\\', '/', $pendingPath));
$len   = count($parts);
if ($len < 4) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'invalid_pending_path', 'path' => $pendingPath]);
    exit;
}
$month = $parts[$len - 3];
$year  = $parts[$len - 4];

$inq = cm_json_read($pendingPath);
if (!is_array($inq)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'invalid_pending_json', 'path' => $pendingPath]);
    exit;
}

/* ----------------------------------------------------------------------
 * SAFETY GATE (local-primary): ICS hard facts block Confirm
 *  - default ON (fail-closed)
 * -------------------------------------------------------------------- */

$GLOBAL_SITE_SETTINGS = $APP . '/common/data/json/units/site_settings.json';
$gSite = cm_json_read($GLOBAL_SITE_SETTINGS);
$icsHardBlocksAccept = true;
if (is_array($gSite)) {
    $icsHardBlocksAccept = (bool)($gSite['safety']['ics_hard_blocks_accept'] ?? true);
}

if ($icsHardBlocksAccept) {
    $unit = trim((string)($inq['unit'] ?? $inq['unit_id'] ?? $inq['apartment'] ?? ''));
    $reqStart = trim((string)($inq['from'] ?? $inq['start'] ?? ''));
    $reqEnd   = trim((string)($inq['to']   ?? $inq['end']   ?? ''));

    if ($unit === '' || $reqStart === '' || $reqEnd === '') {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => 'invalid_inquiry_range',
            'unit'  => $unit,
            'request' => ['start' => $reqStart, 'end' => $reqEnd],
        ]);
        exit;
    }

    $mergedPath = $APP . '/common/data/json/units/' . $unit . '/occupancy_merged.json';
    $merged = cm_json_read($mergedPath);
    if (!is_array($merged)) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => 'ics_state_unavailable',
            'unit'  => $unit,
        ]);
        exit;
    }

    $conflicts = [];
    foreach ($merged as $seg) {
        if (!is_array($seg)) continue;

        $src  = (string)($seg['source'] ?? '');
        $lock = (string)($seg['lock'] ?? '');
        $st   = (string)($seg['status'] ?? '');

        // ICS "hard fact"
        if ($src !== 'ics') continue;
        if ($lock !== 'hard') continue;
        if ($st !== 'reserved') continue;

        $s = (string)($seg['start'] ?? '');
        $e = (string)($seg['end'] ?? '');
        if ($s === '' || $e === '') continue;

        // overlap test: s < reqEnd && reqStart < e
        if ($s < $reqEnd && $reqStart < $e) {
            $meta = (array)($seg['meta'] ?? []);
            $conflicts[] = [
                'id'       => (string)($seg['id'] ?? ''),
                'start'    => $s,
                'end'      => $e,
                'platform' => (string)($seg['platform'] ?? ($meta['platform'] ?? '')),
                'summary'  => (string)($meta['summary'] ?? ''),
            ];
        }
    }

    if (!empty($conflicts)) {
        http_response_code(409);
        echo json_encode([
            'ok'        => false,
            'error'     => 'ics_conflict',
            'unit'      => $unit,
            'request'   => ['start' => $reqStart, 'end' => $reqEnd],
            'conflicts' => $conflicts,
        ]);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * SAFETY GATE (local-secondary): merged occupancy must be free
 *  - prevents overlaps with local/internal/admin/soft_hold blocks
 *  - always ON (no override), because admin Confirm must stay safe
 * -------------------------------------------------------------------- */

$unitLocal     = trim((string)($inq['unit'] ?? $inq['unit_id'] ?? $inq['apartment'] ?? ''));
$reqStartLocal = trim((string)($inq['from'] ?? $inq['start'] ?? ''));
$reqEndLocal   = trim((string)($inq['to']   ?? $inq['end']   ?? ''));

if ($unitLocal === '' || $reqStartLocal === '' || $reqEndLocal === '') {
    http_response_code(422);
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_inquiry_range',
        'unit'    => $unitLocal,
        'request' => ['start' => $reqStartLocal, 'end' => $reqEndLocal],
    ]);
    exit;
}

$mergedPathLocal = $APP . '/common/data/json/units/' . $unitLocal . '/occupancy_merged.json';
$mergedLocal     = cm_json_read($mergedPathLocal);
if (!is_array($mergedLocal)) {
    http_response_code(409);
    echo json_encode([
        'ok'    => false,
        'error' => 'range_state_unavailable',
        'unit'  => $unitLocal,
    ]);
    exit;
}

$localConflicts = [];
foreach ($mergedLocal as $seg) {
    if (!is_array($seg)) {
        continue;
    }

    $s = (string)($seg['start'] ?? '');
    $e = (string)($seg['end'] ?? '');
    if ($s === '' || $e === '') {
        continue;
    }

    // skip completely non-overlapping segments (fast path)
    if ($e <= $reqStartLocal || $reqEndLocal <= $s) {
        continue;
    }

    $src   = (string)($seg['source'] ?? '');
    $lock  = (string)($seg['lock'] ?? '');
    $st    = (string)($seg['status'] ?? '');
    $segId = (string)($seg['id'] ?? '');

    // ICS hard reserved is already handled by the primary gate above
    if ($src === 'ics' && $lock === 'hard' && $st === 'reserved') {
        continue;
    }

    // anything that marks the range as not freely bookable
    $marksBusy =
        $lock === 'hard' ||
        $lock === 'soft' ||
        $st === 'reserved' ||
        $st === 'blocked';

    if (!$marksBusy) {
        continue;
    }

    // ignore potential duplicate from this same inquiry (idempotent re-run)
    if ($segId !== '' && $segId === $id) {
        continue;
    }

    $localConflicts[] = [
        'id'     => $segId,
        'start'  => $s,
        'end'    => $e,
        'status' => $st,
        'lock'   => $lock,
        'source' => $src,
    ];
}

if (!empty($localConflicts)) {
    http_response_code(409);
    echo json_encode([
        'ok'        => false,
        'error'     => 'range_not_free',
        'unit'      => $unitLocal,
        'request'   => ['start' => $reqStartLocal, 'end' => $reqEndLocal],
        'conflicts' => $localConflicts,
    ]);
    exit;
}


/* ----------------------------------------------------------------------
 * Resolve cleaning flags from per-unit site_settings (snapshot)
 * -------------------------------------------------------------------- */

// Unit za branje per-unit nastavitev (če bi kdaj spremenili ključ, ga centralno lovimo tu)
$unitForSettings = trim((string)($inq['unit'] ?? $inq['unit_id'] ?? $inq['apartment'] ?? ''));

if ($unitForSettings !== '') {
    $unitSettingsPath = $APP . '/common/data/json/units/' . $unitForSettings . '/site_settings.json';
    $uSite = cm_json_read($unitSettingsPath);

    if (is_array($uSite) && isset($uSite['auto_block']) && is_array($uSite['auto_block'])) {
        $ab = $uSite['auto_block'];

        // Snapshot trenutnih nastavitev v lokalni spremenljivki
        $cleanBefore = (bool)($ab['before_arrival']   ?? false);
        $cleanAfter  = (bool)($ab['after_departure']  ?? false);
    } elseif (is_array($gSite) && isset($gSite['auto_block']) && is_array($gSite['auto_block'])) {
        // Optional global fallback, če per-unit file še ne obstaja
        $ab = $gSite['auto_block'];
        $cleanBefore = (bool)($ab['before_arrival']   ?? false);
        $cleanAfter  = (bool)($ab['after_departure']  ?? false);
    }
}


/* ----------------------------------------------------------------------
 * pending → accepted (soft-hold)
 * -------------------------------------------------------------------- */

$nowIso = cm_iso_now($tz);

$inq['status']      = 'accepted';
$inq['stage']       = 'accepted_soft_hold';
$inq['accepted_at'] = $nowIso;

if (!isset($inq['meta']) || !is_array($inq['meta'])) {
    $inq['meta'] = [];
}
$inq['meta']['accept_soft_hold']  = (bool)$softHold;
$inq['meta']['clean_before_flag'] = (bool)$cleanBefore;
$inq['meta']['clean_after_flag']  = (bool)$cleanAfter;
// i18n: normalize + persist language for downstream emails/pages
$lang = strtolower(trim((string)($inq['meta']['lang'] ?? ($inq['lang'] ?? 'sl'))));
if (!in_array($lang, ['sl','en'], true)) $lang = 'sl';
$inq['meta']['lang'] = $lang;

// Token + expiry, če še ni nastavljen
if (empty($inq['secure_token'])) {
    $inq['secure_token'] = bin2hex(random_bytes(16));
}
if (empty($inq['token_expires_at'])) {
    try {
        $dt      = new DateTimeImmutable('now', new DateTimeZone($tz));
        $expires = $dt->modify('+2 days')->format('Y-m-d\TH:i:sP');
    } catch (Exception $e) {
        $expires = $nowIso;
    }
    $inq['token_expires_at'] = $expires;
}


// Zapiši v /accepted
$acceptedDir  = "{$INQ_ROOT}/{$year}/{$month}/accepted";
if (!is_dir($acceptedDir) && !@mkdir($acceptedDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mkdir_accepted_failed', 'dir' => $acceptedDir]);
    exit;
}
$acceptedPath = "{$acceptedDir}/{$id}.json";

if (!cm_json_write($acceptedPath, $inq)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_accepted_failed', 'path' => $acceptedPath]);
    exit;
}

// ---------------------------------------------------------
// SOFT HOLD → zapiši blokado v occupancy.json (public to bere)
// ---------------------------------------------------------
$softHoldInfo = null;

if ($softHold) {
    $unitsRoot = $APP . '/common/data/json/units';
    $unit = trim((string)($inq['unit'] ?? $inq['unit_id'] ?? $inq['apartment'] ?? ''));
    $reqStart = trim((string)($inq['from'] ?? $inq['start'] ?? ''));
    $reqEnd   = trim((string)($inq['to']   ?? $inq['end']   ?? ''));

    $occPath   = $unitsRoot . '/' . $unit . '/occupancy.json';

    $occ = cm_json_read($occPath);
    if (!is_array($occ)) $occ = [];

    // dedupe po id (da ne podvajamo)
    $exists = false;
    foreach ($occ as $row) {
        if (is_array($row) && (($row['id'] ?? null) === $id)) { $exists = true; break; }
    }

    if (!$exists) {
        $occ[] = [
            'start'  => $reqStart,
            'end'    => $reqEnd,
            'status' => 'reserved',
            'id'     => $id,
            'source' => 'internal',
            'lock'   => 'soft',
            'meta'   => [
                'inquiry_id' => $id,
                'created_at' => date('c'),
                'expires_at' => (string)($inq['token_expires_at'] ?? ''),
                'note'       => 'soft_hold pending guest confirm',
            ],
        ];

        cm_json_write($occPath, $occ);

        if (function_exists('cm_regen_merged_for_unit')) {
            cm_regen_merged_for_unit($unitsRoot, $unit);
        }

        $softHoldInfo = ['ok' => true, 'deduped' => false, 'path' => $occPath];
    } else {
        $softHoldInfo = ['ok' => true, 'deduped' => true, 'path' => $occPath];
    }
}

// Pobriši pending fajl
@unlink($pendingPath);

// Posodobi pending_requests.json (odstrani ta ID)
cm_pending_index_remove_id($PENDING_INDEX, $id);

/* ----------------------------------------------------------------------
 * Pošlji e-mail z linkom za prevzem rezervacije
 *  - i18n: če cm_send_accept_link podpira 3. parameter (lang), ga podamo.
 * -------------------------------------------------------------------- */

$mailSent  = false;
$mailError = null;

try {
    if (!function_exists('cm_send_accept_link')) {
        $mailError = 'send_accept_link_missing';
    } else {
        // safe call: cm_send_accept_link($id, $isTest, $lang?) – odvisno od definicije
        $resMail = null;
        try {
            $rf = new ReflectionFunction('cm_send_accept_link');
            $argc = $rf->getNumberOfParameters();
            if ($argc >= 3) {
                $resMail = cm_send_accept_link($id, false, $lang);
            } else {
                $resMail = cm_send_accept_link($id, false);
            }
        } catch (Throwable $e) {
            // fallback brez Reflection
            $resMail = cm_send_accept_link($id, false);
        }

        $mailSent = (bool)($resMail['ok'] ?? false);
        if (!$mailSent) {
            $mailError = $resMail['error'] ?? 'send_accept_link_failed';
        }
    }
} catch (Throwable $e) {
    $mailError = $e->getMessage();
}

/* ----------------------------------------------------------------------
 * Odgovor
 * -------------------------------------------------------------------- */

$outInquiry = cm_filter_output_mode($inq, $mode);

echo json_encode([
    'ok'         => true,
    'stage'      => 'accepted',   // za hitro info v admin_shell
    'path'       => $acceptedPath,
    'mail_sent'  => $mailSent,
    'mail_error' => $mailError,
    'data'       => $outInquiry,
    'soft_hold'  => $softHoldInfo,
]);

/* ======================================================================
 * Helperji
 * ==================================================================== */

/**
 * Odstrani en ID iz pending_requests.json.
 */
function cm_pending_index_remove_id(string $indexPath, string $id): void
{
    if (!is_file($indexPath)) {
        return;
    }
    $raw = file_get_contents($indexPath);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return;
    }

    $filtered = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        if (($row['id'] ?? null) === $id) continue;
        $filtered[] = $row;
    }

    cm_json_write($indexPath, $filtered);
}
