<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1, dashboard scopes
 * File: common/lib/cm_bridge_dashboard.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Host/owner-context Bridge scopes for CM Companion (see
 * CM_Mobile_Companion_Plan_v0.1.md §5, pro-dev-repo) - aggregate/
 * operational data, never per-guest PII.
 *
 * Free-tier variant of the PRO original: today/inquiries logic is
 * unchanged, but there is no Price Engine on this tier, so:
 * - unit listing is a plain scandir over units_root() (cm_bridge_list_units())
 *   instead of Price Engine's pe_list_units() - same result, no PRO
 *   dependency.
 * - dashboard.alerts always returns an empty list rather than erroring -
 *   there is no alert-generating job on Free to project, not a bug. Keeps
 *   the same response shape as PRO so Companion's client code needs no
 *   tier-specific branching.
 */

require_once __DIR__ . '/cm_bridge_server.php';

if (!function_exists('units_root')) {
    require_once cm_bridge_app_root() . '/admin/api/_lib/paths.php';
}

/** Same enumeration admin/mobile_calendar.php and integrations_get.php already use. */
function cm_bridge_list_units(): array
{
    $dir = units_root();
    if (!is_dir($dir)) return [];

    $units = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($dir . '/' . $entry)) continue;
        $units[] = $entry;
    }
    sort($units, SORT_NATURAL | SORT_FLAG_CASE);
    return $units;
}

/**
 * Reads only the guest-count fields (adults/kids06/kids712) off a
 * reservation record - never guest.name/phone/email/note. Looked up by
 * ID+unit rather than trusting occupancy_merged's own meta.file (that
 * field is a display hint, not guaranteed current - a direct glob by ID
 * is the same lookup discipline cm_bridge_find_reservation_by_pin() uses).
 */
function cm_bridge_reservation_guest_count(string $unit, string $reservationId): ?int
{
    if ($reservationId === '') return null;
    $matches = glob(cm_bridge_reservations_root() . '/*/' . $unit . '/' . $reservationId . '.json', GLOB_NOSORT) ?: [];
    if (empty($matches)) return null;

    $res = read_json($matches[0]);
    if (!is_array($res)) return null;

    return (int)($res['adults'] ?? 0) + (int)($res['kids06'] ?? 0) + (int)($res['kids712'] ?? 0);
}

/**
 * Today's arrivals/departures/currently-hosted stay per unit, from each
 * unit's occupancy_merged.json (hard reservations only - soft-holds and
 * blocks are operational noise a dashboard summary doesn't need).
 *
 * guest_count is a deliberate, narrow exception to "occupancy_merged rows
 * never carry guest data" - it's an aggregate headcount, not PII.
 */
function cm_bridge_dashboard_today(): array
{
    $today = gmdate('Y-m-d');
    $units = cm_bridge_list_units();
    $out = [];
    $totals = ['arrivals' => 0, 'arrival_guests' => 0, 'departures' => 0, 'departure_guests' => 0, 'hosting_now' => 0, 'hosting_now_guests' => 0];

    foreach ($units as $unitId) {
        $mergedPath = units_root() . '/' . $unitId . '/occupancy_merged.json';
        $segments = read_json($mergedPath);
        if (!is_array($segments)) $segments = [];

        $arrivals = [];
        $departures = [];
        $currentlyHosting = null;

        foreach ($segments as $seg) {
            if (!is_array($seg)) continue;
            if (($seg['lock'] ?? '') !== 'hard') continue;

            $start = (string)($seg['start'] ?? '');
            $end = (string)($seg['end'] ?? '');
            if ($start === '' || $end === '') continue;

            $segId = (string)($seg['id'] ?? '');
            $guestCount = cm_bridge_reservation_guest_count($unitId, $segId);

            if ($start === $today) {
                $arrivals[] = ['id' => $segId, 'guest_count' => $guestCount];
                $totals['arrivals']++;
                $totals['arrival_guests'] += $guestCount ?? 0;
            }
            if ($end === $today) {
                $departures[] = ['id' => $segId, 'guest_count' => $guestCount];
                $totals['departures']++;
                $totals['departure_guests'] += $guestCount ?? 0;
            }
            if ($start <= $today && $today < $end) {
                $currentlyHosting = ['id' => $segId, 'guest_count' => $guestCount, 'checkout' => $end];
                $totals['hosting_now']++;
                $totals['hosting_now_guests'] += $guestCount ?? 0;
            }
        }

        $out[] = [
            'unit' => $unitId,
            'currently_hosting' => $currentlyHosting,
            'arrivals' => $arrivals,
            'departures' => $departures,
        ];
    }

    return ['ok' => true, 'date' => $today, 'totals' => $totals, 'units' => $out];
}

/**
 * Always empty on Free - there's no Price Engine / alert-generating job
 * on this tier. Same response shape as PRO ({"ok":true,"alerts":[...]})
 * so Companion's client code needs no tier-specific branching; the
 * Attention feed just always shows 0 alert-type items here.
 */
function cm_bridge_dashboard_alerts(): array
{
    return ['ok' => true, 'alerts' => []];
}

/**
 * Best-effort E.164 calling-code -> country lookup, sorted longest-prefix
 * first so e.g. +386 (Slovenia) matches before a hypothetical shorter
 * overlapping code would. Not exhaustive (NANP's +1 covers US/Canada/
 * Caribbean as a block - a phone number alone can't disambiguate further)
 * - covers the calling codes most likely for a Slovenian small-stay's
 * guests. Returns null rather than a wrong guess when unmatched.
 */
