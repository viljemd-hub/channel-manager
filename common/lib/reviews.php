<?php
declare(strict_types=1);

require_once __DIR__ . '/urls.php'; // URL helpers (cm_public_base_url, cm_public_url)

// Optional AI module (do not break prod if missing)
$__ai = __DIR__ . '/reviews_ai.php';
if (is_file($__ai)) {
    require_once $__ai;
}

/**
 * CM – Guest reviews helper
 *
 * Stores per-year review lists in:
 *   app/common/data/json/reviews/YYYY.json
 *
 * Rebuilds public files in:
 *   app/public/data/reviews/summary.json
 *   app/public/data/reviews/public.json
 */

/**
 * IMPORTANT:
 * Change this to your own long random secret string and keep it private.
 */
const CM_REVIEW_TOKEN_SECRET = 'CHANGE_THIS_REVIEW_SECRET';

/**
 * How many days after departure the review link stays valid.
 */
const CM_REVIEW_TOKEN_VALID_DAYS = 30;

/**
 * Base directory for per-year review JSON files.
 * __DIR__ = /var/www/html/app/common/lib
 * __DIR__ . '/../data/json/reviews' = /var/www/html/app/common/data/json/reviews
 */
const CM_REVIEW_DATA_DIR = __DIR__ . '/../data/json/reviews';

/**
 * Directory for public JSON used by the public pages.
 * __DIR__ . '/../../public/data/reviews' = /var/www/html/app/public/data/reviews
 */
const CM_REVIEW_PUBLIC_DIR = __DIR__ . '/../../public/data/reviews';

function cm_review_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/** @return array<mixed> */
function cm_review_read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** @param mixed $data *//**
 * Internal helper: base directory for reservations JSON.
 *
 * Layout:
 *   /common/data/json/reservations/YYYY/UNIT/ID.json
 */
function cm_review_reservations_root(): string
{
    static $RES_ROOT = null;

    if ($RES_ROOT === null) {
        $app = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $RES_ROOT = rtrim($app, '/\\') . '/common/data/json/reservations';
    }

    return $RES_ROOT;
}

/**
 * Find reservation JSON path for a given reservation id.
 *
 * @return string|null Absolute path or null if not found
 */
function cm_review_find_reservation_path(string $reservationId): ?string
{
    $root = cm_review_reservations_root();
    if (!is_dir($root)) {
        return null;
    }

    $pattern = rtrim($root, '/\\') . '/*/*/' . $reservationId . '.json';
    $matches = glob($pattern);
    if (!is_array($matches) || !$matches) {
        return null;
    }

    return $matches[0];
}

/**
 * Load basic reservation meta data for a given reservation id.
 *
 * Returns only fields needed by reviews.
 *
 * @return array<string,mixed>|null
 */
function cm_review_load_basic_reservation_meta(string $reservationId): ?array
{
    $path = cm_review_find_reservation_path($reservationId);
    if ($path === null) {
        return null;
    }

    $res = cm_review_read_json($path);
    if (!is_array($res)) {
        return null;
    }

    return [
        'unit' => $res['unit'] ?? null,
        'from' => $res['from'] ?? null,
        'to'   => $res['to']   ?? null,
    ];
}

/**
 * Update reservation JSON with a small review summary block.
 */
function cm_review_update_reservation_review_meta(
    string $reservationId,
    int $score,
    bool $publicAllowed
): void {
    $path = cm_review_find_reservation_path($reservationId);
    if ($path === null) {
        return;
    }

    $res = cm_review_read_json($path);
    if (!is_array($res)) {
        return;
    }

    $res['review'] = [
        'has_review'     => true,
        'score'          => $score,
        'public_allowed' => $publicAllowed,
    ];

    $res['review_score'] = $score;

    cm_review_write_json_atomic($path, $res);
}
function cm_review_write_json_atomic(string $file, $data): void
{
    cm_review_ensure_dir(dirname($file));

    $tmp  = $file . '.tmp';
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for ' . $file);
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temp file ' . $tmp);
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to replace JSON file ' . $file);
    }
}

function cm_review_year_from_reservation_id(string $reservationId): int
{
    $year = (int)substr($reservationId, 0, 4);
    if ($year < 2000 || $year > 2100) {
        $year = (int)date('Y');
    }
    return $year;
}

/** @return array<int, array<string,mixed>> */
function cm_review_load_year(int $year): array
{
    $file = rtrim(CM_REVIEW_DATA_DIR, '/') . '/' . $year . '.json';
    $data = cm_review_read_json($file);
    return is_array($data) ? $data : [];
}

/** @param array<int, array<string,mixed>> $rows */
function cm_review_save_year(int $year, array $rows): void
{
    $file = rtrim(CM_REVIEW_DATA_DIR, '/') . '/' . $year . '.json';
    cm_review_write_json_atomic($file, array_values($rows));
}

/** @param array<int, array<string,mixed>> $rows */
function cm_review_find_review_index(array $rows, string $reservationId): ?int
{
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['reservation_id'] ?? null) === $reservationId) {
            return $i;
        }
    }
    return null;
}

/** @return array<string,mixed>|null */
function cm_review_get_for_reservation(string $reservationId): ?array
{
    $year = cm_review_year_from_reservation_id($reservationId);
    $rows = cm_review_load_year($year);
    $idx  = cm_review_find_review_index($rows, $reservationId);
    if ($idx === null) {
        return null;
    }
    $row = $rows[$idx] ?? null;
    return is_array($row) ? $row : null;
}

/**
 * Upsert review for a reservation id.
 *
 * @param array<string,mixed> $fields
 * @return array<string,mixed>
 */
