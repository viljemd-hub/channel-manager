<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/manifest_set_base_url.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

// ---------- Helpers ----------
function send_json_error(string $code, string $message, int $httpStatus = 400, array $extra = []): void {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok'      => false,
        'error'   => $code,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function send_json_ok(array $data = []): void {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function write_json_file(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ne morem ustvariti mape: {$dir}");
    }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    $tmpPath = $path . '.tmp-' . getmypid() . '-' . uniqid('', true);
    if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        @unlink($tmpPath);
        throw new RuntimeException("Ne morem zapisati: {$tmpPath}");
    }
    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException("Ne morem zamenjati datoteke: {$path}");
    }
}

function read_json_file(string $path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

// ---------- Main ----------
$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    send_json_error('bad_json', 'Neveljaven JSON body.');
}

$baseUrl = trim((string)($body['base_url'] ?? ''));

// Default for Free/Plus dev
if ($baseUrl === '') {
    $baseUrl = 'http://localhost';
}

// Ensure protocol
if (!preg_match('~^https?://~i', $baseUrl)) {
    $baseUrl = 'https://' . ltrim($baseUrl, '/');
}

$manifestPath = '/var/www/html/app/common/data/json/units/manifest.json';

$manifest = read_json_file($manifestPath);
if (!is_array($manifest)) {
    $manifest = ['units' => []];
}
if (!isset($manifest['units']) || !is_array($manifest['units'])) {
    $manifest['units'] = [];
}

$previous = $manifest['base_url'] ?? null;

// Set global base_url + mirror to meta.base_url
$manifest['base_url'] = $baseUrl;
if (!isset($manifest['meta']) || !is_array($manifest['meta'])) {
    $manifest['meta'] = [];
}
$manifest['meta']['base_url'] = $baseUrl;

try {
    write_json_file($manifestPath, $manifest);
} catch (Throwable $e) {
    send_json_error(
        'manifest_write_error',
        'Ne morem zapisati manifest.json: ' . $e->getMessage(),
        500
    );
}

send_json_ok([
    'base_url'          => $baseUrl,
    'previous_base_url' => $previous,
    'manifest_path'     => $manifestPath,
]);
