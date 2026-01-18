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
 * Jedro finalize logike za Autopilota (Phase 1).
 * - iz accepted inqa naredi confirmed rezervacijo,
 * - zapiše v reservations/YYYY/UNIT/ID.json,
 * - doda hard-lock v occupancy.json,
 * - po možnosti regenerira occupancy_merged,
 * - premakne inquiry iz accepted → confirmed.
 *
 * POZOR: headerjev ne nastavlja, ničesar ne echo-a.
 */

require_once __DIR__ . '/../../common/lib/json.php';

/**
 * $accData      = array accepted povpraševanja (kar imamo v $inq po soft-acceptu)
 * $acceptedPath = pot do accepted JSON-a (ki smo ga pravkar zapisali)
 * $cfg          = datetime config (kot ga imaš že v accept_inquiry.php)
 */
function cm_autopilot_finalize_core(array $accData, string $acceptedPath, array $cfg): array
{
    $tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';
    $APP = '/var/www/html/app';

    $UNITS_ROOT = $APP . '/common/data/json/units';
    $INQ_ROOT   = $APP . '/common/data/json/inquiries';
    $RES_ROOT   = $APP . '/common/data/json/reservations';

    // Osnovna polja
    $unit = (string)($accData['unit'] ?? '');
    $from = (string)($accData['from'] ?? '');
    $to   = (string)($accData['to']   ?? '');
    $id   = (string)($accData['id']   ?? '');

    if ($unit === '' || $from === '' || $to === '' || $id === '') {
        return ['ok' => false, 'error' => 'missing_required_fields'];
    }

    // Zgradi "reservation" zapis iz accepted inqa
    $res               = $accData;
    $res['status']     = 'confirmed';
    $res['lock']       = 'hard';
    $res['confirmed_at'] = cm_iso_now($tz);

    // Dodaj formattirana polja (če helper obstaja)
    if (function_exists('cm_add_formatted_fields')) {
        cm_add_formatted_fields($res, [
            'from'         => 'date',
            'to'           => 'date',
            'created'      => 'datetime',
            'accepted_at'  => 'datetime',
            'confirmed_at' => 'datetime',
        ], $cfg);
    }

    // Year iz "from" (YYYY-MM-DD)
    $ryear = substr($from, 0, 4);
    if ($ryear === '' || !ctype_digit($ryear)) {
        $ryear = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y');
    }

    // Zapiši v reservations/YYYY/UNIT/ID.json
    $resDir = "{$RES_ROOT}/{$ryear}/{$unit}";
    if (!is_dir($resDir) && !@mkdir($resDir, 0775, true)) {
        return ['ok' => false, 'error' => 'mkdir_reservation_dir_failed', 'dir' => $resDir];
    }
    $resPath = "{$resDir}/{$id}.json";

    if (!cm_json_write($resPath, $res)) {
        return ['ok' => false, 'error' => 'write_reservation_failed', 'path' => $resPath];
    }

    // Posodobi occupancy.json (hard-lock rezervacija)
    $occPath = "{$UNITS_ROOT}/{$unit}/occupancy.json";
    $occ = cm_json_read($occPath);
    if (!is_array($occ)) {
        $occ = [];
    }

    $occ[] = [
        'start'  => $from,
        'end'    => $to,       // end-exclusive
        'status' => 'reserved',
        'lock'   => 'hard',
        'source' => $res['source'] ?? 'direct',
        'id'     => $id,
    ];

    cm_json_write($occPath, $occ);

    // Regeneriraj occupancy_merged, če helper obstaja
    if (function_exists('cm_regen_merged_for_unit')) {
        cm_regen_merged_for_unit($UNITS_ROOT, $unit);
    }

    // Premakni inquiry iz accepted → confirmed
    $year  = null;
    $month = null;
    $parts = explode('/', str_replace('\\', '/', $acceptedPath));
    $idx   = array_search('inquiries', $parts, true);
    if ($idx !== false && isset($parts[$idx + 1], $parts[$idx + 2])) {
        $year  = $parts[$idx + 1];
        $month = $parts[$idx + 2];
    }

    if ($year && $month) {
        $confirmedDir  = "{$INQ_ROOT}/{$year}/{$month}/confirmed";
        if (!is_dir($confirmedDir)) {
            @mkdir($confirmedDir, 0775, true);
        }
        $confirmedPath = "{$confirmedDir}/{$id}.json";
        cm_json_write($confirmedPath, $res);
    }

    // Accepted datoteko lahko pobrišemo (idempotentno)
    @unlink($acceptedPath);

    return [
        'ok'               => true,
        'reservation'      => $res,
        'reservation_file' => $resPath,
    ];
}
