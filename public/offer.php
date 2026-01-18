<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/offer.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/public/offer.php
declare(strict_types=1);
ini_set('display_errors','1'); 
ini_set('display_startup_errors','1'); 
error_reporting(E_ALL);

// === Sejni “carry” žeton za povratek na koledar ===
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['carry_token'])) {
    $_SESSION['carry_token'] = bin2hex(random_bytes(16));
}
$_SESSION['last_activity'] = time(); // osveži aktivnost
$CARRY_TOKEN = $_SESSION['carry_token'];

// vhodni parametri iz GET/POST (za prvi safety check)
$unit = isset($_POST['unit'])
    ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_POST['unit'])
    : (isset($_GET['unit'])
        ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['unit'])
        : 'A1');

$from = $_POST['from'] ?? ($_GET['from'] ?? '');
$to   = $_POST['to']   ?? ($_GET['to']   ?? '');

// raw (pred normalizacijo ymd) – za fallbacke kasneje
$from_raw = $from;
$to_raw   = $to;

// --------- JEZIK (public SLO/EN) ---------
$supportedLangs = ['sl','en'];
$lang = $_POST['lang'] ?? ($_GET['lang'] ?? null);
if (!in_array($lang, $supportedLangs, true)) {
  // optional: HTTP_ACCEPT_LANGUAGE fallback
  $lang = 'sl';
}

// 2) enostavna tabela prevodov za to stran
$T = [
  'sl' => [
    'short.title' => 'Termin je prekratek',
    'short.h1'    => 'Termin je prekratek za to enoto',
    'short.p_min' => 'Za izbrano enoto je minimalno število nočitev {minNights}.',
    'short.p_sel' => 'Izbrali ste termin z {nights} nočitvami.',
    'short.p_hint'=> 'Prosimo, izberite daljši termin ali nas kontaktirajte za posebne želje.',

    'title.offer' => 'Ponudba – {unit}',
    'h1.offer'    => 'Ponudba – {unit}',

    'btn.back_calendar' => 'Nazaj na koledar',
    'btn.check_new'     => 'Preveri novo izbiro',
    'btn.send_inquiry'  => 'Pošlji povpraševanje',
    'btn.minus_day'     => '− dan',
    'btn.plus_day'      => '+ dan',

    'section.term'      => 'Termin',
    'section.group'     => 'Skupina',
    'contact.title'     => 'Kontakt',

    'label.from'        => 'Od',
    'label.to'          => 'Do',
    'label.nights'      => 'noči',
    'label.adults'      => 'Odrasli (13+)',
    'label.kids712'     => 'Otroci 7–12',
    'label.kids06'      => 'Otroci 0–6',
    'label.keycard'     => 'KEYCARD (št. kartic)',
    'label.weekly'      => 'Tedenski popust',
    'label.promo'       => 'Promo koda',
    'label.breakdown'   => 'Razčlenitev',

    'opt.yes'           => 'Da',
    'opt.no'            => 'Ne',

    'chk.to_seven'      => 'Raztegni do {n} noči',

    'details.daily_breakdown' => 'Razčlenitev cen po dnevih',
    'table.date'        => 'Datum',
    'table.price'       => 'Cena',
    'table.missing'     => 'manjka',
    'hint.pick_range'   => 'Izberi termin za prikaz dnevnih cen.',

    'cap.hint'          => 'Največ {maxGuests} oseb, {maxBeds} postelj, vsaj {minAdults} odrasla oseba.',
    'cap.min_adults'    => 'Za to enoto je zahtevana vsaj %d odrasla oseba.',
    'cap.max_guests'    => 'Največje dovoljeno število oseb za to enoto je %d (trenutno %d).',
    'cap.max_beds'      => 'Največje dovoljeno število postelj (odrasli + 7–12) za to enoto je %d (trenutno %d).',

    'price.title'       => 'Izračun',
    'price.range_unavailable' => 'Razpon ni na voljo (manjkajo cene za vse noči).',
    'price.base'        => 'Osnova',
    'price.discounts'   => 'Popusti skupaj',
    'price.coupon'      => 'Kupon',
    'price.special_offer'=> 'Posebna ponudba',
    'price.cleaning'    => 'Čiščenje (na bivanje)',
    'price.total'       => 'Skupaj',

    'tt.label'          => 'Turistična taksa',
    'tt.pay_on_arrival' => 'plačilo ob prihodu',
    'tt.after_saving'   => 'TT za plačilo po prihranku',
    'keycard.add'       => 'Dodajte KEYCARD za zmanjšanje TT za odrasle ({rate}/noč na osebo).',

    'contact.name'      => 'Ime in priimek',
    'contact.email'     => 'E-pošta',
    'contact.phone'     => 'Telefon',
    'contact.message'   => 'Sporočilo za nas (neobvezno)',

    'ph.email'          => 'vaš e-poštni naslov',
    'ph.phone'          => '+386 …',
    'placeholder.optional' => 'neobvezno',

    'hint.submit'       => 'Izberi veljaven termin z definiranimi cenami za vse noči in skupino gostov v dovoljenem obsegu.',

    'alert.email_required' => 'E-poštni naslov je obvezen za oddajo povpraševanja.',
    'alert.email_invalid'  => 'Prosimo, vnesite veljaven e-poštni naslov.',

    'promo.error'       => 'Koda ni veljavna ali je potekla.',
  ],
  'en' => [
    'short.title' => 'Stay is too short',
    'short.h1'    => 'Stay is too short for this unit',
    'short.p_min' => 'For this unit, the minimum stay is {minNights} nights.',
    'short.p_sel' => 'You selected a stay of {nights} nights.',
    'short.p_hint'=> 'Please choose a longer stay or contact us if you have special requests.',

    'title.offer' => 'Offer – {unit}',
    'h1.offer'    => 'Offer – {unit}',

    'btn.back_calendar' => 'Back to calendar',
    'btn.check_new'     => 'Check new selection',
    'btn.send_inquiry'  => 'Send inquiry',
    'btn.minus_day'     => '− day',
    'btn.plus_day'      => '+ day',

    'section.term'      => 'Dates',
    'section.group'     => 'Guests',
    'contact.title'     => 'Contact',

    'label.from'        => 'From',
    'label.to'          => 'To',
    'label.nights'      => 'nights',
    'label.adults'      => 'Adults (13+)',
    'label.kids712'     => 'Children 7–12',
    'label.kids06'      => 'Children 0–6',
    'label.keycard'     => 'KEYCARD (number of cards)',
    'label.weekly'      => 'Weekly discount',
    'label.promo'       => 'Promo code',
    'label.breakdown'   => 'Breakdown',

    'opt.yes'           => 'Yes',
    'opt.no'            => 'No',

    'chk.to_seven'      => 'Extend up to {n} nights',

    'details.daily_breakdown' => 'Daily price breakdown',
    'table.date'        => 'Date',
    'table.price'       => 'Price',
    'table.missing'     => 'missing',
    'hint.pick_range'   => 'Select dates to see daily prices.',

    'cap.hint'          => 'Max {maxGuests} guests, {maxBeds} beds, at least {minAdults} adult.',
    'cap.min_adults'    => 'Za to enoto je zahtevana vsaj %d odrasla oseba.',
    'cap.max_guests'    => 'Največje dovoljeno število oseb za to enoto je %d (trenutno %d).',
    'cap.max_beds'      => 'Največje dovoljeno število postelj (odrasli + 7–12) za to enoto je %d (trenutno %d).',

    'price.title'       => 'Price summary',
    'price.range_unavailable' => 'Selected range is not available (missing prices for some nights).',
    'price.base'        => 'Base',
    'price.discounts'   => 'Discounts total',
    'price.coupon'      => 'Coupon',
    'price.special_offer'=> 'Special offer',
    'price.cleaning'    => 'Cleaning (per stay)',
    'price.total'       => 'Total',

    'tt.label'          => 'Tourist tax',
    'tt.pay_on_arrival' => 'payable on arrival',
    'tt.after_saving'   => 'Tourist tax to pay after savings',
    'keycard.add'       => 'Add KEYCARD to reduce tourist tax for adults ({rate}/night per person).',

    'contact.name'      => 'Full name',
    'contact.email'     => 'Email',
    'contact.phone'     => 'Phone',
    'contact.message'   => 'Message to us (optional)',

    'ph.email'          => 'your email address',
    'ph.phone'          => 'phone number',
    'placeholder.optional' => 'optional',

    'hint.submit'       => 'Select a valid date range with prices for all nights and a guest group within the allowed limits.',

    'alert.email_required' => 'Email is required to submit an inquiry.',
    'alert.email_invalid'  => 'Please enter a valid email address.',

    'promo.error'       => 'Code is invalid or expired.',
  ]
];

