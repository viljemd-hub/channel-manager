<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/update_reservation.php
 * Purpose: Update selected reservation guest/contact/count/TT exemption fields.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../_common.php';
require_key();

function ur_respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function ur_read_json(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function ur_write_json(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

function ur_find_reservation(string $id): ?array {
    $id = trim($id);
    if ($id === '') return null;

    $root = realpath(__DIR__ . '/../../common/data/json/reservations');
    if ($root === false) return null;

    $year = substr($id, 0, 4);
    $unit = '';
    if (preg_match('~-[0-9a-f]{4}-([A-Za-z0-9_-]+)$~', $id, $m)) {
        $unit = $m[1];
    }

    $candidates = [];
    if ($unit !== '') {
        $candidates[] = sprintf('%s/%s/%s/%s.json', $root, $year, $unit, $id);
    } else {
        $yearDir = $root . '/' . $year;
        if (is_dir($yearDir)) {
            foreach (glob($yearDir . '/*/' . $id . '.json') as $p) {
                $candidates[] = $p;
            }
        }
    }

    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $j = ur_read_json($p);
        if (!is_array($j)) continue;
        $j['_file'] = $p;
        return $j;
    }

    return null;
}

function ur_norm_phone(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $hasPlus = str_starts_with($raw, '+');
    $digits = preg_replace('~\D+~', '', $raw) ?? '';
    $digits = (string)$digits;
    if ($digits === '') return '';
    return $hasPlus ? ('+' . $digits) : $digits;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    ur_respond(false, ['error' => 'no_input'], 400);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    ur_respond(false, ['error' => 'invalid_json'], 400);
}

$id = preg_replace('~[^0-9A-Za-z_-]~', '', (string)($data['id'] ?? ''));
if ($id === '') {
    ur_respond(false, ['error' => 'missing_id'], 400);
}

$res = ur_find_reservation($id);
if (!$res) {
    ur_respond(false, ['error' => 'reservation_not_found'], 404);
}

$file = (string)($res['_file'] ?? '');
if ($file === '' || !is_file($file)) {
    ur_respond(false, ['error' => 'reservation_file_not_found'], 404);
}

$guestName  = trim((string)($data['guest_name'] ?? ''));
$guestEmail = trim((string)($data['guest_email'] ?? ''));
$guestPhone = ur_norm_phone((string)($data['guest_phone'] ?? ''));
$guestNote  = trim((string)($data['guest_note'] ?? ''));

$adultsRaw  = $data['adults'] ?? 0;
$kids06Raw  = $data['kids06'] ?? 0;
$kids712Raw = $data['kids712'] ?? 0;
$disabledRaw = $data['disabled_exempt_count'] ?? 0;

$adults  = is_numeric($adultsRaw) ? max(0, (int)$adultsRaw) : -1;
$kids06  = is_numeric($kids06Raw) ? max(0, (int)$kids06Raw) : -1;
$kids712 = is_numeric($kids712Raw) ? max(0, (int)$kids712Raw) : -1;
$disabledExemptCount = is_numeric($disabledRaw) ? max(0, (int)$disabledRaw) : -1;

$errors = [];

if ($guestName === '') {
    $errors['guest_name'] = 'required';
}
if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    $errors['guest_email'] = 'invalid';
}
if ($adults < 0) {
    $errors['adults'] = 'invalid';
}
if ($kids06 < 0) {
    $errors['kids06'] = 'invalid';
}
if ($kids712 < 0) {
    $errors['kids712'] = 'invalid';
}
if ($disabledExemptCount < 0) {
    $errors['disabled_exempt_count'] = 'invalid';
}

$totalGuests = $adults + $kids06 + $kids712;
if ($totalGuests <= 0) {
    $errors['guest_counts'] = 'at_least_one_guest_required';
}

$ttPersons = max(0, $adults + $kids712);
if ($disabledExemptCount > $ttPersons) {
    $errors['disabled_exempt_count'] = 'cannot_exceed_tt_persons';
}

if ($errors) {
    ur_respond(false, ['error' => 'validation_failed', 'details' => $errors], 400);
}

if (!isset($res['guest']) || !is_array($res['guest'])) {
    $res['guest'] = [];
}

$res['guest']['name']  = $guestName;
$res['guest']['email'] = $guestEmail;
$res['guest']['phone'] = $guestPhone;
$res['guest']['note']  = $guestNote;

$res['adults']  = $adults;
$res['kids06']  = $kids06;
$res['kids712'] = $kids712;

if (array_key_exists('kids_0_6', $res)) {
    $res['kids_0_6'] = $kids06;
}
if (array_key_exists('kids_7_12', $res)) {
    $res['kids_7_12'] = $kids712;
}

if (!isset($res['tt']) || !is_array($res['tt'])) {
    $res['tt'] = [];
}
$res['tt']['disabled_exempt_count'] = $disabledExemptCount;

if (!isset($res['meta']) || !is_array($res['meta'])) {
    $res['meta'] = [];
}
$res['meta']['reservation_updated_at'] = date('c');
$res['meta']['reservation_updated_via'] = 'admin/api/update_reservation.php';

unset($res['_file']);

if (!ur_write_json($file, $res)) {
    ur_respond(false, ['error' => 'write_failed'], 500);
}

ur_respond(true, [
    'id' => $id,
    'adults' => $adults,
    'kids06' => $kids06,
    'kids712' => $kids712,
    'disabled_exempt_count' => $disabledExemptCount,
]);