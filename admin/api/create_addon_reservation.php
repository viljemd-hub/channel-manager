<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/create_addon_reservation.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Purpose: create an "addon" reservation - extra guests joining an existing
 * (parent) reservation's already-booked accommodation for part or all of
 * its stay, at their own arrival/departure dates. A full, independent
 * reservation record (own id, own Guestbook registration and AJPES
 * submission where that module is installed, own remote self-check-in
 * link where public/remote_checkin.php exists - see $hasRemoteCheckin
 * below, CM Free's public repo doesn't ship that file at all) -
 * deliberately does NOT write an occupancy.json segment, since the parent
 * reservation already blocks the unit for these dates; the addon shares
 * that same physical space rather than booking more of it.
 *
 * Real trigger, 2026-09-05: a guest's son+girlfriend joined her existing
 * 14-night stay for a week, with their own dates - see
 * [[cleaning_day_override_feature]]-adjacent session notes for the full
 * incident this was designed from (bumping the parent reservation's own
 * `adults` count would have corrupted its already-AJPES-submitted TT
 * headcount and imputed wrong dates onto the new guests).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Europe/Ljubljana');

require_once __DIR__ . '/../../common/lib/urls.php'; // cm_public_url

function car_respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function car_generate_id(string $unit): string {
    $unit = preg_replace('~[^A-Za-z0-9_-]~', '', $unit) ?? $unit;
    return (new DateTimeImmutable())->format('YmdHis') . '-' . bin2hex(random_bytes(2)) . '-' . $unit;
}

/** Same lookup pattern duplicated across this project's admin/api/*.php files (no shared helper exists yet). */
function car_find_reservation(string $resRoot, string $id): ?array {
    $id = trim($id);
    if ($id === '') return null;

    $year = substr($id, 0, 4);
    $unit = '';
    if (preg_match('~-[0-9a-f]{4}-([A-Za-z0-9_-]+)$~', $id, $m)) {
        $unit = $m[1];
    }

    $candidates = [];
    if ($unit !== '') {
        $candidates[] = sprintf('%s/%s/%s/%s.json', $resRoot, $year, $unit, $id);
    } else {
        foreach (glob($resRoot . '/' . $year . '/*/' . $id . '.json') ?: [] as $p) {
            $candidates[] = $p;
        }
    }

    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $j = json_decode((string)@file_get_contents($p), true);
        if (!is_array($j)) continue;
        $j['_file'] = $p;
        return $j;
    }

    foreach (glob($resRoot . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
        foreach (glob($yearDir . '/*/' . $id . '.json') ?: [] as $p) {
            $j = json_decode((string)@file_get_contents($p), true);
            if (!is_array($j)) continue;
            $j['_file'] = $p;
            return $j;
        }
    }
    return null;
}

/** Sum of adults+kids06+kids712 across every non-cancelled addon of $parentId (cancelled ones are moved out of reservations/ entirely, so a plain scan already excludes them). */
function car_existing_addon_headcount(string $resRoot, string $unit, string $parentId): int {
    $total = 0;
    foreach (glob($resRoot . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
        $unitDir = $yearDir . '/' . $unit;
        if (!is_dir($unitDir)) continue;
        foreach (glob($unitDir . '/*.json') ?: [] as $file) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (!is_array($j)) continue;
            if ((string)($j['meta']['addon_of'] ?? '') !== $parentId) continue;
            $total += (int)($j['adults'] ?? 0) + (int)($j['kids06'] ?? 0) + (int)($j['kids712'] ?? 0);
        }
    }
    return $total;
}

$appRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$resRoot = $appRoot . '/common/data/json/reservations';
$unitsRoot = $appRoot . '/common/data/json/units';

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    car_respond(false, ['error' => 'invalid_json'], 400);
}

$parentId = trim((string)($data['parent_id'] ?? ''));
if ($parentId === '') {
    car_respond(false, ['error' => 'missing_parent_id'], 400);
}

