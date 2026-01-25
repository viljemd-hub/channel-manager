<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/pubcal.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// ==== PUBCAL: early guard for range params, single-use carry, and session timeout ====
session_start();

$STRIP_KEYS = ['from','to','start','end','sel','selection','carry','keepRange'];
$KEEP_UNIT  = true;                 // če želiš reset tudi 'unit', spremeni na false
$SESSION_TTL_MIN = 10;              // po koliko minutah neaktivnosti "seja je potekla"
$now = time();

// 1) timeout (neaktivnost)
if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > $SESSION_TTL_MIN * 60) {
    // potečeno: resetiraj carry in oznaci flash sporočilo
    unset($_SESSION['carry_token']);
    $_SESSION['flash_msg'] = 'Vaša seja je potekla. Koledar je bil ponastavljen.';
    // spucaj range iz URL-ja spodaj v koraku (2)
}
$_SESSION['last_activity'] = $now;

// 2) strip/allow range glede na carry token
$hasRange = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['start']) || isset($_GET['end']) || isset($_GET['sel']) || isset($_GET['selection']);
if ($hasRange) {
    $carry = $_GET['carry'] ?? '';
    $ok = isset($_SESSION['carry_token']) && hash_equals($_SESSION['carry_token'], $carry);

    if ($ok) {
        // enkratna uporaba: pusti range, nato žeton uniči
        unset($_SESSION['carry_token']);
    } else {
        // strip range parametrov in preusmeri na čist URL
        $query = $_GET;
        foreach ($STRIP_KEYS as $k) unset($query[$k]);
        if (!$KEEP_UNIT) unset($query['unit']);

	$reqUri = $_SERVER['REQUEST_URI'] ?? '';
	$path = parse_url($reqUri, PHP_URL_PATH);
	if (!$path) {
	    // Fallback to script name if REQUEST_URI is not available (CLI, unusual env)
	    $path = $_SERVER['SCRIPT_NAME'] ?? '/pubcal.php';
	}

        $qs   = http_build_query($query);
        $url  = $path . ($qs ? ('?'.$qs) : '');

        // anti-cache, da se povratek/back ne lepi
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $url, true, 302);
        exit;
    }
}

// 3) (opcijsko) flash sporočilo za “seja je potekla” — preberi in počisti
$PUBCAL_FLASH = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);

// ---- nadaljuje se tvoj obstoječi pubcal.php (HTML/echo/…)


// /var/www/html/app/public/pubcal.php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] 
    ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');


define('PUBLIC_BASE_URL', $scheme . '://' . $host);

// Prebere parametre iz URL-ja (če prideš nazaj iz offer_v1.php ali refresha)
$unit = isset($_GET['unit']) ? $_GET['unit'] : 'A1';
$from = isset($_GET['from']) ? $_GET['from'] : '';
$to   = isset($_GET['to'])   ? $_GET['to']   : '';

// dovolimo generične ID-je, a jih kasneje na frontendu
// filtriramo skozi manifest.units (public+active)
$unit = preg_replace('/[^A-Za-z0-9_-]/', '', $unit);
if ($unit === '') {
    $unit = 'A1';
}
//  MIN NIGHTS – per-unit iz site_settings.json (z globalnim fallbackom)
$ENFORCE_MIN_NIGHTS = false; // frontend naj še vedno ostane “soft” validator

$GLOBAL_SETTINGS_FILE = __DIR__ . '/../common/data/json/units/site_settings.json';
$UNIT_SETTINGS_FILE   = __DIR__ . '/../common/data/json/units/' . $unit . '/site_settings.json';

// varna privzeta vrednost
$MIN_NIGHTS = 1;

// preberi globalni min_nights (nova struktura booking.min_nights ali legacy min_nights)
$globalMin = null;
if (is_file($GLOBAL_SETTINGS_FILE)) {
    $raw = @file_get_contents($GLOBAL_SETTINGS_FILE);
    if ($raw !== false) {
        $g = json_decode($raw, true);
        if (is_array($g)) {
            if (isset($g['booking']['min_nights'])) {
                $globalMin = (int)$g['booking']['min_nights'];
            } elseif (isset($g['min_nights'])) { // legacy
                $globalMin = (int)$g['min_nights'];
            }
        }
    }
}

