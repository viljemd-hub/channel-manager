<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cancel_reservation.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

//-/var/www/html/app/admin/api/cancel_reservation.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/site_settings.php';
require_once __DIR__ . '/send_rejected.php'; // send_rejected_email()
require_once __DIR__ . '/../../common/lib/email.php';


$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

$APP        = '/var/www/html/app';
$INQ_ROOT   = $APP . '/common/data/json/inquiries';
$RES_ROOT   = $APP . '/common/data/json/reservations';
$UNITS_ROOT = $APP . '/common/data/json/units';
$CAN_ROOT   = $APP . '/common/data/json/cancellations';


/**
 * Najdi accepted soft-hold po ID-ju (nova struktura).
 * /inquiries/YYYY/MM/accepted/ID.json
 */
function find_accepted_soft_hold_by_id(string $root, string $id): array {
    if ($id === '') return [null, null];
    $glob = glob(rtrim($root,'/') . "/*/*/accepted/{$id}.json", GLOB_NOSORT) ?: [];
    if (!$glob) return [null, null];
    $file = $glob[0];
    $data = cm_json_read($file);
    if (!is_array($data)) return [null, null];

    if (($data['status'] ?? '') !== 'accepted' ||
        ($data['stage']  ?? '') !== 'accepted_soft_hold') {
        return [null, null];
    }
    $data['_file'] = $file;
    return [$data, $file];
}

/**
 * Najdi reservations zapis po ID-ju.
 * /reservations/YYYY/UNIT/ID.json
 */
function find_reservation_by_id(string $root, string $id): ?array {
    if ($id === '') return null;
    $glob = glob(rtrim($root,'/') . "/*/*/{$id}.json", GLOB_NOSORT) ?: [];
    if (!$glob) return null;
    $file = $glob[0];
    $data = cm_json_read($file);
    if (!is_array($data)) return null;
    $data['_file'] = $file;
    return $data;
}

/**
 * Premakni reservation JSON iz /reservations/YYYY/UNIT/ID.json
 * v /cancellations/YYYY/UNIT/ID.json (arhiv).
 */
function move_reservation_to_cancellations(string $canRoot, array $resv, string $tz): array {
    $file = $resv['_file'] ?? '';
    if ($file === '') return [false, 'missing_file', null];

    // parse year + unit iz poti reservations
    if (!preg_match('~/reservations/(\d{4})/([^/]+)/~', str_replace('\\','/',$file), $m)) {
        return [false, 'path_parse_failed', null];
    }
    $year = $m[1];
    $unit = $m[2];

    $id = (string)($resv['id'] ?? '');
    if ($id === '') {
        // fallback: filename brez .json
        $id = basename($file, '.json');
    }

    $dir = rtrim($canRoot,'/') . "/{$year}/{$unit}";
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return [false, 'mkdir_failed', $dir];
    }

    $dst = "{$dir}/{$id}.json";

    // ne nosimo _file v arhivu
    unset($resv['_file']);

    // zapiši v cancellations
    cm_json_write($dst, $resv);

    // pobriši original
    @unlink($file);

    return [true, null, $dst];
}

/**
 * Send cancellation email when admin cancels a reservation (no cancel_token branch).
 * Sends only ID + dates + unit, no pricing.
 */
