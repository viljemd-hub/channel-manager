<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/pricing/copy_prices.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Copy prices from one date range to another.
 *
 * Source range uses END-exclusive date logic:
 * - source_from is included
 * - source_to is excluded
 *
 * Example:
 * source_from: 2026-07-01
 * source_to:   2026-08-01
 * target_from: 2027-07-01
 *
 * This copies the full July 2026 price range to July 2027.
 *
 * Percent adjustment supports positive and negative values:
 * - 10  means +10%
 * - -10 means -10%
 * - 0   means unchanged
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['ok' => $ok], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_json_file(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function valid_unit_id(string $unit): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,32}$/', $unit);
}

function parse_date_or_null(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }

    return $dt->format('Y-m-d') === $value ? $dt : null;
}

function bool_from_mixed(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function adjusted_price(int|float $sourcePrice, float $percent, int $roundTo): int
{
    $factor = 1.0 + ($percent / 100.0);
    $value = $sourcePrice * $factor;

    if ($roundTo > 1) {
        $value = round($value / $roundTo) * $roundTo;
    } else {
        $value = round($value);
    }

    return (int)$value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'POST required'], 405);
}

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    respond(false, ['error' => 'invalid_json'], 400);
}

$unit = trim((string)($in['unit'] ?? ''));
$sourceFromRaw = trim((string)($in['source_from'] ?? ''));
$sourceToRaw = trim((string)($in['source_to'] ?? ''));
$targetFromRaw = trim((string)($in['target_from'] ?? ''));

$percent = (float)($in['percent'] ?? 0);
$roundTo = (int)($in['round_to'] ?? 1);
$overwrite = bool_from_mixed($in['overwrite'] ?? false);
$preview = bool_from_mixed($in['preview'] ?? true);
$missingSourceMode = trim((string)($in['missing_source_mode'] ?? 'skip'));
$fallbackPriceRaw = $in['fallback_price'] ?? null;
$fallbackPrice = null;
if ($fallbackPriceRaw !== null && $fallbackPriceRaw !== '') {
    if (!is_numeric($fallbackPriceRaw)) {
        respond(false, ['error' => 'invalid_fallback_price'], 400);
    }
    $fallbackPrice = (float)$fallbackPriceRaw;
}

if ($unit === '' || !valid_unit_id($unit)) {
    respond(false, ['error' => 'invalid_unit'], 400);
}

$sourceFrom = parse_date_or_null($sourceFromRaw);
$sourceTo = parse_date_or_null($sourceToRaw);
$targetFrom = parse_date_or_null($targetFromRaw);

if (!$sourceFrom || !$sourceTo || !$targetFrom) {
    respond(false, ['error' => 'invalid_dates'], 400);
}

if ($sourceTo <= $sourceFrom) {
    respond(false, ['error' => 'source_to_must_be_after_source_from'], 400);
}

$days = (int)$sourceFrom->diff($sourceTo)->days;
if ($days < 1) {
    respond(false, ['error' => 'empty_source_range'], 400);
}

if ($days > 1000) {
    respond(false, ['error' => 'range_too_large', 'max_days' => 1000], 400);
}

if (!is_finite($percent) || $percent < -100 || $percent > 1000) {
    respond(false, ['error' => 'invalid_percent', 'allowed' => '-100 to 1000'], 400);
}

if ($roundTo < 1 || $roundTo > 100) {
    respond(false, ['error' => 'invalid_round_to', 'allowed' => '1 to 100'], 400);
}

if (!in_array($missingSourceMode, ['skip', 'use_previous', 'fixed'], true)) {
    respond(false, [
        'error' => 'invalid_missing_source_mode',
        'allowed' => ['skip', 'use_previous', 'fixed'],
    ], 400);
}

if ($missingSourceMode === 'fixed') {
    if ($fallbackPrice === null || $fallbackPrice < 0) {
        respond(false, [
            'error' => 'fallback_price_required',
            'message' => 'fallback_price is required when missing_source_mode is fixed',
        ], 400);
    }
}

$appRoot = realpath(__DIR__ . '/../../../');
if ($appRoot === false) {
    respond(false, ['error' => 'app_root_not_found'], 500);
}

$unitRoot = realpath($appRoot . '/common/data/json/units/' . $unit);
if ($unitRoot === false || !is_dir($unitRoot)) {
    respond(false, ['error' => 'unit_not_found'], 404);
}

$pricesFile = $unitRoot . '/prices.json';
$prices = read_json_file($pricesFile);

