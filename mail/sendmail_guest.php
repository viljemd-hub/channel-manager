<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: mail/sendmail_guest.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/mail/sendmail_guest.php
// Pošiljanje sporočil iz forme v index.html - Apartmamatevž
declare(strict_types=1);
mb_internal_encoding('UTF-8');

/**
 * Preprost kontaktni handler za index.html
 * - bere POST iz obrazca
 * - preveri obvezna polja
 * - uporabi honeypot "website"
 * - pošlje mail lastniku
 * - preusmeri nazaj na /?sent=1 ali /?error=...
 */

function post_field(string $key): string {
    return trim($_POST[$key] ?? '');
}

// Dovolimo samo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?error=method');
    exit;
}

// Honeypot: če je skrit "website" izpolnjen, se pretvarjamo, da je vse OK
$website = post_field('website');
if ($website !== '') {
    header('Location: /?sent=1');
    exit;
}

// Polja iz obrazca
$name      = post_field('name');
$email     = post_field('email');
$message   = post_field('message');
$arrival   = post_field('arrival');
$departure = post_field('departure');
$phone     = post_field('phone');

// Zaščita pred header injection v poljih
$name  = str_replace(["\r", "\n"], ' ', $name);
$email = str_replace(["\r", "\n"], '', $email);

// Obvezna polja
if ($name === '' || $email === '' || $message === '') {
    header('Location: /?error=missing');
    exit;
}

// Validacija e-pošte
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?error=email');
    exit;
}

// Sestavimo vsebino sporočila
$lines   = [];
$lines[] = 'Novo sporočilo s kontaktnega obrazca na apartmamatevz.duckdns.org';
$lines[] = str_repeat('-', 61);
$lines[] = 'Ime:       ' . $name;
$lines[] = 'E-pošta:   ' . $email;

if ($phone !== '') {
    $lines[] = 'Telefon:   ' . $phone;
}
if ($arrival !== '') {
    $lines[] = 'Prihod:    ' . $arrival;
}
if ($departure !== '') {
    $lines[] = 'Odhod:     ' . $departure;
}

$lines[] = '';
$lines[] = 'Sporočilo:';
$lines[] = $message;
$lines[] = '';
$lines[] = 'IP obiskovalca: ' . ($_SERVER['REMOTE_ADDR'] ?? 'neznan');

$body = implode("\n", $lines);

// Kam pošljemo
$to       = 'viljem.d@gmail.com'; // lahko zamenjaš v svoj glavni naslov
$subject  = 'Novo povpraševanje – Apartma Matevž';
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

// Glave
$headers   = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'From: "Apartma Matevž" <no-reply@apartmamatevz.duckdns.org>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$ok = @mail(
    $to,
    $encodedSubject,
    $body,
    implode("\r\n", $headers)
);

// Preprosto logiranje (ne kritično, če ne uspe)
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logLine = sprintf(
    "[%s] %s | %s | %s–%s | %s | %s\n",
    date('Y-m-d H:i:s'),
    $name,
    $email,
    $arrival ?: '-',
    $departure ?: '-',
    $phone ?: '-',
    $ok ? 'OK' : 'FAIL'
);
@file_put_contents($logDir . '/contact_form.log', $logLine, FILE_APPEND);

if ($ok) {
    header('Location: /?sent=1');
} else {
    header('Location: /?error=send');
}
exit;
