<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/integrations.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/integrations.php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');

// Preberi unit iz query (opcijsko predizbran)
$unit = isset($_GET['unit']) ? preg_replace('/[^A-Za-z0-9_-]/','', $_GET['unit']) : '';

$DATA_ROOT   = '/var/www/html/app/common/data/json';
$UNITS_DIR   = $DATA_ROOT . '/units';
$MANIFEST    = $UNITS_DIR . '/manifest.json';
$HAS_MANIFEST = file_exists($MANIFEST);

// Preberi admin_key za integracijske API-je (ICS IN, pull_now, apply_booking_now)
$ADMIN_KEY_FILE = '/var/www/html/app/common/data/admin_key.txt';
$adminKey = is_file($ADMIN_KEY_FILE)
  ? trim((string)file_get_contents($ADMIN_KEY_FILE))
  : '';

require_once __DIR__ . '/../common/lib/datetime_fmt.php';

// Determine current product tier (free vs plus/pro)
$tier   = function_exists('cm_get_product_tier') ? cm_get_product_tier() : 'free';
$isPlus = cm_is_plus_enabled();


$CFG = [
  'dataRoot'  => '/app/common/data/json',
  'unitsDir'  => '/app/common/data/json/units',
  'manifest'  => '/app/common/data/json/units/manifest.json',
  'fallback'  => '/app/common/data/json/units/_index.json',
  'api'       => [
    'unitsList'    => '/app/admin/api/units_list.php',
    'addUnit'      => '/app/admin/api/add_unit.php',
    'saveUnitMeta' => '/app/admin/api/integrations/save_unit_meta.php',
    'icsPhp'       => '/app/admin/api/integrations/ics.php',

    // NEW live-only endpoints (no TMP on disk)
    'promoGet'     => '/app/admin/api/promo_get.php',
    'promoSave'    => '/app/admin/api/promo_save.php',
    'offersGet'    => '/app/admin/api/offers_get.php',
    'offersSave'   => '/app/admin/api/offers_save.php',
    'channelsGet'  => '/app/admin/api/channels_get.php',
    'channelsSave' => '/app/admin/api/channels_save.php',

    'autopilotGet' => '/app/admin/api/autopilot_get.php',
    'autopilotSave'=> '/app/admin/api/autopilot_save.php',
    // ---- ICS IN (Channels card) ----
    'integrationsSetInUrl'      => '/app/admin/api/integrations/set_in_url.php',
    'integrationsPullNow'       => '/app/admin/api/integrations/pull_now.php',
    'integrationsApplyNow'      => '/app/admin/api/integrations/apply_booking_now.php',
    'integrationsRemovePlatform'=> '/app/admin/api/integrations/remove_platform.php',

  ],

  'unit'      => $unit,
  'adminKey'  => $adminKey,
   // Edition / plan info for JS (Free vs Plus)
  'plan'      => $tier,
  'edition'   => $tier,
  'isPlus'    => $isPlus,
];



// Content Security Policy
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'self';");
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="Content-Security-Policy"
        content="default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; connect-src 'self'; base-uri 'self'; frame-ancestors 'self';">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Integrations</title>
  <link rel="stylesheet" href="/app/admin/ui/css/manage_reservations.dark.css">
  <link rel="stylesheet" href="/app/admin/ui/css/integrations.css?v=2">
