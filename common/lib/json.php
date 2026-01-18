<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/json.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/common/lib/json.php
declare(strict_types=1);

/**
 * Preprosto branje JSON datoteke kot asociativno polje.
 * Vrne array ali null, če datoteka ne obstaja ali ni veljaven JSON.
 */
function cm_json_read(string $path) {
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return null;
    }

    return $data;
}

/**
 * Zapis JSON v datoteko (poskuša biti čim bolj “varen”/atomski).
 * Vrne true ob uspehu, false ob napaki.
 */
function cm_json_write(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp';

    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, 0664);
    return true;
}
