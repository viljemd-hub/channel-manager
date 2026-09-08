<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/addon_capacity_check.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Purpose: read-only capacity lookup for the "Dodaj addon gosta" modal
 * (manage_reservations.js) - shows how many more guests the unit can fit
 * before the addon form is even submitted. Same headcount math as
 * create_addon_reservation.php's own server-side check (duplicated, not
 * shared - matches this project's existing per-endpoint convention).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function acc_respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function acc_find_reservation(string $resRoot, string $id): ?array {
    $id = trim($id);
    if ($id === '') return null;
    $year = substr($id, 0, 4);
    $unit = '';
    if (preg_match('~-[0-9a-f]{4}-([A-Za-z0-9_-]+)$~', $id, $m)) {
        $unit = $m[1];
    }
    $candidates = [];
    if ($unit !== '') {
        $candidates[] = sprintf('%s/%s/%s/%s.json', $resRoot, $year, $unit, $id);
    } else {
        foreach (glob($resRoot . '/' . $year . '/*/' . $id . '.json') ?: [] as $p) {
            $candidates[] = $p;
        }
    }
    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $j = json_decode((string)@file_get_contents($p), true);
        if (!is_array($j)) continue;
        return $j;
    }
    foreach (glob($resRoot . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
        foreach (glob($yearDir . '/*/' . $id . '.json') ?: [] as $p) {
            $j = json_decode((string)@file_get_contents($p), true);
            if (!is_array($j)) continue;
            return $j;
        }
    }
    return null;
}

$appRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$resRoot = $appRoot . '/common/data/json/reservations';
$unitsRoot = $appRoot . '/common/data/json/units';

$parentId = trim((string)($_GET['parent_id'] ?? ''));
if ($parentId === '') {
    acc_respond(false, ['error' => 'missing_parent_id'], 400);
}

$parent = acc_find_reservation($resRoot, $parentId);
if ($parent === null) {
    acc_respond(false, ['error' => 'parent_not_found'], 404);
}

$unit = (string)($parent['unit'] ?? '');
$maxGuests = null;
$unitSettingsPath = $unitsRoot . '/' . $unit . '/site_settings.json';
if (is_file($unitSettingsPath)) {
    $unitSettings = json_decode((string)@file_get_contents($unitSettingsPath), true);
    if (is_array($unitSettings) && isset($unitSettings['capacity']['max_guests']) && is_numeric($unitSettings['capacity']['max_guests'])) {
        $maxGuests = (int)$unitSettings['capacity']['max_guests'];
    }
}

$parentHeadcount = (int)($parent['adults'] ?? 0) + (int)($parent['kids06'] ?? 0) + (int)($parent['kids712'] ?? 0);

$addonHeadcount = 0;
$addons = [];
foreach (glob($resRoot . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
    $unitDir = $yearDir . '/' . $unit;
    if (!is_dir($unitDir)) continue;
    foreach (glob($unitDir . '/*.json') ?: [] as $file) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (!is_array($j)) continue;
        if ((string)($j['meta']['addon_of'] ?? '') !== $parentId) continue;
        $count = (int)($j['adults'] ?? 0) + (int)($j['kids06'] ?? 0) + (int)($j['kids712'] ?? 0);
        $addonHeadcount += $count;
        $addons[] = [
            'id' => (string)($j['id'] ?? basename($file, '.json')),
            'guest_name' => (string)($j['guest']['name'] ?? ''),
            'from' => (string)($j['from'] ?? ''),
            'to' => (string)($j['to'] ?? ''),
            'headcount' => $count,
        ];
    }
}

$used = $parentHeadcount + $addonHeadcount;

acc_respond(true, [
    'unit' => $unit,
    'parent_from' => (string)($parent['from'] ?? ''),
    'parent_to' => (string)($parent['to'] ?? ''),
    'parent_headcount' => $parentHeadcount,
    'existing_addons' => $addons,
    'used' => $used,
    'max_guests' => $maxGuests,
    'available' => $maxGuests !== null ? max(0, $maxGuests - $used) : null,
]);