</head>
<body class="adm-shell theme-dark">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Integrations</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Nazaj">← Nazaj</a>
    </div>
  </header>

  <main id="integrations-root"
        data-config='<?=json_encode($CFG, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>'>

    <!-- FILTER BAR -->
    <section class="bar">
      <div class="bar-left">
        <label class="lbl" for="unitSelect">Unit</label>
        <select id="unitSelect" class="sel"></select>
      </div>
      <div class="bar-right">
        <button id="btnAddUnit" class="btn primary">+ Add Unit</button>
      </div>
    </section>

    <section class="cards-grid">


      <!-- ICS Export -->
      <article class="card" id="card-ics">
        <div class="card-hdr">
          <div class="card-hdr-left">
            <h2>ICS export</h2>
          </div>
          <div class="card-hdr-right">
            <button id="btnRefreshICS" class="btn small">Refresh links</button>
          </div>
        </div>

        <div class="card-body">
          <p class="muted small">
            ICS URL-ji za <strong>trenutno izbrano enoto</strong> (zgornji select “Unit”).
            Te povezave prilepiš v zunanjo platformo (Airbnb, Google Calendar, drugi sistemi),
            ki podpira uvoz <code>.ics</code>.
          </p>

          <div class="row">
  <div class="row-col">
    <div class="lbl">ICS (Booked only)</div>
    <code id="icsBookedUrl" class="code-url">—</code>
    <p class="help small">
      Samo <strong>potrjene rezervacije</strong>. Priporočljivo za portale,
      kjer druga blokiranja (cleaning/maintenance) ne želiš izpostaviti.
    </p>
    <small id="icsBookedStatus" class="ics-status"></small>
  </div>
  <div class="row-actions">
    <button class="btn small copy" data-copy-target="#icsBookedUrl">Kopiraj URL</button>
    <!-- Gumb za testiranje ICS linka -->
    <a id="openIcsBooked" class="btn small" href="#" rel="noopener">Testiraj</a>
  </div>
</div>


  <div class="row">
  <div class="row-col">
    <div class="lbl">ICS (Blocked &amp; Booked)</div>
    <code id="icsBlockedUrl" class="code-url">—</code>
    <p class="help small">
      Rezervacije <strong>in</strong> interni bloki (local/clean/maintenance),
      če so vključeni v nastavitvah. Dobro za interne koledarje
      ali primarne kanale, kjer želiš videti vse zasedene dneve.
    </p>
    <small id="icsBlockedStatus" class="ics-status"></small>
  </div>
  <div class="row-actions">
    <button class="btn small copy" data-copy-target="#icsBlockedUrl">Kopiraj URL</button>
    <!-- Gumb za testiranje ICS linka -->
    <a id="openIcsBlocked" class="btn small" href="#" rel="noopener">Testiraj</a>
  </div>
</div>


          <hr class="sep" />

          <div class="note small">
            <p><strong>Tipična uporaba:</strong></p>
            <ul class="list">
              <li><span class="dot"></span> <strong>Airbnb:</strong> v nastavitvah koledarja izberi “Import calendar”
                in prilepi enega od zgornjih URL-jev.</li>
              <li><span class="dot"></span> <strong>Google Calendar:</strong> “Add calendar &gt; From URL” in prilepi ICS.</li>
              <li><span class="dot"></span> Ostale platforme: uporabi URL v polju za ICS / Calendar import.</li>
            </ul>
            <p class="muted tiny">
              Gumb <strong>Refresh links</strong> po potrebi ponovno naloži / regenerira URL-je preko
              <code>integrations/ics.php</code>.
            </p>
          </div>
        </div>
      </article>
  
<!-- Promo codes (kuponi) -->
<article class="card">
  <div class="card-hdr">
    <h2>Promo codes (kuponi)</h2>
    <div class="actions">
      <button id="btnAddPromo" class="btn small">+ Dodaj kupon</button>
      <button id="btnPromoDeleteSelected" class="btn small danger">Izbriši označene</button>
      <button id="btnPromoPublish" class="btn small danger">Objavi spremembe</button>
    </div>
  </div>
<div class="promo-settings-grid">
    <details class="promo-settings-details">
      <summary>
        <span class="promo-settings-summary-label">⚙ Nastavitve auto-kupona</span>
        <span class="promo-settings-summary-hint">(popust, veljavnost, prefix)</span>
      </summary>
<div class="field">

  <label for="autoCouponEnabled">
    Avto kupon ob zavrnitvi ON/OFF
  </label>
  <input type="checkbox" id="autoCouponEnabled">
