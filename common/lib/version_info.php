<?php
declare(strict_types=1);

/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/version_info.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Reads build metadata written into the package at packaging time
 * (see common/data/version_info.json — generated fresh for each ZIP/DEB
 * build, not tracked in git, same convention as admin_key.txt/i18n).
 */

if (!function_exists('cm_get_build_info')) {
    function cm_get_build_info(): array
    {
        $path = __DIR__ . '/../data/version_info.json';

        if (!is_file($path)) {
            return ['commit_date' => null, 'commit_hash' => null];
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return ['commit_date' => null, 'commit_hash' => null];
        }

        return [
            'commit_date' => isset($data['commit_date']) ? (string)$data['commit_date'] : null,
            'commit_hash' => isset($data['commit_hash']) ? (string)$data['commit_hash'] : null,
        ];
    }
}
