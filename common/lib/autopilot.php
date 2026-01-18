<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/autopilot.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * Autopilot – Phase 1 + conflict-care (brez ICS).
 *
 * Skupna logika za:
 *  - cm_autopilot_precheck($inquiry)
 *  - cm_autopilot_apply_precheck_for_inquiry_id($inquiryId)
 *  - cm_autopilot_run_for_inquiry_id($inquiryId)
 *
 * Ne uporablja admin json_io; ima svoj minimalni JSON reader.
 */

/**
 * Minimalni JSON bralnik za autopilot (brez odvisnosti od admin json_io).
 */
function cm_autopilot_read_json(string $path): array
{
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Prebere autopilot nastavitve (global + per-unit override) in vrne flatten strukturo.
 */
function cm_autopilot_load_settings(string $unit): array
{
    $appRoot   = dirname(__DIR__, 2); // /var/www/html/app
    $unitsRoot = $appRoot . '/common/data/json/units';
    $globalFile = $unitsRoot . '/site_settings.json';
    $unitFile   = $unitsRoot . '/' . $unit . '/site_settings.json';

    $global = cm_autopilot_read_json($globalFile);
    $unitSt = cm_autopilot_read_json($unitFile);

    $gAuto = isset($global['autopilot']) && is_array($global['autopilot'])
        ? $global['autopilot']
        : [];

    $uAuto = isset($unitSt['autopilot']) && is_array($unitSt['autopilot'])
        ? $unitSt['autopilot']
        : [];

    $merged = array_merge(
        [
            'enabled'                    => false,
            'mode'                       => 'auto_confirm_on_accept',
            'min_days_before_arrival'    => 2,
            'max_nights'                 => 14,
            'allowed_sources'            => ['direct', 'website', 'public'],
            'check_ics_on_accept'        => false,
            'check_ics_on_guest_confirm' => false,

            // NOVO: dovolimo odklop varovalk samo v TEST načinu
            // (production: autopilot.enabled=true => ICS checks so obvezni)
            'test_mode'                  => false,
            'test_mode_until'            => '',
            'timezone'                   => 'Europe/Ljubljana',
        ],
        $gAuto,
        $uAuto
    );

    // normalizacija
    $merged['enabled']                 = (bool)($merged['enabled'] ?? false);
    $merged['min_days_before_arrival'] = (int)($merged['min_days_before_arrival'] ?? 0);
    $merged['max_nights']              = (int)($merged['max_nights'] ?? 0);
    $merged['test_mode']               = (bool)($merged['test_mode'] ?? false);
    $merged['check_ics_on_accept']        = (bool)($merged['check_ics_on_accept'] ?? false);
    $merged['check_ics_on_guest_confirm'] = (bool)($merged['check_ics_on_guest_confirm'] ?? false);
    $merged['test_mode_until'] = (string)($merged['test_mode_until'] ?? '');
	// Če je test_mode_until v prihodnosti → test_mode se šteje kot ON
	$untilRaw = trim($merged['test_mode_until']);
	if ($untilRaw !== '') {
    try {
        $tz  = new DateTimeZone($merged['timezone'] ?? 'Europe/Ljubljana');
        $now = new DateTimeImmutable('now', $tz);
        $dt  = new DateTimeImmutable($untilRaw);
        if ($dt > $now) {
            $merged['test_mode'] = true;
        }
    } catch (Throwable $e) {
        // neveljaven datum → ignoriramo (test_mode ostane po booleanu)
    }
}


    if (!isset($merged['allowed_sources']) || !is_array($merged['allowed_sources'])) {
        $merged['allowed_sources'] = ['direct', 'website', 'public'];
    }

    return $merged;
}

/**
 * Preveri osnovne filtre (source, min_days_before_arrival, max_nights).
 */
function cm_autopilot_filters_ok(array $inq, array $settings, DateTimeImmutable $now): array
{
    if (empty($settings['enabled'])) {
        return ['ok' => false, 'reason' => 'disabled'];
    }

    $meta = isset($inq['meta']) && is_array($inq['meta']) ? $inq['meta'] : [];
    $src  = strtolower((string)($meta['source'] ?? $meta['channel'] ?? 'unknown'));

    $allowed = array_map('strtolower', (array)($settings['allowed_sources'] ?? []));
    if ($allowed && !in_array($src, $allowed, true)) {
        return [
            'ok'      => false,
            'reason'  => 'source_not_allowed',
            'src'     => $src,
            'allowed' => $allowed,
        ];
    }

    $from = $inq['from'] ?? null;
    $to   = $inq['to']   ?? null;
    if (!$from || !$to) {
        return ['ok' => false, 'reason' => 'missing_range'];
    }

    // days_before_arrival
    try {
        $dtFrom = new DateTimeImmutable((string)$from, $now->getTimezone());
        $diff   = $now->diff($dtFrom);
        $days   = (int)$diff->days;

        // če je termin v preteklosti, obravnavamo kot 0 dni vnaprej
        if ($dtFrom < $now) {
            $days = 0;
        }
    } catch (Throwable $e) {
        return [
            'ok'     => false,
            'reason' => 'invalid_dates',
            'error'  => $e->getMessage(),
        ];
    }

    $minDays = (int)($settings['min_days_before_arrival'] ?? 0);
    if ($minDays > 0 && $days < $minDays) {
        return [
            'ok'      => false,
            'reason'  => 'too_soon',
            'days'    => $days,
            'minDays' => $minDays,
        ];
    }

    $nights = (int)($inq['nights'] ?? 0);
    $maxN   = (int)($settings['max_nights'] ?? 0);
    if ($maxN > 0 && $nights > $maxN) {
        return [
            'ok'      => false,
            'reason'  => 'too_long',
            'nights'  => $nights,
            'maxN'    => $maxN,
        ];
    }

    return [
        'ok'                  => true,
        'reason'              => 'ok',
        'days_before_arrival' => $days,
        'nights'              => $nights,
        'source'              => $src,
    ];
}

/**
 * Preveri, ali je razpon v occupancy_merged.json prost (konzervativno: če ni podatkov, vrne false).
 */
function cm_autopilot_is_range_free(string $unit, string $from, string $to): bool
{
    if ($unit === '' || $from === '' || $to === '') {
        return false;
    }

    $appRoot = dirname(__DIR__, 2);

    // 1) Najprej poskusimo per-unit occupancy_merged.json:
    //    /common/data/json/units/<UNIT>/occupancy_merged.json
    $unitDir  = $appRoot . '/common/data/json/units/' . $unit;
    $occFile  = $unitDir . '/occupancy_merged.json';
    $unitOcc  = null;

    if (is_file($occFile)) {
        $occ = cm_autopilot_read_json($occFile);
        if (is_array($occ)) {
            // per-unit fajl je že direktno struktura za to enoto
            $unitOcc = $occ;
        }
    }

    // 2) Fallback: če per-unit fajla ni ali ni veljaven, poskusimo še globalni
    if ($unitOcc === null) {
        $globalFile = $appRoot . '/common/data/json/occupancy_merged.json';
        $occ = cm_autopilot_read_json($globalFile);

        if ($occ && (isset($occ[$unit]) || isset(($occ['units'] ?? [])[$unit]))) {
            $unitOcc = $occ[$unit] ?? ($occ['units'][$unit] ?? null);
        }
    }

    if (!is_array($unitOcc)) {
        // če nimamo nobenih uporabnih podatkov, raje NE avtopilot
        return false;
    }

    // normaliziramo from/to
    try {
        $tz     = new DateTimeZone('UTC');
        $rqFrom = new DateTimeImmutable($from, $tz);
        $rqTo   = new DateTimeImmutable($to,   $tz);
    } catch (Throwable $e) {
        return false;
    }

    if ($rqTo <= $rqFrom) {
        return false;
    }

    // Dovolimo dve obliki:
    // 1) day-map: { "2026-03-23": {...}, "2026-03-24": null, ... }
    // 2) seznam razponov: [ {start,end,status,...}, ... ]

    $looksLikeDayMap = false;
    foreach ($unitOcc as $k => $_v) {
        if (is_string($k) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $k)) {
            $looksLikeDayMap = true;
            break;
        }
    }

    if ($looksLikeDayMap) {
        // dan-po-dan: če obstaja kakšen zaseden dan v [from, to), ni prosto
        $cur = $rqFrom;
        while ($cur < $rqTo) {
            $key = $cur->format('Y-m-d');
            if (array_key_exists($key, $unitOcc) && $unitOcc[$key]) {
                // karkoli ne-praznega štejemo kot blokirano
                return false;
            }
            $cur = $cur->modify('+1 day');
        }
        return true;
    }

    // 2) druga varianta: seznam razponov [ ['start'=>'YYYY-MM-DD','end'=>'YYYY-MM-DD', ...], ... ]
    foreach ($unitOcc as $row) {
        if (!is_array($row)) {
            continue;
        }

        $s = $row['start'] ?? ($row['from'] ?? null);
        $e = $row['end']   ?? ($row['to']   ?? null);
        if (!$s || !$e) {
            continue;
        }

        try {
            $rs = new DateTimeImmutable($s, $tz);
            $re = new DateTimeImmutable($e, $tz);
        } catch (Throwable $e) {
            continue;
        }

        // overlap, če NE velja ( rqTo <= rs || rqFrom >= re )
        $noOverlap = ($rqTo <= $rs) || ($rqFrom >= $re);
        if (!$noOverlap) {
            return false;
        }
    }

    return true;
}