</div>

      <div class="promo-settings-grid">
        <div class="promo-setting">
          <label for="autoCouponPercent">
            Auto-kupon: popust (%)
          </label>
          <input type="number"
                 id="autoCouponPercent"
                 min="0" max="100" step="1"
                 class="form-control small">
          <p class="help">
            Privzeti popust za auto-kupon ob zavrnitvi (ročni &amp; auto-conflict).
          </p>
        </div>

        <div class="promo-setting">
          <label for="autoCouponValidDays">
            Auto-kupon: veljavnost (dni)
          </label>
          <input type="number"
                 id="autoCouponValidDays"
                 min="1" max="730" step="1"
                 class="form-control small">
          <p class="help">
            Število dni po zavrnitvi, ko je kupon še veljaven.
          </p>
        </div>

        <div class="promo-setting">
          <label for="autoCouponPrefix">
            Auto-kupon: prefix kode
          </label>
          <input type="text"
                 id="autoCouponPrefix"
                 maxlength="16"
                 class="form-control small"
                 placeholder="RETRY-">
          <p class="help">
            Prefix za generirane kode (npr. RETRY-123ABC).
          </p>
        </div>
      </div>
    </details>
        <div class="card-body">
          <table class="table table-codes">
            <thead>
              <tr>
                <th><input type="checkbox" id="promoSelectAll"></th>
                <th>Koda</th>
                <th>Opis</th>
                <th>Popust %</th>
                <th>Min noči</th>
                <th>Max noči</th>
                <th>Velja od</th>
                <th>Velja do</th>
                <th>Aktivno</th>
                <th>Enota / Global</th>
                <th>Akcije</th>
              </tr>
            </thead>
            <tbody id="promoCodesBody">
              <tr class="muted">
                <td colspan="11">Ni definiranih kuponov za izbrano enoto.</td>
              </tr>
            </tbody>
          </table>

          <div id="promoDetail" class="detail-panel">
            <div class="detail-panel-title">
              <span class="dot"></span>
              <span>Izbran kupon</span>
            </div>
            <div id="promoDetailText" class="detail-panel-body">
              Ni izbranega kupona.
           

	<p class="muted small">
	  Live JSON: <code>/app/common/data/json/units/promo_codes.json</code><br>
	  Backup: <code>/app/common/data/json/units/promo_codes.json.bak</code>
	</p>
        </div>
      </article>

      <!-- Special offers (akcije/popusti) -->
      <article class="card">
        <div class="card-hdr">
          <h2>Special offers (akcije)</h2>
          <div class="actions">
            <button id="btnAddOffer" class="btn small">+ Dodaj akcijo</button>
            <button id="btnOffersPublish" class="btn small danger" style="display:none;">Objavi spremembe</button>
          </div>
        </div>
        <div class="card-body">
          <table class="table table-codes">
            <thead>
              <tr>
                <th>Naziv</th>
                <th>Termin od</th>
                <th>Termin do</th>
                <th>Popust %</th>
                <th>Tip</th>
                <th>Aktivno</th>
                <th>Akcije</th>
              </tr>
            </thead>
            <tbody id="specialOffersBody">
              <tr class="muted">
                <td colspan="7">Ni definiranih akcij za izbrano enoto.</td>
              </tr>
            </tbody>
          </table>

          <div id="offerDetail" class="detail-panel">
            <div class="detail-panel-title">
              <span class="dot"></span>
              <span>Izbrana akcija</span>
            </div>
            <div id="offerDetailText" class="detail-panel-body">
              Ni izbrane akcije.
            </div>
            <button id="offerToggleRaw" class="btn xs" hidden>Pokaži surovi JSON</button>
            <pre id="offerRaw" class="pre small" hidden></pre>
          </div>

	<p class="muted small">
 	 Live JSON: <code>/app/common/data/json/units/&lt;UNIT&gt;/special_offers.json</code><br>
 	 Backup: <code>/app/common/data/json/units/&lt;UNIT&gt;/special_offers.json.bak</code>
	</p>
        </div>
      </article>

      <!-- Diagnostics -->
      <article class="card">
        <div class="card-hdr">
          <h2>Diagnostics</h2>
          <button id="btnDiag" class="btn small">Run</button>
        </div>
        <div class="card-body">
          <pre id="diagOut" class="pre">—</pre>
        </div>
      </article>

      <!-- AUTOPILOT – GLOBAL SETTINGS -->
      <article class="card" id="card-autopilot">
        <div class="card-hdr">
          <h2>Autopilot - Plus</h2>
          <span class="pill neutral">site_settings.json</span>
        </div>
        <div class="card-body">
          <p class="muted small">
            Globalne nastavitve Avtopilota. Per-enoto lahko prilagodiš iz tabele Units (gumb
            <strong>Autopilot</strong>).
          </p>

          <form id="formAutopilotGlobal" class="form-grid">
            <div class="fld">
              <label class="chk">
                <input type="checkbox" id="ap-global-enabled">
                <span>Autopilot omogočen (global)</span>
              </label>
            </div>

            <div class="fld">
              <label for="ap-global-mode">Način delovanja</label>
              <select id="ap-global-mode">
                <option value="auto_confirm_on_accept">
                  Auto-confirm ob admin potrditvi (Phase 1)
                </option>
              </select>
            </div>

	<div class="fld">
	  <label class="chk">
	    <input type="checkbox" id="ap-global-test-mode">
	    <span>TEST MODE (global)</span>
	  </label>
	  <p class="help small">
	    V test načinu je dovoljeno ročno izklapljanje ICS checkov (per-unit). V production je per-unit autopilot vedno “ICS checks ON”.
	  </p>
	</div>
	
	<div class="fld fld-inline">
	  <div>
	    <label for="ap-global-test-until">TEST MODE do (ISO, optional)</label>
	    <input type="text" id="ap-global-test-until" placeholder="2025-12-13T22:30:00+01:00">
	    <p class="help small">Če je datum v prihodnosti, se test_mode samodejno šteje kot ON.</p>
	  </div>
	  <div style="align-self:end;">
	    <button type="button" class="btn btn-small" id="ap-global-test-15">
	      TEST 15 min
	    </button>
	    <button type="button" class="btn btn-small" id="ap-global-test-clear">
	      Počisti
	    </button>
	  </div>
	</div>


            <div class="fld">
              <label for="ap-global-sources">Dovoljeni viri (comma)</label>
              <input type="text" id="ap-global-sources" placeholder="public, direct">
              <p class="help small">
                Primer: <code>public, direct</code>. Ostali viri bodo šli preko običajnega soft-hold flowa.
              </p>
            </div>

            <div class="form-actions">
              <button type="button" class="btn btn-small" id="btnAutopilotReload">
                Reset (ponovno naloži)
              </button>
              <button type="button" class="btn btn-small primary" id="btnAutopilotSave">
                Shrani
              </button>
            </div>

            <p class="muted small">
              Shranjuje se v
              <code>/app/common/data/json/units/site_settings.json &raquo; autopilot</code>.
            </p>
          </form>
        </div>
      </article>

 

