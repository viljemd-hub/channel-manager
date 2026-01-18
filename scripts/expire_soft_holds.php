<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: scripts/expire_soft_holds.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

// /var/www/html/app/scripts/expire_soft_holds.php
//
// Cron-safe TTL sweep for CM soft-holds.
//
// Rules:
// - Primary truth for TTL is occupancy row meta.expires_at (Contract v1.0).
// - Legacy fallback: accepted inquiry JSON stage=accepted_soft_hold with token_expires_at.
// - Only removes occupancy rows that are TTL soft-holds (NOT admin blocks, NOT cleaning).
// - Regenerates occupancy_merged.json for the unit after changes.

$ROOT      = '/var/www/html/app';
$UNITS_DIR = $ROOT . '/common/data/json/units';
$INQ_ROOT  = $ROOT . '/common/data/json/inquiries';

require_once $ROOT . '/common/lib/datetime_fmt.php'; // cm_datetime_cfg(), cm_json_read(), cm_json_write(), cm_regen_merged_for_unit()

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';
$now = new DateTimeImmutable('now', new DateTimeZone($tz));

function find_accepted_inquiry_by_id(string $inqRoot, string $id): array {
    if ($id === '') return [null, null];

    $glob = glob(rtrim($inqRoot, '/') . "/*/*/accepted/{$id}.json", GLOB_NOSORT) ?: [];
    if (!$glob) return [null, null];

    $file = $glob[0];
    $data = cm_json_read($file);
    if (!is_array($data)) return [null, null];

    if (($data['status'] ?? '') !== 'accepted') return [null, null];
    if (($data['stage']  ?? '') !== 'accepted_soft_hold') return [null, null];

    return [$data, $file];
}

function parse_dt(?string $raw, string $tz): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    try {
        return new DateTimeImmutable($raw, new DateTimeZone($tz));
    } catch (Throwable $e) {
        return null;
    }
}

function parse_expires_at(array $inq, string $tz): ?DateTimeImmutable {
    $raw = (string)($inq['token_expires_at'] ?? '');
    return parse_dt($raw, $tz);
}

// Contract-aligned TTL soft-hold detector:
// - lock=soft
// - source=internal (preferred) OR legacy reason="soft-hold"
// - has id OR meta.inquiry_id (for traceability)
function is_ttl_soft_hold_row(array $row): bool {
    if (($row['lock'] ?? '') !== 'soft') return false;

    $src    = (string)($row['source'] ?? '');
    $reason = (string)($row['reason'] ?? '');

    $isNew    = ($src === 'internal');
    $isLegacy = ($reason === 'soft-hold');
    if (!$isNew && !$isLegacy) return false;

    $id = (string)($row['id'] ?? '');
    $inqId = '';
    if (isset($row['meta']) && is_array($row['meta'])) {
        $inqId = (string)($row['meta']['inquiry_id'] ?? '');
    }

    return ($id !== '' || $inqId !== '');
}

if (!is_dir($UNITS_DIR)) {
    fwrite(STDERR, "[ERR] Units directory not found: {$UNITS_DIR}\n");
    exit(2);
}

$dirs = @scandir($UNITS_DIR) ?: [];
$units = [];
foreach ($dirs as $d) {
    if ($d === '.' || $d === '..') continue;
    if (is_dir($UNITS_DIR . '/' . $d)) $units[] = $d;
}
sort($units, SORT_NATURAL);

$summary = [
    'ok'            => true,
    'now'           => $now->format(DATE_ATOM),
    'units_scanned' => count($units),
    'units_changed' => 0,
    'expired_count' => 0,
    'units'         => [],
];

foreach ($units as $unit) {
    $occPath = $UNITS_DIR . '/' . $unit . '/occupancy.json';
    if (!is_file($occPath)) {
        $summary['units'][$unit] = ['ok'=>true,'skipped'=>true,'reason'=>'no_occupancy_json'];
        continue;
    }

    $occ = cm_json_read($occPath);
    if (!is_array($occ)) {
        $summary['units'][$unit] = ['ok'=>false,'error'=>'bad_occupancy_json','path'=>$occPath];
        continue;
    }

    $changed = false;
    $expiredHere = 0;
    $out = [];
    $deletedInq = 0;

    foreach ($occ as $row) {
        if (!is_array($row) || !is_ttl_soft_hold_row($row)) {
            $out[] = $row;
            continue;
        }

        $id   = (string)($row['id'] ?? '');
        $meta = (isset($row['meta']) && is_array($row['meta'])) ? $row['meta'] : [];
        $inqId = (string)($meta['inquiry_id'] ?? '');

        // 1) Primary TTL: occupancy row meta.expires_at (Contract truth)
        $exp = null;
        if (is_array($meta)) {
            $exp = parse_dt((string)($meta['expires_at'] ?? ''), $tz);
        }

        // 2) Legacy fallback: accepted inquiry evidence + token_expires_at
        $inq = null;
        $inqFile = null;
        if (!$exp) {
            $lookupId = ($id !== '') ? $id : $inqId;
            [$inq, $inqFile] = find_accepted_inquiry_by_id($INQ_ROOT, $lookupId);
            if ($inq && $inqFile) {
                $exp = parse_expires_at($inq, $tz);
            }
        }

        if (!$exp) {
            // Missing/invalid TTL => do not delete automatically
            $out[] = $row;
            continue;
        }

        if ($now <= $exp) {
            $out[] = $row;
            continue;
        }

        // EXPIRED: remove occupancy row. Best-effort delete inquiry file if present.
        $changed = true;
        $expiredHere++;
        $summary['expired_count']++;

        if ($inqFile && is_string($inqFile) && is_file($inqFile)) {
            if (@unlink($inqFile)) $deletedInq++;
        }
        // do not add $row to $out
    }

    if ($changed) {
        $okWrite = cm_json_write($occPath, $out);
        if (!$okWrite) {
            $summary['units'][$unit] = [
                'ok' => false,
                'error' => 'write_failed',
                'path' => $occPath,
                'expired_removed' => $expiredHere,
                'inquiries_deleted' => $deletedInq,
            ];
            continue;
        }

        $summary['units_changed']++;
        $summary['units'][$unit] = [
            'ok' => true,
            'expired_removed' => $expiredHere,
            'inquiries_deleted' => $deletedInq,
            'regen_merged' => null,
        ];

        if (function_exists('cm_regen_merged_for_unit')) {
            $regenOk = cm_regen_merged_for_unit($UNITS_DIR, $unit);
            $summary['units'][$unit]['regen_merged'] = (bool)$regenOk;
        }
    } else {
        $summary['units'][$unit] = ['ok'=>true,'expired_removed'=>0];
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
