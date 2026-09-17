<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/cm_connector.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * CM Connector - opcijski, privzeto izklopljen most med to lokalno CM
 * namestitvijo in centralnim CM Relay servisom (glej
 * docs/cm_relay/relay_api_contract.md). CM Free/Plus/PRO mora brez
 * Connectorja in brez Relayja delovati popolnoma samostojno - noben klic
 * v tej datoteki sme narediti odhodno omrežno povezavo dokler uporabnik
 * ni izrecno potrdil soglasij (cm_connector_save_consent).
 *
 * Faza A (trenutno stanje): samo lokalna identiteta + soglasja + poslovni
 * dogovor + neodvisni service opt-in (Connectivity/Community/Sharing/AI, glej
 * CM_Ecosystem_Community_AI_ICS_Addendum_2026-08-10.md §1.1/§7) + status.
 * Funkcije za dejansko komunikacijo z Relayjem so spodaj označene kot
 * "Faza B" in vrnejo not_implemented, dokler Relay servis dejansko ne
 * obstaja.
 */

declare(strict_types=1);

/**
 * Resolves the app root independently of any caller-defined APP_ROOT/
 * APP_COMMON constants, since this module is loaded from contexts that
 * don't always define them (e.g. admin/integrations.php). This file
 * lives at <app>/common/lib/cm_connector.php.
 */
function cm_connector_app_root(): string
{
    return dirname(__DIR__, 2);
}

if (!function_exists('read_json')) {
    require_once cm_connector_app_root() . '/admin/api/_lib/json_io.php';
}

function cm_connector_settings_path(): string
{
    return cm_connector_app_root() . '/common/data/json/integrations/cm_connector.json';
}

function cm_connector_default_settings(): array
{
    return [
        'enabled' => false,
        'installation_uuid' => null,
        'activation_status' => 'not_connected', // not_connected | pending_local | connected
        'consent' => null,
        'agreement' => null,
        // Independent per-service opt-in switches (CM Ecosystem addendum §1.1/§7).
        // Each is a separate decision - Connectivity ON does not imply Community ON,
        // and vice versa. relay_registry is implied by activation_status and not
        // listed here as a switch.
        'services' => [
            'ota_connectivity' => false,
            'community' => false,
            'community_referrals' => false,
            'ai_discovery' => false,
        ],
        'relay_base_url' => null,
        'relay_installation_id' => null,
        'relay_token' => null,
        'last_heartbeat_at' => null,
    ];
}

function cm_connector_get_settings(): array
{
    $data = read_json(cm_connector_settings_path());
    if (!is_array($data)) {
        return cm_connector_default_settings();
    }
    return array_merge(cm_connector_default_settings(), $data);
}

function cm_connector_save_settings(array $settings): bool
{
    return write_json(cm_connector_settings_path(), $settings);
}

/**
 * Generates and persists a local installation UUID on first call.
 * Purely local - the Relay does not learn this value until the user
 * explicitly activates the Connector (see docs §4 Korak 3).
 */
function cm_connector_ensure_installation_uuid(): string
{
    $settings = cm_connector_get_settings();
    if (!empty($settings['installation_uuid'])) {
        return (string)$settings['installation_uuid'];
    }

    $uuid = cm_connector_generate_uuid_v4();
    $settings['installation_uuid'] = $uuid;
    cm_connector_save_settings($settings);

    return $uuid;
}

function cm_connector_generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * @return array{enabled: bool, activation_status: string, installation_uuid: ?string, edition: string, version: string}
 */
function cm_connector_status(): array
{
    $settings = cm_connector_get_settings();

    $edition = function_exists('cm_get_product_tier') ? cm_get_product_tier() : 'free';
    $version = function_exists('cm_get_product_version') ? cm_get_product_version() : '1.0.0';

    return [
        'enabled' => (bool)$settings['enabled'],
        'activation_status' => (string)$settings['activation_status'],
        'installation_uuid' => $settings['installation_uuid'],
        'edition' => $edition,
        'version' => $version,
        'consent' => $settings['consent'],
        'agreement' => $settings['agreement'],
        'services' => $settings['services'],
    ];
}

