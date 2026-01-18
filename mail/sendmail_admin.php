<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: mail/sendmail_admin.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * sendmail_admin.php
 * Admin reply endpoint:
 *  - Accepts POST: to, subject, body
 *  - Optional: cc, bcc
 *  - Logs to /app/common/data/query/outbox_YYYY-MM.ndjson
 *  - Tries /usr/sbin/sendmail; fallback to mail(); else DRY_RUN=true
 * LAN allowlist only (no password auth as per setup note).
 */
declare(strict_types=1);

function isAllowedIp(string $ip): bool {
  if ($ip === '127.0.0.1' || $ip === '::1') return true;
  // RFC1918 ranges
  if (preg_match('~^10\.~', $ip)) return true;
  if (preg_match('~^192\.168\.~', $ip)) return true;
  if (preg_match('~^172\.(1[6-9]|2[0-9]|3[0-1])\.~', $ip)) return true;
  return false;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!isAllowedIp($clientIp)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$baseDir = realpath(__DIR__ . '/../common/data/query') ?: (__DIR__ . '/../common/data/query');
@mkdir($baseDir, 0775, true);
$outFile = $baseDir . '/outbox_' . date('Y-m') . '.ndjson';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo "Method Not Allowed";
  exit;
}

$to      = trim((string)($_POST['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$body    = trim((string)($_POST['body'] ?? ''));
$cc      = trim((string)($_POST['cc'] ?? ''));
$bcc     = trim((string)($_POST['bcc'] ?? ''));

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
  header('Location: /app/admin/mailer.php?error=invalid');
  exit;
}

// Build headers
$fromEmail = 'matevz56c@gmail.com'; // sender display
$fromName  = 'Apartma Matevž';
$headers   = [];
$headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $fromEmail);
if ($cc  !== '') $headers[] = 'Cc: ' . $cc;
if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

// Try /usr/sbin/sendmail
$sent = false;
$method = 'dryrun';
$hdrStr = implode("\r\n", $headers);

if (is_executable('/usr/sbin/sendmail')) {
  $p = @popen('/usr/sbin/sendmail -t -i', 'w');
  if ($p) {
    fwrite($p, "To: {$to}\r\n{$hdrStr}\r\nSubject: {$subject}\r\n\r\n{$body}\r\n");
    $status = pclose($p);
    $sent = ($status === 0);
    $method = $sent ? 'sendmail' : 'sendmail_failed';
  }
}

if (!$sent) {
  // Fallback to mail() — may fail silently if no MTA; still log
  if (@mail($to, $subject, $body, $hdrStr)) {
    $sent = true;
    $method = 'mail';
  }
}

// Log to outbox NDJSON
$rec = [
  'ts'     => gmdate('c'),
  'ip'     => $clientIp,
  'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'to'     => $to,
  'cc'     => $cc,
  'bcc'    => $bcc,
  'subject'=> $subject,
  'body'   => $body,
  'method' => $method,
  'sent'   => $sent,
];
if ($fh = @fopen($outFile, 'ab')) {
  fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n");
  fclose($fh);
  @chmod($outFile, 0664);
}

$q = $sent ? 'sent=1' : 'sent=0';
header('Location: /app/admin/mailer.php?' . $q);
exit;
