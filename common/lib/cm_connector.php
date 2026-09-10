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
    $settings['last_heartbeat_at'] = null;

    return cm_connector_save_settings($settings);
}

// ---------------------------------------------------------------------
// Faza B stubs - no live Relay exists yet, so these must not attempt any
// network I/O. They exist now only so callers/UI can be wired against a
// stable function signature ahead of the real implementation.
// ---------------------------------------------------------------------

function cm_connector_activate_with_relay(): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
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

function cm_connector_send_command(array $command): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}

function cm_connector_full_sync(): array
{
    return ['ok' => false, 'error' => 'not_implemented_faza_b'];
}