<!-- =========================== -->
<!--    UNITS MANAGEMENT CARD    -->
<!-- =========================== -->
<div class="card" id="card-units">
  <div class="card-hdr">
    <div class="card-hdr-left">
      <h2 class="card-title">Units</h2>
      <span class="pill neutral">manifest.json</span>
    </div>
    <div class="card-hdr-right">
      <button class="btn small primary" id="btnAddUnitOpen" type="button">+ Add unit</button>
      <button class="btn small" id="btnRefreshUnits" type="button">Refresh</button>
    </div>
  </div>

<div class="card-body">

  <!-- Global base URL / domain for CM -->
  <div class="row mb-2">
    <div class="row-col">
      <label for="cmBaseUrl" class="lbl">Vaša domena (base URL CM)</label>
      <input
        id="cmBaseUrl"
        type="text"
        class="input"
        placeholder="https://{YOUR_DOMAIN}/app"
      >
      <p class="help small">
        Uporablja se za generiranje ICS URL-jev in drugih povezav v sistemu.
        Če pustite prazno, bo privzeto uporabljeno
        <code>http://localhost/</code> za testni način.
      </p>
    </div>
    <div class="row-actions">
      <button type="button" id="btnSaveBaseUrl" class="btn small">
        Shrani domeno
      </button>
    </div>
  </div>

  <div class="units-summary">
    <span id="unitsTotal" class="pill neutral">0 enot</span>
    <span id="unitsActive" class="pill success">0 aktivnih</span>
  </div>

  <div id="unitsListBox" class="units-list-box scroll-box">
    <p class="muted small">Loading units...</p>
  </div>

</div>