$parent = car_find_reservation($resRoot, $parentId);
if ($parent === null) {
    car_respond(false, ['error' => 'parent_not_found'], 404);
}
if ((string)($parent['status'] ?? '') !== 'confirmed') {
    car_respond(false, ['error' => 'parent_not_confirmed'], 400);
}

$unit = (string)($parent['unit'] ?? '');
$parentFrom = (string)($parent['from'] ?? '');
$parentTo = (string)($parent['to'] ?? '');

$from = trim((string)($data['from'] ?? ''));
$to = trim((string)($data['to'] ?? ''));
$guestName = trim((string)($data['guest_name'] ?? ''));
$guestPhoneRaw = trim((string)($data['guest_phone'] ?? ''));
$guestEmail = trim((string)($data['guest_email'] ?? ''));
$note = trim((string)($data['note'] ?? ''));
$lang = trim((string)($data['lang'] ?? ($parent['lang'] ?? 'en')));

$adults = is_numeric($data['adults'] ?? null) ? max(0, (int)$data['adults']) : 0;
$kids06 = is_numeric($data['kids06'] ?? null) ? max(0, (int)$data['kids06']) : 0;
$kids712 = is_numeric($data['kids712'] ?? null) ? max(0, (int)$data['kids712']) : 0;

$errors = [];
if ($guestName === '') $errors['guest_name'] = 'required';
if ($from === '') $errors['from'] = 'required';
if ($to === '') $errors['to'] = 'required';

$dtFrom = DateTime::createFromFormat('Y-m-d', $from) ?: null;
$dtTo = DateTime::createFromFormat('Y-m-d', $to) ?: null;
if (!$dtFrom || $dtFrom->format('Y-m-d') !== $from) $errors['from'] = 'invalid_date';
if (!$dtTo || $dtTo->format('Y-m-d') !== $to) $errors['to'] = 'invalid_date';

$totalGuests = $adults + $kids06 + $kids712;
if ($totalGuests <= 0) $errors['guest_counts'] = 'at_least_one_guest_required';

// Addon must stay within the parent's own stay window - it shares the
// parent's already-booked accommodation, it can't extend beyond it.
if (!$errors && $from < $parentFrom) $errors['from'] = 'before_parent_arrival';
if (!$errors && $to > $parentTo) $errors['to'] = 'after_parent_departure';
if (!$errors && $from >= $to) $errors['range'] = 'from_must_be_before_to';

if ($errors) {
    car_respond(false, ['error' => 'validation_failed', 'details' => $errors], 400);
}

// Capacity check against the unit's own max_guests, parent's headcount, and
// every other currently-active addon's headcount - never let this addon
// push the unit over its real physical capacity.
$maxGuests = null;
$unitSettingsPath = $unitsRoot . '/' . $unit . '/site_settings.json';
if (is_file($unitSettingsPath)) {
    $unitSettings = json_decode((string)@file_get_contents($unitSettingsPath), true);
    if (is_array($unitSettings) && isset($unitSettings['capacity']['max_guests']) && is_numeric($unitSettings['capacity']['max_guests'])) {
        $maxGuests = (int)$unitSettings['capacity']['max_guests'];
    }
}

if ($maxGuests !== null) {
    $parentHeadcount = (int)($parent['adults'] ?? 0) + (int)($parent['kids06'] ?? 0) + (int)($parent['kids712'] ?? 0);
    $existingAddonHeadcount = car_existing_addon_headcount($resRoot, $unit, $parentId);
    $used = $parentHeadcount + $existingAddonHeadcount;
    $available = max(0, $maxGuests - $used);

    if ($totalGuests > $available) {
        car_respond(false, [
            'error' => 'over_capacity',
            'max_guests' => $maxGuests,
            'used' => $used,
            'available' => $available,
            'requested' => $totalGuests,
        ], 400);
    }
}

$guestPhone = '';
if ($guestPhoneRaw !== '') {
    $hasPlus = str_starts_with($guestPhoneRaw, '+');
    $digits = preg_replace('~\D+~', '', $guestPhoneRaw) ?? '';
    if ($digits !== '') $guestPhone = $hasPlus ? ('+' . $digits) : $digits;
}
if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    car_respond(false, ['error' => 'validation_failed', 'details' => ['guest_email' => 'invalid']], 400);
}

