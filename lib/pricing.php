<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: lib/pricing.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

/** Helpers */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function load_json(string $path) {
  if (!file_exists($path)) return null;
  $raw = file_get_contents($path);
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}
function iso_datespan(string $from, string $to): array {
  $out = [];
  $d = new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('UTC'));
  $e = new DateTimeImmutable($to  .' 00:00:00', new DateTimeZone('UTC'));
  while ($d < $e) { $out[] = $d->format('Y-m-d'); $d = $d->modify('+1 day'); }
  return $out;
}
function iso_nights(string $from, string $to): int {
  $f = new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('UTC'));
  $t = new DateTimeImmutable($to  .' 00:00:00', new DateTimeZone('UTC'));
  return max(0, (int)$t->diff($f)->days);
}

/** Pricing data */
function price_map_for_unit(string $root, string $unit): array {
  $path = "$root/units/$unit/prices.json";
  $j = load_json($path);
  $map = [];
  if (!$j) return $map;

  // a) { "daily": { "YYYY-MM-DD": 95, ... } }
  if (isset($j['daily']) && is_array($j['daily'])) {
    foreach ($j['daily'] as $d => $p) {
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) $map[$d] = is_numeric($p) ? (float)$p : null;
    }
    return $map;
  }

  // b) { "2025-11-01": 95, ... }  <-- tvoja shema
  $hasDirectDates = false;
  foreach ($j as $k => $v) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$k)) {
      $map[$k] = is_numeric($v) ? (float)$v : null;
      $hasDirectDates = true;
    }
  }
  if ($hasDirectDates) return $map;

  // c) { "prices": [ {date,price} | {start,end,price} ] } ali legacy: root je seznam
  $list = $j['prices'] ?? (is_array($j) && isset($j[0]) ? $j : null);
  if (is_array($list)) {
    foreach ($list as $row) {
      if (isset($row['date'])) {
        $d = $row['date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) {
          $map[$d] = is_numeric($row['price'] ?? null) ? (float)$row['price'] : null;
        }
      } elseif (isset($row['start'],$row['end'])) {
        $d = new DateTimeImmutable($row['start']);
        $e = new DateTimeImmutable($row['end']);
        while ($d < $e) {
          $map[$d->format('Y-m-d')] = is_numeric($row['price'] ?? null) ? (float)$row['price'] : null;
          $d = $d->modify('+1 day');
        }
      }
    }
  }
  return $map;
}

function busy_set_for_unit(string $root, string $unit): array {
  $path = "$root/units/$unit/occupancy_merged.json";
  if (!file_exists($path)) $path = "$root/units/$unit/occupancy.json";

  $j = load_json($path);
  $busy = [];
  if (!$j) return $busy;

  // A) dnevna shema: { "daily": { "YYYY-MM-DD": "..." } }
  if (isset($j['daily']) && is_array($j['daily'])) {
    foreach ($j['daily'] as $d => $flagRaw) {
      $flag = strtolower((string)$flagRaw);
      if (in_array($flag, ['busy','booked','blocked','reserved','hold','no_arrival'], true)) {
        $busy[$d] = true; // 'depart' ne blokira noči
      }
    }
    return $busy;
  }

  // B) intervali: { "events": [ {from,to,status}, ... ] } ali legacy: root je seznam
  $events = $j['events'] ?? (is_array($j) && isset($j[0]) ? $j : null);
  if (is_array($events)) {
    foreach ($events as $ev) {
      $f = $ev['from'] ?? ($ev['start'] ?? null);
      $t = $ev['to']   ?? ($ev['end']   ?? null);
      $st= strtolower((string)($ev['status'] ?? $ev['type'] ?? 'busy'));
      if (!$f || !$t) continue;
      if (!in_array($st, ['busy','booked','blocked','reserved','hold','no_arrival'], true)) continue;
      $d = new DateTimeImmutable($f);
      $e = new DateTimeImmutable($t); // exclusive 'to'
      while ($d < $e) { $busy[$d->format('Y-m-d')] = true; $d = $d->modify('+1 day'); }
    }
  }
  return $busy;
}