</div>
<!-- Channels / Platforms -->
      <article class="card" id="card-channels">
        <div class="card-hdr">
          <div class="card-hdr-left">
            <h2>Channels</h2>
            <span class="pill neutral">ICS IN · lab</span>
          </div>
        </div>

        <div class="card-body">
          <p class="muted small">
            Povezava enote z zunanjimi kanali (trenutno fokus na
            <strong>Booking ICS uvozu</strong>).
            Za enoto <strong>A1</strong> kartica ostane
            <strong>read-only (produkcija)</strong>, polni laboratorij
            (ICS + simulatorji) pa je omogočen za enote kot sta
            <code>A2</code> in <code>S1</code>.
          </p>

          <p id="channelsInfo" class="muted tiny">
            Najprej izberi enoto zgoraj levo.
          </p>

          <div class="scroll-box">
            <table class="table-units">
              <thead>
                <tr>
                  <th>Channel</th>
                  <th>ICS URL (IN)</th>
                  <th>Status</th>
                  <th>Akcije</th>
                </tr>
              </thead>
              <tbody id="channelsTbody">
                <tr>
                  <td colspan="4">
                    <span class="muted small">Ni izbrane enote.</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="muted tiny">
            Trenutna faza: <em>UI model</em> za ICS uvoz in simulacijo
            (npr. <code>SIM1</code> / <code>SIM2</code>).
            V naslednjih korakih se bodo tukaj pojavili še gumbi
            <strong>Pull now</strong> in
            <strong>Apply to calendar</strong>, ki bodo neposredno
            povezani na <code>pull_now.php</code> in
            <code>apply_booking_now.php</code>.
          </p>
        </div>
      </article>

    </section>
  </main>




  <!-- Add Unit modal -->
  <dialog id="dlgAddUnit">
    <form id="formAddUnit" method="dialog" class="modal">
      <h3>Add Unit</h3>

      <div class="fld">
        <label for="newUnitId">Unit ID (npr. A1)</label>
		<div class="fld">
 		 <label for="addUnitTemplate">Template unit</label>
		  <select id="addUnitTemplate">
  		  <option value="A2" selected>A2 (test baseline)</option>
 		   <option value="S1">S1 (test baseline)</option>
 		   <option value="A1">A1 (prod baseline)</option>
 		 </select>
		  <small class="form-text text-muted">
		    Iz katere enote kopiramo začetne nastavitve (site_settings/prices/offers/occupancy_sources).
		  </small>
		</div>
        <input
          type="text"
          id="newUnitId"
          required
          pattern="[A-Za-z0-9_\-]{1,32}"
          autocomplete="off"
        />
      </div>

      <div class="fld">
        <label for="newUnitLabel">Prikazno ime / Alias</label>
        <input
          type="text"
          id="newUnitLabel"
          required
          maxlength="64"
          autocomplete="off"
        />
      </div>

      <div class="fld">
        <label for="newPropertyId">Property ID</label>
        <input
          type="text"
          id="newPropertyId"
          maxlength="64"
          autocomplete="off"
          placeholder="HOME"
        />
      </div>

      <div class="fld">
        <label for="newOwner">Owner</label>
        <input
          type="text"
          id="newOwner"
          maxlength="64"
          autocomplete="off"
        />
      </div>

	<div class="form-row">
 	 <label for="newMonthsAhead">Months ahead</label>
 	 <input id="newMonthsAhead" type="number" min="1" max="36" step="1" value="12">
	</div>

	<!-- Cleaning options -->
	<div class="unit-cleaning-block">
 	 <label>
 	   <input type="checkbox" id="unit-clean-before" />
 	   Cleaning day before arrival
 	 </label>
 	 <label style="margin-left:12px;">
 	   <input type="checkbox" id="unit-clean-after" />
 	   Cleaning day after departure
 	 </label>
	</div>

<div class="fld fld-inline">
  <label class="chk">
    <input type="checkbox" id="newUnitActive" checked>
    <span>Active</span>
  </label>

  <label class="chk">
    <input type="checkbox" id="newUnitOnHold">
    <span>On hold (začasno neaktivna)</span>
  </label>

<label class="chk" id="lblUnitPublic">
  <input type="checkbox" id="newUnitPublic" checked>
  <span id="txtUnitPublic">Public (visible)</span>
