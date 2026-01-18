<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/ics_utils.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

namespace App\ICS;

/**
 * Minimalni parser za ICS all-day evente (DTSTART;VALUE=DATE / DTEND;VALUE=DATE).
 * Vrne seznam: [ [ 'uid'=>..., 'start'=>'YYYY-MM-DD', 'end'=>'YYYY-MM-DD', 'status'=>'', ], ... ]
 * STATUS je lahko "CANCELLED" ali prazen.
 */
final class IcsUtils {

  public static function parseIcsText(string $ics): array {
    $lines = preg_split("/\r\n|\n|\r/", $ics);
    if (!$lines) return [];
    // unfold (vrstice, ki se nadaljujejo z vodilnim presledkom)
    $u = [];
    foreach ($lines as $line) {
      if ($line === '') { $u[] = ''; continue; }
      if (isset($u[count($u)-1]) && strlen($line) > 0 && $line[0] === ' ') {
        $u[count($u)-1] .= substr($line, 1);
      } else {
        $u[] = $line;
      }
    }

    $events = [];
    $cur = null;

    foreach ($u as $ln) {
      if ($ln === 'BEGIN:VEVENT') {
        $cur = ['uid'=>'','start'=>'','end'=>'','status'=>''];
        continue;
      }
      if ($ln === 'END:VEVENT') {
        if ($cur && $cur['start'] && $cur['end']) $events[] = $cur;
        $cur = null;
        continue;
      }
      if ($cur === null) continue;

      // UID
      if (stripos($ln, 'UID:') === 0) {
        $cur['uid'] = trim(substr($ln, 4));
        continue;
      }
      // DTSTART;VALUE=DATE:YYYYMMDD
      if (stripos($ln, 'DTSTART') === 0) {
        $parts = explode(':', $ln, 2);
        if (isset($parts[1])) $cur['start'] = self::ymdDashed($parts[1]);
        continue;
      }
      // DTEND;VALUE=DATE:YYYYMMDD
      if (stripos($ln, 'DTEND') === 0) {
        $parts = explode(':', $ln, 2);
        if (isset($parts[1])) $cur['end'] = self::ymdDashed($parts[1]);
        continue;
      }
      // STATUS:CANCELLED
      if (stripos($ln, 'STATUS:') === 0) {
        $cur['status'] = strtoupper(trim(substr($ln, 7)));
        continue;
      }
    }

    return $events;
  }

  private static function ymdDashed(string $yyyymmdd): string {
    $s = preg_replace('/[^0-9]/', '', $yyyymmdd);
    if (strlen($s) !== 8) return '';
    return substr($s,0,4).'-'.substr($s,4,2).'-'.substr($s,6,2);
  }
}
