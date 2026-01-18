<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/unit_settings_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * /app/admin/api/unit_settings_get.php
 *
 * Return per-unit settings from:
 *   /app/common/data/json/units/<UNIT>/site_settings.json
 *
 * Structure in response:
 * {
 *   "ok": true,
 *   "unit": "A1",
 *   "settings": {
 *     "auto_block": {
 *       "before_arrival": bool,
 *       "after_departure": bool
 *     },
 *     "booking": {
 *       "min_nights": int,
 *       "allow_same_day_departure": bool
 *     },
 *     "day_use": {
 *       "enabled": bool,
 *       "from": "HH:MM",
 *       "to": "HH:MM",
 *       "max_persons": int|null
 *     },
 *     "month_render": int|null,
 *     "block_departure_day": bool
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$unit = $_GET['unit'] ?? '';
$unit = preg_replace('~[^A-Za-z0-9_-]~', '', (string)$unit);

if ($unit === '') {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'invalid_unit',
    ]);
    exit;
}

$rootUnits = '/var/www/html/app/common/data/json/units';
$unitDir   = $rootUnits . '/' . $unit;
$unitFile  = $unitDir . '/site_settings.json';

$current = [];
if (is_file($unitFile)) {
    $raw = @file_get_contents($unitFile);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
}

// helpers
$boolVal = function ($v, bool $default = false): bool {
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    return (bool)$v;
};

$intVal = function ($v, int $default): int {
    if ($v === null) return $default;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return $default;
};

$strVal = function ($v, string $default): string {
    if (is_string($v) && $v !== '') {
        return $v;
    }
    return $default;
};

// auto_block
$before = $boolVal($current['auto_block']['before_arrival']  ?? null, false);
$after  = $boolVal($current['auto_block']['after_departure'] ?? null, false);

// legacy: block_departure_day -> after_departure
if (!$after && !empty($current['block_departure_day'])) {
    $after = true;
}

// month_render (optional)
$monthRender = null;
if (isset($current['month_render'])) {
    $monthRender = $intVal($current['month_render'], 12);
}

// block_departure_day flag (still useful elsewhere)
$blockDeparture = $boolVal($current['block_departure_day'] ?? null, false);

// booking.*
$bookingMin = $intVal($current['booking']['min_nights'] ?? null, 1);
if ($bookingMin < 1) {
    $bookingMin = 1;
} elseif ($bookingMin > 365) {
    $bookingMin = 365;
}

$allowSame = $boolVal($current['booking']['allow_same_day_departure'] ?? null, false);

// booking.cleaning_fee_eur (optional)
$bookingCleaning = null;
if (array_key_exists('booking', $current) && is_array($current['booking'])) {
    if (array_key_exists('cleaning_fee_eur', $current['booking'])) {
        $raw = $current['booking']['cleaning_fee_eur'];

        // sprejmemo int/float/string ("45", "45.0", "45,5")
        if (is_int($raw) || is_float($raw)) {
            $bookingCleaning = (float)$raw;
        } elseif (is_string($raw) && $raw !== '') {
            $norm = str_replace(',', '.', $raw);
            if (is_numeric($norm)) {
                $bookingCleaning = (float)$norm;
            }
        }
    }
}



// day_use.* – default: disabled, 14:00–20:00, max_persons unset
$dayUseEnabled = $boolVal($current['day_use']['enabled'] ?? null, false);
$dayUseFrom    = $strVal($current['day_use']['from']    ?? null, '14:00');
$dayUseTo      = $strVal($current['day_use']['to']      ?? null, '20:00');

$maxPersonsRaw = $current['day_use']['max_persons'] ?? null;
$maxPersons    = null;
if ($maxPersonsRaw !== null && $maxPersonsRaw !== '') {
    $maxPersons = (int)$maxPersonsRaw;
    if ($maxPersons < 1) {
        $maxPersons = 1;
    } elseif ($maxPersons > 50) {
        $maxPersons = 50;
    }
}

$dayUseMaxDaysRaw = $current['day_use']['max_days_ahead'] ?? null;
$dayUseMaxDays    = null;
if ($dayUseMaxDaysRaw !== null && $dayUseMaxDaysRaw !== '') {
    $dayUseMaxDays = (int)$dayUseMaxDaysRaw;
    if ($dayUseMaxDays < 0) {
        $dayUseMaxDays = 0;
    } elseif ($dayUseMaxDays > 365) {
        $dayUseMaxDays = 365;
    }
}

// day_use.day_price_person (with optional legacy fallback)
$dayUsePriceRaw = $current['day_use']['day_price_person']
    ?? ($current['day_use']['day_use_person'] ?? null); // legacy key, if exists

$dayUsePrice = null;
if ($dayUsePriceRaw !== null && $dayUsePriceRaw !== '') {
    $dayUsePrice = (float)$dayUsePriceRaw;
    if ($dayUsePrice < 0) {
        $dayUsePrice = 0.0;
    }
}

// capacity.* – optional (max guests / beds / adults / kids / baby bed)
$capacity = null;
if (isset($current['capacity']) && is_array($current['capacity'])) {
    $cap = $current['capacity'];

    $capMaxGuests  = $intVal($cap['max_guests']        ?? null, 0);
    $capMaxBeds    = $intVal($cap['max_beds']          ?? null, 0);
    $capMinAdults  = $intVal($cap['min_adults']        ?? null, 0);
    $capMaxKids06  = $intVal($cap['max_children_0_6']  ?? null, 0);
    $capMaxKids712 = $intVal($cap['max_children_7_12'] ?? null, 0);
    $capAllowBaby  = $boolVal($cap['allow_baby_bed']   ?? null, false);

    $capacity = [
        'max_guests'       => $capMaxGuests,
        'max_beds'         => $capMaxBeds,
        'min_adults'       => $capMinAdults,
        'max_children_0_6' => $capMaxKids06,
        'max_children_7_12'=> $capMaxKids712,
        'allow_baby_bed'   => $capAllowBaby,
    ];
}


$out = [
    'auto_block' => [
        'before_arrival'  => $before,
        'after_departure' => $after,
    ],
    'booking' => [
        'min_nights'               => $bookingMin,
        'allow_same_day_departure' => $allowSame,
        'cleaning_fee_eur'         => $bookingCleaning,
    ],
    'day_use' => [
        'enabled'      => $dayUseEnabled,
        'from'         => $dayUseFrom,
        'to'           => $dayUseTo,
        'max_persons'  => $maxPersons,
      'max_days_ahead' => $dayUseMaxDays,
    'day_price_person' => $dayUsePrice,   // <--- NOVO
    ],
 'block_departure_day' => $blockDeparture,
];

// NOVO: capacity, če obstaja v JSON
if ($capacity !== null) {
    $out['capacity'] = $capacity;
}

if ($monthRender !== null) {
    $out['month_render'] = $monthRender;
}

echo json_encode([
    'ok'       => true,
    'unit'     => $unit,
    'settings' => $out,
]);