/**
 * Persists the 3 separate consent flags from doc §4 Korak 2. These are
 * kept as distinct booleans on purpose - do not collapse them into a
 * single "agree to everything" flag.
 *
 * @param array{data_scope: bool, ecosystem_inclusion: bool, terms_price: bool} $consent
 */
function cm_connector_save_consent(array $consent): bool
{
    $required = ['data_scope', 'ecosystem_inclusion', 'terms_price'];
    foreach ($required as $key) {
        if (empty($consent[$key])) {
            return false;
        }
    }

    cm_connector_ensure_installation_uuid();

    $settings = cm_connector_get_settings();
    $settings['consent'] = [
        'data_scope' => true,
        'ecosystem_inclusion' => true,
        'terms_price' => true,
        'accepted_at' => gmdate('c'),
    ];
    $settings['activation_status'] = 'pending_local';

    return cm_connector_save_settings($settings);
}

/**
 * Persists the manually-entered business agreement (doc §1.4: Relay
 * connectivity may be a paid service, negotiated per installation before
 * any real Relay account exists). This is a plain data template for now -
 * filled in by hand once a commercial arrangement with the CM Relay
 * operator is in place, not derived from any live billing system.
 *
 * @param array{plan?: string, price_eur_month?: string, contract_ref?: string, relay_account_email?: string, notes?: string} $agreement
 */
function cm_connector_save_agreement(array $agreement): bool
{
    $settings = cm_connector_get_settings();
    $settings['agreement'] = [
        'plan' => trim((string)($agreement['plan'] ?? '')),
        'price_eur_month' => trim((string)($agreement['price_eur_month'] ?? '')),
        'contract_ref' => trim((string)($agreement['contract_ref'] ?? '')),
        'relay_account_email' => trim((string)($agreement['relay_account_email'] ?? '')),
        'notes' => trim((string)($agreement['notes'] ?? '')),
        'saved_at' => gmdate('c'),
    ];

    return cm_connector_save_settings($settings);
}

/**
 * Persists the 4 independent ecosystem service opt-in switches (CM Ecosystem
 * addendum §1.1: "Te možnosti morajo biti ločene" - these must stay
 * separate, never merged into one master toggle). Any subset may be true;
 * unknown keys are ignored rather than rejected, so this stays additive if
 * more services are introduced later.
 *
 * @param array{ota_connectivity?: bool, community?: bool, community_referrals?: bool, ai_discovery?: bool} $services
 */
function cm_connector_save_services(array $services): bool
{
    $settings = cm_connector_get_settings();
    $settings['services'] = [
        'ota_connectivity' => !empty($services['ota_connectivity']),
        'community' => !empty($services['community']),
        'community_referrals' => !empty($services['community_referrals']),
        'ai_discovery' => !empty($services['ai_discovery']),
    ];

    return cm_connector_save_settings($settings);
}

/**
 * Reverts to not_connected. installation_uuid is intentionally kept so a
 * later re-activation does not need a new identity.
 */
function cm_connector_disconnect(): bool
{
    $settings = cm_connector_get_settings();
    $settings['enabled'] = false;
    $settings['activation_status'] = 'not_connected';
    $settings['consent'] = null;
    $settings['agreement'] = null;
    $settings['services'] = cm_connector_default_settings()['services'];
    $settings['relay_base_url'] = null;
    $settings['relay_installation_id'] = null;
    $settings['relay_token'] = null;
    $settings['last_heartbeat_at'] = null;

    return cm_connector_save_settings($settings);
}

// ---------------------------------------------------------------------
// Faza B stubs - no live Relay exists yet, so these must not attempt any
// network I/O. They exist now only so callers/UI can be wired against a
// stable function signature ahead of the real implementation.
// ---------------------------------------------------------------------

