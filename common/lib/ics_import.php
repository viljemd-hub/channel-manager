<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/ics_import.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

namespace App\ICS;

final class IcsImport {
  /** Vrne array dogodkov: [['start'=>'YYYY-MM-DD','end'=>'YYYY-MM-DD'], ...] */
  public static function parseAllDayRanges(string $ics): array {
    $events = [];
    $in = false;
    $cur = [];
    foreach (preg_split("/\r\n|\n|\r/", $ics) as $line) {
      $line = rtrim($line);
      if ($line === 'BEGIN:VEVENT') { $in = true; $cur = []; continue; }
      if ($line === 'END:VEVENT') { 
        if (!empty($cur['DTSTART']) && !empty($cur['DTEND'])) {
          $s = self::toYmd($cur['DTSTART']);
          $e = self::toYmd($cur['DTEND']);
          if ($s && $e) $events[] = ['start'=>$s, 'end'=>$e];
        }
        $in = false; $cur = []; continue; 
      }
      if (!$in) continue;

      if (stripos($line, 'DTSTART') === 0) {
        $cur['DTSTART'] = self::extractDateValue($line);
      } elseif (stripos($line, 'DTEND') === 0) {
        $cur['DTEND'] = self::extractDateValue($line);
      }
    }
    return $events;
  }

  private static function extractDateValue(string $line): ?string {
    // Podpiramo VALUE=DATE:YYYYMMDD in tudi DTSTART:YYYYMMDD (nekateri ICS-i)
    $parts = explode(':', $line, 2);
    if (count($parts) < 2) return null;
    $val = trim($parts[1]);
    // Odrežemo čas, pustimo YYYYMMDD
    $val = substr($val, 0, 8);
    return $val;
  }

  private static function toYmd(?string $yyyymmdd): ?string {
    if (!$yyyymmdd || !preg_match('/^\d{8}$/', $yyyymmdd)) return null;
    return substr($yyyymmdd,0,4).'-'.substr($yyyymmdd,4,2).'-'.substr($yyyymmdd,6,2);
  }
}
