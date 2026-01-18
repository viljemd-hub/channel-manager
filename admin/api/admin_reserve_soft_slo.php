<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/admin_reserve_soft_slo.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/admin_reserve_soft.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/site_settings.php';
require_once __DIR__ . '/send_accept_link.php'; // cm_send_accept_link()

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

$APP      = '/var/www/html/app';
$INQ_ROOT = $APP . '/common/data/json/inquiries';

function respond(bool $ok, array $payload = [], int $status = 200): void {
    http_response_code($status);
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Read JSON body ---
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

if (!is_array($data)) {
    respond(false, ['error' => 'invalid_json'], 400);
}

$unit       = trim((string)($data['unit']        ?? ''));
$from       = trim((string)($data['from']        ?? ''));
$to         = trim((string)($data['to']          ?? ''));
$guestName  = trim((string)($data['guest_name']  ?? ''));
$guestEmail = trim((string)($data['guest_email'] ?? ''));
$guestPhone = trim((string)($data['guest_phone'] ?? ''));
$note       = trim((string)($data['note']        ?? ''));
$totalPrice = isset($data['total_price']) ? (float)$data['total_price'] : 0.0;

// Basic validation
if ($unit === '' || $from === '' || $to === '') {
    respond(false, ['error' => 'missing_fields'], 400);
}

// For SOFT mode, email is mandatory
if ($guestEmail === '' || strpos($guestEmail, '@') === false) {
    respond(false, ['error' => 'missing_email'], 400);
}

try {
    $dFrom = new DateTimeImmutable($from, new DateTimeZone($tz));
    $dTo   = new DateTimeImmutable($to,   new DateTimeZone($tz));
} catch (\Throwable $e) {
    respond(false, ['error' => 'invalid_dates'], 400);
}

// Calendar dates are inclusive → nights
$diff   = $dTo->diff($dFrom);
$nights = (int)$diff->days;
if ($nights <= 0) {
    respond(false, ['error' => 'non_positive_nights'], 400);
}

// ID format (same as other reservations/inquiries): YYYYMMDDHHMMSS-rand-UNIT
$now     = new DateTimeImmutable('now', new DateTimeZone($tz));
$stamp   = $now->format('YmdHis');
$rand    = substr(bin2hex(random_bytes(2)), 0, 4);
$id      = "{$stamp}-{$rand}-{$unit}";

$createdIso  = $now->format('Y-m-d\TH:i:sP');
$acceptedIso = $createdIso;

// Base currency from settings (if present)
$settings = cm_load_settings();
$currency = $settings['currency'] ?? 'EUR';

// Secure token for accept link//
$token = bin2hex(random_bytes(16));
// e.g. 7 days validity
$expires = $now->add(new DateInterval('P7D'))->format('Y-m-d\TH:i:sP');

// Write accepted_soft_hold inquiry record
$inq = [
    'id'      => $id,
    'unit'    => $unit,
    'from'    => $from,
    'to'      => $to,
    'nights'  => $nights,
    'status'  => 'accepted',
    'stage'   => 'accepted_soft_hold',
    'created' => $createdIso,
    'accepted_at' => $acceptedIso,

    'guest' => [
        'name'  => $guestName !== '' ? $guestName : $guestEmail,
        'email' => $guestEmail,
        'phone' => $guestPhone,
        'note'  => $note,
    ],

    'calc' => [
        'total'    => $totalPrice,
        'currency' => $currency,
    ],

    'meta' => [
        'source'      => 'admin',
        'admin_mode'  => 'soft',
        'soft_hold'   => true,
        'created_via' => 'admin_calendar',
    ],

    'secure_token'      => $token,
    'token_expires_at'  => $expires,
];

// Path: /inquiries/YYYY/MM/accepted/ID.json
$year = $now->format('Y');
$month = $now->format('m');
$dir   = "{$INQ_ROOT}/{$year}/{$month}/accepted";
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    respond(false, ['error' => 'mkdir_failed', 'dir' => $dir], 500);
}

$path = "{$dir}/{$id}.json";
cm_json_write($path, $inq);

// Occupancy will be updated via the existing merge/regen process (consumes accepted_soft_hold)

// Send accept-link mail
try {
    $mail = cm_send_accept_link($id, false);
    $mailInfo = [
        'sent'   => true,
        'to'     => $guestEmail,
        'subject'=> $mail['subject'] ?? null,
    ];
} catch (\Throwable $e) {
    error_log('[admin_reserve_soft] send_accept_link failed: ' . $e->getMessage());
    $mailInfo = [
        'sent' => false,
        'error'=> $e->getMessage(),
    ];
}

// Occupancy soft-hold
$occPath = "$APP/common/data/json/units/$unit/occupancy.json";
$occArr = is_file($occPath) ? cm_json_read($occPath) : [];
if (!is_array($occArr)) $occArr = [];

$occArr[] = [
    "start"  => $from,
    "end"    => $to,
    "status" => "blocked",
    "reason" => "soft-hold",
    "lock"   => "soft",
    "source" => "admin",
    "id"     => $id,
    "export" => false
];

cm_json_write($occPath, $occArr);
// Regenerate merged immediately after soft-hold write
if (function_exists('cm_regen_merged_for_unit')) {
    $unitsRoot = '/var/www/html/app/common/data/json/units';
    cm_regen_merged_for_unit($unitsRoot, $unit);
}



respond(true, [
    'id'      => $id,
    'unit'    => $unit,
    'from'    => $from,
    'to'      => $to,
    'nights'  => $nights,
    'mail'    => $mailInfo,
]);
