<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/pending_mark_toggle.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * pending_mark_toggle.php
 *
 * GET  /app/admin/api/pending_mark_toggle.php?unit=A1
 *   → vrne seznam markiranih pendingov (po želji filtrirano po enoti)
 *
 * POST /app/admin/api/pending_mark_toggle.php
 *   JSON { id, unit, from, to, marked }
 *   → doda / odstrani zapis v /app/common/data/json/marked_pending.json
 */

header('Content-Type: application/json; charset=utf-8');

$APP_ROOT  = dirname(__DIR__, 2);              // /var/www/html/app
$DATA_ROOT = $APP_ROOT . '/common/data/json';  // .../common/data/json
$MARK_FILE = $DATA_ROOT . '/marked_pending.json';

function respond(bool $ok, array $payload = [], ?int $httpStatus = null): void {
    if ($httpStatus !== null) {
        http_response_code($httpStatus);
    }
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Varno preberi JSON, tudi če je prazen ali poškodovan.
 */
function read_marked_file(string $file): array {
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    // normaliziraj na navaden numerični array
    return array_values($data);
}

/**
 * Varno zapiši JSON (atomic write).
 */
function write_marked_file(string $file, array $items): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }

    $tmp = $file . '.tmp';
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }

    return @rename($tmp, $file);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

    $items = read_marked_file($MARK_FILE);

    if ($unit !== '') {
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['unit'] ?? '') === $unit) {
                $filtered[] = $item;
            }
        }
        $items = $filtered;
    }

    respond(true, [
        'items' => $items,
        'count' => count($items),
    ]);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        respond(false, ['error' => 'invalid_json'], 400);
    }

    $id     = isset($body['id']) ? trim((string)$body['id']) : '';
    $unit   = isset($body['unit']) ? trim((string)$body['unit']) : '';
    $from   = isset($body['from']) ? trim((string)$body['from']) : '';
    $to     = isset($body['to']) ? trim((string)$body['to']) : '';
    $marked = !empty($body['marked']);

    if ($id === '') {
        respond(false, ['error' => 'missing_id'], 400);
    }

    $items = read_marked_file($MARK_FILE);

    // indeksiraj po id za hitrejše urejanje
    $byId = [];
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;
        $itemId = isset($item['id']) ? (string)$item['id'] : '';
        if ($itemId === '') continue;
        $byId[$itemId] = $idx;
    }

    if ($marked) {
        // dodaj ali posodobi zapis
        $record = [
            'id'   => $id,
            'unit' => $unit,
            'from' => $from,
            'to'   => $to,
        ];

        if (isset($byId[$id])) {
            $items[(int)$byId[$id]] = $record;
        } else {
            $items[] = $record;
        }
    } else {
        // odstrani zapis po id
        if (isset($byId[$id])) {
            unset($items[(int)$byId[$id]]);
            $items = array_values($items);
        }
    }

    if (!write_marked_file($MARK_FILE, $items)) {
        respond(false, ['error' => 'write_failed'], 500);
    }

    respond(true, [
        'id'     => $id,
        'marked' => $marked,
        'count'  => count($items),
    ]);
}

// Nepodprta metoda
respond(false, ['error' => 'method_not_allowed'], 405);
