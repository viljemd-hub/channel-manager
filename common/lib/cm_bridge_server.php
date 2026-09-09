<?php
declare(strict_types=1);

/**
 * CM PRO/Plus/Free - CM Bridge Protocol (server side)
 * File: common/lib/cm_bridge_server.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Local, lateral HTTP API that lets satellite modules (Guest Chat,
 * Guestbook, Financing, ...) read reservation/settings data through an
 * authenticated endpoint instead of scanning CM's JSON files directly off
 * disk. Strictly additive: existing *_bridge.php filesystem readers in
 * each module keep working unchanged - this is an optional capability a
 * module can point at instead, including at a DIFFERENT CM installation
 * on the LAN (not just the one it's deployed next to).
 *
 * Not to be confused with cm_connector.php ("CM Connector") - that is the
 * unrelated, still-unbuilt bridge from a local CM install OUT to a future
 * cloud "CM Relay" service (OTA connectivity, Community, AI Discovery).
 * This file is the local, module-to-module concept living inside the
 * "LOCAL CM" box of the CM Ecosystem Whitepaper's diagram.
 *
 * v1 was originally strictly read-only. As of the dashboard and action
 * scopes (see cm_bridge_dashboard.php, built for CM Companion) there is
 * exactly one mutation path: action.inquiry_respond, which never writes
 * directly - it proxies to the existing admin/api/accept_inquiry.php /
 * reject_inquiry.php endpoints, the same ones the admin UI itself calls.
 */

function cm_bridge_app_root(): string
{
    return dirname(__DIR__, 2);
}

if (!function_exists('read_json')) {
    require_once cm_bridge_app_root() . '/admin/api/_lib/json_io.php';
}

function cm_bridge_keys_path(): string
{
    return cm_bridge_app_root() . '/common/data/json/integrations/bridge_keys.json';
}

/**
 * @return array<string, array{key:string,label:string,scopes:string[],created:string,enabled:bool}>
 */
function cm_bridge_load_keys(): array
{
    $data = read_json(cm_bridge_keys_path());
    $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
    return $keys;
}

/**
 * Times-safe lookup across every registered key (not just the first
 * candidate) so comparison cost doesn't leak which module a presented key
 * belongs to. With well under a dozen modules this is cheap either way.
 *
 * @return array{module:string,label:string,scopes:string[]}|null
 */
function cm_bridge_authenticate(string $presentedKey): ?array
{
    if ($presentedKey === '') return null;

    $matched = null;
    foreach (cm_bridge_load_keys() as $module => $rec) {
        if (empty($rec['enabled'])) continue;
        $storedKey = (string)($rec['key'] ?? '');
        if ($storedKey === '') continue;
        if (hash_equals($storedKey, $presentedKey)) {
            $matched = [
                'module' => (string)$module,
                'label' => (string)($rec['label'] ?? $module),
                'scopes' => is_array($rec['scopes'] ?? null) ? array_values($rec['scopes']) : [],
            ];
        }
    }
    if ($matched !== null) return $matched;

    // Paired devices (CM Companion and any future third-party client) live
    // in a separate store from admin-curated module keys - see
    // cm_bridge_pairing.php. Checked second, not merged into
    // cm_bridge_load_keys(), because devices are dynamically minted at
    // pairing time and individually revocable, unlike module keys.
    if (!function_exists('cm_bridge_device_authenticate')) {
        require_once __DIR__ . '/cm_bridge_pairing.php';
    }
    return cm_bridge_device_authenticate($presentedKey);
}

/** Reads the presented key from the X-Bridge-Key header, falling back to ?key= for curl/cron parity. */
/**
 * Header only, deliberately - a key in a query string ends up in Apache's
 * access log, shell history and any proxy in the path. No query-param
 * fallback, not even for local dev, so nobody is tempted to ship a curl
 * one-liner that leaks it.
 */
function cm_bridge_presented_key(): string
{
    foreach (['HTTP_X_BRIDGE_KEY', 'HTTP_X_BRIDGE_TOKEN'] as $h) {
        if (!empty($_SERVER[$h])) return trim((string)$_SERVER[$h]);
    }
    return '';
}

/**
 * Call at the top of every admin/api/bridge/v1/*.php endpoint. Exits with
 * 401 JSON on failure; returns the matched key record on success.
 *
 * @return array{module:string,label:string,scopes:string[]}
 */
function cm_bridge_require_auth(): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $auth = cm_bridge_authenticate(cm_bridge_presented_key());
    if ($auth === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $auth;
}

/**
 * Named field-groups a key's scopes unlock - see docs for the full catalog.
 * 'reservation.financial' is intentionally NOT a list of raw top-level
 * fields - payment/calc are built into a curated payment_summary by
 * cm_bridge_project_reservation() instead (see below), and cancel_token/
 * secure_token/pdf_token are credentials, not financial data, and are never
 * returned by any v1 scope, full stop.
 */
function cm_bridge_scope_fields(string $scope): array
{
    return match ($scope) {
        'reservation.basic' => ['id', 'unit', 'from', 'to', 'status', 'guest', 'door_pin'],
        'reservation.access' => ['id', 'unit', 'from', 'to'],
        'reservation.fiscal' => [],
        default => [],
    };
}