</label>
</div>

      <!-- BOOKING PRAVILA ------------------------------------------------ -->
      <fieldset class="card-subsection">
        <legend class="h6">Booking pravila</legend>

        <div class="row">
          <!-- Min. noči -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-min-nights" class="form-label">
              Minimalno št. nočitev
            </label>
            <input
              type="number"
              id="add-booking-min-nights"
              class="form-control form-control-sm"
              min="1" max="60" step="1"
              placeholder="npr. 2"
            >
            <small class="form-text text-muted">
              Minimalno število nočitev za to enoto.
            </small>
          </div>

          <!-- Cleaning fee -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-cleaning-fee" class="form-label">
              Cleaning fee (v €)
            </label>
            <input
              type="number"
              id="add-booking-cleaning-fee"
              class="form-control form-control-sm"
              min="0" max="500" step="1"
              placeholder="npr. 10"
            >
            <small class="form-text text-muted">
              Fiksni strošek čiščenja za enoto. Uporabi se v offer/rezervaciji.
            </small>
          </div>
        </div>

        <hr class="my-2">

        <div class="row">
          <!-- Tedenski prag -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-weekly-threshold" class="form-label">
              Tedenski prag (noči)
            </label>
            <input
              type="number"
              id="add-booking-weekly-threshold"
              class="form-control form-control-sm"
              min="0" max="60" step="1"
              placeholder="npr. 7"
            >
            <small class="form-text text-muted">
              Od koliko nočitev dalje velja tedenski popust. 0 = onemogočeno.
            </small>
          </div>

          <!-- Tedenski popust -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-weekly-discount" class="form-label">
              Tedenski popust (%)
            </label>
            <input
              type="number"
              id="add-booking-weekly-discount"
              class="form-control form-control-sm"
              min="0" max="80" step="1"
              placeholder="npr. 10"
            >
            <small class="form-text text-muted">
              Popust pri bivanju ≥ tedenskega praga. 0 = onemogočeno.
            </small>
          </div>

          <!-- Dolgo bivanje prag -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-long-threshold" class="form-label">
              Dolgo bivanje od (noči)
            </label>
            <input
              type="number"
              id="add-booking-long-threshold"
              class="form-control form-control-sm"
              min="0" max="120" step="1"
              placeholder="npr. 30"
            >
            <small class="form-text text-muted">
              Od koliko nočitev dalje velja dodatni popust za dolgo bivanje.
            </small>
          </div>

          <!-- Dolgo bivanje popust -->
          <div class="col-sm-3 mb-2">
            <label for="add-booking-long-discount" class="form-label">
              Popust za dolgo bivanje (%)
            </label>
            <input
              type="number"
              id="add-booking-long-discount"
              class="form-control form-control-sm"
              min="0" max="80" step="1"
              placeholder="npr. 20"
            >
            <small class="form-text text-muted">
              Dodatni popust pri bivanju ≥ tega praga. 0 = onemogočeno.
            </small>
          </div>
        </div>
      </fieldset>

      <!-- DAY-USE --------------------------------------------------------- -->
      <fieldset class="card-subsection">
        <legend class="h6">Day-use (dnevni počitek)</legend>

        <div class="form-check mb-2">
          <label class="form-check-label">
            <input type="checkbox" id="add-dayuse-enabled" class="form-check-input">
            Omogoči day-use (dnevni počitek)
          </label>
          <small class="form-text text-muted d-block">
            Fiksni dnevni paket brez turistične takse. Gost uporablja apartma med nastavljenima urama.
          </small>
        </div>

        <div class="row">
          <div class="col">
            <label for="add-dayuse-from" class="form-label">Day-use from</label>
            <input type="time" class="form-control form-control-sm" id="add-dayuse-from" step="900"> </div>
          <div class="col">
            <label for="add-dayuse-to" class="form-label">Day-use do</label>
            <input type="time" class="form-control form-control-sm" id="add-dayuse-to" step="900">
          </div>
        </div>
   <div class="mt-2">
      <label for="add-dayuse-max-days" class="form-label">
        Max. št. dni vnaprej (day-use)
      </label>
      <input type="number" min="1" max="10" step="1" class="form-control form-control-sm" id="add-dayuse-max-days" name="dayuse_max_days_ahead" placeholder="npr. 7">
      <small class="form-text text-muted">
        Koliko dni vnaprej je mogoče ponuditi dnevni počitek (modre pike v koledarju). Če pustite prazno, se uporabi privzeta vrednost (7).
      </small>
    </div>
        <div class="mt-2">
          <label for="add-dayuse-max-persons" class="form-label">
            Max. št. oseb (day-use)
          </label>
          <input
            type="number"
            id="add-dayuse-max-persons"
            class="form-control form-control-sm"
            min="1" max="20" step="1"
          >
          <small class="form-text text-muted">
            Največ oseb za dnevni počitek. Prazno = uporabi kapaciteto enote.
          </small>
        </div>

        <div class="mt-2">
          <label for="add-dayuse-price-person" class="form-label">
            Day-use cena na osebo (v €)
          </label>
          <input
            type="number"
            id="add-dayuse-price-person"
            class="form-control form-control-sm"
            min="0" max="500" step="1"
          >
          <small class="form-text text-muted">
            Osnovna cena day-use paketa na osebo. Uporabi se v offer, če je day-use vklopljen.
          </small>
        </div>
      </fieldset>

      <!-- KAPACITETA ------------------------------------------------------ -->
      <fieldset class="card-subsection">
        <legend class="h6">Kapaciteta enote</legend>

        <div class="row">
          <div class="col-sm-4 mb-2">
            <label for="add-cap-max-guests" class="form-label">
              Največ oseb (skupaj)
            </label>
            <input type="number"
                   id="add-cap-max-guests"
                   class="form-control form-control-sm"
                   min="1" max="20" step="1"
                   placeholder="npr. 6">
            <small class="form-text text-muted">
              Skupno št. oseb (odrasli + vsi otroci).
            </small>
          </div>

          <div class="col-sm-4 mb-2">
            <label for="add-cap-max-beds" class="form-label">
              Ležišča (odrasli + 7-12)
            </label>
            <input type="number"
                   id="add-cap-max-beds"
                   class="form-control form-control-sm"
                   min="1" max="20" step="1"
                   placeholder="npr. 5">
            <small class="form-text text-muted">
              Kapaciteta ležišč (odrasli + otroci 7-12).
            </small>
          </div>

          <div class="col-sm-4 mb-2">
            <label for="add-cap-max-adults" class="form-label">
              Obvezni odrasli 
            </label>
            <input type="number"
                   id="add-cap-max-adults"
                   class="form-control form-control-sm"
                   min="1" max="20" step="1"
                   placeholder="npr. 4">
            <small class="form-text text-muted">
              Obvezno št. odraslih oseb.
            </small>
          </div>
        </div>

        <div class="form-check mt-1">
          <label class="form-check-label">
            <input type="checkbox" id="add-cap-allow-baby" class="form-check-input">
            Dovoljena otroška posteljica (0-6)
          </label>
        </div>
      </fieldset>







      <div class="actions">
        <button type="submit" class="btn primary">Ustvari</button>
        <button type="button" id="btnCancelAdd" class="btn">Prekliči</button>
      </div>

      <p class="muted small">
        Skripta bo ustvarila osnovno strukturo v
        <code>/common/data/json/units/&lt;UNIT&gt;</code> in posodobila
        <code>manifest.json</code> z meta podatki (alias, property_id, owner,
        active, public).
      </p>
    </form>
  </dialog>
