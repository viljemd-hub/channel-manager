<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/site_settings_get.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 *
 * Read global site_settings.json (public_base_url + email block)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$appRoot = dirname(__DIR__, 2);
$settingsFile = $appRoot . '/common/data/json/units/site_settings.json';

$out = [
    'ok' => true,
    'settings' => [
        'public_base_url' => '',
        'email' => [
            'enabled'     => true,
            'from_email'  => '',
            'from_name'   => '',
            'admin_email' => '',
        ],
    ],
];

// Load existing site_settings.json if present
if (is_file($settingsFile)) {
    $raw = @file_get_contents($settingsFile);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            if (isset($data['public_base_url']) && is_string($data['public_base_url'])) {
                $out['settings']['public_base_url'] = $data['public_base_url'];
            }

            if (isset($data['email']) && is_array($data['email'])) {
                $email = $data['email'];

                if (array_key_exists('enabled', $email)) {
                    $out['settings']['email']['enabled'] = (bool)$email['enabled'];
                }

                if (isset($email['from_email']) && is_string($email['from_email'])) {
                    $out['settings']['email']['from_email'] = $email['from_email'];
                }

                if (isset($email['from_name']) && is_string($email['from_name'])) {
                    $out['settings']['email']['from_name'] = $email['from_name'];
                }

                if (isset($email['admin_email']) && is_string($email['admin_email'])) {
                    $out['settings']['email']['admin_email'] = $email['admin_email'];
                }
            }
        }
    }
}

// If public_base_url is still empty, try to read from manifest.json (fallback)
if ($out['settings']['public_base_url'] === '') {
    $manifestFile = $appRoot . '/common/data/json/units/manifest.json';
    if (is_file($manifestFile)) {
        $raw = @file_get_contents($manifestFile);
        if ($raw !== false) {
            $m = json_decode($raw, true);
            if (is_array($m)) {
                $meta = (isset($m['meta']) && is_array($m['meta'])) ? $m['meta'] : null;

                $cand = '';
                if (isset($m['base_url']) && is_string($m['base_url']) && trim($m['base_url']) !== '') {
                    $cand = trim($m['base_url']);
                } elseif ($meta && isset($meta['base_url']) && is_string($meta['base_url']) && trim($meta['base_url']) !== '') {
                    $cand = trim($meta['base_url']);
                } elseif (isset($m['domain']) && is_string($m['domain']) && trim($m['domain']) !== '') {
                    $cand = trim($m['domain']);
                } elseif ($meta && isset($meta['domain']) && is_string($meta['domain']) && trim($meta['domain']) !== '') {
                    $cand = trim($meta['domain']);
                }

                if ($cand !== '') {
                    $out['settings']['public_base_url'] = $cand;
                }
            }
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
