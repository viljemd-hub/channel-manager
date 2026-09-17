<?php
/**
 * CM Free / CM Plus / CM PRO – Channel Manager
 * File: common/lib/cm_connector_outbox.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Local outbound half of the CM Relay command queue (mirrors the inbound
 * cm_connector_events.php). Faza A/B: pure local CRUD over a JSON file, no
 * polling, no network I/O - a future Relay-drain worker will read pending
 * commands and call cm_connector_outbox_mark_sent() once delivered. Never
 * delete records here; a lost local command silently breaks OTA sync with
 * no error surfaced anywhere.
 */

declare(strict_types=1);

if (!function_exists('cm_connector_app_root')) {
    require_once __DIR__ . '/cm_connector.php';
}

if (!function_exists('read_json')) {
    require_once cm_connector_app_root() . '/admin/api/_lib/json_io.php';
}

function cm_connector_outbox_path(): string
{
    return cm_connector_app_root() . '/common/data/json/integrations/cm_connector_outbox.json';
}

/**
 * @return array<int, array<string, mixed>>
 */
function cm_connector_outbox_list(): array
{
    $data = read_json(cm_connector_outbox_path());
    return is_array($data) ? $data : [];
}

function cm_connector_outbox_save_all(array $commands): bool
{
    return write_json(cm_connector_outbox_path(), $commands);
}

/**
 * Appends a locally-queued outbound command. Idempotent on idempotency_key:
 * if a command with the same key already exists and is still pending, it is
 * left untouched instead of being duplicated (a save-handler that fires
 * twice in quick succession - e.g. a double-submit - must not queue two
 * Channex pushes for the same change).
 *
 * Expected shape (see docs/relay_api_contract.md "commands"):
 * id, installation_id, type (pushAvailability|pushRates|pushRestrictions|
 * requestFullSync), unit, idempotency_key, payload, status, created_at,
 * completed_at
 */
function cm_connector_outbox_append(array $command): bool
{
    $commands = cm_connector_outbox_list();

    $idempotencyKey = (string)($command['idempotency_key'] ?? '');
    if ($idempotencyKey !== '') {
        foreach ($commands as $existing) {
            if (($existing['idempotency_key'] ?? null) === $idempotencyKey
                && ($existing['status'] ?? null) === 'pending') {
                return true; // already queued, not an error
            }
        }
    }

    $command += [
        'id' => cm_connector_generate_uuid_v4(),
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'completed_at' => null,
    ];

    $commands[] = $command;

    return cm_connector_outbox_save_all($commands);
}

function cm_connector_outbox_mark_sent(string $commandId): bool
{
    $commands = cm_connector_outbox_list();
    $found = false;

    foreach ($commands as &$command) {
        if (($command['id'] ?? null) === $commandId) {
            $command['status'] = 'sent';
            $command['completed_at'] = gmdate('c');
            $found = true;
            break;
        }
    }
    unset($command);

    if (!$found) {
        return false;
    }

    return cm_connector_outbox_save_all($commands);
}

/**
 * @return array<int, array<string, mixed>>
 */
function cm_connector_outbox_pending(): array
{
    return array_values(array_filter(
        cm_connector_outbox_list(),
        static fn(array $c): bool => ($c['status'] ?? null) === 'pending'
    ));
}
