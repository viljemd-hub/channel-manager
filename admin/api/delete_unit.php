<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/delete_unit.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Minimal helper: JSON response + exit.
 */
function jexit(bool $ok, array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitize unit id: allow only A–Z, 0–9, _ and - (max 32 chars).
 */
function sanitize_id(string $id): string {
    $id = trim($id);
    if ($id === '') return '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
        return '';
    }
    return $id;
}

// -------------------------
//  Read JSON input
// -------------------------

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    jexit(false, ['error' => 'invalid_json']);
}

$id = sanitize_id((string)($data['id'] ?? ''));

if ($id === '') {
    jexit(false, ['error' => 'missing_or_invalid_id']);
}

// Absolutna pot do app root-a (iz inventory: /var/www/html/app)
$appRoot   = '/var/www/html/app';
$unitsRoot = $appRoot . '/common/data/json/units';
$unitDir   = $unitsRoot . '/' . $id;

// -------------------------
//  Delete unit directory
// -------------------------

if (is_dir($unitDir)) {
    try {
        $it    = new RecursiveDirectoryIterator($unitDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($unitDir);
    } catch (Throwable $e) {
        error_log('delete_unit.php: failed to remove dir for ' . $id . ': ' . $e->getMessage());
        jexit(false, ['error' => 'delete_failed_dir', 'message' => $e->getMessage()]);
    }
}

// -------------------------
//  Update manifest.json
// -------------------------

$manifestFile = $unitsRoot . '/manifest.json';

if (is_file($manifestFile)) {
    $json = json_decode(file_get_contents($manifestFile), true);
    if (!is_array($json)) {
        $json = [];
    }

    $units = [];
    if (isset($json['units']) && is_array($json['units'])) {
        $units = $json['units'];
    }

    $changed  = false;
    $filtered = [];

    foreach ($units as $u) {
        if (!is_array($u)) {
            $filtered[] = $u;
            continue;
        }

        $uid = $u['id'] ?? ($u['unit'] ?? null);
        if ($uid === $id) {
            // to je enota, ki jo brišemo -> preskočimo
            $changed = true;
            continue;
        }

        $filtered[] = $u;
    }

    if ($changed) {
        $json['units'] = $filtered;

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            jexit(false, ['error' => 'manifest_encode_failed']);
        }
        if (file_put_contents($manifestFile, $encoded) === false) {
            jexit(false, ['error' => 'manifest_write_failed']);
        }
    }
}

// Če smo prišli do tukaj, je vse OK (ali pa enota sploh ni obstajala)
jexit(true, ['deleted' => $id]);