function span_is_clear(string $root, string $unit, string $from, string $to, &$why = null): bool {
  // Back-compat:
  // - če 5. argument pride kot array, ga tretiramo kot "context" (stari klic),
  //   in interni $why nastavimo posebej kot string.
  $ctx = [];
  if (is_array($why)) { $ctx = $why; $why = null; }

  if ($to <= $from) { $why = 'from>=to'; return false; }

  $prices = price_map_for_unit($root, $unit);
  $busy   = busy_set_for_unit($root, $unit);

  $a = new DateTimeImmutable($from);
  $b = new DateTimeImmutable($to);
  for ($d = $a; $d < $b; $d = $d->modify('+1 day')) {
    $k = $d->format('Y-m-d');

    if (!array_key_exists($k, $prices) || $prices[$k] === null || (float)$prices[$k] == 0.0) {
      $why = $why ? ($why . ", no price " . $k) : ("no price " . $k);
      return false;
    }
    if (!empty($busy[$k])) {
      $why = $why ? ($why . ", busy " . $k) : ("busy " . $k);
      return false;
    }
  }

  return true;
}

/** Special offers (manual periods) */
function offers_for_unit(string $root, string $unit): array {
  $j = load_json("$root/units/$unit/special_offers.json");
  return ($j && isset($j['offers']) && is_array($j['offers'])) ? $j['offers'] : [];
}
function offer_matches(array $offer, string $from, string $to, int $nights, DateTimeImmutable $now): bool {
  if (!(bool)($offer['enabled'] ?? false)) return false;
  $af = new DateTimeImmutable(($offer['active_from'] ?? '1970-01-01').' 00:00:00', new DateTimeZone('UTC'));
  $at = new DateTimeImmutable(($offer['active_to']   ?? '9999-12-31').' 00:00:00', new DateTimeZone('UTC'));
  if ($now < $af || $now > $at) return false;
  $minN = (int)($offer['conditions']['min_nights'] ?? 0);
  if ($nights < $minN) return false;
  $periods = $offer['periods'] ?? [];
  if (!$periods) return true;
  $f = new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('UTC'));
  $t = new DateTimeImmutable($to  .' 00:00:00', new DateTimeZone('UTC'));
  foreach ($periods as $p) {
    if (!isset($p['start'],$p['end'])) continue;
    $ps = new DateTimeImmutable($p['start'].' 00:00:00', new DateTimeZone('UTC'));
    $pe = new DateTimeImmutable($p['end']  .' 00:00:00', new DateTimeZone('UTC'));
    if ($f >= $ps && $t <= $pe) return true;
  }
  return false;
}
function select_offer(array $offers, string $from, string $to, int $nights, DateTimeImmutable $now): ?array {
  $best = null; $bestPrio = -PHP_INT_MAX;
  foreach ($offers as $off) {
    if (!offer_matches($off, $from, $to, $nights, $now)) continue;
    $prio = (int)($off['priority'] ?? 0);
    if ($prio > $bestPrio) { $best = $off; $bestPrio = $prio; }
  }
  return $best;
}

