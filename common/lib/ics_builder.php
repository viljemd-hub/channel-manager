<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/ics_builder.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * ICS builder – all-day events (VALUE=DATE), end-exclusive.
 * No PII in SUMMARY/DESCRIPTION.
 */

namespace App\ICS;

final class IcsBuilder {
  private string $prodId;
  private string $calName;
  private array $lines = [];

  public function __construct(string $prodId = '-//ChannelManager//ICS 1.0//EN', string $calName = 'Reservations') {
    $this->prodId = $prodId;
    $this->calName = $calName;
    $this->lines = [];
  }

  public static function fold(string $line): string {
    // Fold at 75 octets per RFC; here: 73 chars + CRLF + space continuation
    $out = '';
    $len = strlen($line);
    $pos = 0;
    while ($pos < $len) {
      $chunk = substr($line, $pos, 73);
      $pos += 73;
      $out .= $chunk;
      if ($pos < $len) $out .= "\r\n ";
    }
    return $out;
  }

  public static function esc(string $s): string {
    // Escape per RFC: backslash, comma, semicolon, newline
    $s = str_replace("\\", "\\\\", $s);
    $s = str_replace("\n", "\\n", $s);
    $s = str_replace("\r", "", $s);
    $s = str_replace(",", "\\,", $s);
    $s = str_replace(";", "\\;", $s);
    return $s;
  }

  public static function ymd(string $date): string {
    // Expecting YYYY-MM-DD → YYYYMMDD
    return preg_replace('/[^0-9]/', '', $date);
  }

  public function begin(): void {
    $this->lines[] = "BEGIN:VCALENDAR";
    $this->lines[] = "PRODID:".$this->prodId;
    $this->lines[] = "VERSION:2.0";
    $this->lines[] = "CALSCALE:GREGORIAN";
    $this->lines[] = "METHOD:PUBLISH";
    $this->lines[] = "X-WR-CALNAME:".self::esc($this->calName);
  }

  public function addAllDayEvent(array $e): void {
    // Required keys: uid, dtstamp (UTC, Ymd\THis\Z), start (YYYY-MM-DD), end (YYYY-MM-DD), summary
    // Optional: description, sequence (int), status (e.g., CANCELLED), categories
    $uid   = $e['uid'] ?? '';
    $stamp = $e['dtstamp'] ?? gmdate('Ymd\THis\Z');
    $start = $e['start'] ?? '';
    $end   = $e['end'] ?? '';
    $sum   = $e['summary'] ?? 'Booking';
    $desc  = $e['description'] ?? '';
    $seq   = (int)($e['sequence'] ?? 0);
    $stat  = $e['status'] ?? '';
    $cats  = $e['categories'] ?? '';

    $this->lines[] = "BEGIN:VEVENT";
    $this->lines[] = "UID:".self::esc($uid);
    $this->lines[] = "DTSTAMP:".$stamp;
    $this->lines[] = "DTSTART;VALUE=DATE:".self::ymd($start);
    $this->lines[] = "DTEND;VALUE=DATE:".self::ymd($end);
    $this->lines[] = self::fold("SUMMARY:".self::esc($sum));
    if ($desc !== '') $this->lines[] = self::fold("DESCRIPTION:".self::esc($desc));
    if ($cats !== '') $this->lines[] = "CATEGORIES:".self::esc($cats);
    if ($seq > 0)     $this->lines[] = "SEQUENCE:".$seq;
    if ($stat !== '') $this->lines[] = "STATUS:".$stat; // e.g., CANCELLED
    $this->lines[] = "END:VEVENT";
  }

  public function end(): void {
    $this->lines[] = "END:VCALENDAR";
  }

  public function render(): string {
    return implode("\r\n", $this->lines)."\r\n";
  }
}