/**
 * Najde *druge* pending inquiry-je, ki se prekrivajo z danim intervalom (isti unit).
 *
 * - bere direktno iz /common/data/json/inquiries//pending/*.json
 * - NE uporablja pending_index-a
 * - ignorira selfId (trenutnega) in druge enote
 */
function cm_autopilot_find_pending_conflicts(
    string $unit,
    string $from,
    string $to,
    string $selfId = ''
): array {
    if ($unit === '' || $from === '' || $to === '') {
        return [];
    }

    $appRoot = dirname(__DIR__, 2);
    $inqRoot = $appRoot . '/common/data/json/inquiries';
    $pattern = $inqRoot . '/*/*/pending/*.json';

    $files = glob($pattern, GLOB_NOSORT);
    if (!$files) {
        return [];
    }

    $conflicts = [];

    foreach ($files as $file) {
        $row = cm_autopilot_read_json($file);
        if (!$row) {
            continue;
        }

        $id = (string)($row['id'] ?? '');
        if ($id === '' || $id === $selfId) {
            // preskoči samega sebe ali brez ID-ja
            continue;
        }

        $u = (string)($row['unit'] ?? '');
        if ($u !== $unit) {
            // druge enote nas ne zanimajo
            continue;
        }

        $pf = (string)($row['from'] ?? '');
        $pt = (string)($row['to']   ?? '');
        if ($pf === '' || $pt === '') {
            continue;
        }

        // Datumi so v obliki YYYY-MM-DD → string primerjava dela pravilno
        // overlap, če NE velja ( our_to <= other_from || our_from >= other_to )
        $noOverlap = ($to <= $pf) || ($from >= $pt);
        if ($noOverlap) {
            continue;
        }

        $conflicts[] = [
            'id'     => $id,
            'unit'   => $u,
            'from'   => $pf,
            'to'     => $pt,
            'status' => (string)($row['status'] ?? ''),
            'source' => (string)($row['meta']['source'] ?? ''),
        ];
    }

    return $conflicts;
}