$nowIso = (new DateTimeImmutable())->format('c');
$year = $dtFrom->format('Y');
$dir = $resRoot . '/' . $year . '/' . $unit;
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    car_respond(false, ['error' => 'mkdir_failed', 'dir' => $dir], 500);
}

$id = null;
for ($i = 0; $i < 5; $i++) {
    $candidate = car_generate_id($unit);
    if (!file_exists($dir . '/' . $candidate . '.json')) {
        $id = $candidate;
        break;
    }
}
if ($id === null) {
    car_respond(false, ['error' => 'id_collision'], 500);
}

$nights = max(0, (int)$dtFrom->diff($dtTo)->days);
// Remote self-check-in (public/remote_checkin.php) doesn't exist in CM
// Free's public repo at all (Guestbook-only feature) - detect it instead
// of hardcoding a Plus/PRO assumption, so this same file works correctly
// across every tier without a separate stripped-down copy.
$hasRemoteCheckin = is_file($appRoot . '/public/remote_checkin.php');
$checkinToken = $hasRemoteCheckin ? bin2hex(random_bytes(16)) : '';

$res = [
    'id' => $id,
    'status' => 'confirmed',
    'created' => $nowIso,
    'unit' => $unit,
    'lang' => $lang,
    'from' => $from,
    'to' => $to,
    'nights' => $nights,
    'adults' => $adults,
    'kids06' => $kids06,
    'kids712' => $kids712,
    'guest' => [
        'name' => $guestName,
        'phone' => $guestPhone,
        'email' => $guestEmail,
        'note' => $note,
    ],
    'promo_code' => '',
    'promo' => ['code' => '', 'amount' => 0],
    // No accommodation charge - addon guests share the parent's
    // already-paid space, only their own tourist tax applies (per
    // checkin_tt.php's own ct_guestbook_tt_total()).
    'calc' => ['base' => 0, 'discounts' => 0, 'promo' => 0, 'special_offers' => 0, 'cleaning' => 0, 'final' => 0],
    'tt' => ['total' => 0, 'keycard_count' => 0, 'keycard_saving' => 0, 'keycard_note' => ''],
    'special_offer_meta' => ['name' => '', 'percent' => 0],
    'meta' => [
        'source' => 'addon',
        'lang' => $lang,
        'accept_soft_hold' => false,
        'clean_before_flag' => false,
        'clean_after_flag' => false,
        'channel' => 'addon',
        'addon_of' => $parentId,
        'addon_created_at' => $nowIso,
        'addon_created_via' => 'admin/api/create_addon_reservation.php',
    ],
    'stage' => 'addon_confirmed',
    'accepted_at' => $nowIso,
    'confirmed_at' => $nowIso,
    'secure_token' => '',
    'token_expires_at' => '',
    'lock' => 'hard',
    'payment_method' => 'addon',
    'source' => 'addon',
    'payment' => ['method' => 'addon', 'status' => 'n/a'],
    'cancel_token' => '',
    'cancel_link' => '',
    'pdf_token' => '',
    'pdf_link' => '',
    'review' => ['has_review' => false, 'score' => 0, 'public_allowed' => false],
    'review_score' => 0,
    'channel' => 'addon',
    'checkin_token' => $checkinToken,
    'checkin_link' => $hasRemoteCheckin
        ? cm_public_url('public/remote_checkin.php?token=' . rawurlencode($checkinToken))
        : '',
];

$json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($dir . '/' . $id . '.json', $json) === false) {
    car_respond(false, ['error' => 'write_failed'], 500);
}

// Deliberately no occupancy.json write, no cm_regen_merged_for_unit() call -
// the parent reservation's own segment already covers these dates for this
// unit; an addon must never independently block/appear on the calendar.

car_respond(true, [
    'id' => $id,
    'unit' => $unit,
    'from' => $from,
    'to' => $to,
    'checkin_link' => $res['checkin_link'],
    'has_remote_checkin' => $hasRemoteCheckin,
    'parent_id' => $parentId,
]);