/**
 * Minimal JSON-over-HTTP client. Deliberately lives here, not in a shared
 * general-purpose helper - this is the ONLY place in local CM that is
 * allowed to make an outbound call to the Relay, and keeping it self-
 * contained makes that boundary easy to audit.
 */
function cm_connector_relay_http(string $method, string $url, array $body = [], ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => "curl: $curlError"];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => "non_json_response http=$httpCode"];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => $decoded['error'] ?? "http_$httpCode"];
    }

    return $decoded;
}

/**
 * Registers this installation with the Relay named in relay_base_url
 * (must be set first, e.g. via the connector admin page). One-time per
 * installation - a repeat call is safe (Relay's register endpoint is
 * idempotent on installation_uuid) but will NOT reissue a token, since
 * this installation already has one after the first successful call.
 */
function cm_connector_activate_with_relay(): array
{
    $settings = cm_connector_get_settings();

    if (empty($settings['relay_base_url'])) {
        return ['ok' => false, 'error' => 'relay_base_url_not_set'];
    }

    $uuid = cm_connector_ensure_installation_uuid();
    $edition = function_exists('cm_get_product_tier') ? cm_get_product_tier() : 'free';
    $version = function_exists('cm_get_product_version') ? cm_get_product_version() : '1.0.0';

    $result = cm_connector_relay_http('POST', rtrim($settings['relay_base_url'], '/') . '/api/v1/installations/register.php', [
        'installation_uuid' => $uuid,
        'edition' => $edition,
        'cm_version' => $version,
        'connector_version' => '1.0.0',
        'unit_count' => null, // not tracked centrally yet
        'locale' => 'sl',
    ]);

    if (empty($result['ok'])) {
        return $result;
    }

    // Re-read: cm_connector_ensure_installation_uuid() above wrote a fresh
    // installation_uuid to disk after $settings was first loaded - using the
    // stale in-memory copy here would silently overwrite that uuid back to
    // null on the save below.
    $settings = cm_connector_get_settings();
    $settings['relay_installation_id'] = $result['installation_id'] ?? null;
    if (!empty($result['token'])) {
        // Only present on first-ever registration - a repeat call omits it,
        // so don't overwrite an already-stored token with null.
        $settings['relay_token'] = $result['token'];
    }
    $settings['activation_status'] = 'connected';
    cm_connector_save_settings($settings);

    return ['ok' => true, 'installation_id' => $settings['relay_installation_id']];
}

function cm_connector_heartbeat(): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}

function cm_connector_pull_events(): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}

function cm_connector_ack_event(string $relayEventId): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}

/**
 * Queues a command locally (common/lib/cm_connector_outbox.php). Does NOT
 * perform any network I/O - no live Relay to send it to yet. This is the
 * one function real save-handlers (price, availability, reservations)
 * should call; it silently no-ops if Connectivity isn't opted in, so
 * callers never need their own enabled-check.
 */
function cm_connector_send_command(array $command): array
{
    if (!function_exists('cm_connector_outbox_append')) {
        require_once __DIR__ . '/cm_connector_outbox.php';
    }

    $settings = cm_connector_get_settings();
    if (!$settings['enabled'] || empty($settings['services']['ota_connectivity'])) {
        return ['ok' => true, 'skipped' => 'ota_connectivity_not_enabled'];
    }

    $command['installation_id'] = $settings['installation_uuid'];
    $queued = cm_connector_outbox_append($command);

    return $queued ? ['ok' => true] : ['ok' => false, 'error' => 'outbox_write_failed'];
}

/**
 * Convenience wrapper for the common case: "something about this unit
 * changed, wake up and go check what's new" (user's own framing, 2026-09-17).
 * The trigger itself stays deliberately dumb about the exact delta - but
 * Relay can never reach back into a self-hosted, often-NAT'd CM install to
 * fetch current state on its own, so a FRESH read of the relevant data must
 * travel with the nudge. Two ways to supply it:
 *   - caller already has the changed values cheaply in scope (e.g. a price
 *     write handler that just wrote $from/$to/$price) -> pass $snapshot;
 *   - caller doesn't (e.g. the shared cm_regen_merged_for_unit() choke
 *     point) -> omitted, auto-gathered fresh from disk right now via
 *     cm_connector_prepare_unit_snapshot().
 * Either way this is a snapshot taken AT NOTIFY TIME, never a trusted delta
 * from further upstream.
 */