/**
 * Glavni “brain” za en inquiry.
 *
 * Vrne:
 *  - enabled / attempted / success / reason
 *  - osnovne podatke (unit, from, to, nights)
 *  - filters: rezultat cm_autopilot_filters_ok(...)
 *  - range_free: ali je interval po occupancy_merged prost
 *  - conflict_care: info o drugih pendingih, ki se prekrivajo
 */
function cm_autopilot_precheck(array $inquiry, ?DateTimeImmutable $now = null): array
{
    $id     = (string)($inquiry['id']    ?? '');
    $unit   = (string)($inquiry['unit']  ?? '');
    $from   = (string)($inquiry['from']  ?? '');
    $to     = (string)($inquiry['to']    ?? '');
    $nights = (int)($inquiry['nights']   ?? 0);

    $settings = $unit !== '' ? cm_autopilot_load_settings($unit) : cm_autopilot_load_settings('');
    $enabled  = !empty($settings['enabled']);

    // timezone
    $tzName = (string)($settings['timezone'] ?? 'Europe/Ljubljana');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Europe/Ljubljana');
    }

    if ($now === null) {
        $now = new DateTimeImmutable('now', $tz);
    } elseif ($now->getTimezone()->getName() !== $tz->getName()) {
        $now = $now->setTimezone($tz);
    }

    // Osnovna struktura rezultata
    $result = [
        'enabled'       => $enabled,
        'attempted'     => false,
        'success'       => false,
        'reason'        => $enabled ? 'init' : 'disabled',
        'unit'          => $unit,
        'from'          => $from,
        'to'            => $to,
        'nights'        => $nights,
        'filters'       => null,
        'ics_refresh'   => null,
        'range_free'    => false,
        'conflict_care' => [
            'has_conflicts'     => false,
            'pending_conflicts' => [],
        ],
    ];

    // Če ni enote ali datumov, nima smisla nadaljevati
    if ($unit === '' || $from === '' || $to === '' || $nights <= 0) {
        $result['reason'] = 'missing_fields';
        return $result;
    }

    if (!$enabled) {
        // Autopilot izklopljen → nič ne počne, samo zabeleži stanje.
        return $result;
    }

    $result['attempted'] = true;

    // 1) Filtri: source, min_days_before_arrival, max_nights, ...
    $filters   = cm_autopilot_filters_ok($inquiry, $settings, $now);
    $filtersOk = !empty($filters['ok']);

    $result['filters'] = $filters;

    if (!$filtersOk) {
        $result['success'] = false;
        $result['reason']  = (string)($filters['reason'] ?? 'filters_not_ok');
        return $result;
    }

    // 1b) ICS refresh + regen merged
    // POLICY: če je autopilot enabled in NISMO v test_mode → ICS check je OBVEZEN
    $testMode = !empty($settings['test_mode']);
    if (!$testMode) {
        $settings['check_ics_on_accept']        = true;
        $settings['check_ics_on_guest_confirm'] = true;
    }

    $checkIcsOnAccept = !empty($settings['check_ics_on_accept']);

    if ($checkIcsOnAccept && $unit !== '') {
        $ics = cm_autopilot_run_ics_refresh_for_unit($unit, $settings);
        $result['ics_refresh'] = $ics;

        // Fail-closed:
        // - če refresh ni OK → stop
        // - če refresh ni mogoč (script missing) in nismo v test mode → stop
        if (!$testMode && (empty($ics['ok']) || !isset($ics['ok']))) {
            $result['success'] = false;
            $result['reason']  = (!empty($ics['error']) && $ics['error'] === 'ics_script_missing')
                ? 'ics_refresh_not_possible'
                : 'ics_refresh_failed';
            return $result;
        }

        // Stara logika: če smo poskusili in ni uspelo, tudi v test mode raje ne auto-acceptamo
        if (!empty($ics['attempted']) && empty($ics['ok'])) {
            $result['success'] = false;
            $result['reason']  = 'ics_refresh_failed';
            return $result;
        }
    }


    // 2) Koledar: je interval prost glede na occupancy_merged.json?
    $rangeFree = cm_autopilot_is_range_free($unit, $from, $to);
    $result['range_free'] = $rangeFree;

    if (!$rangeFree) {
        $result['success'] = false;
        $result['reason']  = 'range_not_free';
        return $result;
    }

    // 3) Conflict-care: so še drugi pendingi na istem terminu?
    $conflicts = cm_autopilot_find_pending_conflicts($unit, $from, $to, $id);
    $hasConf   = !empty($conflicts);

    $result['conflict_care'] = [
        'has_conflicts'     => $hasConf,
        'pending_conflicts' => $conflicts,
    ];

    if ($hasConf) {
        // Safe varianta: ko imamo več pendingov za isti termin,
        // Autopilot NE auto-accepta → prepusti adminu.
        $result['success'] = false;
        $result['reason']  = 'pending_conflict';
        return $result;
    }

    // 4) Vse OK → precheck uspešen, razlog 'ok'
    $result['success'] = true;
    $result['reason']  = 'ok';

    return $result;
}