/**
 * Projects a raw reservation array down to only what the given scopes
 * unlock. Two fields are special-cased rather than copied verbatim:
 * - 'guest': only guest.name, never guest.phone/email/note.
 * - reservation.financial (handled separately from cm_bridge_scope_fields
 *   above): a curated payment_summary, never the raw payment/calc objects
 *   or any *_token field - those objects carry a lot more than a module
 *   legitimately needs (internal lifecycle event logs, etc).
 */
function cm_bridge_project_reservation(array $res, array $scopes): array
{
    $allowedFields = [];
    foreach ($scopes as $scope) {
        $allowedFields = array_merge($allowedFields, cm_bridge_scope_fields($scope));
    }
    $allowedFields = array_unique($allowedFields);

    $out = [];
    foreach ($allowedFields as $field) {
        if ($field === 'guest') {
            $out['guest'] = ['name' => (string)($res['guest']['name'] ?? '')];
            continue;
        }
        if (array_key_exists($field, $res)) {
            $out[$field] = $res[$field];
        }
    }

    if (in_array('reservation.financial', $scopes, true)) {
        $out['payment_summary'] = cm_bridge_payment_summary($res);
    }

    if (in_array('reservation.fiscal', $scopes, true)) {
        $out['fiscal_summary'] = cm_bridge_fiscal_summary($res);
    }

    return $out;
}

/**
 * Curated financial projection - status/amount/currency/breakdown only.
 * Never includes payment.events (internal lifecycle log), cancel_token,
 * secure_token or pdf_token - those are credentials/internals, not
 * something any satellite module has a legitimate reason to read.
 */
function cm_bridge_payment_summary(array $res): array
{
    $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
    $calc = is_array($res['calc'] ?? null) ? $res['calc'] : [];

    return [
        'status' => (string)($payment['status'] ?? ($res['status'] ?? '')),
        'amount_due' => $payment['amount_due'] ?? null,
        'amount_paid' => $payment['amount_paid'] ?? null,
        'currency' => (string)($payment['currency'] ?? ''),
        'paid_at' => (string)($payment['paid_at'] ?? ''),
        'breakdown' => [
            'base' => $calc['base'] ?? null,
            'discounts' => $calc['discounts'] ?? null,
            'cleaning' => $calc['cleaning'] ?? null,
            'final' => $calc['final'] ?? null,
        ],
    ];
}

/**
 * Curated fiscal projection for Davčna blagajna - the guest-count/keycard/
 * payment-method fields FURS voucher generation needs that no other scope
 * exposes. Deliberately excludes calc fields, guest_name, unit, from, to - a
 * 'blagajna' key requests reservation.basic + reservation.financial
 * alongside this scope for those, instead of duplicating fields here.
 */
function cm_bridge_fiscal_summary(array $res): array
{
    $tt = is_array($res['tt'] ?? null) ? $res['tt'] : [];
    $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];

    return [
        'adults' => (int)($res['adults'] ?? 0),
        'kids06' => (int)($res['kids06'] ?? 0),
        'kids712' => (int)($res['kids712'] ?? 0),
        'tt' => [
            'keycard_count' => $tt['keycard_count'] ?? null,
            'total' => $tt['total'] ?? null,
            'keycard_saving' => $tt['keycard_saving'] ?? null,
        ],
        'payment_method' => (string)($payment['method'] ?? $res['payment_method'] ?? ''),
    ];
}

function cm_bridge_reservations_root(): string
{
    $root = realpath(cm_bridge_app_root() . '/common/data/json/reservations');
    return $root !== false ? $root : (cm_bridge_app_root() . '/common/data/json/reservations');
}

/**
 * Finds a reservation by id, trying the year guessed from the id's leading
 * 4 digits first, then falling back to scanning every year directory - the
 * id's leading digits are the booking timestamp, not the stay year (a
 * reservation booked in 2026 for a 2027 stay is filed under .../2027/, not
 * .../2026/). Ported from guest_messages.php's gm_find_reservation_by_id()
 * (independently duplicated 3 other times before this file existed) so the
 * fix lives in exactly one place from now on.
 */
function cm_bridge_find_reservation_by_id(string $id): ?array
{
    $id = trim($id);
    if ($id === '') return null;

    $root = cm_bridge_reservations_root();
    if (!is_dir($root)) return null;

    $year = substr($id, 0, 4);
    $yearDir = $root . '/' . $year;
    if (is_dir($yearDir)) {
        foreach (glob($yearDir . '/*/*.json', GLOB_NOSORT) ?: [] as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false) continue;
            $res = json_decode($raw, true);
            if (!is_array($res) || (string)($res['id'] ?? '') !== $id) continue;
            return $res;
        }
    }

    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $altYearDir) {
        if ($altYearDir === $yearDir) continue;
        foreach (glob($altYearDir . '/*/*.json', GLOB_NOSORT) ?: [] as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false) continue;
            $res = json_decode($raw, true);
            if (!is_array($res) || (string)($res['id'] ?? '') !== $id) continue;
            return $res;
        }
    }

    return null;
}