$t = function(string $key, array $vars = []) use ($lang, $T): string {
  $fallback = $lang === 'sl' ? 'en' : 'sl';
  $text = $T[$lang][$key] ?? ($T[$fallback][$key] ?? $key);
  if (!$vars) return $text;
  $repl = [];
  foreach ($vars as $k=>$v) { $repl['{'.$k.'}'] = (string)$v; }
  return strtr($text, $repl);
};


// --- MIN NIGHTS po enoti ---
function cm_get_min_nights_for_unit(string $unit, int $fallback = 1): int {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $unit)) {
        return $fallback;
    }
    $base = realpath(__DIR__ . '/../common/data/json/units');
    if ($base === false) {
        return $fallback;
    }
    $path = $base . DIRECTORY_SEPARATOR . $unit . DIRECTORY_SEPARATOR . 'site_settings.json';
    if (!is_readable($path)) {
        return $fallback;
    }

    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) {
        return $fallback;
    }

    $mn = $json['booking']['min_nights'] ?? null;
    if (is_int($mn) && $mn >= 1 && $mn <= 365) {
        return $mn;
    }

    return $fallback;
}

// --- CLEANING FEE po enoti (override globalnega) ---
function cm_get_cleaning_fee_for_unit(string $unit, ?float $fallback = null): ?float {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $unit)) {
        return $fallback;
    }

    $base = realpath(__DIR__ . '/../common/data/json/units');
    if ($base === false) {
        return $fallback;
    }

    $path = $base . DIRECTORY_SEPARATOR . $unit . DIRECTORY_SEPARATOR . 'site_settings.json';
    if (!is_readable($path)) {
        return $fallback;
    }

    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) {
        return $fallback;
    }

    $booking = $json['booking'] ?? null;
    if (!is_array($booking)) {
        return $fallback;
    }

    $fee = $booking['cleaning_fee_eur'] ?? null;
    if (is_numeric($fee)) {
        return (float)$fee;
    }

    return $fallback;
}


$minNights = cm_get_min_nights_for_unit($unit, 1);

$nights = 0;
if ($from !== '' && $to !== '') {
    // varno pretvarjanje datumov
    try {
        $fromDt = new DateTimeImmutable($from);
        $toDt   = new DateTimeImmutable($to);
        // nočitve = razlika v dnevih (to je odhod, exclusive)
        $nights = max(0, $fromDt->diff($toDt)->days);
    } catch (Exception $e) {
        $nights = 0;
    }
}

// Če je to prvi GET iz koledarja in je termin prekratek, vrnemo 400-stran.
// Pri POST (recalc/submit) ne blokiramo – uporabnik lahko popravi v formi.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $from !== '' && $to !== '' && $nights < $minNights) {
    http_response_code(400);
    ?>
    <!doctype html>
    <html lang="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">
    <head>
      <meta charset="utf-8">
      <title><?= h($t('short.title')) ?></title>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <style>
        body{
          font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
          background:#0b1720;color:#f5f5f5;padding:2rem;line-height:1.6;
        }
        .card{
          max-width:600px;margin:0 auto;border-radius:12px;
          padding:1.5rem 1.8rem;background:#111827;border:1px solid #1f2933;
        }
        a.btn{
          display:inline-block;margin-top:1rem;padding:.5rem 1rem;
          border-radius:999px;border:1px solid #3b82f6;
          color:#e5e7eb;text-decoration:none;
        }
        a.btn:hover{background:#1d4ed8;}
      </style>
    </head>
    <body>
      <div class="card">
        <h1><?= h($t('short.h1')) ?></h1>
        <p>Za izbrano enoto <strong><?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?></strong>
           <?= h($t('short.p_min', ['minNights'=>(int)$minNights])) ?></p>
        <p><?= h($t('short.p_sel', ['nights'=>(int)$nights])) ?></p>
        <p><?= h($t('short.p_hint')) ?></p>
        <p>
          <a class="btn" href="/app/public/pubcal.php?unit=<?= urlencode($unit) ?>">
            Nazaj na koledar
          </a>
        </p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// ------------------------------------------------------------------
// Glavni offer engine
// ------------------------------------------------------------------
define('DATA_ROOT', '/var/www/html/app/common/data/json');

require_once __DIR__ . "/../common/lib/site_settings.php"; // helper za site_settings

// ---------- Helperji ----------
function _eur($v): string { 
  if (!is_numeric($v)) return '-'; 
  return number_format((float)$v, 2, ',', '.') . ' €'; 
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ymd(string $s): string { 
  $t=trim($s); 
  return preg_match('/^\d{4}-\d{2}-\d{2}$/',$t)?$t:''; 
}

// mehki JSON loader (toleranten na trailing vejice itd.)
function ofr_load_json_soft(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path); 
  if ($raw===false) return [];
  $j = json_decode($raw, true);
  if (is_array($j)) return $j;
  $raw_norm = preg_replace("/\r\n?/", "\n", $raw);
  $san = preg_replace('/,\s*([}\]])/m', '$1', $raw_norm);
  $j2 = json_decode($san, true);
  return is_array($j2) ? $j2 : [];
}

function ofr_price_map_for_unit(string $root, string $unit): array {
  $j = ofr_load_json_soft("$root/units/$unit/prices.json");
  $map = [];
  if (!$j) return $map;
  if (isset($j['daily']) && is_array($j['daily'])) {
    foreach ($j['daily'] as $d=>$p) {
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) {
        $map[$d] = is_numeric($p) ? (float)$p : null;
      }
    }
  } else {
    foreach ($j as $d=>$p) {
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) {
        $map[$d] = is_numeric($p) ? (float)$p : null;
      }
    }
  }
  return $map;
}