/**
 * Skupni helper: za dani inquiry ID prebere pending JSON, izvede precheck
 * in zapiše rezultat pod ['autopilot']['precheck'].
 *
 * Vrne povzetek za API / debug.
 */
function cm_autopilot_apply_precheck_for_inquiry_id(string $inquiryId): array
{
    $inquiryId = trim($inquiryId);
    if ($inquiryId === '') {
        return ['ok' => false, 'error' => 'empty_id'];
    }

    if (strlen($inquiryId) < 6) {
        return ['ok' => false, 'error' => 'invalid_id'];
    }

    $year  = substr($inquiryId, 0, 4);
    $month = substr($inquiryId, 4, 2);

    if (!ctype_digit($year) || !ctype_digit($month)) {
        return [
            'ok'    => false,
            'error' => 'invalid_id_prefix',
            'id'    => $inquiryId,
        ];
    }

    $appRoot    = dirname(__DIR__, 2); // /var/www/html/app
    $inquiries  = $appRoot . '/common/data/json/inquiries';
    $pendingDir = $inquiries . '/' . $year . '/' . $month . '/pending';
    $pendingFile= $pendingDir . '/' . $inquiryId . '.json';

    if (!is_file($pendingFile)) {
        return [
            'ok'    => false,
            'error' => 'pending_not_found',
            'id'    => $inquiryId,
            'file'  => $pendingFile,
        ];
    }

    $raw  = @file_get_contents($pendingFile);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return [
            'ok'    => false,
            'error' => 'pending_json_invalid',
            'id'    => $inquiryId,
            'file'  => $pendingFile,
        ];
    }

    // Precheck
    $precheck = cm_autopilot_precheck($data);

    if (!isset($data['autopilot']) || !is_array($data['autopilot'])) {
        $data['autopilot'] = [];
    }
    $data['autopilot']['precheck'] = $precheck;

    @file_put_contents(
        $pendingFile,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return [
        'ok'       => true,
        'id'       => $inquiryId,
        'file'     => $pendingFile,
        'precheck' => $precheck,
    ];
}