// preberi per-unit min_nights, če obstaja
$unitMin = null;
if (is_file($UNIT_SETTINGS_FILE)) {
    $raw = @file_get_contents($UNIT_SETTINGS_FILE);
    if ($raw !== false) {
        $u = json_decode($raw, true);
        if (is_array($u)) {
            if (isset($u['booking']['min_nights'])) {
                $unitMin = (int)$u['booking']['min_nights'];
            } elseif (isset($u['min_nights'])) { // legacy fallback
                $unitMin = (int)$u['min_nights'];
            }
        }
    }
}

// prioriteta: per-unit > global > default 1
if ($unitMin !== null && $unitMin > 0) {
    $MIN_NIGHTS = $unitMin;
} elseif ($globalMin !== null && $globalMin > 0) {
    $MIN_NIGHTS = $globalMin;
}


// --------- JEZIK (public SLO/EN) ---------
$supportedLangs = ['sl','en'];

// 1) poberi lang iz ?lang= ali daj default
$lang = $_GET['lang'] ?? null;
if (!in_array($lang, $supportedLangs, true)) {
    // če želiš, lahko tu enkratno pogledaš še HTTP_ACCEPT_LANGUAGE
    $lang = 'sl'; // default
}

// 2) enostavna tabela prevodov za to stran
$T = [
    'sl' => [
        'title'          => 'Razpoložljivost & cene – Apartma Matevž',
        'brand'          => 'Apartma Matevž',
        'brand.reservations' => 'Rezervacije',
        'tagline'        => 'Lastni rezervacijski sistem',
        'nav.today'      => 'Danes',
        'dayuse.label'   => 'Dnevni počitek',
        'legend.dayuse'  => 'Dnevni počitek',
        'legend.offers'  => 'Posebne ponudbe',
        'btn.clear'      => 'Počisti',
        'btn.confirm'    => 'Potrdi izbiro',
        'footer.note'    => 'Za dodatna vprašanja ali daljša bivanja nas kontaktirajte. Če bi podobno koledarsko rešitev CM Free radi uporabljali tudi za svojo nastanitev, pišite razvijalcu na viljem.d@gmail.com.',
        'lang.sl'        => 'Slovenščina',
        'lang.en'        => 'Angleščina',
        'flag.work'      => 'V delu'
    ],
    'en' => [
        'title'          => 'Availability & prices – Apartment Matevž',
        'brand'          => 'Apartment Matevž',
        'brand.reservations' => 'Bookings',
        'tagline'        => 'Self-hosted booking system',
        'nav.today'      => 'Today',
        'dayuse.label'   => 'Day-use stay',
        'legend.dayuse'  => 'Day-use',
        'legend.offers'  => 'Special offers',
        'btn.clear'      => 'Clear',
        'btn.confirm'    => 'Get offer',
        'footer.note'    => 'For any questions or longer stays, feel free to contact us. If you would like to use this CM Free calendar system for your own property, contact the developer at viljem.d@gmail.com.',
        'lang.sl'        => 'Slovene',
        'lang.en'        => 'English',
        'flag.work'      => 'Work in progress'
    ],

];

// 3) helper za prevod na tej strani
$t = function(string $key) use ($lang, $T): string {
    if (isset($T[$lang][$key])) return $T[$lang][$key];
    $fallback = $lang === 'sl' ? 'en' : 'sl';
    return $T[$fallback][$key] ?? $key;
};

// Product meta (edition + version) for header badges / title.
require_once __DIR__ . '/../common/lib/datetime_fmt.php';

$cmTier    = 'free';
$cmVersion = '1.0.0';

try {
    if (function_exists('cm_get_product_tier')) {
        $cmTier = cm_get_product_tier(); // "free" | "plus" | "pro"
    }
    if (function_exists('cm_get_product_version')) {
        $cmVersion = cm_get_product_version(); // e.g. "1.0.0"
    }
} catch (\Throwable $e) {
    // Fallback to defaults if anything goes wrong.
    $cmTier    = 'free';
    $cmVersion = '1.0.0';
}

