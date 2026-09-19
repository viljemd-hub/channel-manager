<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/sepa_qr.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * Build an SVG data URI for EPC QR payload using local `qrencode` CLI.
 *
 * This keeps the project free of bundled PHP QR libraries and avoids GD.
 * If `qrencode` is not available or generation fails, null is returned.
 */
function cm_build_epc_qr_svg_data_uri(string $payload): ?string
{
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    $qrencodeBin = cm_find_qrencode_binary();
    if ($qrencodeBin === null) {
        return null;
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'cm_epc_qr_');
    if ($tmpBase === false) {
        return null;
    }

    $tmpSvg = $tmpBase . '.svg';
    @unlink($tmpBase);

 $cmd = sprintf(
    '%s -t SVG -o %s -l M -s 4 --margin 4 %s 2>/dev/null',
    escapeshellarg($qrencodeBin),
    escapeshellarg($tmpSvg),
    escapeshellarg($payload)
);

if (is_file('/usr/bin/timeout')) {
    $cmd = sprintf(
        '%s 2 %s -t SVG -o %s -l M -s 4 --margin 4 %s 2>/dev/null',
        escapeshellarg('/usr/bin/timeout'),
        escapeshellarg($qrencodeBin),
        escapeshellarg($tmpSvg),
        escapeshellarg($payload)
    );
}

@exec($cmd, $out, $exitCode);

    if ($exitCode !== 0 || !is_file($tmpSvg)) {
        @unlink($tmpSvg);
        return null;
    }

    $svg = @file_get_contents($tmpSvg);
    @unlink($tmpSvg);

    if (!is_string($svg) || trim($svg) === '') {
        return null;
    }

    // Normalize a bit for safer embedding.
    $svg = trim($svg);

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * PNG variant for on-screen camera scanning (2026-09-10 real bug): the
 * SVG output above draws one <rect> per module with no shared edges.
 * At a non-integer CSS pixel-per-module ratio (any browser display size
 * that isn't an exact multiple of the module count - the normal case),
 * per-shape anti-aliasing puts a faint seam between adjacent same-color
 * modules, breaking up what should be solid finder-pattern squares into
 * a scattered dot pattern a phone camera's QR detector cannot lock onto.
 * A rasterized PNG has no such seams regardless of display scaling.
 * Used by CM Companion's pairing QR; SEPA/EPC payment QR keeps the SVG
 * path above unchanged (its own established, working use case).
 */
function cm_build_epc_qr_png_data_uri(string $payload): ?string
{
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    $qrencodeBin = cm_find_qrencode_binary();
    if ($qrencodeBin === null) {
        return null;
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'cm_epc_qr_');
    if ($tmpBase === false) {
        return null;
    }

    $tmpPng = $tmpBase . '.png';
    @unlink($tmpBase);

    $cmd = sprintf(
        '%s -t PNG -o %s -l M -s 8 --margin 4 %s 2>/dev/null',
        escapeshellarg($qrencodeBin),
        escapeshellarg($tmpPng),
        escapeshellarg($payload)
    );

    if (is_file('/usr/bin/timeout')) {
        $cmd = sprintf(
            '%s 2 %s -t PNG -o %s -l M -s 8 --margin 4 %s 2>/dev/null',
            escapeshellarg('/usr/bin/timeout'),
            escapeshellarg($qrencodeBin),
            escapeshellarg($tmpPng),
            escapeshellarg($payload)
        );
    }

    @exec($cmd, $out, $exitCode);

    if ($exitCode !== 0 || !is_file($tmpPng)) {
        @unlink($tmpPng);
        return null;
    }

    $png = @file_get_contents($tmpPng);
    @unlink($tmpPng);

    if (!is_string($png) || $png === '') {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($png);
}

/**
 * CM PRO - EPC QR Generator Bridge
 * Converts reservation data into a standard EPC QR payload and returns a SVG Data URI.
 *
 * @param array $params Booking data (name, iban, bic, amount, ref, descr)
 * @return string|null Base64 SVG or null if generation fails
 */
function cm_generate_sepa_qr(array $params): ?string
{
    // Format amount to 'EUR123.45' as per EPC standard
    $amountFormatted = 'EUR' . number_format((float)($params['amount'] ?? 0), 2, '.', '');
    
    // EPC QR structure: 13 lines separated by newlines
    $lines = [
        "BCD",              // Service ID
        "002",              // Version
        "1",                // Character set (UTF-8)
        "SCT",              // Identification
        $params['bic'] ?? '', 
        $params['name'] ?? '',
        $params['iban'] ?? '',
        $amountFormatted,
        "",                 // Purpose
        $params['ref'] ?? '',
        $params['descr'] ?? '',
        ""                  // Add. info
    ];

    $payload = implode("\n", $lines);

    // Use your existing CLI wrapper that calls /usr/bin/qrencode
    if (function_exists('cm_build_epc_qr_svg_data_uri')) {
        return cm_build_epc_qr_svg_data_uri($payload);
    }

    return null;
}
/**
 * Try to locate the local qrencode binary.
 */
function cm_find_qrencode_binary(): ?string
{
    static $cached = null;
    static $resolved = false;

    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    $candidates = [
        '/usr/bin/qrencode',
        '/usr/local/bin/qrencode',
        '/bin/qrencode',
    ];

    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path)) {
            $cached = $path;
            return $cached;
        }
    }

    $which = @shell_exec('command -v qrencode 2>/dev/null');
    if (is_string($which)) {
        $which = trim($which);
        if ($which !== '' && is_file($which) && is_executable($which)) {
            $cached = $which;
            return $cached;
        }
    }

    $cached = null;
    return null;
} 
