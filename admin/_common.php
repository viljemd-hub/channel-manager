<?php
/**
 * CM PRO – admin/_common.php
 * Central bootstrap for all admin API endpoints
 */

declare(strict_types=1);

/* ------------------------------------------
 * 1) DEFINE PATH CONSTANTS
 * ------------------------------------------ */

// /var/www/html/app
if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/..'));
}

// /var/www/html/app/common
if (!defined('APP_COMMON')) {
    define('APP_COMMON', APP_ROOT . '/common');
}

// /var/www/html/app/public
if (!defined('APP_PUBLIC')) {
    define('APP_PUBLIC', APP_ROOT . '/public');
}


/* ------------------------------------------
 * 2) GENERIC HELPERS
 * ------------------------------------------ */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function load_json(string $path) {
    if (!is_file($path)) return null;
    $j = json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function save_json(string $path, array $data): bool {
    return file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

function json_response(array $arr): void {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}


/* ------------------------------------------
 * 3) LOAD GLOBAL SETTINGS
 * ------------------------------------------ */

function cm_settings_load(): array {
    $file = APP_COMMON . '/data/json/site_settings.json';
    $j = load_json($file);
    return is_array($j) ? $j : [];
}

function cm_settings_save(array $data): bool {
    $file = APP_COMMON . '/data/json/site_settings.json';
    return save_json($file, $data);
}


/* ------------------------------------------
 * 4) ADMIN KEY CHECK (optional)
 * ------------------------------------------ */

function require_key(): void {
    $keyFile = APP_COMMON . '/data/json/admin_key.txt';
    $key = is_file($keyFile) ? trim(file_get_contents($keyFile)) : '';

    if ($key === '') return; // key not enforced

    $got = $_GET['key'] ?? $_POST['key'] ?? '';
    if ($got !== $key) {
        http_response_code(401);
        echo "Unauthorized";
        exit;
    }
}
