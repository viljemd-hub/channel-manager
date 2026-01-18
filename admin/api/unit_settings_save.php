<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/unit_settings_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * /app/admin/api/unit_settings_save.php
 *
 * Save per-unit settings into:
 *   /app/common/data/json/units/<UNIT>/site_settings.json
 *
 * Supports (all optional except unit):
 *  - unit (string, e.g. "A1")
 *  - month_render (int, 1–36)
 *
 *  - booking_min_nights (int, 1–365)
 *  - booking_allow_same_day_departure (bool-ish)
 *
 *  - day_use_enabled (bool-ish)
 *  - day_use_from (string "HH:MM")
 *  - day_use_to (string "HH:MM")
 *  - day_use_max_persons (int >=1)
 *
 * Existing keys (auto_block, etc.) are preserved and merged.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => 'method_not_allowed',
        'msg'   => 'Use POST',
    ]);
    exit;
}

// ---------------------------------------------------------------------
// Read payload (JSON preferred; fallback to $_POST)
// ---------------------------------------------------------------------
$raw  = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data) || !count($data)) {
    // Fallback for x-www-form-urlencoded
    $data = $_POST;
}
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'invalid_payload',
    ]);
    exit;
}

// ---------------------------------------------------------------------
// Unit
// ---------------------------------------------------------------------
$unit = $data['unit'] ?? $data['id'] ?? '';
$unit = preg_replace('~[^A-Za-z0-9_-]~', '', (string)$unit);

if ($unit === '') {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'missing_unit',
    ]);
    exit;
}

// Small helpers
$toBool = function ($v): bool {
    if (is_bool($v)) {
        return $v;
    }
    $trueVals = ['1', 'true', 'on', 'yes', 'y', 'da'];
    return in_array(strtolower((string)$v), $trueVals, true);
};

$sanitizeTime = function (?string $v): ?string {
    if ($v === null) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('~^\d{2}:\d{2}$~', $v)) {
        return null;
    }
    return $v;
};

// ---------------------------------------------------------------------
// month_render (optional)
// ---------------------------------------------------------------------
$monthRenderRaw = $data['month_render'] ?? null;
$doSetMonth     = ($monthRenderRaw !== null && $monthRenderRaw !== '');

$monthRender = null;
if ($doSetMonth) {
    $monthRender = (int)$monthRenderRaw;
    if ($monthRender < 1) {
        $monthRender = 1;
    } elseif ($monthRender > 36) {
        $monthRender = 36;
    }
}

// ---------------------------------------------------------------------
// booking.* (optional)
// ---------------------------------------------------------------------
$bookingMinRaw = $data['booking_min_nights'] ?? null;
$hasBookingMin = ($bookingMinRaw !== null && $bookingMinRaw !== '');

$bookingMin = null;
if ($hasBookingMin) {
    $bookingMin = (int)$bookingMinRaw;
    if ($bookingMin < 1) {
        $bookingMin = 1;
    } elseif ($bookingMin > 365) {
        $bookingMin = 365;
    }
}

$hasAllowSame = array_key_exists('booking_allow_same_day_departure', $data);
$allowSame    = $hasAllowSame ? $toBool($data['booking_allow_same_day_departure']) : null;

// cleaning fee (optional, per-unit)
$bookingCleaningRaw = $data['booking_cleaning_fee_eur'] ?? null;
$hasBookingCleaning = ($bookingCleaningRaw !== null && $bookingCleaningRaw !== '');
$bookingCleaning    = null;

if ($hasBookingCleaning) {
    // dovoli "45", "45.0", "45,5"
    $normalized = str_replace(',', '.', (string)$bookingCleaningRaw);
    $val = (float)$normalized;

    if (!is_finite($val) || $val < 0) {
        $val = 0.0;
    }
    if ($val > 9999) {
        $val = 9999.0;
    }
    $bookingCleaning = $val;
}



// ---------------------------------------------------------------------
// day_use.* (optional)
// ---------------------------------------------------------------------
$hasDayUseEnabled = array_key_exists('day_use_enabled', $data);
$dayUseEnabled    = $hasDayUseEnabled ? $toBool($data['day_use_enabled']) : null;

$dayUseFromRaw = $data['day_use_from'] ?? null;
$dayUseToRaw   = $data['day_use_to']   ?? null;
$hasDayUseFrom = is_string($dayUseFromRaw) && $dayUseFromRaw !== '';
$hasDayUseTo   = is_string($dayUseToRaw)   && $dayUseToRaw   !== '';

