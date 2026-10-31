<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus
 * File: public/api/vrp_offer.php
 *
 * Vacation Rental Protocol (VRP, https://github.com/vacationrentalprotocol/vrp-spec)
 * verified_stay_offer_endpoint. Returns a host-signed (Ed25519 / compact JWS,
 * EdDSA) availability+price offer for a single unit, built from the same
 * occupancy_merged.json / prices.json data public/api/availability.php and
 * public/api/price.php already read - self-contained on purpose, same
 * convention as those two files.
 *
 * Query params:
 *   - unit=A1
 *   - check_in=2026-11-01
 *   - check_out=2026-11-04
 *   - guests=2        (optional, echoed back only)
 *
 * Always returns a signed envelope, including the explicit "not available"
 * case (VRP requires signed false values, not silence, per spec v0.1 S6-S9).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../admin/api/_lib/paths.php';
require_once __DIR__ . '/../../common/lib/urls.php';

/** This installation's own public domain - never another node's. */
function vrp_node_host(): string
{
    $host = (string)(parse_url(cm_public_base_url(), PHP_URL_HOST) ?? '');
    return $host !== '' ? $host : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Key id for the JWKS entry this installation actually signs with. Read
 * from an operator-set file next to the signing key (same directory,
 * written once when the key is generated/rotated) so it always matches
 * the published JWKS; a fresh install with no such file gets a sane
 * domain-derived default instead of a hardcoded one.
 */
function vrp_node_kid(string $host): string
{
    $kidPath = app_root() . '/common/data/vrp_kid.txt';
    if (is_file($kidPath)) {
        $kid = trim((string)file_get_contents($kidPath));
        if ($kid !== '') {
            return $kid;
        }
    }
    return $host . '-vrp-key';
}

/** This unit's own display name, same field admin/api/add_unit.php writes. */
function vrp_unit_display_name(string $unit): string
{
    $path = units_root() . '/' . $unit . '/site_settings.json';
    if (is_file($path)) {
        $json = json_decode((string)file_get_contents($path), true);
        $name = $json['display']['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }
    }
    return $unit;
}

function vrp_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vrp_is_valid_iso_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function vrp_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function vrp_iso(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d\TH:i:s\Z');
}

/** Same blocking rule as public/api/availability.php's segment_blocks_range(). */
function vrp_segment_blocks_range(array $seg): bool
{
    $status = (string)($seg['status'] ?? '');
    $lock   = (string)($seg['lock'] ?? '');
    $source = (string)($seg['source'] ?? '');

    if ($lock === 'soft') {
        return false;
    }
    if ($lock === 'hard') {
        return true;
    }
    if ($status === 'confirmed' || $status === 'blocked') {
        return true;
    }
    if ($status === 'reserved' && $lock !== 'soft') {
        return true;
    }
    if (in_array($source, ['ics', 'internal', 'admin', 'local'], true) && $status !== '') {
        return in_array($status, ['reserved', 'confirmed', 'blocked'], true);
    }
    return false;
}

/** Same read as public/api/price.php's price_api_read_unit_prices(). */
function vrp_read_unit_prices(string $unit): array
{
    $path = units_root() . '/' . $unit . '/prices.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['prices']) && is_array($data['prices'])) {
        $data = $data['prices'];
    }
    $prices = [];
    foreach ($data as $date => $value) {
        if (!is_string($date) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            continue;
        }
        if (is_numeric($value)) {
            $prices[$date] = (float)$value;
        }
    }
    return $prices;
}

function vrp_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/* --------------------------------------------------------------------------
 * Input
 * -------------------------------------------------------------------------- */

$unit     = trim((string)($_GET['unit'] ?? 'A1'));
$checkIn  = trim((string)($_GET['check_in'] ?? ''));
$checkOut = trim((string)($_GET['check_out'] ?? ''));
$guests   = isset($_GET['guests']) ? (int)$_GET['guests'] : null;

if (!preg_match('/^[A-Za-z0-9_-]+$/', $unit)) {
    vrp_out(['ok' => false, 'error' => 'invalid_unit'], 400);
}
if ($checkIn === '' || $checkOut === '') {
    vrp_out(['ok' => false, 'error' => 'missing_params', 'hint' => 'Required: unit, check_in, check_out'], 400);
}
if (!vrp_is_valid_iso_date($checkIn) || !vrp_is_valid_iso_date($checkOut)) {
    vrp_out(['ok' => false, 'error' => 'invalid_date_format', 'hint' => 'Expected YYYY-MM-DD'], 400);
}
if ($checkIn >= $checkOut) {
    vrp_out(['ok' => false, 'error' => 'invalid_range', 'hint' => 'check_in must be earlier than check_out'], 400);
}

/* VRP Core S5 / ADR 0012 D2: a node MUST NOT sign an offer whose check_in has
 * already passed, by the property's own date (not the caller's clock). */
$propertyToday = (new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana')))->format('Y-m-d');
if ($checkIn < $propertyToday) {
    vrp_out(['ok' => false, 'error' => 'checkin_in_past', 'hint' => 'check_in must not be earlier than the current date at the property'], 400);
}

$nights = (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days;

/* --------------------------------------------------------------------------
 * Load signing key (protected path, outside any public web root exposure -
 * see common/data/ deny rule in the vhost config)
 * -------------------------------------------------------------------------- */

$secretKeyPath = app_root() . '/common/data/vrp_ed25519_secret.key';
if (!is_file($secretKeyPath)) {
    vrp_out(['ok' => false, 'error' => 'node_not_configured'], 503);
}
$secretKey = base64_decode(trim((string)file_get_contents($secretKeyPath)), true);
if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    vrp_out(['ok' => false, 'error' => 'node_key_invalid'], 503);
}

$nodeHost = vrp_node_host();
$kid = vrp_node_kid($nodeHost);

/* --------------------------------------------------------------------------
 * Availability
 * -------------------------------------------------------------------------- */

$mergedPath = units_root() . '/' . $unit . '/occupancy_merged.json';
if (!is_file($mergedPath)) {
    vrp_out(['ok' => false, 'error' => 'unit_not_found', 'unit' => $unit], 404);
}
$segments = json_decode((string)file_get_contents($mergedPath), true);
if (!is_array($segments)) {
    vrp_out(['ok' => false, 'error' => 'merged_state_invalid', 'unit' => $unit], 409);
}

$available = true;
foreach ($segments as $seg) {
    if (!is_array($seg)) {
        continue;
    }
    $segFrom = trim((string)($seg['start'] ?? ''));
    $segTo   = trim((string)($seg['end'] ?? ''));
    if ($segFrom === '' || $segTo === '') {
        continue;
    }
    if (!vrp_segment_blocks_range($seg)) {
        continue;
    }
    if (is_overlap($segFrom, $segTo, $checkIn, $checkOut)) {
        $available = false;
        break;
    }
}

/* --------------------------------------------------------------------------
 * Price (only computed when available - fail closed otherwise)
 * -------------------------------------------------------------------------- */

$publicTotalMinor = null;
$exact = false;

if ($available) {
    $allPrices = vrp_read_unit_prices($unit);
    $sum = 0.0;
    $coveredNights = 0;
    $cursor = new DateTimeImmutable($checkIn);
    $end = new DateTimeImmutable($checkOut);
    while ($cursor < $end) {
        $d = $cursor->format('Y-m-d');
        if (isset($allPrices[$d])) {
            $sum += $allPrices[$d];
            $coveredNights++;
        }
        $cursor = $cursor->modify('+1 day');
    }
    if ($coveredNights === $nights && $nights > 0) {
        $publicTotalMinor = (int)round($sum * 100);
        $exact = true;
    } elseif ($coveredNights > 0) {
        $avg = $sum / $coveredNights;
        $publicTotalMinor = (int)round($avg * $nights * 100);
        $exact = false;
    }
}

/* --------------------------------------------------------------------------
 * Build + sign offer
 * -------------------------------------------------------------------------- */

$now = vrp_now();
$validUntil = $now->modify('+10 minutes');

$propertyName = vrp_unit_display_name($unit);

$offer = [
    'kind' => 'verified_stay_offer',
    'protocol_version' => '0.1',
    'canonical_domain' => $nodeHost,
    'node_id' => $nodeHost,
    'generated_at' => vrp_iso($now),
    'valid_until' => vrp_iso($validUntil),
    'request' => array_filter([
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'guests' => $guests,
    ], static fn($v) => $v !== null),
    'property' => [
        'property_id' => $unit,
        'name' => $propertyName,
        'url' => cm_public_url('/offer.php?unit=' . $unit),
    ],
    'availability' => $available
        ? ['available' => true, 'source' => 'official_host_domain']
        : ['available' => false, 'source' => 'official_host_domain', 'reason' => 'not_available'],
    'price' => [
        'currency' => 'EUR',
        'public_total' => $publicTotalMinor,
        'agent_total' => $publicTotalMinor,
        'minor_unit' => true,
        'exact' => $exact,
    ],
    'booking' => [
        'direct_booking_url' => cm_public_url('/offer.php?unit=' . $unit
            . '&from=' . $checkIn . '&to=' . $checkOut),
    ],
    'agent_permission' => [
        'may_quote_as_official_direct_offer' => $available && $exact,
        'must_not_claim_ota_comparison_without_signed_ota_price' => true,
    ],
];

$header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid];
$headerB64 = vrp_b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
$payloadJson = json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$payloadB64 = vrp_b64url($payloadJson);

$signingInput = $headerB64 . '.' . $payloadB64;
$signature = sodium_crypto_sign_detached($signingInput, $secretKey);
sodium_memzero($secretKey);

$jws = $signingInput . '.' . vrp_b64url($signature);

vrp_out([
    'kind' => 'signed_verified_stay_offer',
    'protocol_version' => '0.1',
    'offer' => $offer,
    'signature' => [
        'format' => 'jws_compact',
        'alg' => 'EdDSA',
        'kid' => $kid,
        'jws' => $jws,
    ],
]);
