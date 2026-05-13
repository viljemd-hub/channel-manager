<?php
declare(strict_types=1);
require_once __DIR__ . '/email.php';  

// <- dodaj to vrstico
// IMPORTANT: Do NOT include datetime_fmt.php here, it would redeclare cm_public_base_url().
// We keep this file self-contained and public-safe, and only rely on:
//   - __DIR__ relative JSON paths
//   - optional cm_settings_load() if it already exists (admin context)
//   - cm_send_email() from common/lib/email.php when available.

/**
 * AI + heuristic analysis module for review moderation (CM PRO)
 *
 * reviews.php will include this file and call cm_review_analyze_text().
 */

/**
 * Analyze rating + text → return moderation attributes.
 *
 * Returns array:
 *  - status:       approved|pending|quarantine
 *  - is_flagged:   bool
 *  - flag_reason:  string
 *  - risk_score:   int 0–100
 *  - toxicity:     float 0–1
 *  - ai_category:  string
 *  - ai_decision:  approve|quarantine|manual_review
 *  - processed_at: ISO datetime
 *  - sentiment:    float -1..1
 *
 * @param int    $rating 1–5 stars
 * @param string $text   review text
 * @return array<string,mixed>
 */
function cm_review_analyze_text(int $rating, string $text): array
{
    $res = [
        'status'       => 'pending',
        'is_flagged'   => false,
        'flag_reason'  => '',
        'risk_score'   => 0,
        'toxicity'     => 0.0,
        'ai_category'  => '',
        'ai_decision'  => 'manual_review',
        'sentiment'    => 0.0,
        'processed_at' => date('c'),
    ];

    // -------------------------
    // 1) HEURISTIC SCORE
    // -------------------------
    $risk  = 0;
    $lower = mb_strtolower($text, 'UTF-8');

    // Rating-based risk
    if ($rating <= 2) {
        $risk += 35;
    } elseif ($rating === 3) {
        $risk += 15;
    }

    // Length-based risk
    if (mb_strlen($text) > 400) {
        $risk += 10;
    }

    // Simple Slovene/EN "bad words" (rough)
    $badWords = [
        'idiot','kreten','smrd','pizd','kurc','lopov','goljuf',
        'fašist','rasist','primitiv','debii','bedak',
        'fuck','shit','bastard','moron',
    ];
    foreach ($badWords as $bw) {
        if (str_contains($lower, $bw)) {
            $risk += 40;
        }
    }

    // CAPS abuse
    if (preg_match('/[A-Z]{5,}/', $text)) {
        $risk += 15;
    }

    $res['risk_score'] = min($risk, 100);

    // Fast-path approval: low risk + high rating → skip AI
    if ($risk < 20 && $rating >= 4) {
        $res['status']      = 'approved';
        $res['ai_decision'] = 'approve';
        return $res;
    }

    // -------------------------
    // 2) AI CLASSIFICATION (GROQ)
    // -------------------------
    $ai = cm_review_ai_classify($text);

    $res['toxicity']    = $ai['toxicity'];
    $res['ai_category'] = $ai['category'];
    $res['ai_decision'] = $ai['decision'];
    $res['flag_reason'] = $ai['category'];
    $res['sentiment']   = $ai['sentiment'];

    // -------------------------
    // 3) FINAL MERGE LOGIC
    // -------------------------

    // Hard quarantine
    if ($ai['decision'] === 'quarantine') {
        $res['status']     = 'quarantine';
        $res['is_flagged'] = true;
        return $res;
    }

    // Approve if AI approves AND risk not too high
    if ($ai['decision'] === 'approve' && $risk < 50) {
        $res['status'] = 'approved';
        return $res;
    }

    // Otherwise pending + flagged when risk high
    $res['status']     = 'pending';
    $res['is_flagged'] = $risk >= 50;

    return $res;
}

/**
 * REAL AI CLASSIFICATION (GROQ LLaMA 3.1)
 *
 * Returns:
 *  - category:  ok|complaint|insult|hate|spam
 *  - toxicity:  float 0–1
 *  - sentiment: float -1..1
 *  - decision:  approve|quarantine|manual_review
 *
 * @param string $text
 * @return array<string,mixed>
 */