function cm_review_save_for_reservation(string $reservationId, array $fields): array
{
    $year = cm_review_year_from_reservation_id($reservationId);
    $rows = cm_review_load_year($year);
    $idx  = cm_review_find_review_index($rows, $reservationId);

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    if ($idx === null) {
        $row = [
            'reservation_id' => $reservationId,
            'created_at'     => $now,
        ];
        $row = array_merge($row, $fields);
        $row['updated_at'] = $now;
        $rows[] = $row;
        $targetIdx = count($rows) - 1;
    } else {
        $row = $rows[$idx];
        if (!isset($row['created_at'])) {
            $row['created_at'] = $now;
        }
        $row = array_merge($row, $fields);
        $row['updated_at'] = $now;
        $rows[$idx] = $row;
        $targetIdx = $idx;
    }

    // Ensure moderation fields exist (admin UI expects them)
    $row = $rows[$targetIdx];
    $row['status']       = $fields['status']       ?? ($row['status']       ?? 'pending');
    $row['is_flagged']   = $fields['is_flagged']   ?? ($row['is_flagged']   ?? false);
    $row['flag_reason']  = $fields['flag_reason']  ?? ($row['flag_reason']  ?? '');
    $row['risk_score']   = $fields['risk_score']   ?? ($row['risk_score']   ?? 0);
    $row['toxicity']     = $fields['toxicity']     ?? ($row['toxicity']     ?? 0);
    $row['ai_category']  = $fields['ai_category']  ?? ($row['ai_category']  ?? '');
    $row['ai_decision']  = $fields['ai_decision']  ?? ($row['ai_decision']  ?? '');
    $row['processed_at'] = $fields['processed_at'] ?? ($row['processed_at'] ?? '');

    $rows[$targetIdx] = $row;

    cm_review_save_year($year, $rows);
    return $row;
}

function cm_review_rebuild_public_files(): void
{
    cm_review_ensure_dir(CM_REVIEW_PUBLIC_DIR);

    $all = [];
    if (is_dir(CM_REVIEW_DATA_DIR)) {
        foreach (glob(rtrim(CM_REVIEW_DATA_DIR, '/') . '/*.json') ?: [] as $file) {
            $rows = cm_review_read_json($file);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }
        }
    }

    $sum = 0.0;
    $cnt = 0;
    $public = [];

    foreach ($all as $row) {
        $rating = $row['rating'] ?? null;
        if (!is_numeric($rating)) {
            continue;
        }
        $rating = (int)$rating;
        if ($rating < 1 || $rating > 5) {
            continue;
        }

        $sum += $rating;
        $cnt++;

        $visible       = array_key_exists('visible', $row) ? (bool)$row['visible'] : true;
        $publicAllowed = !empty($row['public_allowed']);

        if (!$visible || !$publicAllowed) {
            continue;
        }

        $stayMonth = '';
        $dateSrc   = $row['from'] ?? ($row['to'] ?? null);
        if (is_string($dateSrc) && $dateSrc !== '') {
            $ts = strtotime($dateSrc);
            if ($ts !== false) {
                $stayMonth = date('M Y', $ts);
            }
        }

        $public[] = [
            'approved'   => true,
            'rating'     => $rating,
            'text'       => (string)($row['text'] ?? ''),
            'name'       => (string)($row['display_name'] ?? 'Guest'),
            'stay_month' => $stayMonth,
            'date'       => (string)($row['created_at'] ?? ''),
        ];
    }

    usort($public, static function (array $a, array $b): int {
        $ad = $a['date'] ?? '';
        $bd = $b['date'] ?? '';
        if ($ad === $bd) {
            return 0;
        }
        return $ad < $bd ? 1 : -1;
    });

    $summaryFile = rtrim(CM_REVIEW_PUBLIC_DIR, '/') . '/summary.json';
    $publicFile  = rtrim(CM_REVIEW_PUBLIC_DIR, '/') . '/public.json';

    $summaryData = [
        'avg'     => $cnt > 0 ? $sum / $cnt : 0,
        'count'   => $cnt,
        'updated' => date('Y-m-d'),
    ];

    cm_review_write_json_atomic($summaryFile, $summaryData);
    cm_review_write_json_atomic($publicFile, ['reviews' => $public]);
}

function cm_review_build_token(string $reservationId, int $expiresTs): string
{
    $payload = $reservationId . '|' . $expiresTs;

    $sig    = hash_hmac('sha256', $payload, CM_REVIEW_TOKEN_SECRET, true);
    $sigB64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    return $expiresTs . '.' . $sigB64;
}

function cm_review_verify_token(string $reservationId, string $token, ?int &$expiresTsOut = null): bool
{
    if (!preg_match('~^(\d+)\.([A-Za-z0-9\-_]{10,})$~', $token, $m)) {
        return false;
    }

    $expiresTs = (int)$m[1];
    $sigGiven  = $m[2];

    if ($expiresTs <= 0) {
        return false;
    }

    if ($expiresTs < time()) {
        return false;
    }

    $payload = $reservationId . '|' . $expiresTs;
    $sig     = hash_hmac('sha256', $payload, CM_REVIEW_TOKEN_SECRET, true);
    $sigB64  = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    if (!hash_equals($sigB64, $sigGiven)) {
        return false;
    }

    $expiresTsOut = $expiresTs;
    return true;
}

function cm_review_build_link(string $baseUrl, string $reservationId, string $token): string
{
    $path = 'public/review.php?id=' . rawurlencode($reservationId)
          . '&token=' . rawurlencode($token);

    return cm_public_url($path);
}