/** Promo codes */
function promo_lookup(string $root, string $code): ?array {
  if (!$code) return null;
  $j = load_json("$root/promo_codes.json"); if(!$j) return null;
  foreach ($j['codes'] ?? [] as $c) {
    if (strcasecmp((string)($c['id'] ?? ''), $code) === 0 && ($c['enabled'] ?? false)) return $c;
  }
  return null;
}
function apply_promo(array $pricing, ?array $promo): array {
  if (!$promo) { $pricing['promo_total'] = 0.0; return $pricing; }
  $base   = (float)$pricing['base_total'];
  $dtype  = $promo['type'] ?? 'percent';
  $val    = (float)($promo['value'] ?? 0);
  $final  = (float)$pricing['final_total'];
  $disc   = 0.0;
  if ($dtype === 'percent')  { $disc = round($base * ($val/100), 2); $final -= $disc; }
  elseif ($dtype === 'absolute'){ $disc = min($base, round($val,2)); $final -= $disc; }
  $pricing['promo_total'] = $disc;
  $pricing['final_total'] = round($final, 2);
  $pricing['applied_offers'][] = [
    'id'=>$promo['id'] ?? '', 'name'=>($promo['name'] ?? ('Promo '.($promo['id'] ?? ''))),
    'type'=>$dtype, 'value'=>$val, 'kind'=>'promo'
  ];
  return $pricing;
}
function count_auto_promo_used(string $root, string $code): int {
  $dir = $root.'/inquiries';
  if (!is_dir($dir)) return 0;
  $cnt = 0;
  $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($iter as $file) {
    if (substr($file->getFilename(), -5) !== '.json') continue;
    $j = json_decode(@file_get_contents($file->getPathname()), true);
    if (!$j) continue;
    if (($j['meta']['source'] ?? '') === 'public' && strcasecmp((string)($j['promo_code_used'] ?? ''), $code) === 0) {
      $cnt++;
    }
  }
  return $cnt;
}

/** Extend helpers (add nights) */
function try_extend_right(string $root, string $unit, string $from, string $to, int $addN): ?array {
  if ($addN <= 0) return ['from'=>$from,'to'=>$to];
  $newTo = (new DateTimeImmutable($to.' 00:00:00', new DateTimeZone('UTC')))->modify("+$addN day")->format('Y-m-d');
  if (span_is_clear($root,$unit,$to,$newTo)) return ['from'=>$from,'to'=>$newTo];
  return null;
}
function try_extend_left(string $root, string $unit, string $from, string $to, int $addN): ?array {
  if ($addN <= 0) return ['from'=>$from,'to'=>$to];
  $newFrom = (new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('UTC')))->modify("-$addN day")->format('Y-m-d');
  if (span_is_clear($root,$unit,$newFrom,$from)) return ['from'=>$newFrom,'to'=>$to];
  return null;
}

