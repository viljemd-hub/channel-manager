<?php
/**
 * CM Free / CM Plus / CM PRO - Channel Manager
 * File: admin/api/pairing_generate.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Admin-facing "Generate pairing code" action - the owner side of CM
 * Companion's universal docking flow (see CM_Mobile_Companion_Plan_v0.1.md
 * §4, docs/architecture.md in the cm-companion repo). Returns a one-time
 * code plus a cmcompanion://pair deep link the admin panel can render as
 * text and, later, as a QR code. The actual code/device-token logic lives
 * in common/lib/cm_bridge_pairing.php, not here - this is just the admin
 * auth wrapper around it.
 */

declare(strict_types=1);

require __DIR__ . '/../_common.php';
require_key();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../common/lib/cm_bridge_pairing.php';

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$deviceLabelHint = is_array($body) ? trim((string)($body['device_label_hint'] ?? '')) : '';

echo json_encode(['ok' => true] + cm_bridge_pairing_generate($deviceLabelHint), JSON_UNESCAPED_UNICODE);