<!-- Autopilot per-unit – Modal dialog -->
<dialog id="dlgAutopilotUnit" class="modal-root">
  <form id="formAutopilotUnit" class="modal" method="dialog">
    <header class="modal-header">
      <h3>Autopilot – enota <span id="ap-unit-id-label"></span></h3>
    </header>

    <section class="modal-body form-grid">
      <p class="muted small">
        Nastavitve veljajo samo za to enoto. Če želiš uporabljati globalne vrednosti,
        nastavi tukaj enake ali pusti to modalno okno zaprto.
      </p>

      <div class="fld">
        <label class="chk">
          <input type="checkbox" id="ap-unit-enabled">
          <span>Autopilot omogočen za to enoto</span>
        </label>
      </div>

      <div class="fld">
        <label for="ap-unit-mode">Način delovanja</label>
        <select id="ap-unit-mode">
          <option value="auto_confirm_on_accept">
            Auto-confirm ob admin potrditvi (Phase 1)
          </option>
        </select>
      </div>

      <div class="fld fld-inline">
        <div>
          <label for="ap-unit-min-days">Min. dni pred prihodom</label>
          <input type="number" id="ap-unit-min-days" min="0" max="365" step="1">
        </div>
        <div>
          <label for="ap-unit-max-nights">Max. noči za auto-confirm</label>
          <input type="number" id="ap-unit-max-nights" min="0" max="365" step="1">
        </div>
      </div>

      <div class="fld">
        <label for="ap-unit-sources">Dovoljeni viri (comma)</label>
        <input type="text" id="ap-unit-sources" placeholder="public, direct">
        <p class="help small">
          Če pustiš prazno, uporabi iste vire kot globalna nastavitev.
        </p>
      </div>

      <div class="fld fld-inline">
        <label class="chk">
          <input type="checkbox" id="ap-unit-check-accept">
          <span>ICS / merged check ob admin potrditvi</span>
        </label>
        <label class="chk">
          <input type="checkbox" id="ap-unit-check-guest">
          <span>ICS / merged check ob potrditvi gosta</span>
        </label>
      </div>
    </section>

    <footer class="modal-footer">
      <button type="button" class="btn btn-small" id="btnAutopilotUnitCancel">
        Prekliči
      </button>
      <button type="submit" class="btn btn-small primary">
        Shrani
      </button>
    </footer>
  </form>