// Suffix for <title> tag (browser tab).
$cmTitleSuffix = ' – CM Free / Plus v' . $cmVersion;
if ($cmTier === 'pro') {
    $cmTitleSuffix = ' – CM Free / Plus / Pro v' . $cmVersion;
}

// manifest.json obstaja v /app/common/data/json/units/manifest.json
// (trenutno ga ne potrebujemo nujno za render, ampak ga damo v config)

// --- Build dynamic OCC_URLS and PRICE_URLS from manifest.json (for all units) ---

$UNITS_JSON_BASE_URL = '/app/common/data/json/units'; // URL base for units JSON

$occUrls   = [];
$priceUrls = [];

$manifestFile = __DIR__ . '/../common/data/json/units/manifest.json';
if (is_file($manifestFile)) {
    $raw = @file_get_contents($manifestFile);
    if ($raw !== false) {
        $manifest = json_decode($raw, true);
        if (!empty($manifest['units']) && is_array($manifest['units'])) {
            foreach ($manifest['units'] as $u) {
                if (empty($u['id'])) {
                    continue;
                }
                // allow only safe IDs
                $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$u['id']);
                if ($id === '') {
                    continue;
                }

                // optional public/active flags in manifest
                if (isset($u['public']) && $u['public'] === false) {
                    continue;
                }
                if (isset($u['active']) && $u['active'] === false) {
                    continue;
                }

                $occUrls[$id]   = $UNITS_JSON_BASE_URL . '/' . $id . '/occupancy.json';
                $priceUrls[$id] = $UNITS_JSON_BASE_URL . '/' . $id . '/prices.json';
            }
        }
    }
}

// Fallback if manifest is missing or empty – keep old A1/A2 behavior
if (!$occUrls) {
    $occUrls = [
        'A1' => $UNITS_JSON_BASE_URL . '/A1/occupancy.json',
        'A2' => $UNITS_JSON_BASE_URL . '/A2/occupancy.json',
    ];
}
if (!$priceUrls) {
    $priceUrls = [
        'A1' => $UNITS_JSON_BASE_URL . '/A1/prices.json',
        'A2' => $UNITS_JSON_BASE_URL . '/A2/prices.json',
    ];
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($t('title') . $cmTitleSuffix) ?></title>

  <meta name="viewport" content="width=device-width,initial-scale=1">

  <link rel="stylesheet" href="/app/public/css/pubcal.css?v=4">
<style>
/* Badge v headerju */
.work-flag{
  display:inline-flex; align-items:center; gap:.45rem;
  margin-left:.6rem; padding:.2rem .5rem;
  border:1px solid #2a2a2a; border-radius:999px;
  background:#141414; color:var(--muted); font-weight:600; line-height:1;
}
.work-flag__img{
  width: max(28px, 2.2rem);
  height: auto; aspect-ratio: 1/1; object-fit: contain;
  filter: drop-shadow(0 1px 0 rgba(0,0,0,.25));
}
.work-flag__txt{ font-size:.95rem; letter-spacing:.1px; }
@media (max-width:560px){
  .work-flag{ padding:.15rem .45rem }
  .work-flag__img{ width: 28px }
  .work-flag__txt{ font-size:.9rem }
}

/* --- Edition badge inside brand tagline --- */
.brand-tagline-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dotted rgba(154,167,181,0.7);
  padding-bottom: 1px;
}

.brand-tagline-link:hover {
  color: #ffffff;
  border-bottom-style: solid;
}

.cm-product {
  white-space: nowrap;
}

.cm-edition-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 .45rem;
  margin: 0 .18rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.7);
  font-size: 1.0rem;
  line-height: 1.1;
}

.cm-version {
  margin-left: .25rem;
  font-size: 1.0rem;
  opacity: 0.8;
}

/* CM Free tagline link v headerju */
.brand-tagline a{
  color: inherit;
  text-decoration: underline;
  text-decoration-style: dotted;
  text-underline-offset: 0.12em;
}
.brand-tagline a:hover{
  text-decoration-style: solid;
}
.brand-res a{
  color: inherit;
  text-decoration: none;
  cursor: pointer;
}
.brand-res a:hover{
  text-decoration: underline;
}