/**
 * Finds a reservation by door_pin within a caller-supplied date window
 * (the server has no opinion on any one module's PIN validity policy).
 */
function cm_bridge_find_reservation_by_pin(string $pin, int $beforeDays, int $afterDays): ?array
{
    $pin = trim($pin);
    if ($pin === '' || !preg_match('/^\d{4,8}$/', $pin)) return null;

    $root = cm_bridge_reservations_root();
    if (!is_dir($root)) return null;

    $today = new DateTimeImmutable('today');
    $windowStart = $today->modify('-' . max(0, $afterDays) . ' days');
    $windowEnd = $today->modify('+' . max(0, $beforeDays) . ' days');

    $years = array_unique([
        $today->modify('-1 year')->format('Y'),
        $today->format('Y'),
        $today->modify('+1 year')->format('Y'),
    ]);

    foreach ($years as $year) {
        $yearDir = $root . '/' . $year;
        if (!is_dir($yearDir)) continue;

        foreach (glob($yearDir . '/*/*.json', GLOB_NOSORT) ?: [] as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false) continue;
            $res = json_decode($raw, true);
            if (!is_array($res)) continue;

            $resPin = (string)($res['door_pin'] ?? '');
            if ($resPin === '' || !hash_equals($resPin, $pin)) continue;

            $from = cm_bridge_parse_date((string)($res['from'] ?? ''));
            $to = cm_bridge_parse_date((string)($res['to'] ?? ''));
            if (!$from || !$to) continue;
            if ($from > $windowEnd || $to < $windowStart) continue;

            return $res;
        }
    }

    return null;
}

function cm_bridge_parse_date(string $s): ?DateTimeImmutable
{
    if ($s === '') return null;
    try {
        return new DateTimeImmutable($s);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Minimal settings projection for the settings.public scope - mirrors
 * admin/api/site_settings_get.php's exact shape/fallback-to-manifest logic
 * on purpose, so both stay in sync if that endpoint's behavior changes.
 */
function cm_bridge_public_settings(): array
{
    $out = [
        'public_base_url' => '',
        'email' => [
            'enabled' => true,
            'from_email' => '',
            'from_name' => '',
            'admin_email' => '',
        ],
    ];

    $settingsFile = cm_bridge_app_root() . '/common/data/json/units/site_settings.json';
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data)) {
            if (isset($data['public_base_url']) && is_string($data['public_base_url'])) {
                $out['public_base_url'] = $data['public_base_url'];
            }
            if (isset($data['email']) && is_array($data['email'])) {
                $email = $data['email'];
                if (array_key_exists('enabled', $email)) $out['email']['enabled'] = (bool)$email['enabled'];
                foreach (['from_email', 'from_name', 'admin_email'] as $f) {
                    if (isset($email[$f]) && is_string($email[$f])) $out['email'][$f] = $email[$f];
                }
            }
        }
    }

    if ($out['public_base_url'] === '') {
        $manifestFile = cm_bridge_app_root() . '/common/data/json/units/manifest.json';
        if (is_file($manifestFile)) {
            $raw = @file_get_contents($manifestFile);
            $m = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($m)) {
                $meta = is_array($m['meta'] ?? null) ? $m['meta'] : null;
                foreach ([$m['base_url'] ?? null, $meta['base_url'] ?? null, $m['domain'] ?? null, $meta['domain'] ?? null] as $cand) {
                    if (is_string($cand) && trim($cand) !== '') {
                        $out['public_base_url'] = trim($cand);
                        break;
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Where a pairing device should actually reach this install's Bridge v1
 * API - NOT necessarily the same as cm_bridge_public_settings()'s
 * public_base_url (that's the guest-facing site URL, used by
 * settings.public and elsewhere; deliberately left untouched by this
 * function so its existing meaning for other callers doesn't shift).
 *
 * Real motivating case (2026-09-17, T630 shadow server): a parallel/
 * shadow install can mirror its site_settings.json from a different,
 * unrelated production domain (rrsync of real data), making
 * public_base_url actively wrong for THIS install's own Bridge endpoint,
 * not just empty. Same problem class applies to DDNS-hosted installs
 * where the public hostname and the machine's actually-reachable address
 * can diverge or change - an explicit override is more robust than
 * inferring one from data that serves a different purpose.
 *
 * Override file lives in common/data/json/integrations/ - deliberately
 * chosen because module-specific integration config in that directory is
 * already the convention for install-local settings that must never be
 * silently overwritten by a data mirror/sync process (see
 * bridge_keys.json, bridge_devices.json in the same directory).
 */
function cm_bridge_settings_path(): string
{
    return cm_bridge_app_root() . '/common/data/json/integrations/bridge_settings.json';
}

function cm_bridge_effective_base_url(): string
{
    $file = cm_bridge_settings_path();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['bridge_base_url_override']) && is_string($data['bridge_base_url_override'])) {
            $override = trim($data['bridge_base_url_override']);
            if ($override !== '') return $override;
        }
    }
    return cm_bridge_public_settings()['public_base_url'] ?? '';
}
