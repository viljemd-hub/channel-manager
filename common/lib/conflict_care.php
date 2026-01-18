<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: common/lib/conflict_care.php
 *
 * Shared helpers for handling conflicting inquiries:
 *  - auto-create coupon on auto-reject
 *  - auto-reject pending inquiries that conflict with a confirmed range
 *
 * This file is intentionally "pure":
 *  - It does NOT do any require/include.
 *  - It assumes the caller has already loaded:
 *      - cm_datetime_cfg(), cm_iso_now(), cm_add_formatted_fields()
 *        (from common/lib/datetime_fmt.php)
 *      - send_rejected_email() (from admin/api/send_rejected.php)
 *      - cm_json_read(), cm_json_write() helpers
 */

declare(strict_types=1);

/**
 * Create an auto coupon for auto_conflict rejection, if promo settings allow it.
 *
 * Settings are read from:
 *   /common/data/json/units/promo_codes.json
 *
 * Expected structure:
 * {
 *   "settings": {
 *     "auto_reject_enabled": true,
 *     "auto_reject_discount_percent": 10,
 *     "auto_reject_valid_days": 14,
 *     "auto_reject_code_prefix": "RETRY-"
 *   },
 *   "codes": [ ... ]
 * }
 *
 * Returns:
 *  - array with coupon data if created
 *  - null if disabled or on write error
 */
if (!function_exists('cm_create_auto_coupon_for_reject')) {
    function cm_create_auto_coupon_for_reject(
        string $appRoot,
        string $unit,
        array $inq,
        string $tz
    ): ?array {
        $promoPath = rtrim($appRoot, '/') . '/common/data/json/units/promo_codes.json';

        // 1) Read existing JSON or prepare default structure
        $raw = @file_get_contents($promoPath);
        if ($raw === false) {
            $data = [
                'settings' => [],
                'codes'    => [],
            ];
        } else {
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            // Migration from old "flat array" form to {settings, codes}
            if (array_keys($data) === range(0, count($data) - 1)) {
                $data = [
                    'settings' => [],
                    'codes'    => $data,
                ];
            }

            if (!isset($data['codes']) || !is_array($data['codes'])) {
                $data['codes'] = [];
            }
            if (!isset($data['settings']) || !is_array($data['settings'])) {
                $data['settings'] = [];
            }
        }

        // 2) Settings may be associative or a list [{key, value}, ...]
        $settingsRaw = $data['settings'] ?? [];
        $settings    = [];

        if (is_array($settingsRaw)) {
            $keys = array_keys($settingsRaw);

            // Associative object: ['auto_reject_discount_percent' => 15, ...]
            if ($keys !== range(0, count($settingsRaw) - 1)) {
                $settings = $settingsRaw;
            } else {
                // List: convert to map by 'key'
                foreach ($settingsRaw as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $k = $item['key']  ?? null;
                    $v = $item['value'] ?? null;
                    if (is_string($k) && $k !== '') {
                        $settings[$k] = $v;
                    }
                }
            }
        }

        // 3) Read auto-coupon settings with safe defaults
        $enabled   = (bool)($settings['auto_reject_enabled'] ?? true);
        $percent   = (int)($settings['auto_reject_discount_percent'] ?? 15);
        $validDays = (int)($settings['auto_reject_valid_days'] ?? 180);
        $prefix    = (string)($settings['auto_reject_code_prefix'] ?? 'RETRY-');

        if (!$enabled || $percent <= 0) {
            return null;
        }

        // 4) Inquiry ID and core code string
        $id   = (string)($inq['id'] ?? bin2hex(random_bytes(4)));
        $core = preg_replace('~[^A-Za-z0-9]~', '', $id);
        $core = strtoupper(substr((string)$core, -6));
        if ($core === '') {
            $core = strtoupper(bin2hex(random_bytes(3)));
        }

        $code = $prefix . $core;

        $now   = new DateTimeImmutable('now', new DateTimeZone($tz));
        $fromD = $now->format('Y-m-d');
        $toD   = $now->modify('+' . max($validDays, 1) . ' days')->format('Y-m-d');

        // 5) Build coupon object (global coupon; unit="" by design)
        $coupon = [
            'id'               => $code,
            'code'             => $code,
            'name'             => 'Kupon po zavrnitvi',
            'type'             => 'percent',
            'value'            => $percent,
            'discount_percent' => $percent,
            'enabled'          => true,
            'unit'             => '',         // global; you can change to $unit if desired
            'valid_from'       => $fromD,
            'valid_to'         => $toD,
            'min_nights'       => 0,
            'max_nights'       => null,
            'usage_limit'      => 1,
            'used_count'       => 0,
            'source'           => 'auto_reject_conflict',
            'inquiry_id'       => $id,
            'note'             => 'Auto coupon for auto-conflict inquiry ' . $id,
            'meta'             => [
                'origin_inquiry_id' => $id,
                'unit'              => $unit,
            ],
        ];

        $data['codes'][] = $coupon;

        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($encoded === false) {
            return null;
        }

        if (@file_put_contents($promoPath, $encoded, LOCK_EX) === false) {
            return null;
        }

        return $coupon;
    }
}