</style>
</head>

<body class="cm-app">
<header class="cm-header">
  <div class="cm-header-inner">

    <div class="brand">
      <div class="brand-title">
        <span class="brand-main"><?= htmlspecialchars($t('brand')) ?></span>
        <span class="brand-sep">–</span>
        <span class="brand-res"><?= htmlspecialchars($t('brand.reservations')) ?></span>
        <span class="brand-sep brand-sep-tagline">–</span>

        <span class="brand-tagline">
          <a href="https://cmfree.netlify.app/"
             class="brand-tagline-link"
             target="_blank"
             rel="noopener noreferrer">
            <?= htmlspecialchars($t('tagline')) ?>
            <span class="brand-sep brand-sep-tagline"> – </span>
            <span class="cm-product">
              CM
              <?php if ($cmTier === 'plus'): ?>
                Free /
                <span class="cm-edition-badge">Plus</span>
              <?php elseif ($cmTier === 'pro'): ?>
                Free / Plus /
                <span class="cm-edition-badge">Pro</span>
              <?php else: ?>
                <span class="cm-edition-badge">Free</span>
                / Plus
              <?php endif; ?>
              <span class="cm-version">v<?= htmlspecialchars($cmVersion) ?></span>
            </span>
          </a>
        </span>
      </div>
    </div>


    <!-- Controls row: nav + desni del + jeziki -->
    <div class="cm-controls-row">

      <nav class="nav-buttons">
        <button id="btnPrev" class="nav-btn" aria-label="Prev">&laquo;</button>
        <button id="btnToday" class="nav-btn"><?= htmlspecialchars($t('nav.today')) ?></button>
        <button id="btnNext" class="nav-btn" aria-label="Next">&raquo;</button>
      </nav>

      <div class="cm-right">
        <!-- DAY USE TOGGLE -->
        <div class="dayuse-toggle">
          <input type="checkbox" id="chkDayUse" />
          <label for="chkDayUse"><?= htmlspecialchars($t('dayuse.label')) ?></label>
        </div>

        <span id="minNightsBadge"
              class="minnights-badge"
              aria-live="polite"></span>

        <select id="unitSelect" class="unit-select" aria-label="Izbira enote">
          <!-- napolni pubcal.js iz manifest.json -->
        </select>

        <button id="btnClear" class="clear-btn"><?= htmlspecialchars($t('btn.clear')) ?></button>
        <button id="btnConfirm" class="confirm-btn-header"><?= htmlspecialchars($t('btn.confirm')) ?></button>

        <div class="lang-switch">
          <button type="button"
                  class="lang-btn lang-sl"
                  data-lang="sl"
                  aria-label="<?= htmlspecialchars($t('lang.sl')) ?>"
                  title="<?= htmlspecialchars($t('lang.sl')) ?>">
            🇸🇮
          </button>
          <button type="button"
                  class="lang-btn lang-en"
                  data-lang="en"
                  aria-label="<?= htmlspecialchars($t('lang.en')) ?>"
                  title="<?= htmlspecialchars($t('lang.en')) ?>">
            🇬🇧
          </button>
        </div>
      </div>

    </div><!-- /.cm-controls-row -->

  </div><!-- /.cm-header-inner -->

<script>
  (function () {
    const root = document.querySelector('.lang-switch');
    if (!root) return;

    root.querySelectorAll('[data-lang]').forEach(btn => {
      btn.addEventListener('click', () => {
        const lang = btn.getAttribute('data-lang');
        const url = new URL(window.location.href);
        url.searchParams.set('lang', lang); // ohrani vse ostale (unit, from, to,...)
        window.location.href = url.toString();
      });
    });
  })();
</script>

    </header>

  <!-- majhna legenda za day-use & special offers -->
<div class="cm-legend">
  <span class="legend-item" title="">
    <span class="legend-dot legend-dayuse"></span>
    <?= htmlspecialchars($t('legend.dayuse')) ?>
  </span>
  <span class="legend-item" title="">
    <span class="legend-swatch legend-offer"></span>
    <?= htmlspecialchars($t('legend.offers')) ?>
  </span>
