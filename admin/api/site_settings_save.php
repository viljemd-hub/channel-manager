<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/site_settings_save.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 *
 * Save global site_settings.json (public_base_url + email block)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$appRoot = dirname(__DIR__, 2);
$settingsFile = $appRoot . '/common/data/json/units/site_settings.json';

function respond(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Read JSON body
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    respond(['ok' => false, 'error' => 'empty_body']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(['ok' => false, 'error' => 'invalid_json']);
}

// Extract & normalize fields
$publicBaseUrl = '';
if (isset($data['public_base_url'])) {
    $publicBaseUrl = trim((string)$data['public_base_url']);
}

$email = isset($data['email']) && is_array($data['email']) ? $data['email'] : [];

$emailEnabled    = array_key_exists('enabled', $email) ? (bool)$email['enabled'] : true;
$emailFromEmail  = isset($email['from_email'])  ? trim((string)$email['from_email'])  : '';
$emailFromName   = isset($email['from_name'])   ? trim((string)$email['from_name'])   : '';
$emailAdminEmail = isset($email['admin_email']) ? trim((string)$email['admin_email']) : '';

// Optional: basic email sanity (non-strict)
foreach (['emailFromEmail' => $emailFromEmail, 'emailAdminEmail' => $emailAdminEmail] as $label => $val) {
    if ($val !== '' && strpos($val, '@') === false) {
        respond(['ok' => false, 'error' => 'invalid_email_' . $label]);
    }
}

// Load current settings to preserve all other keys (autopilot, etc.)
$current = [];
if (is_file($settingsFile)) {
    $rawCurrent = @file_get_contents($settingsFile);
    if ($rawCurrent !== false) {
        $decoded = json_decode($rawCurrent, true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
}

// Apply updates
$current['public_base_url'] = $publicBaseUrl;

$current['email'] = [
    'enabled'     => $emailEnabled,
    'from_email'  => $emailFromEmail,
    'from_name'   => $emailFromName,
    'admin_email' => $emailAdminEmail,
];

// Write back
$dir = dirname($settingsFile);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$tmpFile = $settingsFile . '.tmp';

if (@file_put_contents($tmpFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    respond(['ok' => false, 'error' => 'write_failed_tmp']);
}

if (!@rename($tmpFile, $settingsFile)) {
    @unlink($tmpFile);
    respond(['ok' => false, 'error' => 'rename_failed']);
}

respond(['ok' => true, 'settings' => $current]);