function cm_review_ai_classify(string $text): array
{
    // Load AI settings (admin OR public)
    $settings = [];

    if (function_exists('cm_settings_load')) {
        // Admin/backend context – use central loader
        $settings = cm_settings_load();
    } else {
        // Public context – read global site_settings.json directly
        $file = __DIR__ . '/../data/json/site_settings.json';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        }
    }

    $ai      = $settings['ai'] ?? [];
    $enabled = (bool)($ai['enabled'] ?? false);

    // AI disabled → safe defaults
    if (!$enabled || empty($ai['groq_key'])) {
        return [
            'category'  => 'ok',
            'toxicity'  => 0.1,
            'sentiment' => 0.0,
            'decision'  => 'approve',
        ];
    }

    $key = trim((string)$ai['groq_key']);
    if ($key === '') {
        return [
            'category'  => 'ok',
            'toxicity'  => 0.1,
            'sentiment' => 0.0,
            'decision'  => 'approve',
        ];
    }

    $url = 'https://api.groq.com/openai/v1/chat/completions';

    $payload = [
        'model'    => 'llama-3.1-8b-instant',
        'messages' => [
            [
                'role'    => 'user',
                'content' =>
                    "Analyze this review. Respond ONLY as JSON:\n" .
                    "{\n" .
                    "  \"category\": \"ok|complaint|insult|hate|spam\",\n" .
                    "  \"toxicity\": 0.0-1.0,\n" .
                    "  \"sentiment\": -1.0-1.0\n" .
                    "}\n\n" .
                    "TEXT: \"$text\"",
            ],
        ],
    ];

    $res = cm_ai_http_post_groq($url, $payload, [
        "Authorization: Bearer $key",
        "Content-Type: application/json",
    ]);

    $out = [
        'category'  => 'ok',
        'toxicity'  => 0.1,
        'sentiment' => 0.0,
        'decision'  => 'approve',
    ];

    if (!is_array($res) || empty($res['ok'])) {
        return $out;
    }

    $raw     = json_decode((string)($res['body'] ?? ''), true);
    $content = $raw['choices'][0]['message']['content'] ?? '{}';

    $jsonStart = strpos($content, '{');
    $jsonEnd   = strrpos($content, '}');
    if ($jsonStart !== false && $jsonEnd !== false) {
        $content = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return $out;
    }

    $out['category']  = $parsed['category']  ?? 'ok';
    $out['toxicity']  = (float)($parsed['toxicity']  ?? 0.1);
    $out['sentiment'] = (float)($parsed['sentiment'] ?? 0.0);

    if (
        in_array($out['category'], ['insult', 'hate', 'spam'], true) ||
        $out['toxicity'] >= 0.70
    ) {
        $out['decision'] = 'quarantine';
    } else {
        $out['decision'] = 'approve';
    }

    return $out;
}

/**
 * Simple HTTP POST helper for GROQ.
 *
 * @param string              $url
 * @param array<string,mixed> $payload
 * @param string[]            $headers
 * @return array{ok:bool, body?:string, error?:string}
 */
function cm_ai_http_post_groq(string $url, array $payload, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($err || $code >= 400) {
        return ['ok' => false, 'error' => $err ?: ("HTTP " . $code), 'body' => (string)$body];
    }

    return ['ok' => true, 'body' => (string)$body];
}

/**
 * Notify guest that their review was quarantined by the automatic safety filter.
 *
 * PUBLIC-SAFE IMPLEMENTATION:
 *  - DOES NOT use CM_DATA_DIR or cm_load_settings()
 *  - reads reservation directly from common/data/json/reservations/YYYY/UNIT/ID.json
 *  - reads email config from common/data/json/site_settings.json
 *  - sends mail via cm_send_email([...]) if available
 *
 * @param array<string,mixed> $review
 * @return void
 */
