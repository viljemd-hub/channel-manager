<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/_lib/paths.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Shared path configuration for admin API.
 *
 * Responsibilities:
 * - Provide centralised absolute/relative paths to JSON roots:
 *   units, inquiries, reservations, cancellations, integrations, etc.
 * - Avoid hard-coded filesystem paths scattered across API endpoints.
 *
 * Used by:
 * - Most admin API endpoints in /admin/api/*.php.
 *
 * Notes:
 * - Keep this file side-effect free (no I/O on include).
 * - If paths change (e.g. new JSON root), update them here first.
 */
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/_lib/paths.php
 */

declare(strict_types=1);

/**
 * Resolve app root from:
 * app/admin/api/_lib/paths.php -> app/
 */
function app_root(): string {
    $root = realpath(__DIR__ . '/../../..');
    if ($root !== false) {
        return $root;
    }
    return dirname(__DIR__, 3);
}

function data_root(): string {
    return app_root() . '/common/data/json';
}

function common_data_root(): string {
    return app_root() . '/common/data';
}

function admin_key_path(): string {
    return common_data_root() . '/admin_key.txt';
}

function units_root(): string {
    return data_root() . '/units';
}

function integrations_root(): string {
    return data_root() . '/integrations';
}

function reservations_root(): string {
    return data_root() . '/reservations';
}

function cancellations_root(): string {
    return data_root() . '/cancellations';
}

function inquiries_root(): string {
    return data_root() . '/inquiries';
}

function inquiry_path(string $id, string $stage): string {
    if (!preg_match('~^(\d{4})(\d{2})~', $id, $m)) {
        return '';
    }

    return inquiries_root() . '/' . $m[1] . '/' . $m[2] . '/' . $stage . '/' . $id . '.json';
}

function list_stage_dir(string $year, string $month, string $stage): string {
    return inquiries_root() . '/' . $year . '/' . $month . '/' . $stage;
}

function is_overlap(string $a_from, string $a_to, string $b_from, string $b_to): bool {
    return ($a_from < $b_to) && ($b_from < $a_to);
}