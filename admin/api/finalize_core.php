<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/finalize_core.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * finalize_core.php
 *
 * Jedro finalize logike:
 * - iz accepted inquiry naredi confirmed reservation,
 * - zapiše v reservations/YYYY/UNIT/ID.json,
 * - odstrani stare soft-hold / cleaning zapise za isti ID,
 * - doda hard-lock v occupancy.json,
 * - doda clean_before / clean_after blokade,
 * - regenerira occupancy_merged, če helper obstaja,
 * - premakne inquiry iz accepted → confirmed.
 *
 * Ne nastavlja headerjev in ničesar ne echo-a.
 */

if (is_file(__DIR__ . '/_lib/paths.php')) {
    require_once __DIR__ . '/_lib/paths.php';
}

if (!function_exists('cm_json_read') || !function_exists('cm_json_write')) {
    require_once __DIR__ . '/../../common/lib/json.php';
}

if (!function_exists('cm_finalize_app_root')) {
    function cm_finalize_app_root(): string
    {
        if (function_exists('app_root')) {
            return app_root();
        }

        $root = realpath(__DIR__ . '/../..');
        if ($root !== false) {
            return $root;
        }

        return dirname(__DIR__, 2);
    }
}

if (!function_exists('cm_finalize_ymd_add_days')) {
    function cm_finalize_ymd_add_days(string $ymd, int $days): string
    {
        try {
            $dt = new DateTimeImmutable($ymd . ' 00:00:00');
            return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        } catch (Throwable $e) {
            return $ymd;
        }
    }
}

function cm_autopilot_finalize_core(array $accData, string $acceptedPath, array $cfg): array
{
    $tz = $cfg['timezone'] ?? 'Europe/Ljubljana';
    $APP = cm_finalize_app_root();

    $UNITS_ROOT = $APP . '/common/data/json/units';
    $INQ_ROOT   = $APP . '/common/data/json/inquiries';
    $RES_ROOT   = $APP . '/common/data/json/reservations';

    $unit = (string)($accData['unit'] ?? '');
    $from = (string)($accData['from'] ?? '');
    $to   = (string)($accData['to']   ?? '');
    $id   = (string)($accData['id']   ?? '');

    if ($unit === '' || $from === '' || $to === '' || $id === '') {
        return ['ok' => false, 'error' => 'missing_required_fields'];
    }

    $res = $accData;
    $res['status'] = 'confirmed';
    $res['lock'] = 'hard';

    if (empty($res['confirmed_at'])) {
        if (function_exists('cm_iso_now')) {
            $res['confirmed_at'] = cm_iso_now($tz);
        } else {
            $res['confirmed_at'] = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format(DateTimeInterface::ATOM);
        }
    }

    if (!isset($res['meta']) || !is_array($res['meta'])) {
        $res['meta'] = [];
    }

    $cleanBefore = !empty($res['meta']['clean_before_flag']);
    $cleanAfter  = !empty($res['meta']['clean_after_flag']);

    $res['meta']['finalized_at'] = $res['meta']['finalized_at'] ?? $res['confirmed_at'];
    $res['meta']['finalized_via'] = $res['meta']['finalized_via'] ?? 'admin/api/finalize_core.php';

    if (function_exists('cm_add_formatted_fields')) {
        cm_add_formatted_fields($res, [
            'from'         => 'date',
            'to'           => 'date',
            'created'      => 'datetime',
            'accepted_at'  => 'datetime',
            'confirmed_at' => 'datetime',
        ], $cfg);
    }

    $ryear = substr($from, 0, 4);
    if ($ryear === '' || !ctype_digit($ryear)) {
        $ryear = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y');
    }

    $resDir = "{$RES_ROOT}/{$ryear}/{$unit}";
    if (!is_dir($resDir) && !@mkdir($resDir, 0775, true)) {
        return ['ok' => false, 'error' => 'mkdir_reservation_dir_failed', 'dir' => $resDir];
    }

    $resPath = "{$resDir}/{$id}.json";
    if (!cm_json_write($resPath, $res)) {
        return ['ok' => false, 'error' => 'write_reservation_failed', 'path' => $resPath];
    }

    $occPath = "{$UNITS_ROOT}/{$unit}/occupancy.json";
    $occ = cm_json_read($occPath);
    if (!is_array($occ)) {
        $occ = [];
    }

    $cleanBeforeId = $id . '-clean-before';
    $cleanAfterId  = $id . '-clean-after';

    $filtered = [];
    foreach ($occ as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowId = (string)($row['id'] ?? '');
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        $belongsToThisReservation =
            $rowId === $id ||
            $rowId === $cleanBeforeId ||
            $rowId === $cleanAfterId ||
            (string)($meta['inquiry_id'] ?? '') === $id ||
            (string)($meta['reservation_id'] ?? '') === $id ||
            (string)($meta['parent_reservation_id'] ?? '') === $id;

        if ($belongsToThisReservation) {
            continue;
        }

        $filtered[] = $row;
    }

    $filtered[] = [
        'start'  => $from,
        'end'    => $to,
        'status' => 'reserved',
        'lock'   => 'hard',
        'source' => $res['source'] ?? 'direct',
        'id'     => $id,
        'meta'   => [
            'kind' => 'reservation',
            'reservation_id' => $id,
            'confirmed_at' => (string)($res['confirmed_at'] ?? ''),
        ],
    ];

    if ($cleanBefore) {
        $filtered[] = [
            'start'  => cm_finalize_ymd_add_days($from, -1),
            'end'    => $from,
            'status' => 'blocked',
            'lock'   => 'hard',
            'source' => 'internal',
            'id'     => $cleanBeforeId,
            'meta'   => [
                'kind' => 'clean_before',
                'reservation_id' => $id,
                'parent_reservation_id' => $id,
            ],
        ];
    }

    if ($cleanAfter) {
        $filtered[] = [
            'start'  => $to,
            'end'    => cm_finalize_ymd_add_days($to, 1),
            'status' => 'blocked',
            'lock'   => 'hard',
            'source' => 'internal',
            'id'     => $cleanAfterId,
            'meta'   => [
                'kind' => 'clean_after',
                'reservation_id' => $id,
                'parent_reservation_id' => $id,
            ],
        ];
    }

    if (!cm_json_write($occPath, $filtered)) {
        return ['ok' => false, 'error' => 'write_occupancy_failed', 'path' => $occPath];
    }

    if (function_exists('cm_regen_merged_for_unit')) {
        cm_regen_merged_for_unit($UNITS_ROOT, $unit);
    }

    $year = null;
    $month = null;
    $parts = explode('/', str_replace('\\', '/', $acceptedPath));
    $idx = array_search('inquiries', $parts, true);

    if ($idx !== false && isset($parts[$idx + 1], $parts[$idx + 2])) {
        $year = $parts[$idx + 1];
        $month = $parts[$idx + 2];
    }

    if ($year && $month) {
        $confirmedDir = "{$INQ_ROOT}/{$year}/{$month}/confirmed";
        if (!is_dir($confirmedDir)) {
            @mkdir($confirmedDir, 0775, true);
        }

        $confirmedPath = "{$confirmedDir}/{$id}.json";
        cm_json_write($confirmedPath, $res);
    }

    @unlink($acceptedPath);

    return [
        'ok'               => true,
        'reservation'      => $res,
        'reservation_file' => $resPath,
        'occupancy_file'   => $occPath,
        'clean_before'     => $cleanBefore,
        'clean_after'      => $cleanAfter,
    ];
}
