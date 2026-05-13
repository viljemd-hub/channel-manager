<?php
declare(strict_types=1);

/**
 * URL helpers for public part of the site.
 *
 * Central function cm_public_base_url():
 *  - tries to read urls.public_base_url from site_settings.json
 *  - falls back to auto-detected scheme/host (also supports reverse proxy)
 *
 * cm_public_url($path):
 *  - builds a full public URL based on cm_public_base_url()
 */

if (!function_exists('cm_public_base_url')) {

    function cm_public_base_url(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // 1) Try to read from site_settings.json
        $settingsPath = __DIR__ . '/../data/json/units/site_settings.json';

        if (is_readable($settingsPath)) {
            $raw = file_get_contents($settingsPath);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $url = $data['urls']['public_base_url'] ?? null;
                    if (is_string($url) && $url !== '') {
                        $cache = rtrim($url, '/');
                        return $cache;
                    }
                }
            }
        }

        // 2) Fallback – auto-detect from request (also works behind reverse proxy)
        $scheme = 'http';

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            // Reverse proxy sends the original scheme here
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Try to detect /app base from script path
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = '/app';

        $pos = strpos($script, '/app/');
        if ($pos !== false) {
            // Everything up to and including "/app"
            $basePath = substr($script, 0, $pos) . '/app';
        }

        $cache = $scheme . '://' . $host . rtrim($basePath, '/');
        return $cache;
    }
}

/**
 * Build a full public URL from a relative path.
 * Example: cm_public_url('public/confirm_reservation.php?token=...')
 */
if (!function_exists('cm_public_url')) {

    function cm_public_url(string $path): string
    {
        $base = cm_public_base_url();
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