// PROMO CODES
function ofr_load_promo_config(string $root): array {
  $path = $root . '/units/promo_codes.json';
  $data = ofr_load_json_soft($path);
  if (!is_array($data)) {
    return ['settings'=>[], 'codes'=>[]];
  }

  $settings = [];
  if (isset($data['settings']) && is_array($data['settings'])) {
    $settings = $data['settings'];
  }

  $codes = [];
  if (isset($data['codes']) && is_array($data['codes'])) {
    foreach ($data['codes'] as $c) {
      if (is_array($c)) $codes[] = $c;
    }
  }

  // backward compat: stare korenske vnose
  foreach ($data as $k=>$v) {
    if ((is_int($k) || (is_string($k) && ctype_digit($k))) && is_array($v)) {
      $codes[] = $v;
    }
  }

  return ['settings'=>$settings, 'codes'=>$codes];
}

function ofr_find_valid_promo(array $promoCfg, string $code, string $unit, int $nights): ?array {
  $codeNorm = strtoupper(trim($code));
  if ($codeNorm === '' || empty($promoCfg['codes']) || !is_array($promoCfg['codes'])) {
    return null;
  }
  try {
    $today = new DateTimeImmutable('today');
  } catch (Throwable $e) {
    $today = new DateTimeImmutable();
  }

  foreach ($promoCfg['codes'] as $c) {
    if (!is_array($c)) continue;

    $cCode = strtoupper((string)($c['code'] ?? ($c['id'] ?? '')));
    if ($cCode === '' || $cCode !== $codeNorm) continue;

    // enabled / active
    if (array_key_exists('enabled', $c) && !$c['enabled']) continue;
    if (array_key_exists('active',  $c) && !$c['active'])  continue;

    // unit restriction
    $unitRestr = $c['unit'] ?? null;
    if (is_string($unitRestr) && $unitRestr !== '' && $unitRestr !== $unit) continue;
    if (is_array($unitRestr) && $unitRestr && !in_array($unit, $unitRestr, true)) continue;

    // datumi veljavnosti (po "danes")
    $validFrom = $c['valid_from'] ?? null;
    $validTo   = $c['valid_to']   ?? null;

    if (is_string($validFrom) && $validFrom !== '') {
      try { $fromDate = new DateTimeImmutable($validFrom); } catch (Throwable $e) { $fromDate = null; }
      if ($fromDate && $today < $fromDate) continue;
    }
    if (is_string($validTo) && $validTo !== '') {
      try { $toDate = new DateTimeImmutable($validTo . ' 23:59:59'); } catch (Throwable $e) { $toDate = null; }
      if ($toDate && $today > $toDate) continue;
    }

    // min/max nights
    $minN = isset($c['min_nights']) ? (int)$c['min_nights'] : 0;
    if ($nights < $minN) continue;
    if (isset($c['max_nights']) && $c['max_nights'] !== null && $c['max_nights'] !== '') {
      if ($nights > (int)$c['max_nights']) continue;
    }

    // usage limit
    if (isset($c['usage_limit']) && (int)$c['usage_limit'] > 0 && isset($c['used_count'])) {
      if ((int)$c['used_count'] >= (int)$c['usage_limit']) continue;
    }

    return $c;
  }

  return null;
}

function ofr_calc_promo_discount(array $promo, float $baseAmount): float {
  if ($baseAmount <= 0) return 0.0;

  $type = (string)($promo['type'] ?? '');
  if ($type === 'fixed') {
    $v = (float)($promo['value'] ?? 0);
    if ($v <= 0) return 0.0;
    return min($baseAmount, $v);
  }

  // privzeto percent
  $pct = (float)($promo['value'] ?? ($promo['discount_percent'] ?? 0));
  if ($pct <= 0) return 0.0;

  return round($baseAmount * ($pct / 100.0), 2);
}

// ---------- SPECIAL OFFERS ----------

/**
 * Normalizira special_offers v kanoničen array z:
 *   id, name, from, to, discount_percent, min_nights, max_nights, priority, enabled, active
 *
 * Podpira:
 *  - root { offers:[...] } ali [ ... ]
 *  - periods[] (start,end)
 *  - from/to ali active_from/active_to fallback
 *  - discount{type,value} ali discount_percent
 *  - conditions.min_nights / max_nights
 */
function ofr_load_special_offers(string $root, string $unit): array {
  $path = $root . '/units/' . $unit . '/special_offers.json';
  $raw  = ofr_load_json_soft($path);
  if (!$raw) return [];

  // {offers:[...]} ali [...]
  if (isset($raw['offers']) && is_array($raw['offers'])) {
    $offers = $raw['offers'];
  } elseif (is_array($raw)) {
    $offers = $raw;
  } else {
    return [];
  }

  $out = [];

  foreach ($offers as $o) {
    if (!is_array($o)) continue;

    // enabled / active
    $enabled = array_key_exists('enabled', $o) ? (bool)$o['enabled'] : true;
    $active  = array_key_exists('active',  $o) ? (bool)$o['active']  : true;
    if (!$enabled || !$active) {
      // vseeno ohranimo v out, a kot disabled
      // (da admin vidi v editorju, če bo kdaj bral ta kanoničen format)
    }

    $id   = (string)($o['id']   ?? '');
    $name = (string)($o['name'] ?? '');

    // periods[] ali from/to ali active_from/active_to
    $ranges = [];

    if (isset($o['periods']) && is_array($o['periods']) && $o['periods']) {
      foreach ($o['periods'] as $p) {
        if (!is_array($p)) continue;
        $start = ymd((string)($p['start'] ?? ''));
        $end   = ymd((string)($p['end']   ?? ''));
        if ($start && $end) {
          $ranges[] = [$start, $end];
        }
      }
    }

    if (!$ranges) {
      // fallback na from/to
      $ofFrom = ymd((string)($o['from'] ?? ($o['active_from'] ?? '')));
      $ofTo   = ymd((string)($o['to']   ?? ($o['active_to']   ?? '')));
      if ($ofFrom && $ofTo) {
        $ranges[] = [$ofFrom, $ofTo];
      }
    }

    if (!$ranges) {
      // brez usable ranges -> nič
      continue;
    }

    // discount %
    $discountPercent = null;
    if (isset($o['discount_percent']) && $o['discount_percent'] !== '') {
      $discountPercent = (float)$o['discount_percent'];
    } elseif (isset($o['discount']) && is_array($o['discount'])) {
      $dtype = (string)($o['discount']['type'] ?? '');
      $dval  = (float)($o['discount']['value'] ?? 0);
      if ($dtype === 'percent' && $dval > 0) {
        $discountPercent = $dval;
      }
    }

    if ($discountPercent === null || $discountPercent <= 0) {
      // brez popusta nima smisla za pricing, a ga lahko vseeno prikažeš v UI – tukaj ga preskočimo
      continue;
    }

    // min/max nights
    $minN = 0;
    $maxN = null;
    if (isset($o['min_nights'])) {
      $minN = (int)$o['min_nights'];
    } elseif (isset($o['conditions']['min_nights'])) {
      $minN = (int)$o['conditions']['min_nights'];
    }
    if (isset($o['max_nights'])) {
      $maxN = (int)$o['max_nights'];
    } elseif (isset($o['conditions']['max_nights'])) {
      $maxN = (int)$o['conditions']['max_nights'];
    }

    $priority = isset($o['priority']) ? (int)$o['priority'] : 0;

    foreach ($ranges as [$rFrom, $rTo]) {
      $out[] = [
        'id'               => $id,
        'name'             => $name,
        'from'             => $rFrom,
        'to'               => $rTo,
        'discount_percent' => $discountPercent,
        'min_nights'       => $minN,
        'max_nights'       => $maxN,
        'priority'         => $priority,
        'enabled'          => $enabled,
        'active'           => $active,
      ];
    }
  }

  return $out;
}

