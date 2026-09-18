<?php
/**
 * CM Free/Plus – Cron endpoint: pull + process inbound Relay events.
 *
 * Purpose:
 * - Pull queued events from Relay (GET /api/v1/events, via
 *   cm_connector_pull_events()) into the local inbound queue
 *   (cm_connector_events.php).
 * - Process what's safe to auto-acknowledge (ari/sync_error/sync_warning/
 *   booking_unmapped_* - informational, CM stays master of its own pricing/
 *   availability) via cm_connector_process_inbound_events().
 * - booking_new/booking_modification/booking_cancellation are deliberately
 *   NOT auto-applied - see cm_connector_process_inbound_events()'s own
 *   comment. They accumulate in the local queue with status 'received',
 *   visible via cm_connector_events_list(), pending a real reservation-
 *   creation implementation and a human decision on that mapping.
 *
 * No-ops safely if Connectivity was never activated.
 *
 * Author: Viljem Dvojmoč, Assistant: Claude
 */
declare(strict_types=1);

require_once __DIR__ . '/../../common/lib/cm_connector.php';

header('Content-Type: application/json; charset=utf-8');

$pull = cm_connector_pull_events();
$process = cm_connector_process_inbound_events();

echo json_encode(['pull' => $pull, 'process' => $process]);
