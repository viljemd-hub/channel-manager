<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1, device pairing
 * File: common/lib/cm_bridge_pairing.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Implements CM_Mobile_Companion_Plan_v0.1.md §4 option B: a short-lived
 * one-time pairing code, generated from the CM admin panel, exchanged
 * once by a client (CM Companion or any third-party Bridge integrator)
 * for a permanent per-device token. Same installation_uuid CM Connector
 * already uses for Relay/Community (cm_connector_ensure_installation_uuid)
 * - one installation identity across the whole ecosystem, not a second one
 * invented here.
 *
 * Two JSON files, both runtime state (gitignored, unlike the
 * admin-curated bridge_keys.json):
 * - bridge_pairing_codes.json: short-TTL, one-shot codes awaiting exchange.
 * - bridge_devices.json: paired devices - one row per (device, installation)
 *   token, individually revocable from "CM admin > Connected devices".
 */

require_once __DIR__ . '/cm_bridge_server.php';
require_once __DIR__ . '/cm_connector.php';
require_once __DIR__ . '/sepa_qr.php';

function cm_bridge_pairing_codes_path(): string
{
    return cm_bridge_app_root() . '/common/data/json/integrations/bridge_pairing_codes.json';
}

function cm_bridge_devices_path(): string
{
    return cm_bridge_app_root() . '/common/data/json/integrations/bridge_devices.json';
}

/** Dashboard scopes granted to a Companion device on pairing - see plan v0.1 §5. */
function cm_bridge_default_device_scopes(): array
{
    return ['dashboard.today', 'dashboard.alerts', 'dashboard.inquiries', 'action.inquiry_respond'];
}

/**
 * Real active-connection count, not the cumulative "every successful
 * pairing ever" counter (companion_connections_total in page_counters.json,
 * cmfree-demo-specific, added 2026-09-09). That counter only ever
 * increments - a real user question ("11 successful connections?" against
 * only ever pairing ~9 real devices at once) surfaced the gap: it can't
 * tell "currently paired" from "paired, then forgotten, then re-paired
 * during testing". This reads bridge_devices.json directly instead -
 * `enabled` flips false on self-service unpair (pairing/unpair.php) or
 * admin revoke, so counting enabled===true rows is the actual live state.
 *
 * Deliberately just two numbers, no per-device detail (labels, tokens,
 * last_seen) - this is meant to be safe to expose without auth (same
 * shape a future cross-installation Community monitor would aggregate
 * from many installations, see CM_Connectivity_Community_Contract_v0.2
 * §7), and per-device detail belongs in the admin-only "Connected
 * devices" list, not a public count endpoint.
 */
function cm_bridge_device_stats(): array
{
    $devices = cm_bridge_pairing_read(cm_bridge_devices_path());
    $active = 0;
    foreach ($devices as $d) {
        if (!empty($d['enabled'])) {
            $active++;
        }
    }
    return ['total_ever' => count($devices), 'active' => $active];
}

function cm_bridge_pairing_read(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function cm_bridge_pairing_write(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) return false;
    return @rename($tmp, $path);
}

/**
 * Called from the admin panel ("Generate pairing code" button). 10-minute
 * TTL, single use. Human-readable code (groups of 4, like the reference
 * app description) so it can be typed by hand, not just scanned.
 */
function cm_bridge_pairing_generate(string $deviceLabelHint = ''): array
{
    $installationId = cm_connector_ensure_installation_uuid();
    $code = strtoupper(bin2hex(random_bytes(4)));
    $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);

    $codes = cm_bridge_pairing_read(cm_bridge_pairing_codes_path());
    $codes = array_values(array_filter($codes, static fn (array $c): bool => (
        (string)($c['expires_at'] ?? '') > gmdate('c')
    )));

    $entry = [
        'code' => $code,
        'installation_id' => $installationId,
        'device_label_hint' => $deviceLabelHint,
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + 600),
        'used' => false,
    ];
    $codes[] = $entry;
    cm_bridge_pairing_write(cm_bridge_pairing_codes_path(), $codes);

    $bridgeBaseUrl = rtrim(cm_bridge_effective_base_url(), '/') . '/admin/api/bridge/v1';
    $deepLink = 'cmcompanion://pair?' . http_build_query([
        'url' => $bridgeBaseUrl,
        'installation_id' => $installationId,
        'code' => $code,
    ]);

    return [
        'code' => $code,
        'installation_id' => $installationId,
        'expires_at' => $entry['expires_at'],
        'bridge_base_url' => $bridgeBaseUrl,
        'deep_link' => $deepLink,
        // PNG, not SVG (2026-09-10 real bug) - the SVG variant's
        // per-module <rect> seams broke up the finder patterns into an
        // unscannable dot pattern once displayed at a non-integer
        // pixel-per-module ratio (the normal case). See sepa_qr.php's
        // cm_build_epc_qr_png_data_uri() doc comment. Field name kept
        // as qr_svg_data_uri for backward compatibility - it's a
        // generic data URI either way, callers shouldn't assume MIME.
        'qr_svg_data_uri' => cm_build_epc_qr_png_data_uri($deepLink),
    ];
}