/**
 * Najde "najboljšo" special offer za termin (najvišji % popusta).
 * Termin gleda kot [from,to) – odhod je exclusive, tako kot pri rezervaciji.
 */
function ofr_find_best_special_offer(array $offers, string $from, string $to, int $nights): ?array {
  if ($nights <= 0) return null;

  $best = null;
  $bestPct = 0.0;

  foreach ($offers as $o) {
    if (!is_array($o)) continue;

    $enabled = array_key_exists('enabled', $o) ? (bool)$o['enabled'] : true;
    $active  = array_key_exists('active',  $o) ? (bool)$o['active']  : true;
    if (!$enabled || !$active) continue;

    $ofFrom = ymd((string)($o['from'] ?? ''));
    $ofTo   = ymd((string)($o['to']   ?? ''));
    if ($ofFrom === '' || $ofTo === '') continue;

    // bivanje mora biti v celoti v oknu ponudbe
    if ($from < $ofFrom) continue;
    if ($to   > $ofTo)   continue;

    $minN = isset($o['min_nights']) ? (int)$o['min_nights'] : 0;
    if ($nights < $minN) continue;
    if (isset($o['max_nights']) && $o['max_nights'] !== null && $o['max_nights'] !== '') {
      if ($nights > (int)$o['max_nights']) continue;
    }

    $pct = (float)($o['discount_percent'] ?? 0);
    if ($pct <= 0) continue;

    if ($best === null || $pct > $bestPct) {
      $best    = $o;
      $bestPct = $pct;
    }
  }

  return $best;
}

// Utility
function nights_between(string $from, string $to): int {
  try { 
    $a=new DateTime($from); 
    $b=new DateTime($to); 
    if($b<=$a) return 0; 
    return (int)$a->diff($b)->days; 
  } catch(Throwable $e){ 
    return 0; 
  }
}
function per_day_list(string $from, string $to, array $priceMap): array {
  $out=[]; 
  try { 
    $d=new DateTime($from); 
    $toDT=new DateTime($to);
    while($d<$toDT){
      $ds=$d->format('Y-m-d');
      $out[]=[
        'date'=>$ds,
        'price'=>array_key_exists($ds,$priceMap)
          ? (is_numeric($priceMap[$ds])?(float)$priceMap[$ds]:null)
          : null
      ];
      $d->modify('+1 day');
    }
  } catch(Throwable $e){} 
  return $out;
}

// ---------- Vhodni parametri (drugi del, canonical) ----------
$unit = $_GET['unit'] ?? ($_POST['unit'] ?? $unit);
$unit = preg_match('/^[A-Za-z0-9_-]+$/',$unit) ? $unit : 'A1';

$settings = load_site_settings($unit);
// Kapaciteta enote (per-unit), z globalnim defaultom:
// max 6 oseb, max 5 "postelj" (odrasli + 7–12), min 1 odrasel
$capCfg = isset($settings['capacity']) && is_array($settings['capacity'])
    ? $settings['capacity']
    : [];

$CAP_MAX_GUESTS   = (int)($capCfg['max_guests']        ?? 6);
$CAP_MAX_BEDS     = (int)($capCfg['max_beds']          ?? 5);
$CAP_MIN_ADULTS   = (int)($capCfg['min_adults']        ?? 1);
$CAP_MAX_KID06    = isset($capCfg['max_children_0_6']) ? (int)$capCfg['max_children_0_6'] : null;
$CAP_MAX_KID712   = isset($capCfg['max_children_7_12'])? (int)$capCfg['max_children_7_12']: null;
$CAP_ALLOW_BABY   = isset($capCfg['allow_baby_bed'])   ? (bool)$capCfg['allow_baby_bed']  : true;

// Varovalke
if ($CAP_MAX_GUESTS < 1) $CAP_MAX_GUESTS = 1;
if ($CAP_MAX_BEDS   < 1) $CAP_MAX_BEDS   = 1;
if ($CAP_MIN_ADULTS < 1) $CAP_MIN_ADULTS = 1;


// global default (iz /units/site_settings.json, mapping via load_site_settings)
$cleaning_fee = (float)($settings['cleaning_fee'] ?? 45.0);

// per-unit override iz /units/<UNIT>/site_settings.json → booking.cleaning_fee_eur
$cleaning_fee = cm_get_cleaning_fee_for_unit($unit, $cleaning_fee);

$tt_adult_per_night = (float)($settings['tt_adult_per_night']    ?? 2.50);
$tt_kid7_12_factor  = (float)($settings['tt_kid7_12_factor']     ?? 0.5);
$tt_kid0_6_factor   = (float)($settings['tt_kid0_6_factor']      ?? 0.0);
$weekly_threshold   = (int)  ($settings['weekly_threshold']      ?? 7);
$weekly_discount_pct= (float)($settings['weekly_discount_pct']   ?? 10.0);
$chkToWeeklyLabel = $t('chk.to_seven', ['n' => $weekly_threshold]);
$weeklyEnabled     = ($weekly_threshold > 0 && $weekly_discount_pct > 0);
$long_threshold     = (int)  ($settings['long_threshold']        ?? 30);
$long_discount_pct  = (float)($settings['long_discount_pct']     ?? 20.0);

// datumi za izračun
$from = ymd($_GET['from'] ?? ($_POST['from'] ?? $from_raw));
$to   = ymd($_GET['to']   ?? ($_POST['to']   ?? $to_raw));

// skupina
$adults        = (int)($_POST['adults']        ?? 2);
$kids712       = (int)($_POST['kids712']       ?? 0);
$kids06        = (int)($_POST['kids06']        ?? 0);
$keycard_count = (int)($_POST['keycard_count'] ?? 0);
// tedenski popust je odvisen samo od nastavitev, ne od gosta
$weekly_toggle = ($weekly_threshold > 0 && $weekly_discount_pct > 0) ? 1 : 0;

$promo         = trim((string)($_POST['promo'] ?? ''));
$promo_error   = '';

// Normaliziraj v spodnjo mejo 0 (da se ne zgodi negativno)
$adults  = max(0, $adults);
$kids06  = max(0, $kids06);
$kids712 = max(0, $kids712);

// Izračun kapacitete
$totalGuests = $adults + $kids06 + $kids712;
$totalBeds   = $adults + $kids712; // tvoja pravila: odrasli + 7–12 štejejo kot "postelja"

$groupErrors = [];

// Min. odraslih
if ($adults < $CAP_MIN_ADULTS) {
    $groupErrors[] = sprintf(
        $t('cap.min_adults'),
        $CAP_MIN_ADULTS
    );
}

// Max. oseb
if ($totalGuests > $CAP_MAX_GUESTS) {
    $groupErrors[] = sprintf(
        $t('cap.max_guests'),
        $CAP_MAX_GUESTS,
        $totalGuests
    );
}

// Max. postelj (odrasli + 7–12)
if ($totalBeds > $CAP_MAX_BEDS) {
    $groupErrors[] = sprintf(
        $t('cap.max_beds'),
        $CAP_MAX_BEDS,
        $totalBeds
    );
}