/**
 * Core: reject all pending inquiries that overlap [from,to) for a given unit.
 *
 * Params:
 *  - $appRoot      : /var/www/html/app
 *  - $unit         : e.g. "A1"
 *  - $from, $to    : YYYY-MM-DD (from inclusive, to exclusive)
 *  - $referenceId  : id of confirmed inquiry/reservation
 *  - $cfg          : result of cm_datetime_cfg()
 *
 * Returns array:
 *  [
 *    'ok'             => true,
 *    'reference'      => [...],
 *    'rejected_count' => N,
 *    'items'          => [...],
 *  ]
 */
if (!function_exists('cm_reject_pending_for_range')) {
    function cm_reject_pending_for_range(
        string $appRoot,
        string $unit,
        string $from,
        string $to,
        string $referenceId,
        array $cfg
    ): array {
        $tz   = $cfg['timezone'] ?? 'Europe/Ljubljana';
        $mode = $cfg['output_mode'] ?? 'raw';

        $inqRoot = rtrim($appRoot, '/') . '/common/data/json/inquiries';

        if ($unit === '' || $from === '' || $to === '') {
            throw new RuntimeException('missing_unit_or_range');
        }

        try {
            $start = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone($tz));
            $end   = new DateTimeImmutable($to   . ' 00:00:00', new DateTimeZone($tz));
        } catch (Throwable $e) {
            throw new RuntimeException('invalid_date_range: ' . $e->getMessage());
        }

        $pendingFiles = glob("{$inqRoot}/*/*/pending/*.json", GLOB_NOSORT) ?: [];
        $rejected     = [];

        foreach ($pendingFiles as $pf) {
            $p = cm_json_read($pf);
            if (!is_array($p)) {
                continue;
            }

            if (($p['unit'] ?? '') !== $unit) {
                continue;
            }

            // Do not reject the reference inquiry itself
            $pid = (string)($p['id'] ?? '');
            if ($pid !== '' && $pid === $referenceId) {
                continue;
            }

            $pFrom = (string)($p['from'] ?? '');
            $pTo   = (string)($p['to']   ?? '');
            if ($pFrom === '' || $pTo === '') {
                continue;
            }

            try {
                $ps = new DateTimeImmutable($pFrom . ' 00:00:00', new DateTimeZone($tz));
                $pe = new DateTimeImmutable($pTo   . ' 00:00:00', new DateTimeZone($tz));
            } catch (Throwable $e) {
                continue;
            }

            // Overlap of [ps,pe) with [start,end)
            if (!($ps < $end && $pe > $start)) {
                continue;
            }

            $nowIso = cm_iso_now($tz);

            $p['status']      = 'rejected';
            $p['stage']       = 'rejected_auto_conflict';
            $p['rejected_at'] = $nowIso;

            if (!isset($p['meta']) || !is_array($p['meta'])) {
                $p['meta'] = [];
            }
            $p['meta']['reject_reason'] = 'auto_conflict';

            cm_add_formatted_fields($p, [
                'from'        => 'date',
                'to'          => 'date',
                'created'     => 'datetime',
                'rejected_at' => 'datetime',
            ], $cfg);

            $year      = substr($nowIso, 0, 4);
            $month     = substr($nowIso, 5, 2);
            $targetDir = "{$inqRoot}/{$year}/{$month}/rejected";

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
                // If we cannot create dir, skip this inquiry
                continue;
            }

            $pid        = $pid !== '' ? $pid : ($p['id'] ?? bin2hex(random_bytes(4)));
            $targetPath = "{$targetDir}/{$pid}.json";

            if (!cm_json_write($targetPath, $p)) {
                continue;
            }

            @unlink($pf);

            // Auto coupon + rejected email
            $coupon = cm_create_auto_coupon_for_reject($appRoot, $unit, $p, $tz);

            try {
                // Parameter order: ($inq, $coupon, $reasonCode)
                send_rejected_email($p, $coupon, 'auto_conflict');
            } catch (Throwable $e) {
                error_log('[conflict_care] send_rejected_email failed: ' . $e->getMessage());
            }

            $out = cm_filter_output_mode($p, $mode);
            $out['coupon'] = $coupon;
            $rejected[]    = $out;
        }

        return [
            'ok'       => true,
            'reference' => [
                'id'   => $referenceId,
                'unit' => $unit,
                'from' => $from,
                'to'   => $to,
            ],
            'rejected_count' => count($rejected),
            'items'          => $rejected,
        ];
    }
}
