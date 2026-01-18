<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/email.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/**
 * Legacy helper: sends one message to a single recipient via msmtp.
 * Requirement: msmtp configured with account "default".
 *
 * Returns a structured result array: ['ok'=>bool, ...]
 */
function cm_send_email_msmtp(
  string $toEmail,
  string $subject,
  string $htmlBody,
  string $textBody,
  string $fromEmail,
  string $fromName = ''
): array {
  $fromName = $fromName !== '' ? $fromName : $fromEmail;

  // Build MIME (simple multipart/alternative)
  $boundary = 'b_' . bin2hex(random_bytes(8));

  // NOTE: This legacy function always includes "To:" header for the single recipient.
  $headers =
    "From: {$fromName} <{$fromEmail}>\r\n" .
    "To: <{$toEmail}>\r\n" .
    "Subject: {$subject}\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

  $body =
    "--{$boundary}\r\n" .
    "Content-Type: text/plain; charset=UTF-8\r\n" .
    "Content-Transfer-Encoding: 8bit\r\n\r\n" .
    $textBody . "\r\n\r\n" .
    "--{$boundary}\r\n" .
    "Content-Type: text/html; charset=UTF-8\r\n" .
    "Content-Transfer-Encoding: 8bit\r\n\r\n" .
    $htmlBody . "\r\n\r\n" .
    "--{$boundary}--\r\n";

  // Send via msmtp (stdin). Envelope recipient is passed as argv (NOT via headers).
  $cmd = 'msmtp -a default -- ' . escapeshellarg($toEmail);
  $descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $process = proc_open($cmd, $descriptorspec, $pipes);
  if (!is_resource($process)) {
    return ['ok'=>false, 'error'=>'msmtp_proc_open_failed'];
  }

  fwrite($pipes[0], $headers . "\r\n" . $body);
  fclose($pipes[0]);

  $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);

  $code = proc_close($process);
  if ($code !== 0) {
    return ['ok'=>false, 'error'=>'msmtp_failed', 'code'=>$code, 'stderr'=>$stderr, 'stdout'=>$stdout];
  }
  return ['ok'=>true, 'stdout'=>$stdout];
}

/* -------------------------------------------------------------------------------------------------
 * New unified wrapper: cm_send_email([...]) -> bool
 * -------------------------------------------------------------------------------------------------
 * Why:
 *  - Stabilize "mail API" across the project (Free/Plus)
 *  - Support to/cc/bcc/replyTo
 *  - Keep BCC as envelope recipients ONLY (no Bcc: header) so guests never see it
 */

/** @return array<int,string> */
function cm_email_list($value): array {
  if ($value === null || $value === false) return [];
  if (is_string($value)) {
    $value = trim($value);
    if ($value === '') return [];
    // allow comma-separated list
    $parts = preg_split('/\s*,\s*/', $value) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
  }
  if (is_array($value)) {
    $out = [];
    foreach ($value as $v) {
      if (!is_string($v)) continue;
      $v = trim($v);
      if ($v !== '') $out[] = $v;
    }
    return array_values($out);
  }
  return [];
}

function cm_email_is_valid(string $email): bool {
  $email = trim($email);
  if ($email === '') return false;
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** @param array<int,string> $emails */
function cm_email_filter_valid(array $emails): array {
  $out = [];
  foreach ($emails as $e) {
    $e = trim($e);
    if ($e === '') continue;
    if (!cm_email_is_valid($e)) continue;
    $out[] = $e;
  }
  // unique, preserve order
  $uniq = [];
  foreach ($out as $e) {
    if (!in_array($e, $uniq, true)) $uniq[] = $e;
  }
  return $uniq;
}

/** @param array<int,string> $emails */
function cm_email_header_list(array $emails): string {
  $emails = cm_email_filter_valid($emails);
  $parts = [];
  foreach ($emails as $e) {
    $parts[] = "<{$e}>";
  }
  return implode(', ', $parts);
}

function cm_email_clean_header_value(string $s): string {
  // prevent header injection
  $s = str_replace(["\r", "\n"], ' ', $s);
  return trim($s);
}

function cm_email_encode_subject(string $subject): string {
  $subject = cm_email_clean_header_value($subject);
  if ($subject === '') return '(no subject)';
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
  }
  return $subject;
}

/**
 * Low-level sender: uses msmtp with explicit envelope recipients.
 *
 * @param array<int,string> $envelopeRecipients (to+cc+bcc), must be valid emails
 * @return array{ok:bool,error?:string,code?:int,stderr?:string,stdout?:string}
 */
