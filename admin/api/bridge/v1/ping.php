<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus / CM PRO - CM Bridge Protocol v1
 * File: admin/api/bridge/v1/ping.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Liveness + auth + scope introspection. What a module's own "Test Bridge
 * Connection" admin button calls first.
 */

require_once __DIR__ . '/../../../../common/lib/cm_bridge_server.php';
require_once __DIR__ . '/../../../../common/lib/datetime_fmt.php';

$auth = cm_bridge_require_auth(); // exits 401 JSON on failure

echo json_encode([
    'ok' => true,
    'cm' => [
        'tier' => cm_get_product_tier(),
        'version' => cm_get_product_version(),
    ],
    'module' => $auth['module'],
    'scopes' => $auth['scopes'],
], JSON_UNESCAPED_UNICODE);
