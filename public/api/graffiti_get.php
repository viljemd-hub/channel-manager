<?php
declare(strict_types=1);

// Public endpoint to read current hero graffiti.
// Returns JSON: { ok: true, text: "...", updated_at: "..." }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Absolutna pot do JSON-a
$path = '/var/www/html/app/common/data/json/graffiti.json';
$defaultText = 'No-stress booking';

if (!is_readable($path)) {
    echo json_encode([
        'ok'         => false,
        'text'       => $defaultText,
        'updated_at' => null,
        'error'      => 'graffiti_file_not_readable',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($path);
if ($raw === false) {
    echo json_encode([
        'ok'         => false,
        'text'       => $defaultText,
        'updated_at' => null,
        'error'      => 'graffiti_read_failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode([
        'ok'         => false,
        'text'       => $defaultText,
        'updated_at' => null,
        'error'      => 'graffiti_invalid_json',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Novi format: { current: {...}, history: [...] }
if (isset($data['current']) && is_array($data['current'])) {
    $cur = $data['current'];
    $text = isset($cur['text']) && is_string($cur['text']) ? trim($cur['text']) : '';
    if ($text === '') {
        $text = $defaultText;
    }
    $updated = isset($cur['updated_at']) && is_string($cur['updated_at'])
        ? $cur['updated_at']
        : null;

    echo json_encode([
        'ok'         => true,
        'text'       => $text,
        'updated_at' => $updated,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Legacy format: { text: "...", updated_at: "...", ip: "..." }
if (isset($data['text']) && is_string($data['text'])) {
    $text = trim($data['text']);
    if ($text === '') {
        $text = $defaultText;
    }
    $updated = isset($data['updated_at']) && is_string($data['updated_at'])
        ? $data['updated_at']
        : null;

    echo json_encode([
        'ok'         => true,
        'text'       => $text,
        'updated_at' => $updated,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Če nič od zgoraj, fallback
echo json_encode([
    'ok'         => false,
    'text'       => $defaultText,
    'updated_at' => null,
    'error'      => 'graffiti_unknown_format',
], JSON_UNESCAPED_UNICODE);