</div>

  <!-- plavajoči info box (skrit dokler ni izbire) -->
  <aside id="cm-info" class="cm-info" hidden></aside>

  <main id="calendarRoot" class="calendar-wrapper" aria-live="polite"></main>

<footer class="cm-footer">
  <p class="cm-footer-note"><?= htmlspecialchars($t('footer.note')) ?></p>
 <!-- <small><?= htmlspecialchars($t('footer.note')) ?></small> -->
</footer>
  <script src="/app/public/lib/i18n.js"></script>
  <script>
  // Konfiguracija, ki jo rabi pubcal.js
  window.CM_CONFIG = {
    LANG: "<?= htmlspecialchars($lang) ?>",
    UNIT: "<?= htmlspecialchars($unit) ?>", // začetna enota
    MONTHS_AHEAD: 14,

    // kam vodi potrdi gumb
    OFFER_URL: "/app/public/offer.php",

    // kje je manifest z enotami (info, opisi ipd.)
    MANIFEST_URL: "<?= htmlspecialchars($UNITS_JSON_BASE_URL) ?>/manifest.json",

    // min nights politika (za prihodnost)
    ENFORCE_MIN_NIGHTS: <?= $ENFORCE_MIN_NIGHTS ? 'true' : 'false' ?>,
    MIN_NIGHTS: <?= (int)$MIN_NIGHTS ?>,

    // dynamic absolute URLs for occupancy/prices per unit (built in PHP from manifest.json)
    OCC_URLS: <?= json_encode($occUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    PRICE_URLS: <?= json_encode($priceUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>

  };


  // Seed iz URL parametrov, da pri reloadu/povratku vrne isti termin
  window.CM_SEED = {
    unit: "<?= htmlspecialchars($unit) ?>",
    from: "<?= htmlspecialchars($from) ?>",
    to:   "<?= htmlspecialchars($to) ?>"
  };
  </script>
<script src="/app/public/js/reset_pubcal.js" defer></script>
<script src="/app/public/js/pubcal.js?v=5" defer></script>
<script src="/app/public/js/info_drag.js" defer></script>
<script>
(function(){
  // robustna detekcija reload-a
  let isReload = false;
  try {
    const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    let navType = nav ? nav.type : (performance.navigation && performance.navigation.type);
    if (typeof navType === 'number') { navType = ({1:'reload',2:'back_forward'})[navType] || 'navigate'; }
    isReload = navType === 'reload';
  } catch(e) {}

  // ob load-u sprožimo popolnoma isto, kot gumb "Počisti"
  window.addEventListener('load', () => {
    if (!isReload) return;

    // 1) Če imaš izpostavljen API (česar včasih nimamo), pokliči direkt:
    if (window.PUBCAL && typeof window.PUBCAL.clearSelection === 'function') {
      try { window.PUBCAL.clearSelection(); } catch(e){}
      scrubUrl();
      return;
    }

    // 2) Poskusi "klikniti" gumb Počisti (isti handler, nič menjave v pubcal.js)
    const btn =
      document.getElementById('btnClear') ||
      document.querySelector('[data-action="clear"], .btn-clear, #clear');
    if (btn) {
      try { btn.click(); } catch(e){}
      scrubUrl();
      return;
    }

    // 3) Fallback (če ni gumba ali API-ja): ročno izvede iste klice kot gumb
    try {
      if (window.S) {
        S.start = null;
        S.end = null;
      }
      if (typeof repaintSelection === 'function') repaintSelection(false);
      if (typeof clearSolidFill === 'function') clearSolidFill();
      if (typeof hideInfo === 'function') hideInfo();
      if (typeof persist === 'function') persist();
    } catch(e) {}

    scrubUrl();
  });

  // počišči query string (unit/from/to), da ostane čist URL
  function scrubUrl(){
    try {
      if (location.search) history.replaceState({}, '', location.pathname);
    } catch(e) {}
  }
})();
</script>

</body>
</html>
