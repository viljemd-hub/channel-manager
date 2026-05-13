<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/create_external_reservation.php
 * Purpose: Create a manual "external" reservation for real guests
 *          (e.g. Booking.com / Airbnb / phone) so they can be managed
 *          like normal reservations (invoice, review link, stats),
 *          without touching occupancy / ICS.
 *
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Use local time (same as the rest of the app)
date_default_timezone_set('Europe/Ljubljana');

function respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

/**
 * Generate reservation ID in the same style as the rest of the system:
 *   YYYYMMDDHHMMSS-xxxx-UNIT
 */
function cm_generate_reservation_id(string $unit): string {
    $unit = preg_replace('~[^A-Za-z0-9_-]~', '', $unit) ?? $unit;
    $ts   = (new DateTimeImmutable())->format('YmdHis');
    $hex  = bin2hex(random_bytes(2)); // 4 hex chars
    return $ts . '-' . $hex . '-' . $unit;
}

// -----------------------------------------------------------------------------
// Resolve data root (same pattern as send_review_request.php)
// -----------------------------------------------------------------------------
$APP       = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$DATA_ROOT = $APP . '/common/data/json';
$RES_ROOT  = $DATA_ROOT . '/reservations';

// -----------------------------------------------------------------------------
// Read JSON input
// -----------------------------------------------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    respond(false, ['error' => 'no_input'], 400);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(false, ['error' => 'invalid_json'], 400);
}

// Basic fields from UI
$unit       = trim((string)($data['unit']        ?? ''));
$guestName  = trim((string)($data['guest_name']  ?? ''));
$guestEmail = trim((string)($data['guest_email'] ?? ''));
$from       = trim((string)($data['from']        ?? ''));
$to         = trim((string)($data['to']          ?? ''));
$channel    = trim((string)($data['channel']     ?? ''));
$lang       = trim((string)($data['lang']        ?? 'en'));

$adultsRaw  = $data['adults'] ?? 2;
$adults     = is_numeric($adultsRaw) ? (int)$adultsRaw : 2;

// Optional phone (preferred key: guest_phone)
$guestPhoneRaw = '';
if (isset($data['guest_phone'])) {
    $guestPhoneRaw = trim((string)$data['guest_phone']);
} elseif (isset($data['phone'])) {
    $guestPhoneRaw = trim((string)$data['phone']);
} elseif (
    isset($data['guest']) &&
    is_array($data['guest']) &&
    isset($data['guest']['phone'])
) {
    $guestPhoneRaw = trim((string)$data['guest']['phone']);
}

// Normalize phone: keep digits, preserve leading '+' if present
$guestPhone = '';
if ($guestPhoneRaw !== '') {
    $hasPlus = str_starts_with($guestPhoneRaw, '+');
    $digits  = preg_replace('~\D+~', '', $guestPhoneRaw) ?? '';
    $digits  = (string)$digits;
    if ($digits !== '') {
        $guestPhone = $hasPlus ? ('+' . $digits) : $digits;
    }
}

$totalRaw = $data['total'] ?? null;
$total    = null;
if ($totalRaw !== null && $totalRaw !== '') {
    $total = (float)$totalRaw;
    if (!is_finite($total) || $total < 0) {
        $total = null;
    }
}

// Basic validation
$errors = [];

if ($unit === '') {
    $errors['unit'] = 'required';
}
if ($guestName === '') {
    $errors['guest_name'] = 'required';
}
if ($guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    $errors['guest_email'] = 'invalid';
}
if ($from === '') {
    $errors['from'] = 'required';
}
if ($to === '') {
    $errors['to'] = 'required';
}
if ($adults < 1) {
    $errors['adults'] = 'invalid';
}

$dtFrom = DateTime::createFromFormat('Y-m-d', $from) ?: null;
$dtTo   = DateTime::createFromFormat('Y-m-d', $to)   ?: null;

if (!$dtFrom || $dtFrom->format('Y-m-d') !== $from) {
    $errors['from'] = 'invalid_date';
}
if (!$dtTo || $dtTo->format('Y-m-d') !== $to) {
    $errors['to'] = 'invalid_date';
}