function cm_review_notify_guest_quarantine(array $review): void
{
    // 1) Basic reservation id from review meta
    $reservationId = $review['reservation_id'] ?? null;
    if (!is_string($reservationId) || $reservationId === '') {
        error_log('[reviews_ai] quarantine: missing reservation_id in review meta');
        return;
    }

    // Format: YYYYMMDDHHMMSS-xxxx-UNIT
    $parts = explode('-', $reservationId);
    if (count($parts) < 3) {
        error_log('[reviews_ai] quarantine: invalid reservation_id format: ' . $reservationId);
        return;
    }

    [$datePart, $_hex, $unit] = $parts;
    $year = substr($datePart, 0, 4);
    if (!preg_match('/^[0-9]{4}$/', $year)) {
        error_log('[reviews_ai] quarantine: invalid year in reservation_id: ' . $reservationId);
        return;
    }

    // 2) Build reservation JSON path WITHOUT CM_DATA_DIR
    $resRoot = __DIR__ . '/../data/json/reservations';
    $resFile = $resRoot . '/' . $year . '/' . $unit . '/' . $reservationId . '.json';

    if (!is_file($resFile)) {
        error_log('[reviews_ai] quarantine: reservation file not found: ' . $resFile);
        return;
    }

    $raw = @file_get_contents($resFile);
    if (!is_string($raw) || $raw === '') {
        error_log('[reviews_ai] quarantine: empty reservation file: ' . $resFile);
        return;
    }

    $res = json_decode($raw, true);
    if (!is_array($res)) {
        error_log('[reviews_ai] quarantine: invalid reservation JSON: ' . $resFile);
        return;
    }

    $guest      = $res['guest'] ?? [];
    $guestEmail = trim((string)($guest['email'] ?? ''));
    $guestName  = trim((string)($guest['name']  ?? ''));

    if ($guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[reviews_ai] quarantine: missing/invalid guest email for ' . $reservationId);
        return;
    }
    if ($guestName === '') {
        $guestName = 'Guest';
    }

    $unitCode = (string)($res['unit'] ?? '');
    $fromDate = (string)($res['from'] ?? '');
    $toDate   = (string)($res['to']   ?? '');

    // 3) Load global email settings from site_settings.json (NO cm_load_settings)
    $settingsFile = __DIR__ . '/../data/json/units/site_settings.json';

    $settings     = [];
    if (is_file($settingsFile)) {
        $sRaw = @file_get_contents($settingsFile);
        if (is_string($sRaw) && $sRaw !== '') {
            $decoded = json_decode($sRaw, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }
    }

    $emailCfg  = $settings['email'] ?? [];
    $enabled   = (bool)($emailCfg['enabled'] ?? true); // default: on
    $fromEmail = trim((string)($emailCfg['from_email'] ?? 'no-reply@localhost'));
    $fromName  = trim((string)($emailCfg['from_name']  ?? 'Apartma Matevž'));

    if (!$enabled) {
        error_log('[reviews_ai] quarantine: email notifications disabled in site_settings');
        return;
    }
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[reviews_ai] quarantine: invalid from_email in site_settings');
        return;
    }
    if ($fromName === '') {
        $fromName = 'Apartma Matevž';
    }

    // 4) Make sure cm_send_email is available (from common/lib/email.php)
    if (!function_exists('cm_send_email')) {
        $emailLib = __DIR__ . '/email.php';
        if (is_file($emailLib)) {
            require_once $emailLib;
        }
    }
    if (!function_exists('cm_send_email')) {
        error_log('[reviews_ai] quarantine: cm_send_email() not available');
        return;
    }

    // 5) Compose friendly email text
    $daysValid = defined('CM_REVIEW_TOKEN_VALID_DAYS')
        ? (int)CM_REVIEW_TOKEN_VALID_DAYS
        : 30;

    $subject = 'Thank you for your review – quick note about our safety filter';

    $stayParts = [];
    if ($unitCode !== '') {
        $stayParts[] = "apartment {$unitCode}";
    }
    if ($fromDate !== '' && $toDate !== '') {
        $stayParts[] = "stay from {$fromDate} to {$toDate}";
    }
    $stayLine = $stayParts ? implode(', ', $stayParts) : '';

    $lines   = [];
    $lines[] = "Hello {$guestName},";
    $lines[] = "";
    $lines[] = "Thank you again for taking the time to leave a review for us" . ($stayLine !== '' ? " ({$stayLine})" : "") . ".";
    $lines[] = "";
    $lines[] = "Our website uses an automatic safety filter that checks every review before it is published.";
    $lines[] = "This protection is built into the system so that we do not accidentally publish content";
    $lines[] = "with very strong language, sensitive personal details or anything that might be harmful or misleading.";
    $lines[] = "";
    $lines[] = "Because of this, your comment was temporarily placed \"on hold\" and is not visible on the public page at the moment.";
    $lines[] = "";
    $lines[] = "If you wish, you can adjust the wording of your review (for example, make it a bit shorter, less sharp or remove sensitive details)";
    $lines[] = "and save it again. As long as your original review link (with the token) stays valid (about {$daysValid} days after your stay),";
    $lines[] = "you can use that same link to edit and resubmit your review.";
    $lines[] = "";
    $lines[] = "Once the text no longer triggers the safety filter, your review can be shown normally on the website";
    $lines[] = "(of course only if you allowed public display when submitting it).";
    $lines[] = "";
    $lines[] = "If you believe this was a mistake or if you have any questions, just reply to this email and we will gladly review your case manually.";
    $lines[] = "";
    $lines[] = "Thank you again for your understanding and for helping us keep the review section friendly and safe for everyone.";
    $lines[] = "";
    $lines[] = "Kind regards,";
    $lines[] = $fromName;

    $textBody = implode("\n", $lines);

    $htmlBody = nl2br(htmlspecialchars($textBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    // 6) Send using unified wrapper (same pattern as send_review_request.php)
$ok = cm_send_email([
    'to'       => $guestEmail,
    'subject'  => $subject,
    'html'     => $htmlBody,
    'text'     => $textBody,
    'from'     => $fromEmail,
    'fromName' => $fromName,
]);

    if (!$ok) {
        error_log('[reviews_ai] quarantine: cm_send_email() failed for ' . $reservationId);
    } else {
        error_log('[reviews_ai] quarantine: email sent for ' . $reservationId . ' to ' . $guestEmail);
    }
}

?>
