<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/integrations/save_unit_meta.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Minimal helper: JSON response + exit.
 */
function jexit(bool $ok, array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Sanitize unit id: allow only A–Z, 0–9, _ and - (max 32 chars).
 */
function sanitize_id(string $id): string {
    $id = trim($id);
    if ($id === '') return '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
        return '';
    }
    return $id;
}

// -------------------------
//  Read JSON input
// -------------------------

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    jexit(false, ['error' => 'invalid_json']);
}

$id = sanitize_id((string)($data['id'] ?? ''));
if ($id === '') {
    jexit(false, ['error' => 'missing_or_invalid_id']);
}

$alias        = trim((string)($data['alias'] ?? ''));
$label        = trim((string)($data['label'] ?? ''));
$property_id  = trim((string)($data['property_id'] ?? ''));
$owner        = trim((string)($data['owner'] ?? ''));
$active       = array_key_exists('active', $data) ? (bool)$data['active'] : (bool)($existing['active'] ?? true);
$public       = array_key_exists('public', $data) ? (bool)$data['public'] : (bool)($existing['public'] ?? true);
$months_ahead = (int)($data['months_ahead'] ?? 0);
$clean_before = (int)($data['clean_before'] ?? 0);
$clean_after  = (int)($data['clean_after'] ?? 0);
$on_hold      = array_key_exists('on_hold', $data) ? (bool)$data['on_hold'] : (bool)($existing['on_hold'] ?? false);
// booking / popusti (0 je veljavna vrednost → ne uporabljaj empty())
$booking_min_nights        = array_key_exists('booking_min_nights', $data)
    ? (string)$data['booking_min_nights']
    : null;
$booking_cleaning_fee_eur  = array_key_exists('booking_cleaning_fee_eur', $data)
    ? (string)$data['booking_cleaning_fee_eur']
    : null;
$weekly_threshold          = array_key_exists('weekly_threshold', $data)
    ? (string)$data['weekly_threshold']
    : null;
$weekly_discount_pct       = array_key_exists('weekly_discount_pct', $data)
    ? (string)$data['weekly_discount_pct']
    : null;
$long_threshold            = array_key_exists('long_threshold', $data)
    ? (string)$data['long_threshold']
    : null;
$long_discount_pct         = array_key_exists('long_discount_pct', $data)
    ? (string)$data['long_discount_pct']
    : null;





// fallbacki
if ($label === '' && $alias !== '') {
    $label = $alias;
}
if ($alias === '' && $label !== '') {
    $alias = $label;
}
if ($label === '') {
    $label = $id;
}
if ($alias === '') {
    $alias = $label;
}
if ($property_id === '') {
    $property_id = 'HOME';
}

// -------------------------
//  Load manifest.json
// -------------------------

$appRoot      = '/var/www/html/app';
$manifestFile = $appRoot . '/common/data/json/units/manifest.json';

$manifest = [
    'units' => [],
];

if (is_file($manifestFile)) {
    $decoded = json_decode(file_get_contents($manifestFile), true);
    if (is_array($decoded)) {
        $manifest = $decoded + $manifest;
        if (!isset($manifest['units']) || !is_array($manifest['units'])) {
            $manifest['units'] = [];
        }
    }
}

$units     = $manifest['units'];
$maxOrder  = 0;
$foundIdx  = null;

foreach ($units as $i => $u) {
    if (!is_array($u)) continue;

    $uid = $u['id'] ?? ($u['unit'] ?? null);
    if ($uid === $id) {
        $foundIdx = $i;
    }

    if (isset($u['order']) && is_numeric($u['order'])) {
        $o = (int)$u['order'];
        if ($o > $maxOrder) $maxOrder = $o;
    }
}

if ($maxOrder === 0) {
    $maxOrder = count($units);
}

$order = $maxOrder + 1;
$existing = null;
if ($foundIdx !== null) {
    $existing = $units[$foundIdx];
    if (isset($existing['order']) && is_numeric($existing['order'])) {
        $order = (int)$existing['order'];
    }
}

// zgradi "poln" zapis
$entry = is_array($existing) ? $existing : [];
$entry['id']           = $id;
$entry['alias']        = $alias;
$entry['label']        = $label;
$entry['name']         = $label;
$entry['property_id']  = $property_id;
$entry['owner']        = $owner;
$entry['active']       = $active;
$entry['public']       = $public;
$entry['order']        = $order;
$entry['months_ahead'] = $months_ahead;
$entry['clean_before'] = $clean_before;
$entry['clean_after']  = $clean_after;
$entry['on_hold']      = $on_hold;



if ($foundIdx !== null) {
    $units[$foundIdx] = $entry;
} else {
    $units[] = $entry;
}

$manifest['units'] = $units;

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    jexit(false, ['error' => 'encode_failed']);
}
if (file_put_contents($manifestFile, $encoded, LOCK_EX) === false) {
    jexit(false, ['error' => 'write_failed']);
}

// ------------------------------------------------------
//  Posodobi še per-unit site_settings.json (booking/popupsti)
// ------------------------------------------------------
$settingsPath = $appRoot . '/common/data/json/units/' . $id . '/site_settings.json';
$settingsRaw  = @file_get_contents($settingsPath);
$settings     = is_string($settingsRaw) ? json_decode($settingsRaw, true) : null;
if (!is_array($settings)) {
    $settings = [];
}

// booking struktura
if (!isset($settings['booking']) || !is_array($settings['booking'])) {
    $settings['booking'] = [
        'min_nights'               => 1,
        'allow_same_day_departure' => $settings['booking']['allow_same_day_departure'] ?? false,
        'cleaning_fee_eur'         => 0,
    ];
}

// weekly / long ključi – če manjkajo, dodaj default
if (!array_key_exists('weekly_threshold', $settings)) {
    $settings['weekly_threshold'] = 7;
}
if (!array_key_exists('weekly_discount_pct', $settings)) {
    $settings['weekly_discount_pct'] = 10.0;
}
if (!array_key_exists('long_threshold', $settings)) {
    $settings['long_threshold'] = 30;
}
if (!array_key_exists('long_discount_pct', $settings)) {
    $settings['long_discount_pct'] = 20.0;
}

// override z vrednostmi iz requesta (če so bile poslane)
if ($booking_min_nights !== null && $booking_min_nights !== '') {
    $settings['booking']['min_nights'] = (int)$booking_min_nights;
}
if ($booking_cleaning_fee_eur !== null && $booking_cleaning_fee_eur !== '') {
    $settings['booking']['cleaning_fee_eur'] = (float)$booking_cleaning_fee_eur;
}
if ($weekly_threshold !== null && $weekly_threshold !== '') {
    $settings['weekly_threshold'] = (int)$weekly_threshold;
}
if ($weekly_discount_pct !== null && $weekly_discount_pct !== '') {
    $settings['weekly_discount_pct'] = (float)$weekly_discount_pct;
}
if ($long_threshold !== null && $long_threshold !== '') {
    $settings['long_threshold'] = (int)$long_threshold;
}
if ($long_discount_pct !== null && $long_discount_pct !== '') {
    $settings['long_discount_pct'] = (float)$long_discount_pct;
}

// display ime/alias lahko opcijsko tudi osvežiš:
if (!isset($settings['display']) || !is_array($settings['display'])) {
    $settings['display'] = [];
}
$settings['display']['name']  = $label;
$settings['display']['short'] = $id;

file_put_contents(
    $settingsPath,
    json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

jexit(true, ['unit' => $entry]);