/**
 * Pomagalček: regenerira /common/data/json/units/<UNIT>/occupancy_merged.json
 * iz occupancy.json + local_bookings.json + ics_import.json.
 *
 * Najprej poskusi uporabiti cm_regen_merged_for_unit iz datetime_fmt.php,
 * če obstaja; sicer naredi preprost fallback merge.
 */
function cm_autopilot_regen_merged_for_unit(string $unit): array
{
    $unit = trim($unit);
    if ($unit === '') {
        return ['ok' => false, 'error' => 'empty_unit'];
    }

    $appRoot  = dirname(__DIR__, 2); // /var/www/html/app
    $unitsDir = $appRoot . '/common/data/json/units';
    $unitDir  = $unitsDir . '/' . $unit;
    $merged   = $unitDir . '/occupancy_merged.json';

    if (!is_dir($unitDir)) {
        return [
            'ok'    => false,
            'error' => 'unit_dir_missing',
            'file'  => $merged,
        ];
    }

    // Poskus: uporabimo obstoječi helper iz datetime_fmt.php, če je na voljo
    require_once __DIR__ . '/datetime_fmt.php';

    if (function_exists('cm_regen_merged_for_unit')) {
        $ok = cm_regen_merged_for_unit($unitsDir, $unit);

        return [
            'ok'   => (bool)$ok,
            'file' => $merged,
            'mode' => 'helper',
        ];
    }

    // Fallback: ročni merge occupancy.json + local_bookings.json + ics_import.json
    $sources = [
        'local_bookings.json',
        'occupancy.json',
        'ics_import.json',
    ];

    $all = [];

    foreach ($sources as $name) {
        $p = $unitDir . '/' . $name;
        $arr = cm_autopilot_read_json($p);
        if (is_array($arr)) {
            foreach ($arr as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }
        }
    }

    $json = json_encode(
        $all,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return [
            'ok'    => false,
            'error' => 'json_encode_failed',
            'file'  => $merged,
        ];
    }

    $ok = @file_put_contents($merged, $json) !== false;

    return [
        'ok'   => $ok,
        'file' => $merged,
        'mode' => 'fallback',
    ];
}

/**
 * ICS refresh + regen merged za eno enoto.
 *
 * Koraki:
 *  1) poišče /common/data/json/units/<UNIT>/update_occupancy_from_ics.py
 *  2) če obstaja, požene: python3 update_occupancy_from_ics.py
 *  3) če exit code == 0, pokliče cm_autopilot_regen_merged_for_unit($unit)
 *
 * Vrača podatkovno strukturo, ki jo zapišemo v precheck['ics_refresh'].
 */