function cm_send_admin_cancel_email(array $resv, string $reason, string $tz): void
{
    if (!function_exists('cm_send_email') || !function_exists('cm_load_settings')) {
        return;
    }

    $settingsAll = cm_load_settings();
    $emailCfg    = $settingsAll['email'] ?? [];
    $enabled     = (bool)($emailCfg['enabled'] ?? false);
    if (!$enabled) {
        return;
    }

    $fromEmail  = (string)($emailCfg['from_email']  ?? 'no-reply@example.test');
    $fromName   = (string)($emailCfg['from_name']   ?? 'Reservations');
    $adminEmail = (string)($emailCfg['admin_email'] ?? '');

    // Guest email (admin-only reservations might still contain email)
    $guestEmail = (string)($resv['guest']['email'] ?? ($resv['email'] ?? ''));
    $guestEmail = trim($guestEmail);
    if ($guestEmail === '') {
        return; // nothing to send to
    }

    // Language
    $lang = (string)($resv['lang'] ?? ($resv['meta']['lang'] ?? 'sl'));
    if (!in_array($lang, ['sl', 'en'], true)) {
        $lang = 'en';
    }

    // Helper for EU date
    $fmtEu = function (?string $d): string {
        $d = (string)$d;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $d;
    };

    $fromRaw = (string)($resv['from'] ?? '');
    $toRaw   = (string)($resv['to']   ?? '');
    $fromEu  = $fmtEu($fromRaw);
    $toEu    = $fmtEu($toRaw);

    $unit = (string)($resv['unit'] ?? '');
    $id   = (string)($resv['id']   ?? '');

    if ($lang === 'en') {
        $subject = 'Reservation cancelled by host'
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' || ($fromEu && $toEu) ? ' – ' : '')
                 . ($unit !== '' ? "{$unit} " : '')
                 . ($fromEu && $toEu ? "({$fromEu} – {$toEu})" : '');

        $hello   = 'Hello';
        $line    = "Your reservation"
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' ? " for {$unit}" : '')
                 . ($fromEu && $toEu ? " from {$fromEu} to {$toEu}" : '')
                 . ' has been cancelled by the host.';
        $reasonLabel = 'Reason for cancellation';
        $footer = "If you have any questions, please contact us.\n\nBest regards,\nApartma Matevž";
    } else {
        $subject = 'Odpoved rezervacije s strani gostitelja'
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' || ($fromEu && $toEu) ? ' – ' : '')
                 . ($unit !== '' ? "{$unit} " : '')
                 . ($fromEu && $toEu ? "({$fromEu} – {$toEu})" : '');

        $hello   = 'Spoštovani';
        $line    = "Vaša rezervacija"
                 . ($id !== '' ? " #{$id}" : '')
                 . ($unit !== '' ? " za enoto {$unit}" : '')
                 . ($fromEu && $toEu ? " za termin {$fromEu} – {$toEu}" : '')
                 . ' je bila odpovedana s strani gostitelja.';
        $reasonLabel = 'Razlog odpovedi';
        $footer = "Če imate kakršnakoli vprašanja, nas prosim kontaktirajte.\n\nLep pozdrav,\nApartma Matevž";
    }

    $safe = function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">';
    $html .= '<p>' . $safe($hello) . '.</p>';
    $html .= '<p>' . $safe($line) . '</p>';
    if ($reason !== '') {
        $html .= '<p><b>' . $safe($reasonLabel) . ':</b> ' . $safe($reason) . '</p>';
    }
    $html .= '<p>' . nl2br($safe($footer)) . '</p>';
    $html .= '</div>';

    $text  = $hello . ".\n\n" . $line . "\n\n";
    if ($reason !== '') {
        $text .= $reasonLabel . ': ' . $reason . "\n\n";
    }
    $text .= $footer . "\n";

    cm_send_email([
        'to'       => $guestEmail,
        'from'     => $fromEmail,
        'fromName' => $fromName,
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $text,
        'bcc'      => $adminEmail ?: null,
    ]);
}



/**
 * Odstrani vse occupancy zapise, kjer 'id' == $reservationId,
 * za dano enoto v /units/<UNIT>/occupancy.json.
 */
function remove_occupancy_by_id(string $unitsRoot, string $unit, string $reservationId): void {
    $path = rtrim($unitsRoot,'/') . "/{$unit}/occupancy.json";
    $occ  = cm_json_read($path);
    if (!is_array($occ)) return;

    $out = [];
    foreach ($occ as $row) {
        if (!is_array($row)) { $out[] = $row; continue; }
        if (($row['id'] ?? null) === $reservationId) {
            // preskoči vse segmente te rezervacije (reserved, clean-before, clean-after, soft-hold ...)
            continue;
        }
        $out[] = $row;
    }
    cm_json_write($path, $out);

    if (function_exists('cm_regen_merged_for_unit')) {
        cm_regen_merged_for_unit($unitsRoot, $unit);
    }
}