/** Main pricing */
function compute_pricing(string $root, string $unit, string $from, string $to, array $opts=[]): array {
  $nights   = iso_nights($from,$to);
  $priceMap = price_map_for_unit($root, $unit);
  $busySet  = busy_set_for_unit($root, $unit);
  if (!span_is_clear($root,$unit,$from,$to,$priceMap,$busySet))
    return ['ok'=>false, 'error'=>'Razpon ni na voljo (zasedenost ali cene).'];

  // base
  $dates = iso_datespan($from,$to);
  $breakdown = []; $base = 0.0;
  foreach ($dates as $d) { $p = (float)($priceMap[$d] ?? 0.0); $breakdown[] = ['date'=>$d,'price'=>$p]; $base += $p; }

  // special offers (manual)
  $offers  = offers_for_unit($root, $unit);
  $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $selected = select_offer($offers, $from, $to, $nights, $now);

  $discounts = 0.0; $final = $base; $applied_offers = [];
  if ($selected) {
    $t = $selected['discount']['type'] ?? 'percent';
    $v = (float)($selected['discount']['value'] ?? 0);
    if ($t === 'percent')      { $discounts = round($base*($v/100), 2); $final = $base - $discounts; }
    elseif ($t === 'absolute') { $discounts = min($base, round($v,2));   $final = $base - $discounts; }
    elseif ($t === 'set_nightly'){ $set=$v; $final = round($set*$nights,2); $discounts = max(0.0, $base - $final); }
    $applied_offers[] = [
      'id'=>$selected['id']??'', 'name'=>$selected['name']??'',
      'type'=>$t, 'value'=>$v, 'kind'=>'special'
    ];
  }

  // settings (global + per-unit override)
  $siteG = load_json("$root/site_settings.json") ?: [];
  $siteU = load_json("$root/units/$unit/site_settings.json") ?: [];
  $site  = array_merge($siteG, $siteU);
  $tt    = isset($site['tourist_tax_eur_per_night']) ? (float)$site['tourist_tax_eur_per_night'] : null;
  $clean = isset($site['cleaning_fee_eur_per_stay']) ? (float)$site['cleaning_fee_eur_per_stay'] : 45.0;

  // weekly discount (stalna ugodnost, percent na base) – NE dodaj v applied_offers
  $pricing_weekly_applied = false;
  $pricing_weekly_percent = null;
  $wd = $site['weekly_discount'] ?? null;
  if ($wd && ($wd['enabled'] ?? false)) {
    $len = (int)($wd['length_nights'] ?? 7);
    $pct = (float)($wd['percent'] ?? 0);
    if ($nights >= $len && $pct > 0) {
      $wd_disc = round($base * ($pct/100), 2);
      $final -= $wd_disc;
      $discounts += $wd_disc;
      $pricing_weekly_applied = true;
      $pricing_weekly_percent = $pct;
    }
  }

  // group info (adults/kids)
  $adults  = max(0, (int)($opts['adults']  ?? 2));
  $kids06  = max(0, (int)($opts['kids06']  ?? 0));
  $kids712 = max(0, (int)($opts['kids712'] ?? 0));

  // informative totals
  $tt_payers   = $adults + 0.5*$kids712;
  $tt_group    = ($tt !== null) ? round($tt_payers * $tt * $nights, 2) : null;
  $payer_count = $adults + $kids712;
  $kc_count    = max(0, (int)($opts['keycards'] ?? 0));
  $kc_saving   = ($tt !== null) ? round(min($kc_count, $payer_count) * $tt * $nights, 2) : null;

  $pricing = [
    'base_total'   => round($base,2),
    'discounts_total' => round($discounts,2),
    'weekly_applied' => $pricing_weekly_applied,
    'weekly_percent' => $pricing_weekly_percent,
    'final_total'  => round($final + $clean, 2), // cleaning included
    'cleaning_fee_total' => round($clean,2),
    'breakdown'    => $breakdown,
    'applied_offers' => $applied_offers, // weekly NI tu
    'tourist_tax_eur_per_night' => $tt,
    'group_info' => [
      'adults'=>$adults, 'kids_0_6'=>$kids06, 'kids_7_12'=>$kids712,
      'tt_group_total'=>$tt_group,
      'keycard_saving_group'=>$kc_saving
    ],
    'discount_notices' => ($site['discount_notices'] ?? null)
  ];

  // promo (manual or AUTO WELCOME5 for first 3 public)
  $promoCode   = trim((string)($opts['promo_code'] ?? ''));
  $autoApplied = false;
  if ($promoCode === '') {
    $used = count_auto_promo_used($root, 'WELCOME5');
    if ($used < 3) { $promoCode = 'WELCOME5'; $autoApplied = true; }
  }
  $promo = promo_lookup($root, $promoCode);
  if ($promo) {
    $pricing = apply_promo($pricing, $promo);
    $pricing['promo_code'] = $promoCode;
    $pricing['promo_auto_applied'] = $autoApplied;
  } else {
    $pricing['promo_total'] = 0.0;
  }

  // legacy keycards info (per-nights potential)
  $pricing['keycards_info'] = [
    'keycards' => $kc_count,
    'tourist_tax_eur_per_night' => $tt,
    'potential_savings' => ($tt !== null ? round($kc_count * $tt * $nights, 2) : null)
  ];

  return [
    'ok'      => true,
    'unit'    => $unit,
    'from'    => $from,
    'to'      => $to,
    'nights'  => $nights,
    'currency'=> 'EUR',
    'pricing' => $pricing
  ];
}