// Opcijsko: omejitve po starostnih skupinah, če so nastavljene
if ($CAP_MAX_KID06 !== null && $kids06 > $CAP_MAX_KID06) {
    $groupErrors[] = sprintf(
        'Največje dovoljeno število otrok 0–6 let za to enoto je %d (trenutno %d).',
        $CAP_MAX_KID06,
        $kids06
    );
}
if ($CAP_MAX_KID712 !== null && $kids712 > $CAP_MAX_KID712) {
    $groupErrors[] = sprintf(
        'Največje dovoljeno število otrok 7–12 let za to enoto je %d (trenutno %d).',
        $CAP_MAX_KID712,
        $kids712
    );
}

// kontakt
$name   = trim((string)($_POST['name']   ?? ''));
$email  = trim((string)($_POST['email']  ?? ''));
$phone  = trim((string)($_POST['phone']  ?? '')); // telefon

// osnovni izračuni
$nights   = ($from && $to) ? nights_between($from,$to) : 0;
$priceMap = ofr_price_map_for_unit(DATA_ROOT, $unit);
$perDay   = ($nights>0) ? per_day_list($from,$to,$priceMap) : [];

$has_all_prices = true;
$base_total = 0.0;
foreach ($perDay as $row){
  if ($row['price']===null){ $has_all_prices = false; }
  $base_total += (float)($row['price'] ?? 0);
}

// tedenski / long popusti
$disc_weekly = 0.0;
$disc_long   = 0.0;
if ($nights >= $weekly_threshold && $weekly_toggle) {
  $disc_weekly = round($base_total * ($weekly_discount_pct/100.0), 2);
}
if ($nights >= $long_threshold) {
  $disc_long = round($base_total * ($long_discount_pct/100.0), 2);
}
$discounts_total = $disc_weekly + $disc_long;

// PROMO koda
$promo_discount = 0.0;
$calc_promo     = 0.0;
$promo_label_percent = 0;

if ($promo !== '' && $nights > 0 && $base_total > 0) {
  $promoCfg   = ofr_load_promo_config(DATA_ROOT);
  $foundPromo = ofr_find_valid_promo($promoCfg, $promo, $unit, $nights);
  if ($foundPromo) {
    $baseForPromo   = max(0.0, $base_total - $discounts_total);
    $promo_discount = ofr_calc_promo_discount($foundPromo, $baseForPromo);
    $calc_promo     = $promo_discount;
    if ($promo_discount > 0 && $baseForPromo > 0) {
      $promo_label_percent = (int)round($promo_discount / $baseForPromo * 100);
    }
  } else {
    $promo_error = $t('promo.error');
  }
}

// čiščenje
$cleaning = $nights>0 ? (float)$cleaning_fee : 0.0;

// TT
$tt_adult = $tt_adult_per_night;
$tt_kid12 = $tt_adult * $tt_kid7_12_factor;
$tt_kid06 = $tt_adult * $tt_kid0_6_factor;
$tt_total = round($nights * ($adults*$tt_adult + $kids712*$tt_kid12 + $kids06*$tt_kid06), 2);

/* KEYCARD prihranek */
$cards_effective = max(0, min($keycard_count, $adults));
$keycard_saving  = round($nights * $cards_effective * $tt_adult, 2);
$tt_net_to_pay   = max(0, $tt_total - $keycard_saving);
$keycard_break   = $cards_effective>0
  ? ($cards_effective.' × '.$nights.' × '._eur($tt_adult).' = '._eur($keycard_saving))
  : '';

// ---- SPECIAL OFFERS (popust samo na nočitve, brez čiščenja in TT) ----
$special_offers_discount = 0.0;
$special_offer_name      = '';
$special_offer_percent   = 0.0;

// baza za special offers = nočitve po tedenskih/long + kuponu
$accommodation_for_offers = max(0, $base_total - $discounts_total - $promo_discount);

// končna baza pred special offers = nočitve + čiščenje (TT ločeno)
$final_before_offers = $accommodation_for_offers + $cleaning;

if ($nights > 0 && $accommodation_for_offers > 0) {
  $allOffers = ofr_load_special_offers(DATA_ROOT, $unit);
  $bestOffer = ofr_find_best_special_offer($allOffers, $from, $to, $nights);
  if ($bestOffer) {
    $special_offer_name    = (string)($bestOffer['name'] ?? 'Posebna ponudba');
    $special_offer_percent = (float)($bestOffer['discount_percent'] ?? 0);
    if ($special_offer_percent > 0) {
      $special_offers_discount = round($accommodation_for_offers * ($special_offer_percent / 100.0), 2);
    }
  }
}

// Popusti skupaj = tedenski/long + kupon + special offers
$display_total_discounts = $discounts_total + $promo_discount + $special_offers_discount;

// Končna cena za prikaz gostu (brez TT, TT se plača na lokaciji)
$final_total = max(0, $final_before_offers - $special_offers_discount);

