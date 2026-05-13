<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/send_review_request.php
 * Purpose: Send a post-stay review link to the guest for a single reservation.
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_lib/json_io.php';          // cm_json_read, json_ok/json_err
require_once __DIR__ . '/_lib/paths.php';           // data_root() ...
require_once __DIR__ . '/../../common/lib/datetime_fmt.php';
require_once __DIR__ . '/../../common/lib/site_settings.php'; 
require_once __DIR__ . '/../../common/lib/reviews.php';
require_once __DIR__ . '/../../common/lib/email.php';

$APP       = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
$DATA_ROOT = $APP . '/common/data/json';
$RES_ROOT  = $DATA_ROOT . '/reservations';

$cfg = cm_datetime_cfg();
$tz  = $cfg['timezone'] ?? 'Europe/Ljubljana';

function respond(bool $ok, array $payload = [], int $http = 200): void {
    http_response_code($http);
    $payload['ok'] = $ok;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Find reservation JSON file by ID (same search pattern as cancel_reservation).
 */
function find_reservation_file(string $root, string $id): ?string {
    $pattern = rtrim($root, '/') . '/*/*/' . $id . '.json';
    $files   = glob($pattern, GLOB_NOSORT);
    if (!$files) {
        return null;
    }
    // Prefer the first match; IDs are unique so there should be only one.
    return $files[0];
}

/**
 * Build simple localised text map for review request e-mail.
 */
function cm_review_mail_text(string $key, string $lang): string {
    static $MAP = [
        'subject' => [
            'sl' => 'Prošnja za mnenje o vašem bivanju',
            'en' => 'Please rate your recent stay',
        ],
        'hello' => [
            'sl' => 'Pozdravljeni',
            'en' => 'Hello',
        ],
        'intro' => [
            'sl' => 'veseli smo, da ste bivali pri nas. Veseli bomo, če si vzamete minuto in delite svoje mnenje.',
            'en' => 'thank you for staying with us. We would really appreciate it if you could take a minute and share your experience.',
        ],
        'cta' => [
            'sl' => 'Odprite spodnjo povezavo in oddajte ali uredite svoje mnenje:',
            'en' => 'Please use the link below to submit or update your review:',
        ],
        'expiry' => [
            'sl' => 'Povezava za oddajo mnenja je veljavna 30 dni po vašem odhodu.',
            'en' => 'The review link is valid for 30 days after your departure.',
        ],
        'bye' => [
            'sl' => 'Hvala in lep pozdrav',
            'en' => 'Thank you and kind regards',
        ],
    ];

    if (isset($MAP[$key][$lang])) {
        return $MAP[$key][$lang];
    }
    // Fallback: English, then key
    if (isset($MAP[$key]['en'])) {
        return $MAP[$key]['en'];
    }
    return $key;
}

// -----------------------------------------------------------------------------
// Input validation
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(false, ['error' => 'invalid_json'], 400);
}

$id = trim((string)($data['id'] ?? ''));
if ($id === '' || !preg_match('/^\d{14}-[0-9a-f]{4}-[A-Z0-9]+$/', $id)) {
    respond(false, ['error' => 'invalid_id_format'], 400);
}

// -----------------------------------------------------------------------------
// Load reservation
// -----------------------------------------------------------------------------
$resFile = find_reservation_file($RES_ROOT, $id);
if ($resFile === null) {
    respond(false, ['error' => 'reservation_not_found', 'id' => $id], 404);
}

$res = cm_json_read($resFile);
if (!is_array($res)) {
    respond(false, ['error' => 'invalid_reservation_json', 'file' => $resFile], 500);
}

// Only confirmed reservations should get review links
$status = (string)($res['status'] ?? '');
if ($status !== 'confirmed') {
    respond(false, ['error' => 'not_confirmed', 'status' => $status], 400);
}

// Guest e-mail
$guest = $res['guest'] ?? [];
$to    = trim((string)($guest['email'] ?? ''));
$unit  = (string)($res['unit'] ?? '');
$fromDate = (string)($res['from'] ?? '');
$toDate   = (string)($res['to'] ?? '');
$resId    = (string)($res['id'] ?? $id);

if ($to === '') {
    respond(false, ['error' => 'missing_guest_email'], 400);
}

$guestName = trim((string)($guest['name'] ?? ''));
$lang      = 'sl';
if (isset($res['lang'])) {
    $lang = (string)$res['lang'];
} elseif (isset($res['meta']['lang'])) {
    $lang = (string)$res['meta']['lang'];
}
if (!in_array($lang, ['sl', 'en'], true)) {
    $lang = 'sl';
}

// -----------------------------------------------------------------------------
// Build review link (30 days after departure)
// -----------------------------------------------------------------------------
$toDate = (string)($res['to'] ?? '');
try {
    $dtTz = new DateTimeZone($tz);
} catch (Throwable $e) {
    $dtTz = new DateTimeZone('Europe/Ljubljana');
}

if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $baseDate = new DateTimeImmutable($toDate . ' 12:00:00', $dtTz);
} else {
    $baseDate = new DateTimeImmutable('now', $dtTz);
}

// CM_REVIEW_TOKEN_VALID_DAYS is defined in common/lib/reviews.php
$validDays = defined('CM_REVIEW_TOKEN_VALID_DAYS') ? (int)CM_REVIEW_TOKEN_VALID_DAYS : 30;
$expires   = $baseDate->modify('+' . $validDays . ' days')->setTime(23, 59, 59)->getTimestamp();

// Base URL for public side (handles /app in path via settings)
$baseUrl = cm_public_base_url();