if ($hasDayUseFrom) {
    $dayUseFromRaw = $sanitizeTime($dayUseFromRaw);
    $hasDayUseFrom = ($dayUseFromRaw !== null);
}
if ($hasDayUseTo) {
    $dayUseToRaw = $sanitizeTime($dayUseToRaw);
    $hasDayUseTo = ($dayUseToRaw !== null);
}

// max_persons (optional)
$dayUseMaxRaw = $data['day_use_max_persons'] ?? null;
$hasDayUseMax = ($dayUseMaxRaw !== null && $dayUseMaxRaw !== '');
$dayUseMax    = null;
if ($hasDayUseMax) {
    $dayUseMax = (int)$dayUseMaxRaw;
    if ($dayUseMax < 1) {
        $dayUseMax = 1;
    }
    if ($dayUseMax > 50) {
        $dayUseMax = 50; // some reasonable upper bound
    }
}

// price_person (optional)
$dayUsePriceRaw = $data['day_use_price_person'] ?? null;
$hasDayUsePrice = ($dayUsePriceRaw !== null && $dayUsePriceRaw !== '');
$dayUsePrice    = null;
if ($hasDayUsePrice) {
    $dayUsePrice = (float)$dayUsePriceRaw;
    if ($dayUsePrice < 0) {
        $dayUsePrice = 0.0;
    }
}



// max_days_ahead (optional)
$dayUseMaxDaysRaw = $data['day_use_max_days_ahead'] ?? null;
$hasDayUseMaxDays = ($dayUseMaxDaysRaw !== null && $dayUseMaxDaysRaw !== '');
$dayUseMaxDays    = null;
if ($hasDayUseMaxDays) {
    $dayUseMaxDays = (int)$dayUseMaxDaysRaw;
    if ($dayUseMaxDays < 1) {
        $dayUseMaxDays = 1;
    } elseif ($dayUseMaxDays > 10) {
        $dayUseMaxDays = 10;
    }
}

// ---------------------------------------------------------------------
// capacity.* (max guests / beds / adults / kids / baby bed)
// ---------------------------------------------------------------------
$capRaw = $data['capacity'] ?? null;
if (!is_array($capRaw)) {
    $capRaw = null;
}

$capMaxGuests     = null;
$capMaxBeds       = null;
$capMinAdults     = null;
$capMaxKids06     = null;
$capMaxKids712    = null;
$capAllowBabyBed  = null;

$hasCapMaxGuests    = false;
$hasCapMaxBeds      = false;
$hasCapMinAdults    = false;
$hasCapMaxKids06    = false;
$hasCapMaxKids712   = false;
$hasCapAllowBabyBed = false;

if ($capRaw !== null) {
    // max_guests (1–50)
    if (array_key_exists('max_guests', $capRaw)) {
        $v = (int)$capRaw['max_guests'];
        if ($v < 1)  $v = 1;
        if ($v > 50) $v = 50;
        $capMaxGuests  = $v;
        $hasCapMaxGuests = true;
    }

    // max_beds (1–50)
    if (array_key_exists('max_beds', $capRaw)) {
        $v = (int)$capRaw['max_beds'];
        if ($v < 1)  $v = 1;
        if ($v > 50) $v = 50;
        $capMaxBeds    = $v;
        $hasCapMaxBeds = true;
    }

    // min_adults (1–20)
    if (array_key_exists('min_adults', $capRaw)) {
        $v = (int)$capRaw['min_adults'];
        if ($v < 1)  $v = 1;
        if ($v > 20) $v = 20;
        $capMinAdults    = $v;
        $hasCapMinAdults = true;
    }

    // max_children_0_6 (0–20, opcijsko)
    if (array_key_exists('max_children_0_6', $capRaw)) {
        $v = (int)$capRaw['max_children_0_6'];
        if ($v < 0)  $v = 0;
        if ($v > 20) $v = 20;
        $capMaxKids06    = $v;
        $hasCapMaxKids06 = true;
    }

    // max_children_7_12 (0–20, opcijsko)
    if (array_key_exists('max_children_7_12', $capRaw)) {
        $v = (int)$capRaw['max_children_7_12'];
        if ($v < 0)  $v = 0;
        if ($v > 20) $v = 20;
        $capMaxKids712    = $v;
        $hasCapMaxKids712 = true;
    }

    // allow_baby_bed (bool)
    if (array_key_exists('allow_baby_bed', $capRaw)) {
        $capAllowBabyBed    = $toBool($capRaw['allow_baby_bed']);
        $hasCapAllowBabyBed = true;
    }
}