$nights = 0;
if (!$errors && $dtFrom && $dtTo) {
    $diff   = $dtFrom->diff($dtTo);
    $nights = max(0, (int)$diff->days);
    if ($nights <= 0) {
        $errors['range'] = 'from_must_be_before_to';
    }
}

if ($errors) {
    respond(false, ['error' => 'validation_failed', 'details' => $errors], 400);
}

// -----------------------------------------------------------------------------
// Build reservation JSON (approximate existing structure)
// -----------------------------------------------------------------------------
$nowIso = (new DateTimeImmutable())->format('c');
$year   = $dtFrom->format('Y');

$dir = rtrim($RES_ROOT, '/') . '/' . $year . '/' . $unit;
if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(false, ['error' => 'mkdir_failed', 'dir' => $dir], 500);
    }
}

// Generate unique ID (few tries, just in case)
$id = null;
for ($i = 0; $i < 5; $i++) {
    $candidate = cm_generate_reservation_id($unit);
    $file      = $dir . '/' . $candidate . '.json';
    if (!file_exists($file)) {
        $id = $candidate;
        break;
    }
}
if ($id === null) {
    respond(false, ['error' => 'id_collision'], 500);
}

$file = $dir . '/' . $id . '.json';

// Use "total" as a simple final price; everything else is 0 by default
$finalAmount = $total !== null ? $total : 0.0;
$baseAmount  = $finalAmount;

// External reservations are considered already paid via channel
$res = [
    'id'      => $id,
    'status'  => 'confirmed',
    'created' => $nowIso,
    'unit'    => $unit,
    'lang'    => $lang,
    'from'    => $from,
    'to'      => $to,
    'nights'  => $nights,
    'adults'  => $adults,
    'kids06'  => 0,
    'kids712' => 0,

    'guest' => [
        'name'  => $guestName,
        'phone' => $guestPhone,
        'email' => $guestEmail,
        'note'  => '',
    ],

    'promo_code' => '',
    'promo'      => [
        'code'   => '',
        'amount' => 0,
    ],

    'calc' => [
        'base'            => $baseAmount,
        'discounts'       => 0,
        'promo'           => 0,
        'special_offers'  => 0,
        'cleaning'        => 0,
        'final'           => $finalAmount,
    ],

    'tt' => [
        'total'          => 0,
        'keycard_count'  => 0,
        'keycard_saving' => 0,
        'keycard_note'   => '',
    ],

    'special_offer_meta' => [
        'name'    => '',
        'percent' => 0,
    ],

    'meta' => [
        'source'            => 'external',
        'lang'              => $lang,
        'accept_soft_hold'  => false,
        'clean_before_flag' => false,
        'clean_after_flag'  => false,
        'channel'           => $channel,
    ],

    // Lifecycle – this is a fully confirmed external stay
    'stage'        => 'external_confirmed',
    'accepted_at'  => $nowIso,
    'confirmed_at' => $nowIso,

    // No tokens / links for now – this reservation is not part of CM front-end flow
    'secure_token'     => '',
    'token_expires_at' => '',
    'lock'             => 'hard',

    'payment_method' => 'external',
    'source'         => 'external',
    'payment'        => [
        'method' => 'external',
        'status' => 'paid',
    ],

    'cancel_token' => '',
    'cancel_link'  => '',
    'pdf_token'    => '',
    'pdf_link'     => '',

    'review' => [
        'has_review'      => false,
        'score'           => 0,
        'public_allowed'  => false,
    ],
    'review_score' => 0,
    'channel'      => $channel,
];

// Write JSON file
$json = json_encode(
    $res,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
if ($json === false) {
    respond(false, ['error' => 'json_encode_failed'], 500);
}

if (file_put_contents($file, $json) === false) {
    respond(false, ['error' => 'write_failed', 'file' => $file], 500);
}

respond(true, [
    'id'   => $id,
    'unit' => $unit,
    'file' => $file,
]);
