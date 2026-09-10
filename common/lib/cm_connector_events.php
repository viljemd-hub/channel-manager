<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/cm_connector_events.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Local skeleton of the CM Relay event queue (see docs/cm_relay/relay_api_contract.md
 * §Events). Faza A: pure local CRUD over a JSON file, no polling, no
 * network I/O. A future Faza B poller will call cm_connector_events_append()
 * when it pulls events from a live Relay, and the CM Connector delivery
 * logic will call cm_connector_events_ack() once a local handler has
 * processed an event - never delete records on retry, to avoid creating
 * duplicate reservations from repeated webhooks upstream.
 */

declare(strict_types=1);

if (!function_exists('cm_connector_app_root')) {
    require_once __DIR__ . '/cm_connector.php';
}

if (!function_exists('read_json')) {
    require_once cm_connector_app_root() . '/admin/api/_lib/json_io.php';
}

function cm_connector_events_path(): string
{
    return cm_connector_app_root() . '/common/data/json/integrations/cm_connector_events.json';
}

/**
 * @return array<int, array<string, mixed>>
 */
function cm_connector_events_list(): array
{
    $data = read_json(cm_connector_events_path());
    return is_array($data) ? $data : [];
}

function cm_connector_events_save_all(array $events): bool
{
    return write_json(cm_connector_events_path(), $events);
}

/**
 * Appends a locally-known event. Idempotent on idempotency_key: if an
 * event with the same key already exists, it is left untouched instead
 * of being duplicated.
 *
 * Expected shape (see doc §9):
 * relay_event_id, provider_event_id, installation_id, idempotency_key,
 * type, channel, occurred_at, payload, status, delivery_attempts,
 * next_retry_at, acknowledged_at, created_at
 */
function cm_connector_events_append(array $event): bool
{
    $events = cm_connector_events_list();

    $idempotencyKey = (string)($event['idempotency_key'] ?? '');
    if ($idempotencyKey !== '') {
        foreach ($events as $existing) {
            if (($existing['idempotency_key'] ?? null) === $idempotencyKey) {
                return true; // already recorded, not an error
            }
        }
    }

    $event += [
        'status' => 'received',
        'delivery_attempts' => 0,
        'next_retry_at' => null,
        'acknowledged_at' => null,
        'created_at' => gmdate('c'),
    ];

    $events[] = $event;

    return cm_connector_events_save_all($events);
}

function cm_connector_events_ack(string $relayEventId): bool
{
    $events = cm_connector_events_list();
    $found = false;

    foreach ($events as &$event) {
        if (($event['relay_event_id'] ?? null) === $relayEventId) {
            $event['status'] = 'acknowledged';
            $event['acknowledged_at'] = gmdate('c');
            $found = true;
            break;
        }
    }
    unset($event);

    if (!$found) {
        return false;
    }

    return cm_connector_events_save_all($events);
}