// Basic validation: if enabled, we should have from < to
if ($hasDayUseEnabled && $dayUseEnabled === true) {
    if (!$hasDayUseFrom || !$hasDayUseTo) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'day_use_time_missing',
            'msg'   => 'When day_use_enabled is true, from/to must be provided',
        ]);
        exit;
    }
    if ($dayUseFromRaw >= $dayUseToRaw) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'day_use_time_invalid',
            'msg'   => '"from" must be earlier than "to" for day-use',
        ]);
        exit;
    }
}

// ---------------------------------------------------------------------
// Load existing settings JSON for this unit and merge
// ---------------------------------------------------------------------
$baseDir = '/var/www/html/app/common/data/json/units';
$unitDir = $baseDir . '/' . $unit;
$file    = $unitDir . '/site_settings.json';

if (!is_dir($unitDir)) {
    if (!mkdir($unitDir, 0775, true) && !is_dir($unitDir)) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'mkdir_failed',
            'path'  => $unitDir,
        ]);
        exit;
    }
}

$settings = [];
if (is_file($file)) {
    $existingRaw = @file_get_contents($file);
    $decoded     = json_decode((string)$existingRaw, true);
    if (is_array($decoded)) {
        $settings = $decoded;
    }
}

// month_render
if ($doSetMonth) {
    $settings['month_render'] = $monthRender;
}

// booking.*
if ($hasBookingMin || $hasAllowSame || $hasBookingCleaning) {
    if (!isset($settings['booking']) || !is_array($settings['booking'])) {
        $settings['booking'] = [];
    }
    if ($hasBookingMin) {
        $settings['booking']['min_nights'] = $bookingMin;
    }
    if ($hasAllowSame) {
        $settings['booking']['allow_same_day_departure'] = $allowSame;
    }
    if ($hasBookingCleaning && $bookingCleaning !== null) {
        $settings['booking']['cleaning_fee_eur'] = $bookingCleaning;
    }
}

// day_use.*
if ($hasDayUseEnabled || $hasDayUseFrom || $hasDayUseTo || $hasDayUseMax || $hasDayUseMaxDays) {
    if (!isset($settings['day_use']) || !is_array($settings['day_use'])) {
        $settings['day_use'] = [];
    }

    if ($hasDayUseEnabled) {
        $settings['day_use']['enabled'] = $dayUseEnabled;
    }
    if ($hasDayUseFrom) {
        $settings['day_use']['from'] = $dayUseFromRaw;
    }
    if ($hasDayUseTo) {
        $settings['day_use']['to'] = $dayUseToRaw;
    }
    if ($hasDayUseMax) {
        $settings['day_use']['max_persons'] = $dayUseMax;
    }
    if ($hasDayUseMaxDays) {
        $settings['day_use']['max_days_ahead'] = $dayUseMaxDays;
    }
    if ($hasDayUsePrice) {
        $settings['day_use']['day_price_person'] = $dayUsePrice;
    }
}

// capacity.*
if (
    $hasCapMaxGuests ||
    $hasCapMaxBeds ||
    $hasCapMinAdults ||
    $hasCapMaxKids06 ||
    $hasCapMaxKids712 ||
    $hasCapAllowBabyBed
) {
    if (!isset($settings['capacity']) || !is_array($settings['capacity'])) {
        $settings['capacity'] = [];
    }

    if ($hasCapMaxGuests) {
        $settings['capacity']['max_guests'] = $capMaxGuests;
    }
    if ($hasCapMaxBeds) {
        $settings['capacity']['max_beds'] = $capMaxBeds;
    }
    if ($hasCapMinAdults) {
        $settings['capacity']['min_adults'] = $capMinAdults;
    }
    if ($hasCapMaxKids06) {
        $settings['capacity']['max_children_0_6'] = $capMaxKids06;
    }
    if ($hasCapMaxKids712) {
        $settings['capacity']['max_children_7_12'] = $capMaxKids712;
    }
    if ($hasCapAllowBabyBed) {
        $settings['capacity']['allow_baby_bed'] = $capAllowBabyBed;
    }
}


// ---------------------------------------------------------------------
// Write back JSON
// ---------------------------------------------------------------------
$jsonOut = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($jsonOut === false) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'encode_failed',
    ]);
    exit;
}

if (@file_put_contents($file, $jsonOut, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'write_failed',
        'path'  => $file,
    ]);
    exit;
}

echo json_encode([
    'ok'       => true,
    'unit'     => $unit,
    'settings' => $settings,
]);