// ---------------------------------------------------------
// Input: podpiramo JSON body *in* klasičen POST/GET
// ---------------------------------------------------------
$rawBody = file_get_contents('php://input') ?: '';
$bodyJ   = json_decode($rawBody, true);

$id = '';
$reason = '';

// id: POST > JSON > GET
if (isset($_POST['id'])) {
    $id = trim((string)$_POST['id']);
} elseif (is_array($bodyJ) && isset($bodyJ['id'])) {
    $id = trim((string)$bodyJ['id']);
} else {
    $id = trim((string)($_GET['id'] ?? ''));
}

// reason (opcijski): POST > JSON > GET
if (isset($_POST['reason'])) {
    $reason = trim((string)$_POST['reason']);
} elseif (is_array($bodyJ) && array_key_exists('reason', $bodyJ)) {
    $reason = trim((string)$bodyJ['reason']);
} else {
    $reason = trim((string)($_GET['reason'] ?? ''));
}


if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_id']);
    exit;
}

/* ----------------------------------------------------------------------
 * 1) Poskus: je to accepted soft-hold inquiry?
 * -------------------------------------------------------------------- */
[$soft, $softFile] = find_accepted_soft_hold_by_id($INQ_ROOT, $id);

if ($soft && $softFile) {
    $unit = (string)($soft['unit'] ?? '');
    if ($unit === '') {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'soft_hold_invalid_unit']);
        exit;
    }

    // 1a) Počisti occupancy (soft blok za ta id)
    remove_occupancy_by_id($UNITS_ROOT, $unit, $id);

    // 1b) Premakni v /inquiries/YYYY/MM/rejected/ID.json
    $parts = explode('/', str_replace('\\','/',$softFile));
    $len   = count($parts);
    $month = $len >= 3 ? $parts[$len-3] : substr((string)($soft['from'] ?? ''), 5, 2);
    $year  = $len >= 4 ? $parts[$len-4] : substr((string)($soft['from'] ?? ''), 0, 4);

    $soft['status']        = 'rejected';
    $soft['stage']         = 'rejected';
    $soft['rejected_at']   = cm_iso_now($tz);
    $soft['rejected_by']   = 'admin';
    if ($reason !== '') {
        $soft['reject_reason'] = $reason;
    }

    cm_add_formatted_fields($soft, [
        'from'        => 'date',
        'to'          => 'date',
        'created'     => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ], $cfg);

    $rejDir = "{$INQ_ROOT}/{$year}/{$month}/rejected";
    if (!is_dir($rejDir) && !@mkdir($rejDir, 0775, true)) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'mkdir_rejected_failed','dir'=>$rejDir]);
        exit;
    }
    $rejPath = "{$rejDir}/{$id}.json";
    cm_json_write($rejPath, $soft);

    // 1c) Pošlji "rejected" mail gostu (kupon po želji iz site_settings)
    try {
        $settings   = cm_load_settings();
        $couponCfg  = $settings['reject_coupon'] ?? null; // ali pa null, če ne uporabljaš
        send_rejected_email($soft, $couponCfg, 'admin_soft_hold_cancel');
    } catch (\Throwable $e) {
        error_log('[admin cancel_soft_hold] send_rejected_email failed: ' . $e->getMessage());
        // ne prekinjamo zaradi maila
    }

    // 1d) Odstrani accepted soft-hold datoteko
    @unlink($softFile);

    echo json_encode([
        'ok'   => true,
        'kind' => 'soft_hold',
        'id'   => $id,
        'unit' => $unit,
        'paths'=> [
            'accepted_deleted' => $softFile,
            'rejected'         => $rejPath,
        ]
    ]);
    exit;
}

/* ----------------------------------------------------------------------
 * 2) Če ni soft-hold: poskusi najti reservations zapis (confirmed)
 *    → admin cancel confirmed rezervacije (proxy na public cancel_reservation.php)
 * -------------------------------------------------------------------- */

