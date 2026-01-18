<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/ics_parse.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

namespace App\ICS;

/**
 * Minimal ICS parser za VEVENT-e.
 * Podpira DTSTART/DTEND (VALUE=DATE ali z UTC/tz), SUMMARY.
 * Vrne: [ [ 'start'=>'YYYY-MM-DD', 'end'=>'YYYY-MM-DD', 'summary'=>'...' ], ... ]
 */
final class IcsParse {
  public static function parseFile(string $path): array {
    if (!is_file($path)) return [];
    $raw = (string)file_get_contents($path);
    return self::parseString($raw);
  }

  public static function parseString(string $ics): array {
    // Normalize CRLF
    $ics = str_replace("\r\n", "\n", $ics);
    // Unfold lines (RFC: lines starting with space are continuations)
    $lines = [];
    foreach (explode("\n", $ics) as $ln) {
      if ($ln !== '' && isset($lines[count($lines)-1]) && strlen($ln)>0 && $ln[0]===' ') {
        $lines[count($lines)-1] .= substr($ln, 1);
      } else {
        $lines[] = $ln;
      }
    }

    $events = [];
    $inEvent = false;
    $cur = ['DTSTART'=>null,'DTEND'=>null,'SUMMARY'=>''];
    foreach ($lines as $ln) {
      if (strncmp($ln, 'BEGIN:VEVENT', 12) === 0) {
        $inEvent = true; $cur = ['DTSTART'=>null,'DTEND'=>null,'SUMMARY'=>''];
        continue;
      }
      if (strncmp($ln, 'END:VEVENT', 10) === 0) {
        if ($inEvent && $cur['DTSTART'] && $cur['DTEND']) {
          $events[] = [
            'start' => self::toYmd($cur['DTSTART']),
            'end'   => self::toYmd($cur['DTEND']),
            'summary' => $cur['SUMMARY'] ?? ''
          ];
        }
        $inEvent = false; $cur = ['DTSTART'=>null,'DTEND'=>null,'SUMMARY'=>''];
        continue;
      }
      if (!$inEvent) continue;

      // Key may have params, e.g., DTSTART;VALUE=DATE:
      if (stripos($ln, 'DTSTART') === 0) {
        $cur['DTSTART'] = self::extractValue($ln);
      } elseif (stripos($ln, 'DTEND') === 0) {
        $cur['DTEND'] = self::extractValue($ln);
      } elseif (stripos($ln, 'SUMMARY:') === 0) {
        $cur['SUMMARY'] = substr($ln, 8);
      }
    }
    // Filter invalids
    return array_values(array_filter($events, fn($e) => $e['start'] && $e['end']));
  }

  private static function extractValue(string $line): string {
    $pos = strpos($line, ':');
    if ($pos === false) return '';
    return trim(substr($line, $pos+1));
  }

  private static function toYmd(string $v): string {
    // Accept YYYYMMDD or YYYYMMDDTHHMMSS(Z)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) {
      return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    // Already YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    return '';
  }
}
