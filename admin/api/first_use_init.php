<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/first_use_init.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/api/first_use_init.php


declare(strict_types=1);

require_once __DIR__ . '/_lib/paths.php';
require __DIR__ . '/_lib/json_io.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, array $extra = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function ensure_dir_local(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create directory: {$dir}");
    }
}

function write_text_if_missing(string $path, string $content): bool {
    if (is_file($path)) {
        return false;
    }

    ensure_dir_local(dirname($path));

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write file: {$path}");
    }

    return true;
}

try {
    $in = require_post_json();
} catch (Throwable $e) {
    respond(false, ['error' => 'invalid_json'], 400);
}

try {
    $root = app_root();
    $commonJson = $root . '/common/data/json';
    $commonData = $root . '/common/data';
    $unitsRoot = $commonJson . '/units';
    $integrationsRoot = $commonJson . '/integrations';

    $ownerName   = trim((string)($in['owner_name'] ?? ''));
    $ownerEmail  = trim((string)($in['owner_email'] ?? ''));
    $domain      = trim((string)($in['domain'] ?? ''));
    $publicIp    = trim((string)($in['public_ip'] ?? ''));
    $installPath = trim((string)($in['install_path'] ?? '/app'));
    $tz          = trim((string)($in['timezone'] ?? 'Europe/Ljubljana'));

    $unitId = strtoupper(trim((string)($in['unit_id'] ?? '')));
    $unitId = preg_replace('~[^A-Z0-9_]~', '', $unitId) ?: '';

    $unitLabel = trim((string)($in['unit_label'] ?? $unitId));

    if ($unitId === '') {
        respond(false, ['error' => 'missing_unit_id'], 400);
    }

    if (!preg_match('/^[A-Z0-9_]{1,12}$/', $unitId)) {
        respond(false, ['error' => 'bad_unit_id'], 400);
    }

    if ($installPath === '') {
        $installPath = '/app';
    }

    if ($installPath[0] !== '/') {
        $installPath = '/' . $installPath;
    }

    $installPath = rtrim($installPath, '/');
    if ($installPath === '') {
        $installPath = '/app';
    }

    // Compute base URL.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if ($domain !== '') {
        $baseUrl = 'https://' . preg_replace('~^https?://~', '', $domain);
    } elseif ($publicIp !== '') {
        $baseUrl = 'http://' . preg_replace('~^https?://~', '', $publicIp);
    } else {
        $baseUrl = $scheme . '://' . $host;
    }

    ensure_dir_local($commonJson);
    ensure_dir_local($commonData);
    ensure_dir_local($unitsRoot);
    ensure_dir_local($integrationsRoot);

    $created = [];

    // Admin key for protected scripts / API helpers.
    $adminKeyFile = $commonData . '/admin_key.txt';
    if (!is_file($adminKeyFile)) {
        $adminKey = bin2hex(random_bytes(16));
        write_text_if_missing($adminKeyFile, $adminKey);
        @chmod($adminKeyFile, 0660);
        $created[] = 'admin_key.txt';
    } else {
        $adminKey = trim((string)@file_get_contents($adminKeyFile));
    }

    // 1) instance.json
    $instanceFile = $commonJson . '/instance.json';
    $instance = [
        'initialized' => true,
        'instance' => [
            'base_url' => $baseUrl,
            'domain' => ($domain !== '' ? $domain : null),
            'fallback_ip' => ($publicIp !== '' ? $publicIp : null),
            'installed_path' => $installPath,
            'timezone' => ($tz !== '' ? $tz : 'Europe/Ljubljana'),
            'created_at' => gmdate('c'),
        ],
        'owner' => [
            'name' => $ownerName,
            'email' => $ownerEmail,
        ],
    ];
    write_json($instanceFile, $instance);
    $created[] = 'instance.json';

    // 2) global units/site_settings.json
    $globalSettingsFile = $unitsRoot . '/site_settings.json';
    if (!is_file($globalSettingsFile)) {
        write_json($globalSettingsFile, [
            'license' => [
                'tier' => 'free',
                'features' => [
                    'advanced_payments' => false,
                    'invoicing' => false,
                    'basic_accounting' => false,
                ],
            ],
            'product' => [
                'tier' => 'free',
                'version' => '1.0.0',
            ],
            'email' => [
                'enabled' => true,
                'from_email' => $ownerEmail,
                'from_name' => $ownerName,
                'admin_email' => $ownerEmail,
            ],
            'instance' => [
                'base_url' => $baseUrl,
                'install_path' => $installPath,
                'timezone' => ($tz !== '' ? $tz : 'Europe/Ljubljana'),
            ],
        ]);
        $created[] = 'units/site_settings.json';
    }

    // 3) manifest.json
    $manifestFile = $unitsRoot . '/manifest.json';
    $manifest = is_file($manifestFile) ? read_json($manifestFile) : null;
    if (!is_array($manifest)) {
        $manifest = [];
    }

    $units = $manifest['units'] ?? [];
    if (!is_array($units)) {
        $units = [];
    }

    $norm = [];
    foreach ($units as $u) {
        if (is_string($u)) {
            $norm[] = [
                'id' => $u,
                'label' => $u,
                'name' => $u,
                'active' => true,
                'public' => true,
            ];
        } elseif (is_array($u) && isset($u['id'])) {
            $id = (string)$u['id'];
            $norm[] = array_merge($u, [
                'id' => $id,
                'label' => (string)($u['label'] ?? $u['name'] ?? $id),
                'name' => (string)($u['name'] ?? $u['label'] ?? $id),
                'active' => ($u['active'] ?? true) !== false,
                'public' => ($u['public'] ?? true) !== false,
            ]);
        }
    }

    $exists = false;
    foreach ($norm as $u) {
        if (($u['id'] ?? '') === $unitId) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $norm[] = [
            'id' => $unitId,
            'label' => ($unitLabel ?: $unitId),
            'name' => ($unitLabel ?: $unitId),
            'property_id' => 'HOME',
            'owner' => $ownerName,
            'months_ahead' => 12,
            'clean_before' => 0,
            'clean_after' => 0,
            'active' => true,
            'public' => true,
        ];
    }

    $manifest['units'] = $norm;
    $manifest['generated_by'] = 'first_use_init';
    $manifest['updated_at'] = gmdate('c');
    write_json($manifestFile, $manifest);
    $created[] = 'units/manifest.json';

    // 4) per-unit structure
    $unitDir = $unitsRoot . '/' . $unitId;
    $externalDir = $unitDir . '/external';
    $rawDir = $unitDir . '/occupancy_raw';

    ensure_dir_local($unitDir);
    ensure_dir_local($externalDir);
    ensure_dir_local($rawDir);

    $siteSettingsFile = $unitDir . '/site_settings.json';
    if (!is_file($siteSettingsFile)) {
        write_json($siteSettingsFile, [
            'display' => [
                'name' => ($unitLabel ?: $unitId),
                'short' => $unitId,
                'month_render' => 13,
            ],
            'booking' => [
                'min_nights' => 1,
                'allow_same_day_departure' => false,
                'cleaning_fee_eur' => 0,
            ],
            'auto_block' => [
                'before_arrival' => false,
                'after_departure' => false,
            ],
            'day_use' => [
                'enabled' => false,
                'from' => '14:00',
                'to' => '20:00',
                'max_persons' => 1,
                'max_days_ahead' => 7,
                'day_price_person' => null,
            ],
            'email' => [
                'enabled' => true,
            ],
            'weekly_threshold' => 7,
            'weekly_discount_pct' => 10.0,
            'long_threshold' => 30,
            'long_discount_pct' => 20.0,
            'block_departure_day' => false,
        ]);
        $created[] = "units/{$unitId}/site_settings.json";
    }

    $defaults = [
        $unitDir . '/prices.json' => [],
        $unitDir . '/occupancy.json' => [],
        $unitDir . '/occupancy_merged.json' => [],
        $unitDir . '/local_bookings.json' => [],
        $unitDir . '/occupancy_external_ics.json' => [],
        $rawDir . '/local.json' => [],
        $unitDir . '/day_use.json' => [],
        $unitDir . '/special_offers.json' => ['offers' => []],
        $unitDir . '/occupancy_data.json' => [
            'January' => 0,
            'February' => 0,
            'March' => 0,
            'April' => 0,
            'May' => 0,
            'June' => 0,
            'July' => 0,
            'August' => 0,
            'September' => 0,
            'October' => 0,
            'November' => 0,
            'December' => 0,
        ],
        $unitDir . '/occupancy_sources.json' => [
            'channels' => [
                'local' => [
                    'type' => 'json',
                    'path' => $unitDir . '/local_bookings.json',
                    'enabled' => true,
                ],
                'booking' => [
                    'type' => 'ics',
                    'url' => '',
                    'enabled' => false,
                ],
                'airbnb' => [
                    'type' => 'ics',
                    'url' => '',
                    'enabled' => false,
                ],
            ],
            'priority' => ['local', 'booking', 'airbnb'],
            'suppressions' => [],
        ],
        $externalDir . '/booking_ics.json' => [
            'unit' => $unitId,
            'platform' => 'booking',
            'fetched_at' => null,
            'count' => 0,
            'events' => [],
        ],
    ];

    foreach ($defaults as $file => $content) {
        if (!is_file($file)) {
            write_json($file, $content);
            $created[] = str_replace($commonJson . '/', '', $file);
        }
    }

    // 5) integrations/<UNIT>.json
    $integrationFile = $integrationsRoot . '/' . $unitId . '.json';
    if (!is_file($integrationFile)) {
        write_json($integrationFile, [
            'unit' => $unitId,
            'connections' => [
                'booking' => [
                    'in' => [
                        'enabled' => false,
                        'ics_url' => '',
                    ],
                    'status' => [
                        'last_ok' => null,
                        'last_error' => null,
                    ],
                ],
            ],
        ]);
        $created[] = "integrations/{$unitId}.json";
    }

    // 6) shared helper JSONs
    $markedPending = $commonJson . '/marked_pending.json';
    if (!is_file($markedPending)) {
        write_json($markedPending, []);
        $created[] = 'marked_pending.json';
    }

    $promoCodes = $unitsRoot . '/promo_codes.json';
    if (!is_file($promoCodes)) {
        write_json($promoCodes, [
            'codes' => [],
        ]);
        $created[] = 'units/promo_codes.json';
    }

    respond(true, [
        'base_url' => $baseUrl,
        'install_path' => $installPath,
        'unit' => $unitId,
        'admin_key_created' => in_array('admin_key.txt', $created, true),
        'admin_key' => in_array('admin_key.txt', $created, true) ? $adminKey : null,
        'created' => $created,
    ]);

} catch (Throwable $e) {
    respond(false, [
        'error' => 'first_use_init_failed',
        'message' => $e->getMessage(),
    ], 500);
}