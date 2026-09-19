<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/device_stats.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Aggregate-only Companion connection counts (2026-09-11) - "how many
 * pairings ever" and "how many are currently active", nothing per-device
 * (no labels, tokens, last_seen). Deliberately unauthenticated, same
 * reasoning as admin/api/counter.php already being publicly fetchable:
 * two counts reveal nothing sensitive, and this is meant to be the same
 * shape a future cross-installation Community monitor would poll from
 * many installations (see CM_Connectivity_Community_Contract_v0.2 §7) -
 * requiring a device token here would defeat that.
 */

require_once __DIR__ . '/../../../../common/lib/cm_bridge_pairing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo json_encode(['ok' => true] + cm_bridge_device_stats(), JSON_UNESCAPED_UNICODE);