$resv = find_reservation_by_id($RES_ROOT, $id);
if (!$resv) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found']);
    exit;
}

if ($token === '') {
    // Admin-only reservation without cancel_token → local cancel with email if guest email present
    $unit = $resv['unit'] ?? '';

    // Try to extract unit from path if missing
    if ($unit === '' && isset($resv['_file'])) {
        if (preg_match('~/reservations/\d{4}/([^/]+)/~', str_replace('\\','/',$resv['_file']), $m)) {
            $unit = $m[1];
        }
    }

    if ($reason === '') {
        $reason = 'Preklicano v admin vmesniku (brez cancel_token)';
    }

    // Update reservation fields for archive + email
    $resv['status']        = 'cancelled';
    $resv['stage']         = 'cancelled_admin';
    $resv['cancelled_at']  = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('c');
    $resv['cancel_source'] = 'admin_panel';

    if (!isset($resv['meta']) || !is_array($resv['meta'])) {
        $resv['meta'] = [];
    }
    $resv['meta']['cancelled_by']  = 'admin';
    $resv['meta']['cancel_reason'] = $reason;

    // Move reservation JSON from /reservations → /cancellations and remove original
    [$okMove, $moveErr, $movePath] = move_reservation_to_cancellations($CAN_ROOT, $resv, $tz);
    if (!$okMove) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'move_to_cancellations_failed',
            'detail'=> $moveErr,
            'path'  => $movePath,
        ]);
        exit;
    }

    // Remove all occupancy segments for this reservation id
    if ($unit !== '') {
        remove_occupancy_by_id($UNITS_ROOT, $unit, $id);
    }

    // Send cancellation email to guest if email is present
    cm_send_admin_cancel_email($resv, $reason, $tz);

    echo json_encode([
        'ok'   => true,
        'kind' => 'confirmed_no_token',
        'id'   => $id,
        'unit' => $unit,
    ]);
    exit;
}

// ↓↓↓ obstoječi proxy del pustiš tak, kot je ↓↓↓

// Če admin ni podal razloga, naredi generičnega
if ($reason === '') {
    $reason = 'Preklicano v admin vmesniku';
}

// At this point we know:
// - $resv exists (confirmed reservation)
// - $token !== ''  → direct reservation with cancel_token

// If admin did not provide a reason, use a generic one
if ($reason === '') {
    $reason = 'Preklicano v admin vmesniku';
}

$unit = $resv['unit'] ?? '';

// Try to extract unit from path if missing
if ($unit === '' && isset($resv['_file'])) {
    if (preg_match('~/reservations/\d{4}/([^/]+)/~', str_replace('\\','/',$resv['_file']), $m)) {
        $unit = $m[1];
    }
}

$file = $resv['_file'] ?? null;
if ($file) {
    // Prepare copy for cancellations
    unset($resv['_file']);

    $resv['status']        = 'cancelled';
    $resv['stage']         = 'cancelled_admin';
    $resv['cancelled_at']  = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('c');
    $resv['cancel_source'] = 'admin_panel';

    if (!isset($resv['meta']) || !is_array($resv['meta'])) {
        $resv['meta'] = [];
    }
    $resv['meta']['cancelled_by']   = 'admin';
    $resv['meta']['cancel_reason']  = $reason;

    // Pass original file path so helper can move it
    $resv['_file'] = $file;

    [$movedOk, $movedErr, $movedPath] = move_reservation_to_cancellations($CAN_ROOT, $resv, $tz);
    if (!$movedOk) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'move_to_cancellations_failed',
            'detail'=> $movedErr,
            'path'  => $movedPath,
        ]);
        exit;
    }
}

// Remove all occupancy segments belonging to this reservation id
if ($unit !== '') {
    remove_occupancy_by_id($UNITS_ROOT, $unit, $id);
}

echo json_encode([
    'ok'   => true,
    'kind' => 'confirmed_with_token',
    'id'   => $id,
    'unit' => $unit,
]);