?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= h($t('title.offer', ['unit'=>$unit])) ?></title>
<style>
  :root{ --panel:#121a2b; --muted:#9fb0cc; --line:#1d2530; --ok:#10b981; --danger:#ef4444; }
   html,body{
    margin:0;
    padding:0;
    background:#0c1425;
    color:#e8f0ff;
    font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;
    width:100%;
    max-width:100%;
    overflow-x:hidden;
  }
  .wrap{
    max-width:1100px;
    margin:24px auto;
    padding:0 16px;
  }

  .h{font-size:18px;margin:0 0 10px}

  /* generična "vrstica" – tudi za vrstice v izračunu */
  .row{
    display:flex;
    gap:8px;
    align-items:center;
    justify-content:space-between;
  }

  /* glavna vrstica z LEVO in DESNO kartico */
  .row-main{
    gap:10px;
    align-items:flex-start;
  }

  .row-main .card{
    flex:1 1 0;
  }

  .card{
    background:var(--panel);border:1px solid var(--line);
    border-radius:16px;padding:6px;box-shadow:0 1px 2px rgba(0,0,0,.2)
  }

  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  label{display:flex;flex-direction:column;gap:6px;font-size:14px}
  input,select,textarea{
    background:#0b1322;color:#e8f0ff;border:1px solid #22324b;
    border-radius:10px;padding:5px;outline:none; max-width:130px;
  }
  input[type="number"]{max-width:40px}
  .controls{display:flex;gap:8px;align-items:center;margin:8px 0}
  button.btn{
    padding:10px 14px;border-radius:10px;border:1px solid #26446f;
    background:#10233f;color:#e8f0ff;cursor:pointer
  }
  button.btn:hover{background:#0e1f36}
  button.btn-ghost{
    padding:8px 12px;border-radius:10px;border:1px solid #26446f;
    background:transparent;color:#cfe1ff;cursor:pointer
  }
  button.btn-ghost:hover{background:#0e1f36}
  .btn{color:#e8f0ff}
  .muted{color:var(--muted)}
  .right{text-align:right}
  .sum{font-weight:700}
  .ok{color:var(--ok)}
  .danger{color:var(--danger)}
  .smallnum{ max-width:3.5ch; text-align:center; }
  .table{width:100%;border-collapse:collapse;margin-top:8px}
  .table th,.table td{border-bottom:1px solid #1f2a3a;padding:6px 8px;text-align:left}
  details summary{cursor:pointer;color:#cfe1ff}
  .contact .grid-2{
    display:grid !important;
    grid-template-columns: repeat(2, minmax(0, 220px)) !important;
    column-gap: 20px !important; row-gap: 12px !important;
    justify-content: start !important; margin-left: 20px !important;
  }
  .contact input,.contact textarea{ max-width:180px !important; }

  /* ikone v date/month inputih */
  .grid-2 input[type="date"]::-webkit-calendar-picker-indicator,
  .grid-2 input[type="month"]::-webkit-calendar-picker-indicator {
    filter: invert(0.8) sepia(1) saturate(5) hue-rotate(10deg);
    cursor: pointer;
  }

  @media (max-width:1024px){
    /* samo glavna vrstica kartic gre v stolpec */
    .row-main{
      flex-direction:column;
    }
    .row-main .card{
      width:95%;
    }

    .contact .grid-2{
      grid-template-columns:1fr !important;
      margin-left:0 !important;
    }
    .contact input,
    .contact textarea{
      max-width:95% !important;

    }
  }

  @media (max-width:900px){
    /* malo manj paddinga in margine na telefonu, da ne "tišči" desno */
    .wrap{
      margin:10px auto;
      padding:0 10px;

    }

    .row{flex-direction:left;}

    .contact .grid-2{
      grid-template-columns:1fr !important;
      margin-left:0 !important;
    }
    .contact input,
    .contact textarea{
      max-width:100% !important;
    }
  }



</style>
</head>
<body>
<div class="wrap">

  <h1><?= h($t('h1.offer', ['unit'=>$unit])) ?></h1>

  <form id="offerForm" method="post" action="/app/public/offer.php">
    <input type="hidden" name="unit" value="<?=h($unit)?>">
    <input type="hidden" name="lang" value="<?=h($lang)?>">

  <div class="row row-main">
    <!-- LEVA KARTICA -->
    <div class="card">
      <h2 class="h" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
        <span><?= h($t('section.term')) ?></span>
        <a id="backLink" class="btn" href="#"><?= h($t('btn.back_calendar')) ?></a>
      </h2>

      <div class="grid-2">
        <label><?= h($t('label.from')) ?>
          <input type="date" id="from" name="from" value="<?=h($from)?>">
        </label>
        <label><?= h($t('label.to')) ?>
          <input type="date" id="to" name="to" value="<?=h($to)?>">
        </label>
      </div>

<div class="controls">
  <strong><span id="nights"><?=$nights?></span> <?= h($t('label.nights')) ?></strong>
  <button type="button" class="btn" id="btnMinus"><?= h($t('btn.minus_day')) ?></button>
  <button type="button" class="btn" id="btnPlus"><?= h($t('btn.plus_day')) ?></button>

  <?php if ($weeklyEnabled): ?>
    <span id="weeklyHint"
          style="margin-left:8px;font-size:0.9em;"></span>
    <button type="button"
            class="btn"
            id="btnExtendWeekly"
            style="cursor:pointer;font-size:0.9em;">
      <?= h($chkToWeeklyLabel) ?>
    </button>
  <?php endif; ?>
</div>



      <details style="margin-top:10px" <?= $nights>0 ? '' : 'open'?>>
        <summary><?= h($t('details.daily_breakdown')) ?></summary>
        <?php if ($nights>0): ?>
        <table class="table">
          <thead><tr><th><?= h($t('table.date')) ?></th><th><?= h($t('table.price')) ?></th></tr></thead>
          <tbody>
          <?php foreach($perDay as $row): ?>
            <tr>
              <td><?=h($row['date'])?></td>
              <td><?= $row['price']===null ? '<span class="danger">'.h($t('table.missing')).'</span>' : _eur($row['price']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="muted"><?= h($t('hint.pick_range')) ?></div>
        <?php endif; ?>
      </details>

            <h2 class="h" style="margin-top:10px"><?= h($t('section.group')) ?></h2>

      <?php if (!empty($groupErrors)): ?>
        <div class="danger" style="margin:6px 0 10px;">
          <ul style="margin:0 0 0 18px;padding:0;">
            <?php foreach ($groupErrors as $err): ?>
              <li><?= h($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <div class="muted small" style="margin:4px 0 8px;">
          <?= h($t('cap.hint', ['maxGuests'=>(int)$CAP_MAX_GUESTS,'maxBeds'=>(int)$CAP_MAX_BEDS,'minAdults'=>(int)$CAP_MIN_ADULTS])) ?>
        </div>
      <?php endif; ?>

      <div class="grid-2">
        <!-- vrstica 1: odrasli / otroci 7-12 -->
        <label><?= h($t('label.adults')) ?>
          <input
            type="number"
            name="adults"
            value="<?= (int)$adults ?>"
            min="<?= (int)$CAP_MIN_ADULTS ?>"
            max="<?= (int)$CAP_MAX_BEDS ?>"
            step="1"
            class="smallnum"
            required
          >
        </label>

        <label><?= h($t('label.kids712')) ?>
          <input
            type="number"
            name="kids712"
            value="<?= (int)$kids712 ?>"
            min="0"
            step="1"
            class="smallnum"
          >
        </label>

        <!-- vrstica 2: otroci 0-6 / KEYCARD -->
        <label><?= h($t('label.kids06')) ?>
          <input
            type="number"
            name="kids06"
            value="<?= (int)$kids06 ?>"
            min="0"
            step="1"
            class="smallnum"
          >
        </label>

        <label><?= h($t('label.keycard')) ?>
          <input
            type="number"
            name="keycard_count"
            value="<?= (int)$keycard_count ?>"
            min="0"
            step="1"
            class="smallnum"
          >
        </label>

	<?php $weeklyEnabled = ($weekly_threshold > 0 && $weekly_discount_pct > 0); ?>
	<!-- vrstica 3: info o tedenskem popustu / promo koda (DESNO) -->
	<div class="muted">
 	 <?= h($t('label.weekly')) ?>:
	  <?= $weeklyEnabled ? h($t('opt.yes')) : h($t('opt.no')) ?>
	</div>


        <label><?= h($t('label.promo')) ?>
          <?php if ($promo_error): ?>
            <div class="danger small mt-1"><?= h($promo_error) ?></div>
          <?php endif; ?>
          <input
            type="text"
            name="promo"
            value="<?= h($promo) ?>"
            placeholder="<?= h($t('placeholder.optional')) ?>"
            style="max-width:140px"
          >
        </label>
      </div>


      <div class="mt-4">
        <button type="submit" class="btn" name="action" value="recalc" formnovalidate>Osveži izračun</button>
        <button type="button" id="btnCheckNew" class="btn btn-secondary"><?= h($t('btn.check_new')) ?></button>
      </div>
    </div>
  </form>

    <!-- DESNA KARTICA -->
    <div class="card">
      <h2 class="h"><?= h($t('price.title')) ?></h2>

      <?php if ($nights>0 && !$has_all_prices): ?>
        <div class="danger"><?= h($t('price.range_unavailable')) ?></div>
      <?php endif; ?>

      <div class="row"><div><?= h($t('price.base')) ?></div><div class="right"><?= _eur($base_total) ?></div></div>
      <div class="row"><div><?= h($t('price.discounts')) ?></div><div class="right ok">− <?= _eur($display_total_discounts) ?></div></div>

      <?php if ($disc_weekly>0): ?>
        <div class="row">
          <div class="muted">7+ (<?= (float)$weekly_discount_pct ?>%)</div>
          <div class="right muted">− <?= _eur($disc_weekly) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($disc_long>0): ?>
        <div class="row">
          <div class="muted">30+ (<?= (float)$long_discount_pct ?>%)</div>
          <div class="right muted">− <?= _eur($disc_long) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($promo_discount>0): ?>
        <div class="row">
          <div><?= h($t('price.coupon')) ?><?php if ($promo_label_percent > 0): ?>(<?= (int)$promo_label_percent ?>%)<?php endif; ?></div>
          <div class="right muted">− <?= _eur($promo_discount) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($special_offers_discount>0): ?>
        <div class="row">
          <div>
            <?= h($t('price.special_offer')) ?><?= $special_offer_name !== '' ? ' – '.h($special_offer_name) : '' ?>
            <?php if ($special_offer_percent > 0): ?>(<?= (float)$special_offer_percent ?>%)<?php endif; ?>
          </div>
          <div class="right muted">− <?= _eur($special_offers_discount) ?></div>
        </div>
      <?php endif; ?>

      <div class="row"><div><?= h($t('price.cleaning')) ?></div><div class="right"><?= _eur($cleaning) ?></div></div>
      <div class="row"><div><?= h($t('price.total')) ?></div><div class="right sum"><?= _eur($final_total) ?></div></div>

      <div class="mt-3 muted">
        <?= h($t('tt.label')) ?> <b>(<?= h($t('tt.pay_on_arrival')) ?>)</b>: <?= _eur($tt_total) ?>.<br>
        <?php if ($cards_effective>0): ?>
          Potencialni prihranek s KEYCARD (<?= (int)$cards_effective ?> kartic): <b><?= _eur($keycard_saving) ?></b><br>
          <?= h($t('tt.after_saving')) ?>: <b><?= _eur($tt_net_to_pay) ?></b><br>
          <span class="muted"><?= h($t('label.breakdown')) ?>: <?= h($keycard_break) ?></span>
        <?php else: ?>
          <?= h($t('keycard.add', ['rate'=>_eur($tt_adult)])) ?>
        <?php endif; ?>
      </div>

      <h2 class="h" style="margin-top:16px"><?= h($t('contact.title')) ?></h2>
      <div class="contact">
        <div class="grid-2">
          <label><?= h($t('contact.name')) ?>
            <input type="text" id="guest_name" name="name" value="<?=h($name)?>" required>
          </label>
          <label><?= h($t('contact.email')) ?> <span style="color:#e57373">*</span>
            <input type="email"
                   id="guest_email"
                   name="email"
                   value="<?=h($email)?>"
                   required
                   autocomplete="email"
                   inputmode="email"
                   placeholder="<?= h($t('ph.email')) ?>">
          </label>
          <label><?= h($t('contact.phone')) ?>

            <input type="text" id="guest_phone" name="phone" value="<?=h($phone)?>" placeholder="<?= h($t('ph.phone')) ?>">
          </label>
          <label style="grid-column:1 / -1">Sporočilo za nas (neobvezno)
            <textarea name="message" rows="3" placeholder="<?= h($t('placeholder.optional')) ?>"></textarea>
          </label>
        </div>
      </div>

      <!-- SUBMIT – vedno prikazan; gumb se omogoča/onesposablja -->
      <form id="submitForm" action="/app/public/api/submit_inquiry.php" method="post" style="margin-top:16px">
        <input type="hidden" name="unit" value="<?=h($unit)?>">
        <input type="hidden" name="from" id="h_from" value="<?=h($from)?>">
        <input type="hidden" name="to" id="h_to" value="<?=h($to)?>">
        <input type="hidden" name="nights" id="h_nights" value="<?= (int)$nights ?>">

        <!-- skupina -->
        <input type="hidden" name="adults"  value="<?= (int)$adults ?>">
        <input type="hidden" name="kids712" value="<?= (int)$kids712 ?>">
        <input type="hidden" name="kids06"  value="<?= (int)$kids06 ?>">
        <input type="hidden" name="weekly"  value="<?= (int)$weekly_toggle ?>">
        <input type="hidden" name="keycard_count" value="<?= (int)$keycard_count ?>">
        <input type="hidden" name="promo" value="<?= h($promo) ?>">

        <!-- kontakt -->
        <input type="hidden" name="guest_name"  id="h_guest_name"  value="<?=h($name)?>">
        <input type="hidden" name="guest_email" id="h_guest_email" value="<?=h($email)?>">
        <input type="hidden" name="guest_phone" id="h_guest_phone" value="<?=h($phone)?>">
        <input type="hidden" name="guest_message" id="h_guest_message" value="">

        <!-- finančna polja -->
        <input type="hidden" name="calc_base"       id="h_calc_base"     value="<?= number_format($base_total,      2, '.', '') ?>">
        <input type="hidden" name="calc_discounts"  id="h_calc_discounts"value="<?= number_format($discounts_total, 2, '.', '') ?>">
        <input type="hidden" name="calc_promo"      id="h_calc_promo"    value="<?= number_format($calc_promo,      2, '.', '') ?>">
        <input type="hidden" name="promo_code"      id="h_promo_code"    value="<?= h($promo) ?>">
        <input type="hidden" name="calc_cleaning"   id="h_calc_cleaning" value="<?= number_format($cleaning,        2, '.', '') ?>">
        <input type="hidden" name="calc_final"      id="h_calc_final"    value="<?= number_format($final_total,     2, '.', '') ?>">
        <input type="hidden" name="calc_tt"         value="<?= number_format($tt_total,         2, '.', '') ?>">
        <input type="hidden" name="calc_keycard"    value="<?= number_format($keycard_saving,   2, '.', '') ?>">
        <input type="hidden" name="calc_keycard_breakdown" value="<?= h($keycard_break) ?>">
        <input type="hidden" name="calc_special_offers" value="<?= number_format($special_offers_discount, 2, '.', '') ?>">
        <input type="hidden" name="special_offer_name"   value="<?= h($special_offer_name) ?>">
        <input type="hidden" name="special_offer_pct"    value="<?= number_format($special_offer_percent, 2, '.', '') ?>">
        <input type="hidden" name="calc_weeklylen"  value="<?= (int)$weekly_threshold ?>">
        <input type="hidden" name="calc_weeklypct"  value="<?= number_format($weekly_discount_pct, 2, '.', '') ?>">
        <input type="hidden" name="calc_longthr"    value="<?= (int)$long_threshold ?>">
        <input type="hidden" name="calc_longpct"    value="<?= number_format($long_discount_pct, 2, '.', '') ?>">

        <button type="submit" class="btn" id="btnSubmit"><?= h($t('btn.send_inquiry')) ?></button>
        <div id="submitHint" class="muted" style="margin-top:6px;display:none">
          <?= h($t('hint.submit')) ?>
        </div>

      </form>
    </div>
  </div>

</div>

<script>
(function(){
  'use strict';

  const elFrom   = document.getElementById('from');
  const elTo     = document.getElementById('to');
  const elN      = document.getElementById('nights');
  const weeklyHint      = document.getElementById('weeklyHint');
  const btnExtendWeekly = document.getElementById('btnExtendWeekly');
  const btnMinus = document.getElementById('btnMinus');
  const btnPlus  = document.getElementById('btnPlus');
  const WEEKLY_THRESHOLD = <?= (int)$weekly_threshold ?>;

  const lang = '<?= h($lang) ?>';

  function formatWeeklyHintMissing(diff) {
    if (diff <= 0) return '';
    if (lang === 'en') {
      if (diff === 1) return 'Add 1 more night to get the weekly discount.';
      return `Add ${diff} more nights to get the weekly discount.`;
    } else {
      if (diff === 1) return 'Manjka vam še 1 noč do tedenskega popusta.';
      return `Manjka vam še ${diff} noči do tedenskega popusta.`;
    }
  }

  const MSG_WEEKLY_REACHED =
    lang === 'en'
      ? 'Weekly discount is applied.'
      : 'Tedenski popust je uveljavljen.';


  const backLink    = document.getElementById('backLink');
  const btnCheckNew = document.getElementById('btnCheckNew');

  const fSubmit  = document.getElementById('submitForm');
  const btnSubmit= document.getElementById('btnSubmit');
  const hintSub  = document.getElementById('submitHint');

  const h_from = document.getElementById('h_from');
  const h_to   = document.getElementById('h_to');
  const h_n    = document.getElementById('h_nights');
  const h_gn   = document.getElementById('h_guest_name');
  const h_ge   = document.getElementById('h_guest_email');
  const h_gp   = document.getElementById('h_guest_phone');
  const h_gm   = document.getElementById('h_guest_message');

  const GROUP_OK = <?= empty($groupErrors) ? 'true' : 'false' ?>;


  function ymd(d){ return d.toISOString().slice(0,10); }
  function parseDate(v){ const t = Date.parse(v); return isNaN(t) ? null : new Date(t); }
  function days(a,b){ return Math.round((b - a)/86400000); }

  function updateBackLink(){
    if (!backLink || !elFrom || !elTo) return;

    const u = new URL('/app/public/pubcal.php', window.location.origin);
    u.searchParams.set('unit', '<?=h($unit)?>');

    const from = elFrom.value || '';
    const to   = elTo.value   || '';

    if (from) u.searchParams.set('from', from);
    if (to)   u.searchParams.set('to',   to);

    // enkratni "carry" žeton
    u.searchParams.set('carry', '<?=h($CARRY_TOKEN)?>');
    // ohrani jezik skozi flow
    u.searchParams.set('lang', '<?=h($lang)?>');

    backLink.href = u.pathname + u.search;
  }

  function setSubmitState(valid){
    if (!btnSubmit) return;

    // Submit je dovoljen samo, če:
    //  - je termin veljaven + pokrite vse cene (valid === true)
    //  - in je skupina gostov znotraj omejitev (GROUP_OK === true)
    const ok = !!valid && !!GROUP_OK;

    btnSubmit.disabled = !ok;

    if (hintSub) {
      // če je termin OK, a je skupina napačna, hint vseeno pokažemo –
      // tekst spodaj bo posodobljen, da omeni tudi skupino
      hintSub.style.display = ok ? 'none' : 'block';
    }
  }


  function recalc(){
    if (!elFrom || !elTo) return;

    const d1 = parseDate(elFrom.value);
    const d2 = parseDate(elTo.value);

    if (!d1 || !d2 || d2 <= d1){
      if (elN) elN.textContent = '0';
      if (h_n) h_n.value = '0';
      updateBackLink();
      setSubmitState(false);
      return;
    }

      const n = days(d1, d2);
    if (elN) elN.textContent = String(n);

    if (h_from) h_from.value = elFrom.value;
    if (h_to)   h_to.value   = elTo.value;
    if (h_n)    h_n.value    = String(n);

    // namig za tedenski popust
    if (weeklyHint && typeof WEEKLY_THRESHOLD === 'number' && WEEKLY_THRESHOLD > 0) {
      if (n <= 0) {
        weeklyHint.textContent = '';
        if (btnExtendWeekly) btnExtendWeekly.style.display = 'none';
      } else if (n < WEEKLY_THRESHOLD) {
        const diff = WEEKLY_THRESHOLD - n;
        weeklyHint.textContent = formatWeeklyHintMissing(diff);
        if (btnExtendWeekly) btnExtendWeekly.style.display = 'inline-block';
      } else {
        weeklyHint.textContent = MSG_WEEKLY_REACHED;
        if (btnExtendWeekly) btnExtendWeekly.style.display = 'none';
      }
    }

    updateBackLink();

    // Submit je dovoljen samo, če imamo noči > 0 in so cene pokrite za vse noči
    setSubmitState(n > 0 && <?= $has_all_prices ? 'true' : 'false' ?>);
    }

  function shift(daysDelta){
    if (!elFrom || !elTo) return;
    const d1 = parseDate(elFrom.value);
    const d2 = parseDate(elTo.value);
    if (!d1 || !d2) return;
    d2.setDate(d2.getDate() + daysDelta);
    elTo.value = ymd(d2);
    recalc();
  }

  // + / − gumbi
  if (btnPlus)  btnPlus.addEventListener('click',  function(e){ e.preventDefault(); shift(1); });
  if (btnMinus) btnMinus.addEventListener('click', function(e){ e.preventDefault(); shift(-1); });

 
  // Gumb: razširi termin na WEEKLY_THRESHOLD noči
  if (btnExtendWeekly) {
    btnExtendWeekly.addEventListener('click', function(e){
      e.preventDefault();
      if (!elFrom || !elTo) return;
      const d1 = parseDate(elFrom.value);
      if (!d1) return;

      const newTo = new Date(d1.getTime());
      newTo.setDate(newTo.getDate() + WEEKLY_THRESHOLD); // +7 dni -> 7 noči, +6 -> 6 noči ...
      elTo.value = ymd(newTo);
      recalc();
    });
  }


  // prenesi kontakt v hidden pred submitom + preveri e-pošto
  if (fSubmit) {
    const gn = document.getElementById('guest_name');
    const ge = document.getElementById('guest_email');
    const gp = document.getElementById('guest_phone');
    const gm = document.querySelector('textarea[name="message"]');

    fSubmit.addEventListener('submit', function(e){
      // 1) e-mail je OBVEZEN
      if (ge) {
        const email = (ge.value || '').trim();

        if (!email) {
          e.preventDefault();
          alert(<?= json_encode($t('alert.email_required'), JSON_UNESCAPED_UNICODE) ?>);
          ge.focus();
          return;
        }

        // zelo enostaven pattern – dovolj za basic validacijo
        const emailPattern = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
        if (!emailPattern.test(email)) {
          e.preventDefault();
          alert(<?= json_encode($t('alert.email_invalid'), JSON_UNESCAPED_UNICODE) ?>);
          ge.focus();
          return;
        }
      }

      // 2) šele nato prenesemo podatke v hidden polja
      if (h_gn && gn) h_gn.value = (gn.value || '').trim();
      if (h_ge && ge) h_ge.value = (ge.value || '').trim();
      if (h_gp && gp) h_gp.value = (gp.value || '').trim();
      if (h_gm && gm) h_gm.value = (gm.value || '').trim();
    });
  }


  // začetni izračun in nastavitev linka
  // reagiraj na ročne spremembe datumov
  if (elFrom) {
    elFrom.addEventListener('change', recalc);
    elFrom.addEventListener('input', recalc);
  }
  if (elTo) {
    elTo.addEventListener('change', recalc);
    elTo.addEventListener('input', recalc);
  }

  // začetni izračun in nastavitev linka
  recalc();

  // Gumb "Preveri novo izbiro" – vrne na koledar z novo izbiro termina
  if (btnCheckNew) {
    btnCheckNew.addEventListener('click', function (e) {
      e.preventDefault();
      // osveži noči in backLink na podlagi trenutnih datumov
      recalc();
      // preusmeri na posodobljen link za koledar
      if (backLink && backLink.href) {
        window.location.href = backLink.href;
      }
    });
  }



})();
</script>

</body>
</html>