</dialog>


  <!-- JSON edit dialog (advanced per-record editor) -->
  <dialog id="jsonEditDialog" class="dlg">
    <form id="jsonEditForm" method="dialog" class="dlg-inner">
      <h2 id="jsonEditTitle">Urejanje zapisa</h2>

      <p class="muted small" id="jsonEditMeta">
        Tip: —
      </p>

      <!-- "Easy" fields -->
      <section class="field-group">
        <h3 class="field-group-title">Osnovne nastavitve</h3>

        <div class="field">
          <label for="editName">Naziv / opis</label>
          <input type="text" id="editName" autocomplete="off">
        </div>

        <div class="field">
          <label for="editCode">Koda (za kupon)</label>
          <input type="text" id="editCode" autocomplete="off">
        </div>

        <div class="field-grid-3">
          <div class="field">
            <label for="editPercent">Popust %</label>
            <input type="number" id="editPercent" min="0" max="100" step="1">
          </div>
          <div class="field">
            <label for="editFrom">Velja od</label>
            <input type="date" id="editFrom">
          </div>
          <div class="field">
            <label for="editTo">Velja do</label>
            <input type="date" id="editTo">
          </div>
        </div>

        <div class="field checkbox">
          <label>
            <input type="checkbox" id="editActive">
            Aktiven zapis
          </label>
        </div>
      </section>

      <!-- Raw JSON -->
      <section class="field-group">
        <h3 class="field-group-title">Napredno · surovi JSON</h3>
        <p class="muted small">
          Zgornja polja pri shranjevanju prepišejo osnovne vrednosti
          (naziv, popust, datumi, aktivno) v JSON objektu.
        </p>
        <textarea id="jsonEditTextarea" rows="12" class="mono"></textarea>
      </section>

      <div class="actions">
        <button type="submit" class="btn primary">Shrani </button>
        <button type="button" id="jsonEditDelete" class="btn danger">Odstrani zapis</button>
        <button type="button" id="jsonEditCancel" class="btn">Prekliči</button>
      </div>

      <p class="muted small">
        Urejevalnik zapisov.
      </p>
    </form>
  </dialog>

<script src="/app/admin/ui/js/integrations_core.js?v=1"></script>

<!-- shared JSON editor + record editor used by Promo/Offers/etc -->
<script src="/app/admin/ui/js/integrations_editor.js?v=1"></script>

<script src="/app/admin/ui/js/integrations_units.js?v=1"></script>
<script src="/app/admin/ui/js/integrations_channels.js?v=1"></script>
<script src="/app/admin/ui/js/integrations_diagnostics.js?v=1"></script>

<script src="/app/admin/ui/js/integrations_promo.js?v=1"></script>
<script src="/app/admin/ui/js/integrations_offers.js?v=1"></script>
<script src="/app/admin/ui/js/integrations_autopilot.js?v=2"></script>
</body>
</html>


</body>
</html>