function cm_send_msmtp_envelope(array $envelopeRecipients, string $headers, string $body): array {
  $envelopeRecipients = cm_email_filter_valid($envelopeRecipients);
  if (!$envelopeRecipients) {
    return ['ok' => false, 'error' => 'no_recipients'];
  }

  $args = array_map('escapeshellarg', $envelopeRecipients);
  $cmd = 'msmtp -a default -- ' . implode(' ', $args);

  $descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $process = proc_open($cmd, $descriptorspec, $pipes);
  if (!is_resource($process)) {
    return ['ok'=>false, 'error'=>'msmtp_proc_open_failed'];
  }

  fwrite($pipes[0], $headers . "\r\n" . $body);
  fclose($pipes[0]);

  $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);

  $code = proc_close($process);
  if ($code !== 0) {
    return ['ok'=>false, 'error'=>'msmtp_failed', 'code'=>$code, 'stderr'=>$stderr, 'stdout'=>$stdout];
  }

  return ['ok'=>true, 'stdout'=>$stdout];
}

/**
 * Unified mail wrapper.
 *
 * Expected keys:
 *  - to (string|array)   REQUIRED (at least one)
 *  - cc (string|array)   optional
 *  - bcc (string|array)  optional (NO "Bcc:" header will be added!)
 *  - from (string)       REQUIRED
 *  - fromName (string)   optional
 *  - replyTo (string)    optional
 *  - subject (string)    REQUIRED
 *  - html (string)       optional
 *  - text (string)       optional
 */
function cm_send_email(array $opts): bool {
  $res = cm_send_email_ex($opts);
  return (bool)($res['ok'] ?? false);
}

/**
 * Same as cm_send_email(), but returns debug info.
 *
 * @return array{ok:bool,error?:string,code?:int,stderr?:string,stdout?:string}
 */
function cm_send_email_ex(array $opts): array {
  $to      = cm_email_filter_valid(cm_email_list($opts['to'] ?? null));
  $cc      = cm_email_filter_valid(cm_email_list($opts['cc'] ?? null));
  $bcc     = cm_email_filter_valid(cm_email_list($opts['bcc'] ?? null));

  $from    = isset($opts['from']) ? trim((string)$opts['from']) : '';
  $fromNm  = isset($opts['fromName']) ? trim((string)$opts['fromName']) : '';
  $replyTo = isset($opts['replyTo']) ? trim((string)$opts['replyTo']) : '';
  $subject = isset($opts['subject']) ? (string)$opts['subject'] : '';

  $html    = (string)($opts['html'] ?? '');
  $text    = (string)($opts['text'] ?? '');

  if ($from === '' || !cm_email_is_valid($from)) {
    return ['ok'=>false, 'error'=>'invalid_from'];
  }
  if (!$to && !$cc && !$bcc) {
    return ['ok'=>false, 'error'=>'no_recipients'];
  }
  if ($subject === '') {
    return ['ok'=>false, 'error'=>'missing_subject'];
  }
  if ($html === '' && $text === '') {
    // Avoid sending empty messages
    return ['ok'=>false, 'error'=>'missing_body'];
  }

  $fromNm = $fromNm !== '' ? cm_email_clean_header_value($fromNm) : $from;
  $subjectHdr = cm_email_encode_subject($subject);

  // Visible headers
  $headers = '';
  $headers .= "From: {$fromNm} <{$from}>\r\n";
  if ($to) {
    $headers .= "To: " . cm_email_header_list($to) . "\r\n";
  } else {
    // fallback when only cc/bcc exists
    $headers .= "To: <{$from}>\r\n";
  }
  if ($cc) {
    $headers .= "Cc: " . cm_email_header_list($cc) . "\r\n";
  }
  if ($replyTo !== '' && cm_email_is_valid($replyTo)) {
    $headers .= "Reply-To: <" . cm_email_clean_header_value($replyTo) . ">\r\n";
  }
  $headers .= "Subject: {$subjectHdr}\r\n";
  $headers .= "MIME-Version: 1.0\r\n";

  // Build body (multipart/alternative when both are present)
  $body = '';
  if ($html !== '' && $text !== '') {
    $boundary = 'b_' . bin2hex(random_bytes(8));
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body =
      "--{$boundary}\r\n" .
      "Content-Type: text/plain; charset=UTF-8\r\n" .
      "Content-Transfer-Encoding: 8bit\r\n\r\n" .
      $text . "\r\n\r\n" .
      "--{$boundary}\r\n" .
      "Content-Type: text/html; charset=UTF-8\r\n" .
      "Content-Transfer-Encoding: 8bit\r\n\r\n" .
      $html . "\r\n\r\n" .
      "--{$boundary}--\r\n";
  } elseif ($html !== '') {
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $body = $html . "\r\n";
  } else {
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $body = $text . "\r\n";
  }

  // Envelope recipients: to + cc + bcc
  $envelope = array_merge($to, $cc, $bcc);

  // IMPORTANT: we do NOT add "Bcc:" header, by design.
  return cm_send_msmtp_envelope($envelope, $headers, $body);
}