$items = [];
$summary = [
    'source_days' => $days,
    'copied' => 0,
    'skipped_missing_source' => 0,
    'skipped_existing_target' => 0,
    'skipped_invalid_price' => 0,
    'filled_from_previous' => 0,
    'filled_from_fixed' => 0,
    'would_write' => 0,
];

$lastKnownSourcePrice = null;

for ($i = 0; $i < $days; $i++) {
    $sourceDate = $sourceFrom->modify('+' . $i . ' days')->format('Y-m-d');
    $targetDate = $targetFrom->modify('+' . $i . ' days')->format('Y-m-d');

    $sourceExists = array_key_exists($sourceDate, $prices);
    $targetExists = array_key_exists($targetDate, $prices);

    $sourcePriceRaw = $sourceExists ? $prices[$sourceDate] : null;

    $row = [
        'source_date' => $sourceDate,
        'target_date' => $targetDate,
        'source_price' => $sourcePriceRaw,
        'target_old_price' => $targetExists ? $prices[$targetDate] : null,
        'fallback_price' => null,
        'target_new_price' => null,
        'action' => '',
    ];

    $effectiveSourcePrice = null;
    $usedPreviousFallback = false;

    if ($sourceExists && is_numeric($sourcePriceRaw)) {
        $effectiveSourcePrice = (float)$sourcePriceRaw;
        $lastKnownSourcePrice = $effectiveSourcePrice;
    } elseif ($sourceExists && !is_numeric($sourcePriceRaw)) {
        $row['action'] = 'skipped_invalid_price';
        $summary['skipped_invalid_price']++;
        $items[] = $row;
        continue;
    } elseif ($missingSourceMode === 'use_previous' && $lastKnownSourcePrice !== null) {
        $effectiveSourcePrice = (float)$lastKnownSourcePrice;
        $usedPreviousFallback = true;
        $row['fallback_price'] = $effectiveSourcePrice;
        $summary['filled_from_previous']++;
    } elseif ($missingSourceMode === 'fixed' && $fallbackPrice !== null) {
        $effectiveSourcePrice = (float)$fallbackPrice;
        $row['fallback_price'] = $effectiveSourcePrice;
        $summary['filled_from_fixed']++;
    } else {
        $row['action'] = 'skipped_missing_source';
        $summary['skipped_missing_source']++;
        $items[] = $row;
        continue;
    }

    if ($targetExists && !$overwrite) {
        $row['action'] = 'skipped_existing_target';
        $summary['skipped_existing_target']++;
        $items[] = $row;
        continue;
    }

    $newPrice = adjusted_price($effectiveSourcePrice, $percent, $roundTo);
    if ($newPrice < 0) {
        $row['action'] = 'skipped_invalid_price';
        $summary['skipped_invalid_price']++;
        $items[] = $row;
        continue;
    }

    $row['target_new_price'] = $newPrice;

    if ($usedPreviousFallback) {
        $row['action'] = $preview ? 'would_copy_from_previous' : 'copied_from_previous';
    } elseif ($missingSourceMode === 'fixed' && !$sourceExists) {
        $row['action'] = $preview ? 'would_copy_from_fixed' : 'copied_from_fixed';
    } else {
        $row['action'] = $preview ? 'would_copy' : 'copied';
    }

    $summary['would_write']++;

    if (!$preview) {
        $prices[$targetDate] = $newPrice;
        $summary['copied']++;
    }

    $items[] = $row;
}

ksort($prices);

$backupPath = null;

if (!$preview && $summary['copied'] > 0) {
    if (is_file($pricesFile)) {
        $backupPath = $pricesFile . '.bak.copy_prices.' . date('Ymd_His');
        @copy($pricesFile, $backupPath);
    }

    if (!write_json_file($pricesFile, $prices)) {
        respond(false, ['error' => 'write_failed'], 500);
    }
}

respond(true, [
    'unit' => $unit,
    'source_from' => $sourceFrom->format('Y-m-d'),
    'source_to' => $sourceTo->format('Y-m-d'),
    'target_from' => $targetFrom->format('Y-m-d'),
    'percent' => $percent,
    'round_to' => $roundTo,
    'overwrite' => $overwrite,
    'preview' => $preview,
    'missing_source_mode' => $missingSourceMode,
    'fallback_price' => $fallbackPrice,
    'summary' => $summary,
    'backup' => $backupPath,
    'items' => $items,
]);