function cm_connector_notify_unit_changed(string $unit, string $reason, array $snapshot = []): array
{
    if (empty($snapshot)) {
        $snapshot = cm_connector_prepare_unit_snapshot($unit, $reason);
    }

    return cm_connector_send_command([
        'type' => 'unitStateChanged',
        'unit' => $unit,
        'reason' => $reason, // e.g. 'price', 'availability', 'min_stay'
        'idempotency_key' => $unit . ':' . $reason . ':' . date('Y-m-d-H-i'),
        'payload' => $snapshot,
    ]);
}

/**
 * Reads current unit state fresh from disk for the given reason - the
 * "priprava" step that follows the "dregljaj" (nudge). Only called when a
 * caller doesn't already have cheaper, more targeted data in scope.
 */
function cm_connector_prepare_unit_snapshot(string $unit, string $reason): array
{
    $unitDir = cm_connector_app_root() . '/common/data/json/units/' . $unit;

    if ($reason === 'price') {
        $prices = read_json($unitDir . '/prices.json');
        return ['prices' => is_array($prices) ? $prices : []];
    }

    // availability, min_stay, and reservation-driven reasons all funnel
    // through cm_regen_merged_for_unit(), so the same current-state pair
    // (merged occupancy + min_nights) covers all of them.
    $merged = read_json($unitDir . '/occupancy_merged.json');
    $settings = read_json($unitDir . '/site_settings.json');

    return [
        'occupancy_merged' => is_array($merged) ? $merged : [],
        'min_nights' => (int)($settings['booking']['min_nights'] ?? 1),
    ];
}

/**
 * Drains the local outbox (common/lib/cm_connector_outbox.php) by POSTing
 * each pending command to the Relay's /api/v1/commands endpoint. Meant to
 * be called periodically (cron - see admin/api/cron_drain_connector_outbox.php),
 * not from the save-handler request path itself, so a slow/unreachable
 * Relay never adds latency to an admin saving a price.
 *
 * Every request carries this installation's own token (installation-level
 * traceability - Relay's auth layer resolves WHICH installation a command
 * came from purely from the token, never from a client-supplied id) and
 * every command already carries its own unit + idempotency_key (unit-level
 * traceability), so a command is fully attributable end to end without
 * needing anything extra bolted on here.
 */
function cm_connector_outbox_drain(int $limit = 50): array
{
    $settings = cm_connector_get_settings();

    if ($settings['activation_status'] !== 'connected' || empty($settings['relay_token']) || empty($settings['relay_base_url'])) {
        return ['ok' => false, 'error' => 'not_connected_to_relay'];
    }

    if (!function_exists('cm_connector_outbox_pending')) {
        require_once __DIR__ . '/cm_connector_outbox.php';
    }

    $pending = array_slice(cm_connector_outbox_pending(), 0, $limit);
    $sent = 0;
    $failed = 0;

    foreach ($pending as $command) {
        $result = cm_connector_relay_http(
            'POST',
            rtrim($settings['relay_base_url'], '/') . '/api/v1/commands.php',
            [
                'type' => $command['type'],
                'unit' => $command['unit'],
                'reason' => $command['reason'] ?? '',
                'idempotency_key' => $command['idempotency_key'] ?? '',
                'payload' => $command['payload'] ?? [],
            ],
            $settings['relay_token']
        );

        if (!empty($result['ok'])) {
            cm_connector_outbox_mark_sent($command['id']);
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'remaining' => count(cm_connector_outbox_pending())];
}

function cm_connector_full_sync(): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}
