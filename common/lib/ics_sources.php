<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/ics_sources.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

namespace App\ICS;

final class IcsSources {
  private string $dataRoot;
  private string $domain;

  public function __construct(string $dataRoot = '/var/www/html/app/common/data/json', string $domain = 'apartma.local') {
    $this->dataRoot = rtrim($dataRoot, '/');
    $this->domain   = $domain;
  }

  /**
   * Vrne seznam rezervacij za enoto kot [ [id, unit, from, to], ... ]
   * Gleda: /common/data/json/reservations/<YYYY>/<UNIT>/*.json
   */
  public function loadReservations(string $unit): array {
    $unit = preg_replace('/[^A-Za-z0-9_-]/', '', $unit);
    $base = "{$this->dataRoot}/reservations";
    if (!is_dir($base)) return [];

    $out = [];
    $years = glob($base.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($years as $yearDir) {
      $y = basename($yearDir);
      if (!preg_match('/^\d{4}$/', $y)) continue;
      $uDir = "{$yearDir}/{$unit}";
      if (!is_dir($uDir)) continue;

      foreach (glob($uDir.'/*.json') ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!is_array($j)) continue;

        $id   = (string)($j['id']   ?? '');
        $from = (string)($j['from'] ?? '');
        $to   = (string)($j['to']   ?? '');
        if ($id === '' || $from === '' || $to === '') continue;

        $out[] = [
          'id'   => $id,
          'unit' => $unit,
          'from' => $from,
          'to'   => $to
        ];
      }
    }
    // Opcijsko: sort by from
    usort($out, fn($a,$b) => strcmp($a['from'], $b['from']));
    return $out;
  }

  public function reservationToEvent(array $r): array {
    $uid = $r['id'].'@'.$this->domain;
    return [
      'uid'         => $uid,
      'dtstamp'     => gmdate('Ymd\THis\Z'),
      'start'       => $r['from'],
      'end'         => $r['to'],          // end-exclusive
      'summary'     => "Booked – {$r['unit']}",
      'description' => "Nights: ".$this->nights($r['from'], $r['to']),
      'sequence'    => 0,
      'status'      => '',                // active
      'categories'  => 'RESERVATION'
    ];
  }

  private function nights(string $from, string $to): int {
    $a = \DateTime::createFromFormat('Y-m-d', $from, new \DateTimeZone('UTC'));
    $b = \DateTime::createFromFormat('Y-m-d', $to,   new \DateTimeZone('UTC'));
    if (!$a || !$b) return 0;
    return (int)$a->diff($b)->days;
  }

  /**
   * Prebere integracijski ključ iz /integrations/<UNIT>.json
   */
  public function readReservationsKey(string $unit): ?string {
    $f = "{$this->dataRoot}/integrations/{$unit}.json";
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) return null;
    return $j['keys']['reservations_out'] ?? null;
  }
}