function cm_autopilot_run_ics_refresh_for_unit(string $unit, array $settings = []): array
{
    $unit = trim($unit);

    $result = [
        'attempted'   => false,
        'ok'          => null,
        'script'      => null,
        'exit_code'   => null,
        'output_tail' => null,
        'merge'       => null,
    ];

    if ($unit === '') {
        $result['ok']    = false;
        $result['error'] = 'empty_unit';
        return $result;
    }

    $appRoot  = dirname(__DIR__, 2); // /var/www/html/app
    $unitDir  = $appRoot . '/common/data/json/units/' . $unit;
    $script   = $unitDir . '/update_occupancy_from_ics.py';

    $result['script'] = $script;

    if (!is_dir($unitDir) || !is_file($script)) {
        // Ni per-unit ICS skripte.
        // Če smo v production (test_mode=false), in pričakujemo ICS check,
        // potem mora autopilot fail-closed (ne sme potrditi "na slepo").
        $testMode = !empty($settings['test_mode']);
        if (!$testMode) {
            $result['attempted'] = false;
            $result['ok']        = false;
            $result['error']     = 'ics_script_missing';
        }
        return $result;
    }

    // 1) Pognemo python skripto
    $cmd = 'python3 ' . escapeshellarg($script) . ' 2>&1';

    $output   = [];
    $exitCode = 1;

    @exec($cmd, $output, $exitCode);

    $result['attempted']   = true;
    $result['exit_code']   = $exitCode;
    $result['output_tail'] = implode("\n", array_slice($output ?? [], -10));

    if ($exitCode !== 0) {
        // ICS refresh ni uspel → ok = false, merge ne poskušamo.
        $result['ok']    = false;
        $result['error'] = 'ics_script_failed';
        return $result;
    }

    // 2) Po uspešnem ICS pullu regeneriramo merged
    $merge = cm_autopilot_regen_merged_for_unit($unit);
    $result['merge'] = $merge;
    $result['ok']    = !empty($merge['ok']);

    if (empty($merge['ok'])) {
        $result['error'] = 'merge_failed';
    }

    return $result;
}




/**
 * Interni helper: pokliče /admin/api/accept_inquiry.php z JSON {id: ...}.
 *
 * Uporablja se iz cm_autopilot_run_for_inquiry_id in lahko tudi iz CLI skript.
 */
function cm_autopilot_call_accept_inquiry(string $inquiryId): array
{
    $url = 'http://localhost/app/admin/api/accept_inquiry.php';

    $payload = json_encode(['id' => $inquiryId], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'json_encode_failed'];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n" .
                         "Accept: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);

    $resBody = @file_get_contents($url, false, $ctx);

    $statusLine = '';
    $code       = 0;

    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        $statusLine = $http_response_header[0];
        if (is_string($statusLine) && preg_match('~\s(\d{3})\s~', $statusLine, $m)) {
            $code = (int)$m[1];
        }
    }

    if ($resBody === false) {
        return [
            'ok'          => false,
            'error'       => 'http_failed',
            'http_status' => $code,
        ];
    }

    $decoded = json_decode($resBody, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $ok = isset($decoded['ok']) ? (bool)$decoded['ok'] : ($code >= 200 && $code < 300);

    $decoded['ok']          = $ok;
    $decoded['http_status'] = $code;
    $decoded['raw']         = $resBody;

    return $decoded;
}

/**
 * High-level helper: za dani inquiry ID:
 *  - izvede precheck (cm_autopilot_apply_precheck_for_inquiry_id),
 *  - če je precheck "zelen" (enabled + success + range_free + reason=ok),
 *      pokliče accept_inquiry.php in vrne summary.
 *
 * Uporaba:
 *  - thankyou.php (nova povpraševanja, instant auto-accept),
 *  - CLI/cron worker (batch obdelava pendingov).
 */
/**
 * High-level helper: za dani inquiry ID:
 *  - izvede precheck (cm_autopilot_apply_precheck_for_inquiry_id),
 *  - če je precheck "zelen" (enabled + success + range_free + reason=ok),
 *      preveri unit-lock in pokliče accept_inquiry.php.
 *
 * Uporaba:
 *  - thankyou.php (nova povpraševanja, instant auto-accept),
 *  - CLI/cron worker (batch obdelava pendingov).
 */