function cm_bridge_phone_country(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if ($digits === '') return null;

    static $codes = null;
    if ($codes === null) {
        $codes = [
            '385' => 'Croatia', '386' => 'Slovenia', '381' => 'Serbia', '387' => 'Bosnia and Herzegovina',
            '382' => 'Montenegro', '389' => 'North Macedonia', '355' => 'Albania',
            '39' => 'Italy', '43' => 'Austria', '49' => 'Germany', '33' => 'France', '34' => 'Spain',
            '351' => 'Portugal', '41' => 'Switzerland', '31' => 'Netherlands', '32' => 'Belgium',
            '352' => 'Luxembourg', '420' => 'Czech Republic', '421' => 'Slovakia', '36' => 'Hungary',
            '40' => 'Romania', '359' => 'Bulgaria', '48' => 'Poland', '370' => 'Lithuania',
            '371' => 'Latvia', '372' => 'Estonia', '30' => 'Greece', '90' => 'Turkey',
            '44' => 'United Kingdom', '353' => 'Ireland', '45' => 'Denmark', '46' => 'Sweden',
            '47' => 'Norway', '358' => 'Finland', '354' => 'Iceland',
            '7' => 'Russia/Kazakhstan', '380' => 'Ukraine', '375' => 'Belarus',
            '1' => 'US/Canada/Caribbean', '61' => 'Australia', '64' => 'New Zealand',
            '81' => 'Japan', '82' => 'South Korea', '86' => 'China', '91' => 'India',
            '971' => 'United Arab Emirates', '966' => 'Saudi Arabia', '972' => 'Israel',
            '27' => 'South Africa', '55' => 'Brazil', '52' => 'Mexico', '54' => 'Argentina',
        ];
        uksort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    foreach ($codes as $code => $country) {
        // PHP casts purely-numeric string array keys (e.g. "7", "1") to
        // int, so re-stringify before str_starts_with().
        if (str_starts_with($digits, (string)$code)) return $country;
    }
    return null;
}

/**
 * Pending inquiries with the extra fields the Inquiry detail screen needs:
 * adults/kids06/kids712 breakdown and a derived guest_phone_country -
 * never the raw phone number itself, only what a calling-code lookup can
 * tell without exposing contact info.
 */
function cm_bridge_dashboard_inquiries(): array
{
    $inqRoot = cm_bridge_app_root() . '/common/data/json/inquiries';
    $files = glob($inqRoot . '/*/*/pending/*.json', GLOB_NOSORT) ?: [];

    $summaries = [];
    foreach ($files as $path) {
        $inq = read_json($path);
        if (!is_array($inq)) continue;
        $guest = is_array($inq['guest'] ?? null) ? $inq['guest'] : [];
        $summaries[] = [
            'id' => (string)($inq['id'] ?? ''),
            'unit' => (string)($inq['unit'] ?? ''),
            'from' => (string)($inq['from'] ?? ''),
            'to' => (string)($inq['to'] ?? ''),
            'nights' => (int)($inq['nights'] ?? 0),
            'created' => (string)($inq['created'] ?? ''),
            'adults' => (int)($inq['adults'] ?? 0),
            'kids06' => (int)($inq['kids06'] ?? 0),
            'kids712' => (int)($inq['kids712'] ?? 0),
            'guest_phone_country' => cm_bridge_phone_country((string)($guest['phone'] ?? '')),
        ];
    }

    usort($summaries, static fn (array $a, array $b): int => strcmp($b['created'], $a['created']));

    return ['ok' => true, 'count' => count($summaries), 'inquiries' => $summaries];
}

/**
 * Proxies to the existing admin accept/reject endpoints over a loopback
 * HTTP call - never a direct write. This is deliberate, not a shortcut:
 * accept_inquiry.php carries real safety gates (ICS hard-fact conflict
 * check, local occupancy conflict check, soft-hold bookkeeping) that this
 * function has no business re-implementing or bypassing. Bridge callers
 * get exactly the same guarantees the admin UI's own Confirm/Reject
 * buttons get, nothing more. Free's accept_inquiry.php doesn't gate on
 * admin_key at all (unlike PRO) - the key is still forwarded here
 * harmlessly, in case that ever changes.
 */
function cm_bridge_action_inquiry_respond(string $id, string $decision, ?string $reason): array
{
    if (!in_array($decision, ['accept', 'reject'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }

    $baseUrl = rtrim((string)(cm_bridge_public_settings()['public_base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'public_base_url_not_configured'];
    }

    $adminKeyFile = cm_bridge_app_root() . '/common/data/json/admin_key.txt';
    $adminKey = is_file($adminKeyFile) ? trim((string)file_get_contents($adminKeyFile)) : '';

    $endpoint = $decision === 'accept' ? '/admin/api/accept_inquiry.php' : '/admin/api/reject_inquiry.php';
    $payload = ['id' => $id, 'key' => $adminKey];
    if ($decision === 'reject' && $reason !== null && $reason !== '') {
        $payload['reason'] = $reason;
    }

    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'upstream_unreachable', 'detail' => $error];
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'upstream_invalid_response'];
    }

    return $decoded;
}
