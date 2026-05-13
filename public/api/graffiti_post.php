<?php
declare(strict_types=1);

// Public endpoint for guests to update the hero graffiti.
// Expects POST text, returns JSON { ok: true, text: "...", updated_at: "..." }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 🔹 Absolutna pot do JSON-a
$path = '/var/www/html/app/common/data/json/graffiti.json';
$defaultText = 'No-stress booking';

// Poskrbimo, da mapa obstaja
$dir = dirname($path);
if (!is_dir($dir)) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'dir_not_found',
        'path'  => $dir,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Preberi in normaliziraj vnos ──────────────────────────────────────────────
$text = $_POST['text'] ?? '';
$text = trim((string)$text);

// Basic sanitization
$text = strip_tags($text);
// normalize whitespace
$text = preg_replace('/\s+/', ' ', $text ?? '');

// Limit to 3 words
$words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
if (count($words) === 0) {
    echo json_encode([
        'ok'    => false,
        'error' => 'empty_text',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (count($words) > 3) {
    $words = array_slice($words, 0, 3);
}
$text = implode(' ', $words);

// Limit total length
if (mb_strlen($text) > 60) {
    $text = mb_substr($text, 0, 60);
}

// ── Preberi obstoječi JSON in zgradi history ─────────────────────────────────
$history = [];

// Legacy ali novi format?
if (is_readable($path)) {
    $raw = file_get_contents($path);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            if (isset($data['current']) && is_array($data['current'])) {
                // Novi format: { current: {...}, history: [...] }
                if (isset($data['history']) && is_array($data['history'])) {
                    $history = $data['history'];
                }
                // poskrbi, da je tudi current v history (če ga slučajno ni)
                if (!empty($data['current']['text'])) {
                    $history[] = $data['current'];
                }
            } elseif (isset($data['text']) && is_string($data['text'])) {
                // Legacy format: { text: "...", updated_at: "...", ip: "..." }
                $prev = [
                    'text'       => $data['text'],
                    'updated_at' => $data['updated_at'] ?? null,
                    'ip'         => $data['ip'] ?? null,
                ];
                $history[] = $prev;
            }
        }
    }
}

// ── Dodaj nov vnos ───────────────────────────────────────────────────────────
$entry = [
    'text'       => $text,
    'updated_at' => date('c'),
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? null, // info-only
];

$history[] = $entry;

// Opcijsko: omeji zgodovino na zadnjih N vnosov (da ne zraste v neskončnost)
$maxHistory = 200;
if (count($history) > $maxHistory) {
    $history = array_slice($history, -$maxHistory);
}

// Zgradi payload v novem formatu
$payload = [
    'current' => $entry,
    'history' => $history,
];

// ── Zapiši JSON ──────────────────────────────────────────────────────────────
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($encoded === false) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'json_encode_failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = @file_put_contents($path, $encoded, LOCK_EX);

if ($ok === false) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'write_failed',
        'path'  => $path,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'         => true,
    'text'       => $entry['text'],
    'updated_at' => $entry['updated_at'],
], JSON_UNESCAPED_UNICODE);
