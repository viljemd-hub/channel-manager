<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/reject_pending_conflicts.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/api/reject_pending_conflicts.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/send_rejected.php';
require_once __DIR__ . '/../../common/lib/conflict_care.php';

/**
 * Local JSON helpers so we do not depend on a shared json.php.
 * Wrapped in function_exists guards to avoid conflicts with other loaders.
 */
if (!function_exists('cm_json_read')) {
    function cm_json_read(string $path) {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('cm_json_write')) {
    function cm_json_write(string $path, array $data): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            return false;
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        @chmod($path, 0664);
        return true;
    }
}

/**
 * Find inquiry file by id and stage.
 * Used to locate accepted_soft_hold inquiries.
 */
if (!function_exists('cm_find_inquiry_file')) {
    function cm_find_inquiry_file(string $inqRoot, string $id, string $stage): ?string {
        $pattern = rtrim($inqRoot, '/') . "/*/*/{$stage}/*.json";
        $files   = glob($pattern, GLOB_NOSORT) ?: [];
        foreach ($files as $file) {
            $data = cm_json_read($file);
            if (!is_array($data)) {
                continue;
            }
            if (($data['id'] ?? null) === $id) {
                return $file;
            }
        }
        return null;
    }
}

// ---------------------------------------------------------------------
// HTTP endpoint (manual admin click: "Reject conflicts")
// ---------------------------------------------------------------------

if (php_sapi_name() !== 'cli'
    && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')
) {
    $id = trim($_POST['id'] ?? $_GET['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_id']);
        exit;
    }

    $appRoot = '/var/www/html/app';
    $cfg     = cm_datetime_cfg();

    $inqRoot = $appRoot . '/common/data/json/inquiries';
    $resRoot = $appRoot . '/common/data/json/reservations';

    $unit = '';
    $from = '';
    $to   = '';

    // 1) Try to find reference in accepted_soft_hold
    $accFile = cm_find_inquiry_file($inqRoot, $id, 'accepted_soft_hold');
    $acc     = $accFile ? cm_json_read($accFile) : null;

    if (is_array($acc)) {
        $unit = (string)($acc['unit'] ?? '');
        $from = (string)($acc['from'] ?? '');
        $to   = (string)($acc['to']   ?? '');
    } else {
        // 2) Fallback: look in reservations
        $pattern = rtrim($resRoot, '/') . "/*/*/{$id}.json";
        $files   = glob($pattern, GLOB_NOSORT) ?: [];
        if ($files) {
            $res = cm_json_read($files[0]);
            if (is_array($res)) {
                $unit = (string)($res['unit'] ?? '');
                $from = (string)($res['from'] ?? '');
                $to   = (string)($res['to']   ?? '');
            }
        }
    }

    if ($unit === '' || $from === '' || $to === '') {
        http_response_code(404);
        echo json_encode([
            'ok'    => false,
            'error' => 'reference_not_found',
            'id'    => $id,
        ]);
        exit;
    }

    try {
        $result = cm_reject_pending_for_range($appRoot, $unit, $from, $to, $id, $cfg);
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'reject_pending_failed',
            'msg'   => $e->getMessage(),
        ]);
    }
}
