<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/load_inquiry.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/common/lib/load_inquiry.php
declare(strict_types=1);

/**
 * Lightweight helpers for reading inquiry JSON files.
 *
 * Currently exposes:
 *  - cm_load_inquiry_by_token(string $token): array
 *
 * The function:
 *  - searches accepted inquiries under:
 *      common/data/json/inquiries/YYYY/MM/accepted/*.json
 *  - returns the first inquiry whose "secure_token" matches
 *  - returns [] if nothing is found
 */

function cm_load_inquiry_by_token(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        return [];
    }

    // Root: /var/www/html/app/common/data/json/inquiries
    // __DIR__ = /var/www/html/app/common/lib
    $inqRoot = dirname(__DIR__) . '/data/json/inquiries';
    if (!is_dir($inqRoot)) {
        return [];
    }

    // Iterate over years
    $yearDirs = glob($inqRoot . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
    foreach ($yearDirs as $yearDir) {
        // Iterate over months
        $monthDirs = glob($yearDir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        foreach ($monthDirs as $monthDir) {
            $acceptedDir = $monthDir . '/accepted';
            if (!is_dir($acceptedDir)) {
                continue;
            }

            // Check all accepted inquiries in this month
            $files = glob($acceptedDir . '/*.json', GLOB_NOSORT) ?: [];
            foreach ($files as $path) {
                $raw = @file_get_contents($path);
                if ($raw === false || $raw === '') {
                    continue;
                }

                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    continue;
                }

                $t = (string)($data['secure_token'] ?? '');
                if ($t !== '' && hash_equals($t, $token)) {
                    // Found matching inquiry
                    return $data;
                }
            }
        }
    }

    // Not found
    return [];
}