function cm_autopilot_run_for_inquiry_id(string $inquiryId): array
{
    $inquiryId = trim($inquiryId);

    // 1) Najprej precheck + zapis v pending JSON
    $preRes = cm_autopilot_apply_precheck_for_inquiry_id($inquiryId);

    if (empty($preRes['ok'])) {
        $preRes['stage'] = 'precheck_failed';
        $preRes['ok']    = false;
        return $preRes;
    }

    $pre = isset($preRes['precheck']) && is_array($preRes['precheck'])
        ? $preRes['precheck']
        : [];

    $enabled   = !empty($pre['enabled']);
    $attempted = !empty($pre['attempted']);
    $success   = !empty($pre['success']);
    $rangeFree = array_key_exists('range_free', $pre) ? (bool)$pre['range_free'] : false;
    $reason    = (string)($pre['reason'] ?? '');
    $unit      = (string)($pre['unit'] ?? '');

    // Autopilot izklopljen za to enoto
    if (!$enabled) {
        return [
            'ok'       => false,
            'stage'    => 'disabled',
            'id'       => $inquiryId,
            'precheck' => $pre,
        ] + $preRes;
    }

    // Precheck ni "zelen" – filtri / range / razlog (vključno s pending_conflict)
    if (!$attempted || !$success || !$rangeFree || $reason !== 'ok') {
        return [
            'ok'       => false,
            'stage'    => 'precheck_not_green',
            'id'       => $inquiryId,
            'precheck' => $pre,
        ] + $preRes;
    }

    // 2) Precheck OK → za varnost poskusimo pridobiti unit-lock
    if ($unit === '') {
        // brez enote ne moremo zaklenit – za vsak slučaj NE avto-acceptamo
        return [
            'ok'       => false,
            'stage'    => 'unit_unknown',
            'id'       => $inquiryId,
            'precheck' => $pre,
        ] + $preRes;
    }

    $lock = cm_autopilot_acquire_unit_lock($unit);
    if (empty($lock['ok'])) {
        // enota je trenutno "busy" (drug autopilot ali proces dela na njej)
        // → ne bomo avto-potrdili, raje prepustimo adminu
        return [
            'ok'       => false,
            'stage'    => 'unit_lock_busy',
            'id'       => $inquiryId,
            'precheck' => $pre,
            'lock'     => [
                'ok'    => false,
                'error' => $lock['error'] ?? 'unknown',
                'file'  => $lock['file'] ?? null,
            ],
        ] + $preRes;
    }

    try {
        // 3) Z zaklenjeno enoto pokličemo accept_inquiry (kot da bi admin kliknil Confirm)
        $acceptRes = cm_autopilot_call_accept_inquiry($inquiryId);
        $ok        = !empty($acceptRes['ok']);

        return [
            'ok'       => $ok,
            'stage'    => $ok ? 'auto_accepted' : 'accept_failed',
            'id'       => $inquiryId,
            'precheck' => $pre,
            'accept'   => $acceptRes,
        ] + $preRes;
    } finally {
        cm_autopilot_release_unit_lock($lock);
    }
}

/**
 * Interni helper: pridobi ekskluzivni lock za enoto.
 *
 * Uporabimo preprost file-lock v:
 *   /common/data/json/units/<UNIT>/autopilot.lock
 *
 * Vrne:
 *   ['ok' => true,  'fh' => resource, 'file' => '...']  ob uspehu
 *   ['ok' => false, 'error' => '...', 'file' => '...'] ob napaki / zasedenosti
 */
function cm_autopilot_acquire_unit_lock(string $unit): array
{
    $unit = trim($unit);
    if ($unit === '') {
        return ['ok' => false, 'error' => 'empty_unit'];
    }

    $appRoot   = dirname(__DIR__, 2); // /var/www/html/app
    $lockDir   = $appRoot . '/common/data/json/units/' . $unit;
    $lockFile  = $lockDir . '/autopilot.lock';

    if (!is_dir($lockDir)) {
        // če enota še nima svojega dirja, nekaj ne štima – raje ne lockamo
        return ['ok' => false, 'error' => 'unit_dir_missing', 'file' => $lockFile];
    }

    $fh = @fopen($lockFile, 'c+');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'lock_open_failed', 'file' => $lockFile];
    }

    // non-blocking exclusive lock – če je že zaklenjen, takoj fail
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        @fclose($fh);
        return ['ok' => false, 'error' => 'lock_busy', 'file' => $lockFile];
    }

    return [
        'ok'   => true,
        'fh'   => $fh,
        'file' => $lockFile,
    ];
}

/**
 * Interni helper: sprosti unit-lock, če obstaja.
 */
function cm_autopilot_release_unit_lock(array $lock): void
{
    if (!isset($lock['fh'])) {
        return;
    }
    $fh = $lock['fh'];
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