// Build review token (reservation ID + expiry timestamp) and final URL
$token     = cm_review_build_token($id, $expires);
$reviewUrl = cm_review_build_link($baseUrl, $id, $token);
// -----------------------------------------------------------------------------
// Build e-mail
// -----------------------------------------------------------------------------
$subject = cm_review_mail_text('subject', $lang);

$hello = cm_review_mail_text('hello', $lang);
if ($guestName !== '') {
    $hello .= ' ' . $guestName;
}
$intro  = cm_review_mail_text('intro', $lang);
$cta    = cm_review_mail_text('cta', $lang);
$expiry = cm_review_mail_text('expiry', $lang);
$bye    = cm_review_mail_text('bye', $lang);

$h = static fn(string $s): string =>
    htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$bodyHtml  = '<p>' . $h($hello) . '.</p>';
$bodyHtml .= '<p>' . $h(ucfirst($intro)) . '</p>';

$bodyHtml .= '<ul>';
$bodyHtml .= '<li><b>Rezervacija:</b> ' . $h($resId) . '</li>';
if ($unit !== '') {
    $bodyHtml .= '<li><b>Enota:</b> ' . $h($unit) . '</li>';
}
if ($fromDate !== '' && $toDate !== '') {
    $bodyHtml .= '<li><b>Termin:</b> ' . $h($fromDate) . ' – ' . $h($toDate) . '</li>';
}
$bodyHtml .= '</ul>';

$bodyHtml .= '<p>' . $h($cta) . '</p>';
$bodyHtml .= '<p><a href="' . $h($reviewUrl) . '" target="_blank" rel="noopener">'
          . $h($lang === 'sl' ? 'Odpri obrazec za mnenje' : 'Open review form')
          . '</a></p>';

$bodyHtml .= '<p><small>' . $h($expiry) . '</small></p>';
$bodyHtml .= '<p>' . $h($bye) . '</p>';

$bodyHtml .= '<p style="font-size:11px;color:#666;">'
          . $h($lang === 'sl'
              ? 'To sporočilo ste prejeli, ker ste bivali pri nas in ste ob rezervaciji vnesli ta e-poštni naslov. Če menite, da je bilo sporočilo poslano pomotoma, ga lahko preprosto ignorirate.'
              : 'You are receiving this message because you stayed with us and used this e-mail address when booking. If you believe this message was sent in error, you can safely ignore it.')
          . '</p>';

$bodyText =
    $hello . ".\n\n" .
    ucfirst($intro) . "\n\n" .
    "Rezervacija: " . $resId . "\n" .
    ($unit !== '' ? "Unit: " . $unit . "\n" : "") .
    (($fromDate !== '' && $toDate !== '') ? "Dates: {$fromDate} – {$toDate}\n" : "") .
    "\n" .
    $cta . "\n" .
    $reviewUrl . "\n\n" .
    $expiry . "\n\n" .
    $bye . "\n\n" .
    ($lang === 'sl'
        ? "To sporočilo ste prejeli, ker ste bivali pri nas in ste ob rezervaciji vnesli ta e-poštni naslov. Če menite, da je bilo sporočilo poslano pomotoma, ga lahko ignorirate.\n"
        : "You are receiving this message because you stayed with us and used this e-mail address when booking. If you believe this message was sent in error, you can safely ignore it.\n");


// From e-mail: use site settings / license settings
$settings = cm_load_settings();
$emailCfg = $settings['email'] ?? [];
$fromEmail = trim((string)($emailCfg['from_email'] ?? 'no-reply@localhost'));
$fromName  = trim((string)($emailCfg['from_name']  ?? 'Rezervacije'));

// Use unified mail wrapper: cm_send_email([...]) -> bool
$sentOk = cm_send_email([
    // arrays of recipients (API expects arrays)
    'to'        => [$to],
    'cc'        => [],
    'bcc'       => [],

    // content
    'subject'   => $subject,
    'html'      => $bodyHtml,
    'text'      => $bodyText,

    // sender / reply-to
    'from'      => $fromEmail,
    'from_name' => $fromName,
    'reply_to'  => $fromEmail,
]);

if (!$sentOk) {
    respond(false, ['error' => 'send_failed'], 500);
}



// Optional: lightweight JSON audit log (kept outside reservations to avoid UI mixing)
try {
    // Keep review logs in a dedicated folder: common/data/json/reviews/logs/<YEAR>/
    $year = '';
    if ($toDate !== '' && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $toDate, $m)) {
        $year = $m[1];
    } else {
        $year = (new DateTimeImmutable('now', $dtTz))->format('Y');
    }

    $logsDir = $DATA_ROOT . '/reviews/logs/' . $year;
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }

    // One file per reservation ID, clearly marked as a log file:
    // e.g. 20260202134633-b136-A1.review_log.json
    $logPath = rtrim($logsDir, '/') . '/' . $id . '.review_log.json';

    $log = [];
    if (is_file($logPath)) {
        $tmp = json_decode((string)file_get_contents($logPath), true);
        if (is_array($tmp)) { $log = $tmp; }
    }

    $log[] = [
        'sent_at' => (new DateTimeImmutable('now', $dtTz))->format('c'),
        'to'      => $to,
        'lang'    => $lang,
        'url'     => $reviewUrl,
    ];

    @file_put_contents(
        $logPath,
        json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
} catch (Throwable $e) {
    // non-fatal
    error_log('[send_review_request] failed to write review_log for ' . $id . ': ' . $e->getMessage());
}


respond(true, [
    'id'         => $id,
    'email'      => $to,
    'review_url' => $reviewUrl,
    'expires'    => $expires,
]);
 
