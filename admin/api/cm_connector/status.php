<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/cm_connector/status.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_lib/json_io.php';
require_once __DIR__ . '/../../_common.php';
require_once APP_COMMON . '/lib/cm_connector.php';

json_ok(cm_connector_status());