/**
 * Exchanges a one-time code for a permanent device token. Called from the
 * public (unauthenticated by design - the code itself is the credential)
 * admin/api/bridge/v1/pairing/exchange.php endpoint.
 *
 * @return array{ok:bool,error?:string,device_token?:string,scopes?:string[]}
 */
function cm_bridge_pairing_exchange(string $installationId, string $code, string $deviceLabel): array
{
    $codesPath = cm_bridge_pairing_codes_path();
    $codes = cm_bridge_pairing_read($codesPath);

    $matchIndex = null;
    foreach ($codes as $i => $entry) {
        if (!is_array($entry)) continue;
        if (($entry['code'] ?? '') !== $code) continue;
        $matchIndex = $i;
        break;
    }

    if ($matchIndex === null) {
        return ['ok' => false, 'error' => 'invalid_code'];
    }

    $entry = $codes[$matchIndex];
    if (!empty($entry['used'])) {
        return ['ok' => false, 'error' => 'code_already_used'];
    }
    if ((string)($entry['expires_at'] ?? '') <= gmdate('c')) {
        return ['ok' => false, 'error' => 'code_expired'];
    }
    if (!hash_equals((string)$entry['installation_id'], $installationId)) {
        return ['ok' => false, 'error' => 'installation_mismatch'];
    }

    // Mark used (one-shot) - even though it also expires, an explicit flag
    // prevents any race where two exchanges land inside the TTL window.
    $codes[$matchIndex]['used'] = true;
    cm_bridge_pairing_write($codesPath, $codes);

    $deviceToken = bin2hex(random_bytes(32));
    $devices = cm_bridge_pairing_read(cm_bridge_devices_path());
    $device = [
        'id' => bin2hex(random_bytes(8)),
        'installation_id' => $installationId,
        'device_token' => $deviceToken,
        'device_label' => $deviceLabel !== '' ? $deviceLabel : ($entry['device_label_hint'] ?? 'Unnamed device'),
        'scopes' => cm_bridge_default_device_scopes(),
        'created_at' => gmdate('c'),
        'last_seen_at' => null,
        'enabled' => true,
    ];
    $devices[] = $device;
    cm_bridge_pairing_write(cm_bridge_devices_path(), $devices);

    return ['ok' => true, 'device_token' => $deviceToken, 'scopes' => $device['scopes']];
}

/** For the future "CM admin > Connected devices" screen (revoke UI, plan v0.1 §4). */
function cm_bridge_devices_list(): array
{
    return cm_bridge_pairing_read(cm_bridge_devices_path());
}

function cm_bridge_devices_revoke(string $deviceId): bool
{
    $devices = cm_bridge_pairing_read(cm_bridge_devices_path());
    $found = false;
    foreach ($devices as $i => $device) {
        if (($device['id'] ?? '') === $deviceId) {
            $devices[$i]['enabled'] = false;
            $found = true;
        }
    }
    if (!$found) return false;
    return cm_bridge_pairing_write(cm_bridge_devices_path(), $devices);
}

/**
 * Self-service "forget this device" (2026-09-17, Connection screen) -
 * revokes by presented token, not by id. Deliberately narrower than
 * cm_bridge_devices_revoke(): a device can only ask to revoke itself
 * (proven by holding a currently-valid token), never an arbitrary id -
 * that would let one paired device revoke a different one it has no
 * business touching. Admin-initiated revoke-by-id (e.g. a future
 * "Connected devices" admin screen) stays a separate, admin_key-gated path.
 */
function cm_bridge_devices_revoke_by_token(string $presentedToken): bool
{
    if ($presentedToken === '') return false;
    $devices = cm_bridge_pairing_read(cm_bridge_devices_path());
    $found = false;
    foreach ($devices as $i => $device) {
        $stored = (string)($device['device_token'] ?? '');
        if ($stored !== '' && hash_equals($stored, $presentedToken)) {
            $devices[$i]['enabled'] = false;
            $found = true;
        }
    }
    if (!$found) return false;
    return cm_bridge_pairing_write(cm_bridge_devices_path(), $devices);
}

/** Matches a presented device token, mirrors cm_bridge_authenticate() for module keys. */
function cm_bridge_device_authenticate(string $presentedKey): ?array
{
    if ($presentedKey === '') return null;
    $devices = cm_bridge_pairing_read(cm_bridge_devices_path());
    foreach ($devices as $i => $device) {
        if (empty($device['enabled'])) continue;
        $stored = (string)($device['device_token'] ?? '');
        if ($stored === '') continue;
        if (hash_equals($stored, $presentedKey)) {
            $devices[$i]['last_seen_at'] = gmdate('c');
            cm_bridge_pairing_write(cm_bridge_devices_path(), $devices);
            return [
                'module' => 'device:' . (string)($device['device_label'] ?? $device['id']),
                'label' => (string)($device['device_label'] ?? 'Companion device'),
                'scopes' => is_array($device['scopes'] ?? null) ? array_values($device['scopes']) : [],
            ];
        }
    }
    return null;
}
