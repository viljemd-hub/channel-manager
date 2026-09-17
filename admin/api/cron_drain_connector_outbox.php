<?php
/**
 * CM Free / CM Plus – Cron endpoint: drain the CM Connector outbox to CM Relay.
 *
 * Purpose:
 * - POST every pending local outbound command (common/lib/cm_connector_outbox.php)
 *   to this installation's Relay, using its stored bearer token.
 * - No-ops safely if Connectivity was never activated (cm_connector_outbox_drain()
 *   checks activation_status itself).
 *
 * Deliberately NOT called from the save-handler request path (price/
 * availability changes queue locally and return immediately) - runs on a
 * schedule instead, so a slow or unreachable Relay never adds latency to an
 * admin saving a price.
 *
 * Author: Viljem Dvojmoč, Assistant: Claude
 */
declare(strict_types=1);

require_once __DIR__ . '/../../common/lib/cm_connector.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode(cm_connector_outbox_drain());
