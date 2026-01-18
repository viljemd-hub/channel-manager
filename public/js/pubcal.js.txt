/**
 * CM Free / CM Plus – Channel Manager
 * File: public/js/pubcal.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/public/js/pubcal.js
// Public Calendar V5
// - based on range_selection_v2.10.3 logic
// - expandable range (left/right), reset on in-range click
// - busy / depart / no-price treated as blocked (bordo in not clickable)
// - checkout day highlighted
// - info box: 1-click state (samo prihod), 2-click state (termin + vsota + primerjava druge enote)
// - prices: podpira dnevni map format { "2025-11-02":85, ... }
// - occupancy: blokovni formati [ {start,end,status}, ... ]
// - persists selection in URL/localStorage
// - OFFER link -> offer_v1.php?unit=...&from=...&to=...

(function(){

  console.log("[pubcal V5] init");

  function getCurrentLang(){
    // 1) iz PHP configa
    if (window.CM_CONFIG && typeof window.CM_CONFIG.LANG === "string") {
      return window.CM_CONFIG.LANG;
    }
    // 2) iz i18n.js (če je že naložen)
    if (typeof window.__LANG__ === "string") {
      return window.__LANG__;
    }
    // 3) fallback
    return "sl";
  }

  function getLocaleForLang(lang){
    switch(lang){
      case "en": return "en-GB";
      case "de": return "de-DE";
      case "it": return "it-IT";
      case "fr": return "fr-FR";
      case "nl": return "nl-NL";
      default:   return "sl-SI";
    }
  }

  function getWeekdayNames(lang){
    // vedno Pon → Ned (Mon-first), ker imaš že logiko za offset (Mon=0)
    switch(lang){
      case "en": return ["Mo","Tu","We","Th","Fr","Sa","Su"];
      case "de": return ["Mo","Di","Mi","Do","Fr","Sa","So"];
      case "it": return ["Lu","Ma","Me","Gi","Ve","Sa","Do"];
      case "fr": return ["Lu","Ma","Me","Je","Ve","Sa","Di"];
      case "nl": return ["Ma","Di","Wo","Do","Vr","Za","Zo"];
      default:   return ["Po","To","Sr","Če","Pe","So","Ne"]; // sl
    }
  }


  // ------------------
  // CONFIG / DOM HOOKS
  // ------------------

  // podpiramo staro (PUBCAL_*) in novo (CM_*) poimenovanje konfiguracije
  const cfg = window.CM_CONFIG || window.PUBCAL_CONFIG || {};
  const d   = document;

  // seed iz strežnika (npr. iz PHP)
  const SEED = window.CM_SEED || window.PUBCAL_SEED || {};
  let MONTHS_AHEAD = cfg.MONTHS_AHEAD || 12;
  // minimal nits per current unit (per-unit iz site_settings.json)
  let MIN_NIGHTS = cfg.MIN_NIGHTS || 1;
// === DAY USE (Dnevni počitek) ===================================

  function updateMinNightsBadge(){
    const badge = document.getElementById("minNightsBadge");
    if (!badge) return;

    const n = Number(MIN_NIGHTS) || 1;
    const tFn = (window.t || (k => k));
    const label = tFn("cal_min_nights"); // "Min. noči" / "Min. nights"

    if (n <= 1) {
      badge.style.display = "none";
      badge.textContent = "";
      return;
    }

    badge.style.display = "inline-flex";
    badge.textContent = `${label}: ${n}`;
  }


  let DAY_USE_MODE = false;
  let DAY_USE_SETTINGS = {
      enabled: false,
      from: null,
      to: null,
      max_days_ahead: 0,
      max_persons: 0
  };



  const calendarRoot = document.getElementById("calendarRoot");
  const infoBox      = document.getElementById("cm-info");

  const btnPrev    = document.getElementById("btnPrev");
  const btnNext    = document.getElementById("btnNext");
  const btnToday   = document.getElementById("btnToday");
  const btnClear   = document.getElementById("btnClear");
  const btnConfirm = document.getElementById("btnConfirm");
  const unitSelect = document.getElementById("unitSelect");

 

  let currentUnit = (SEED.unit || cfg.UNIT || "A1");

  // ------------------
  // PUBLIC UNITS (manifest)
  // ------------------
  let PUBLIC_UNITS = []; // [{id, alias, order}]

  function findPublicUnit(id){
    return PUBLIC_UNITS.find(u => u.id === id) || null;
  }
  function firstPublicUnitId(){
    return PUBLIC_UNITS.length ? PUBLIC_UNITS[0].id : "A1";
  }

  async function loadPublicUnitsFromManifest(){
    if (!cfg.MANIFEST_URL) {
      PUBLIC_UNITS = [];
      return;
    }
    const data = await fetchJSON(cfg.MANIFEST_URL);
    const arr = (data && Array.isArray(data.units)) ? data.units : [];
    PUBLIC_UNITS = arr
      .map(u => ({
        id:      u.id || u.unit || "",
        alias:   u.alias || u.label || u.name || (u.id || ""),
        order:   typeof u.order === "number" ? u.order : 0,
        active:  (u.active !== false),
        isPublic:(u.public !== false)
      }))
      .filter(u => u.id && u.active && u.isPublic)
      .sort((a,b) => (a.order - b.order) || a.id.localeCompare(b.id));
  }

  function syncUnitSelectAndLabel(){
    if (!unitSelect) return;
    unitSelect.innerHTML = "";

    PUBLIC_UNITS.forEach(u => {
      const opt = document.createElement("option");
      opt.value = u.id;
      opt.textContent = u.alias || u.id;
      unitSelect.appendChild(opt);
    });

    // poskrbimo, da je currentUnit dejansko v seznamu
    if (!findPublicUnit(currentUnit) && PUBLIC_UNITS.length) {
      currentUnit = PUBLIC_UNITS[0].id;
    }

    if (findPublicUnit(currentUnit)) {
      unitSelect.value = currentUnit;
    }

    // če imaš kje v headerju izpis aliasa, ga tu lahko posodobiš
    const aliasEl = document.querySelector("[data-role='unit-alias']") || document.getElementById("unitAlias");
    if (aliasEl) {
      const u = findPublicUnit(currentUnit);
      aliasEl.textContent = (u && u.alias) ? u.alias : currentUnit;
    }
  }



  // caches (after expansion)
  // OCC_CACHE[unit][ymd]       = "busy" | "depart" | "free"
  // PRICE_CACHE[unit][ymd]     = number
  // LOCAL_BOOKINGS[unit]       = array iz local_bookings.json
  const OCC_CACHE       = {};
  const PRICE_CACHE     = {};
  const SPECIAL_OFFERS  = {};
  const LOCAL_BOOKINGS  = {};


  let currentMonthIdx = 0;
  let solidTimer = null;

 //  selection state S //
 // const S = {
 //   start: null,    // Date local midnight
 //   end:   null,    // Date local midnight (exclusive)
 //   disableDeparture: true // public treats departure day as blocked
 // };
   const S = {
    start: null,    // Date local midnight
    end:   null,    // Date local midnight (exclusive)
    // public: dovolimo prihod na dan odhoda prejšnjega gosta
    disableDeparture: false
  };

  async function updateMonthsAheadForUnit(unit){
    const fallbackMonths = cfg.MONTHS_AHEAD || 12;
    let val = fallbackMonths;

    try {
      const settings = await fetchJSON(`/app/common/data/json/units/${encodeURIComponent(unit)}/site_settings.json`);

      if (settings && typeof settings.month_render === "number") {
        const m = settings.month_render;
        if (m >= 1 && m <= 36) {
          val = m;
        }
      }

// --- DAY USE SETTINGS ---
if (settings.day_use && settings.day_use.enabled) {
    DAY_USE_SETTINGS.enabled = true;
    DAY_USE_SETTINGS.from = settings.day_use.from || null;
    DAY_USE_SETTINGS.to = settings.day_use.to || null;
    DAY_USE_SETTINGS.max_days_ahead = Number(settings.day_use.max_days_ahead || 0);
    DAY_USE_SETTINGS.max_persons = Number(settings.day_use.max_persons || 0);
} else {
    DAY_USE_SETTINGS.enabled = false;
    DAY_USE_SETTINGS.max_days_ahead = 0;
}

      // booking.min_nights per-unit
      if (settings && settings.booking && typeof settings.booking.min_nights === "number") {
        const mn = settings.booking.min_nights;
        if (mn >= 1 && mn <= 365) {
          MIN_NIGHTS = mn;
        } else {
          MIN_NIGHTS = cfg.MIN_NIGHTS || 1;
        }
      } else {
        MIN_NIGHTS = cfg.MIN_NIGHTS || 1;
      }
      // posodobi UI badge za min. noči
      updateMinNightsBadge();
    } catch (e) {
      console.warn("[pubcal] site_settings load failed for unit", unit, e);
      // v primeru napake fallback
      MONTHS_AHEAD = fallbackMonths;
      MIN_NIGHTS = cfg.MIN_NIGHTS || 1;
      updateMinNightsBadge();
      currentMonthIdx = 0;
      console.log("[pubcal] MONTHS_AHEAD fallback for", unit, "=", MONTHS_AHEAD, "MIN_NIGHTS=", MIN_NIGHTS);
      return;
    }

    MONTHS_AHEAD = val;
    currentMonthIdx = 0; // reset scroll index, da prev/next ne zgreši
    console.log("[pubcal] MONTHS_AHEAD for", unit, "=", MONTHS_AHEAD, "MIN_NIGHTS=", MIN_NIGHTS);
  }


  // ------------------
  // DATE HELPERS
  // ------------------
  function ymd(d){
    const y = d.getFullYear();
    const m = (d.getMonth()+1).toString().padStart(2,"0");
    const da= d.getDate().toString().padStart(2,"0");
    return `${y}-${m}-${da}`;
  }
  function parseISO(s){
    const [Y,M,D] = s.split("-").map(n=>parseInt(n,10));
    return new Date(Y, M-1, D, 0,0,0,0);
  }
  function addDays(d,n){
    const r = new Date(d.getTime());
    r.setDate(r.getDate()+n);
    return r;
  }
  function sameDay(a,b){
    return a.getFullYear()===b.getFullYear()
        && a.getMonth()===b.getMonth()
        && a.getDate()===b.getDate();
  }
  function daysBetween(startDate,endDate){
    return Math.round((endDate - startDate)/86400000);
  }

function isDayUseEligible(isoDate) {
    if (!DAY_USE_MODE) return false;
    if (!DAY_USE_SETTINGS.enabled) return false;

    const today = new Date();
    const isoToday = today.toISOString().slice(0,10);

    let limit = new Date();
    limit.setDate(limit.getDate() + DAY_USE_SETTINGS.max_days_ahead);
    const isoLimit = limit.toISOString().slice(0,10);

    // mora biti v oknu
    if (isoDate < isoToday || isoDate > isoLimit) return false;

    // ne sme biti dejansko zaseden;
    // "depart" (dan odhoda prejšnjega gosta) je OK za day-use,
    // ker auto-clean after bi ga, če je vklopljen, že označil kot "busy".
    const stat = getDayStatus(currentUnit, isoDate); // "busy" | "depart" | "free"
    if (stat === "busy") return false;

    return true;
}


  // ------------------
  // DATA LOADING + EXPANSION
  // ------------------

  function getOccUrl(unit){
    if (cfg.OCC_URLS && cfg.OCC_URLS[unit]) return cfg.OCC_URLS[unit];
    return `/app/common/data/json/units/${encodeURIComponent(unit)}/occupancy.json`;
  }
  function getPriceUrl(unit){
    if (cfg.PRICE_URLS && cfg.PRICE_URLS[unit]) return cfg.PRICE_URLS[unit];
    return `/app/common/data/json/units/${encodeURIComponent(unit)}/prices.json`;
  }
  function getSpecialOffersUrl(unit){
    if (cfg.SPECIAL_OFFERS_URLS && cfg.SPECIAL_OFFERS_URLS[unit]) {
      return cfg.SPECIAL_OFFERS_URLS[unit];
    }
    return `/app/common/data/json/units/${encodeURIComponent(unit)}/special_offers.json`;
  }

  // Normalizacija special_offers.json -> array aktivnih intervalov
  function normalizeSpecialOffers(raw){
    const out = [];
    if (!raw || !Array.isArray(raw.offers)) return out;

    raw.offers.forEach((offer) => {
      if (!offer) return;

      // disable if explicitly off
      if (offer.enabled === false) return;
      if (offer.active === false) return;

      // zberemo vse periode
      const ranges = [];

      // kanonični datum: periods[]; fallback: from/to ali active_from/active_to
      const baseFrom = offer.from || offer.active_from;
      const baseTo   = offer.to   || offer.active_to;

      if (Array.isArray(offer.periods) && offer.periods.length > 0) {
        offer.periods.forEach((p) => {
          if (p && p.start && p.end) {
            ranges.push({ from: p.start, to: p.end });
          }
        });
      } else if (baseFrom && baseTo) {
        ranges.push({ from: baseFrom, to: baseTo });
      }

      if (!ranges.length) return;

      const discountPercent =
        typeof offer.discount_percent === "number"
          ? offer.discount_percent
          : (offer.discount &&
             offer.discount.type === "percent" &&
             typeof offer.discount.value === "number")
              ? offer.discount.value
              : null;

      const minNights =
        offer.min_nights != null
          ? offer.min_nights
          : (offer.conditions &&
             typeof offer.conditions.min_nights === "number")
              ? offer.conditions.min_nights
              : null;

      const priority =
        typeof offer.priority === "number" ? offer.priority : 0;

      ranges.forEach((r) => {
        try {
          const fromDate = parseISO(r.from);
          const toDate   = parseISO(r.to);
          if (isNaN(fromDate.getTime()) || isNaN(toDate.getTime())) return;

          out.push({
            id:   offer.id || null,
            name: offer.name || "",
            from: r.from,
            to:   r.to,
            fromDate,
            toDate,
            discount_percent: discountPercent,
            min_nights:       minNights,
            priority
          });
        } catch (e) {
          // ignore broken range
        }
      });
    });

    return out;
  }

  async function loadSpecialOffersForUnit(unit){
    if (SPECIAL_OFFERS[unit]) return; // že naloženo ali poskus

    try {
      const raw = await fetchJSON(getSpecialOffersUrl(unit));
      SPECIAL_OFFERS[unit] = normalizeSpecialOffers(raw || {});
    } catch (e) {
      console.warn("[pubcal] special_offers load failed for", unit, e);
      SPECIAL_OFFERS[unit] = [];
    }
  }

  function getSpecialOffersForDate(unit, isoDate){
    const list = SPECIAL_OFFERS[unit] || [];
    if (!list.length) return [];
    const d = parseISO(isoDate);
    return list.filter((o) => d >= o.fromDate && d < o.toDate); // [from,to) kot occupancy
  }

  function hasSpecialOfferOn(unit, isoDate){
    const list = getSpecialOffersForDate(unit, isoDate);
    return !!(list && list.length);
  }

  function buildSpecialOfferTooltip(unit, isoDate){
    const list = getSpecialOffersForDate(unit, isoDate);
    if (!list.length) return "";

    // vzemi najvišji priority, fallback na prvega
    let best = list[0];
    for (let i = 1; i < list.length; i++) {
      if ((list[i].priority || 0) > (best.priority || 0)) {
        best = list[i];
      }
    }

    const name = best.name || "Posebna ponudba";
    const pct  =
      typeof best.discount_percent === "number"
        ? ` – ${best.discount_percent}%`
        : "";
    const minN =
      typeof best.min_nights === "number" && best.min_nights > 0
        ? ` (min. ${best.min_nights} noči)`
        : "";

    return `${name}${pct}${minN}`;
  }

  async function fetchJSON(url){
    const r = await fetch(url, {cache:"no-store"});
    if(!r.ok){
      console.error("fetch fail", url, r.status);
      return null;
    }
    try { return await r.json(); }
    catch(e){
      console.error("bad json", url, e);
      return null;
    }
  }

  // occupancy blocks:
  // [
  //   { "start": "2025-10-26", "end": "2025-10-29", "status": "reserved" },
  //   { "start": "2025-11-10", "end": "2025-11-13", "status": "blocked" }
  // ]
  //
  // expand to map[ymd] = "busy" for each stay night,
  // plus "depart" on end day (exclusive) if not otherwise busy.
  // expand occupancy blocks (supports BOTH schemas):
  // - interval v2: {start,end,status}  (end exclusive / checkout)
  // - interval v1: {from,to,type}
  // - daily:       {date,type} or {day,type} or {from,type} (no "to")
  function expandOccupancyBlocksToMap(blocks){
    const map = {};
    if(!Array.isArray(blocks)) return map;

    const getStart = (b) => b.start || b.from || b.date || b.day || null;
    const getEnd   = (b) => b.end   || b.to   || null;

    for(const blk of blocks){
      if(!blk) continue;

      const sStr = getStart(blk);
      const eStr = getEnd(blk);

      // DAILY mode (no end): mark that day busy
      if (sStr && !eStr) {
        const s = parseISO(sStr);
        if (isNaN(s.getTime())) continue;
        map[ymd(s)] = "busy";
        continue;
      }

      // INTERVAL mode
      if(!sStr || !eStr) continue;

      const s = parseISO(sStr);
      const e = parseISO(eStr);
      if (isNaN(s.getTime()) || isNaN(e.getTime())) continue;

      // nights in [s, e)
      let cur = new Date(s.getTime());
      while(cur < e){
        const key = ymd(cur);
        map[key] = "busy"; // treat reserved/blocked/buffer all as busy
        cur = addDays(cur,1);
      }

      // departure marker on e (checkout day)
      if(e > s){
        const depKey = ymd(e);
        if(!map[depKey]){
          map[depKey] = "depart";
        }
      }
    }
    return map;
  }

  // ------------------
  // LOCAL BOOKINGS (admin_block / cleaning / maintenance overlay)
  // ------------------

  function getLocalBookingsUrl(unit){
    if (cfg.LOCAL_BOOKINGS_URLS && cfg.LOCAL_BOOKINGS_URLS[unit]) {
      return cfg.LOCAL_BOOKINGS_URLS[unit];
    }
    return `/app/common/data/json/units/${encodeURIComponent(unit)}/local_bookings.json`;
  }

  async function loadLocalBookingsForUnit(unit){
    if (LOCAL_BOOKINGS[unit]) return;
    try {
      const data = await fetchJSON(getLocalBookingsUrl(unit));
      LOCAL_BOOKINGS[unit] = Array.isArray(data) ? data : [];
    } catch (e) {
      console.warn("[pubcal] local_bookings load failed for", unit, e);
      LOCAL_BOOKINGS[unit] = [];
    }
  }

  // V public koledarju prikažemo:
  // - admin_block, cleaning, maintenance  -> kot zasedeno
  // - soft_hold / source:"inquiry"        -> IGNORE (gost ne vidi pendinga)
  function overlayLocalBookingsIntoOcc(unit){
    const occ = OCC_CACHE[unit];
    if (!occ) return;

    const rows = LOCAL_BOOKINGS[unit] || [];
    if (!rows.length) return;

    const overlayBlocks = [];

    for (const row of rows) {
      if (!row) continue;

      const t      = row.type   || "";
      const source = row.source || "";

      // soft_hold / inquiry = nikoli v public koledar
      if (t === "soft_hold" || source === "inquiry") {
        continue;
      }

      // admin_block / cleaning / maintenance = v public koledar kot blokada
      if (t === "admin_block" || t === "cleaning" || t === "maintenance") {
        const s = row.start || row.from || row.date || row.day;
        const e = row.end   || row.to   || null;

        if (!s) continue;

        overlayBlocks.push({
          start: s,
          end:   e,
          status: "blocked"
        });
      }
    }

    if (!overlayBlocks.length) return;

    // Uporabimo obstoječo logiko za razširjanje blokov
    const overlayMap = expandOccupancyBlocksToMap(overlayBlocks);

    // Merge v OCC_CACHE:
    // - "busy" vedno zmaga
    // - "depart" zapišemo samo, če dan še ni označen
    for (const key in overlayMap) {
      const val = overlayMap[key];
      if (val === "busy") {
        occ[key] = "busy";
      } else if (val === "depart") {
        if (!occ[key]) {
          occ[key] = "depart";
        }
      }
    }
  }

  // prices formats:
  //
  // A) flat daily map (ideal for public):
  // {
  //   "2025-11-02":85,
  //   "2025-11-03":85,
  //   "2025-11-04":90
  // }
  //
  // B) fallback legacy:
  // { "prices":[ {start,end,price}, {date,price} ] }
  //
  function normalizePrices(priceRaw){
    const out = {};

    if(!priceRaw) return out;

    // detect flat daily map
    const keys = Object.keys(priceRaw);
    if(keys.length && /^\d{4}-\d{2}-\d{2}$/.test(keys[0])){
      // direct copy
      for(const k of keys){
        out[k] = priceRaw[k];
      }
      return out;
    }

    // fallback legacy
    const list = Array.isArray(priceRaw) ? priceRaw : priceRaw.prices;
    if(!Array.isArray(list)) return out;

    for(const entry of list){
      if(entry.date && entry.price != null){
        out[entry.date] = entry.price;
        continue;
      }
      if(entry.start && entry.end && entry.price != null){
        const s = parseISO(entry.start);
        const e = parseISO(entry.end);
        let cur = new Date(s.getTime());
        while(cur < e){
          out[ymd(cur)] = entry.price;
          cur = addDays(cur,1);
        }
      }
    }

    return out;
  }

  async function loadDataForUnit(unit){
    // naloži occupancy + prices (po potrebi)
    if (!OCC_CACHE[unit] || !PRICE_CACHE[unit]) {
      const [occRaw, priceRaw] = await Promise.all([
        fetchJSON(getOccUrl(unit)),
        fetchJSON(getPriceUrl(unit))
      ]);

      OCC_CACHE[unit]   = expandOccupancyBlocksToMap(occRaw || []);
      PRICE_CACHE[unit] = normalizePrices(priceRaw || {});
    }

    // naloži local_bookings in jih projiciraj v OCC map (admin_block / cleaning / maintenance)
    await loadLocalBookingsForUnit(unit);
    overlayLocalBookingsIntoOcc(unit);
  }

  function getDayStatus(unit, dateStr){
    const occ = OCC_CACHE[unit] || {};
    return occ[dateStr] || "free"; // "busy" | "depart" | "free"
  }

  function getDayPrice(unit, dateObj){
    const pc = PRICE_CACHE[unit] || {};
    return pc[ymd(dateObj)];
  }

  // ------------------
  // BLOCK CHECK LOGIC
  // ------------------

  // day is blocked for guest if:
  //  - busy
  //  - depart && disableDeparture===true
  //  - no price
  function isBlockedDateForUnit(unit, dateObj){
    // preteklost = vedno blokirano za javni booking
    const today = new Date();
    const todayMid = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (dateObj < todayMid) return true;

    const stat = getDayStatus(unit, ymd(dateObj));
    const priceVal = getDayPrice(unit, dateObj);

    if(stat === "busy") return true;
    if(stat === "depart" && S.disableDeparture === true) return true;
    if(priceVal == null) return true; // no price = we don't sell it publicly

    return false;
  }

  // check every day in [A .. B] inclusive
  function spanClearForUnit(unit, A, Binclusive){
    let d = new Date(A.getTime());
    const stop = new Date(Binclusive.getTime());
    while(d.getTime() <= stop.getTime()){
      if(isBlockedDateForUnit(unit, d)) return false;
      d = addDays(d,1);
    }
    return true;
  }

  function spanClear(A, Binclusive){
    return spanClearForUnit(currentUnit, A, Binclusive);
  }

  // same for otherUnit to compare offers
  function fullRangeClearOnUnit(otherUnit, startDate, endDateExclusive){
    if(!startDate || !endDateExclusive) return false;
    const lastNight = addDays(endDateExclusive,-1);
    return spanClearForUnit(otherUnit, startDate, lastNight);
  }

  // ------------------
  // RENDER CALENDAR
  // ------------------

  function render(){
    if(!calendarRoot) return;
    calendarRoot.innerHTML = "";

    const today = new Date();
    const baseY = today.getFullYear();
    const baseM = today.getMonth();

    for(let i=0;i<MONTHS_AHEAD;i++){
      const d = new Date(baseY, baseM+i, 1, 0,0,0,0);
      calendarRoot.appendChild(renderMonthCard(d));
    }

    repaintSelection(false);
  }

  function renderMonthCard(firstOfMonth){
    const y = firstOfMonth.getFullYear();
    const m = firstOfMonth.getMonth();
    
    const lang   = getCurrentLang();
    const locale = getLocaleForLang(lang);
    const label  = firstOfMonth.toLocaleString(locale, {
      month: "long",
      year: "numeric"
    });

    const card = document.createElement("section");
    card.className = "month-card";

    const head = document.createElement("header");
    head.className = "month-head";
    head.textContent = label;
    card.appendChild(head);

    const grid = document.createElement("div");
    grid.className = "calendar-grid";

    const weekdayNames = getWeekdayNames(lang);

    weekdayNames.forEach(wd => {
      const th = document.createElement("div");
      th.className = "weekday";
      th.textContent = wd;
      grid.appendChild(th);
    });


    // filler offset: Mon=0
    const firstDayIdx = (new Date(y,m,1).getDay()+6)%7;
    for(let k=0;k<firstDayIdx;k++){
      const pad=document.createElement("div");
      pad.className="pad";
      grid.appendChild(pad);
    }

    const lastDate = new Date(y,m+1,0).getDate();
    for(let day=1;day<=lastDate;day++){
      const cellDate=new Date(y,m,day,0,0,0,0);
      const cell = renderDayCell(cellDate);
      grid.appendChild(cell);
    }

    card.appendChild(grid);
    return card;
  }

  // RENDER DAY CELL (final version)
  function renderDayCell(dateObj){
    const dYmd  = ymd(dateObj);
    const stat  = getDayStatus(currentUnit,dYmd);   // "busy"|"depart"|"free"
    const price = getDayPrice(currentUnit,dateObj); // number|undefined
    const iso = ymd(dateObj);


    const cell = document.createElement("div");
    cell.className = "day";
    cell.dataset.ymd = dYmd;

    const now = new Date();
    const todayMid = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const isPast = dateObj < todayMid;

    // highlight today
    if (sameDay(now, dateObj)) {
      cell.classList.add("today");
    }

    // is day available for guest?
    const isBlockedForGuest =
      isPast ||
      stat === "busy" ||
      (stat === "depart" && S.disableDeparture === true) ||
      (price == null); // no price => not sellable publicly

    if (isPast) {
      cell.classList.add("past");
    } else if (isBlockedForGuest) {
      cell.classList.add("busy"); // reuse bordo style
    }

    // number row
    const num = document.createElement("div");
    num.className = "d-num";
    num.textContent = dateObj.getDate();
    cell.appendChild(num);

    // bottom row
    const sub = document.createElement("div");
    sub.className = "d-sub";

    if (isBlockedForGuest) {
      sub.innerHTML = `<span class="no-price">—</span>`;
    } else {
      sub.innerHTML = `<span class="price">${price}€</span>`;
    }

    cell.appendChild(sub);

    // klik:
    // - klasično: samo če ni blokiran za prenočevanje
    // - day_use: tudi če ni cene, dokler je dan free po zasedenosti
    if (!isBlockedForGuest || (DAY_USE_MODE && isDayUseEligible(iso))) {
      cell.addEventListener("click", () => handleDayClick(dateObj));
    }


    // --- SPECIAL OFFERS FRAME (light green border) ---
    // Prikaz special offer sloja je neodvisen od availability/cene.
    // Klikabilnost dneva še vedno kontrolira isBlockedForGuest,
    // ta sloj pa samo vizualno označi, da je dan v obdobje special_offers.json.
    if (!isPast && hasSpecialOfferOn(currentUnit, iso)) {
      cell.classList.add("has-special-offer");
      const tip = buildSpecialOfferTooltip(currentUnit, iso);
      if (tip) {
        cell.title = tip;
      }
    }

// --- DAY USE BLUE DOT ---
if (isDayUseEligible(iso)) {
    cell.classList.add("has-dayuse");

    const dot = document.createElement("span");
    dot.className = "dot-dayuse";
    cell.appendChild(dot);
}


    return cell;
  }

  // ------------------
  // SELECTION VISUALS
  // ------------------

  function clearSelectionClasses(){
    document.querySelectorAll(".day.sel,.day.sel-start,.day.sel-mid,.day.sel-end,.day.sel-checkout")
      .forEach(el=>{
        el.classList.remove("sel","sel-start","sel-mid","sel-end","sel-checkout","sel-solid");
      });
  }

  function repaintSelection(triggerSolidDelay){
    clearSelectionClasses();

    if(!S.start && !S.end){
      return;
    }

    if(S.start && !S.end){
      const firstY = ymd(S.start);
      const el = document.querySelector(`.day[data-ymd="${firstY}"]`);
      if(el){
        el.classList.add("sel","sel-start","sel-end");
      }
      return;
    }

    if(S.start && S.end){
      const firstY = ymd(S.start);
      const lastNight = addDays(S.end,-1);
      const lastY = ymd(lastNight);
      const checkoutY = ymd(S.end);

      // mark all sleep nights
      document.querySelectorAll(".day[data-ymd]").forEach(el=>{
        const cellY = el.dataset.ymd;
        if(!cellY) return;
        const dt = parseISO(cellY);
        if(dt>=S.start && dt<S.end){
          el.classList.add("sel");
          if(cellY===firstY){
            el.classList.add("sel-start");
          }else if(cellY===lastY){
            el.classList.add("sel-end");
          }else{
            el.classList.add("sel-mid");
          }
        }
      });

      // checkout day highlight
      const checkoutEl = document.querySelector(`.day[data-ymd="${checkoutY}"]`);
      if(checkoutEl){
        checkoutEl.classList.add("sel-checkout");
      }

      if(triggerSolidDelay){
        armSolidFill();
      }
    }
  }

  function armSolidFill(){
  if(solidTimer){
    clearTimeout(solidTimer);
  }
  solidTimer = setTimeout(()=>{
    document.querySelectorAll(".day.sel").forEach(el=>{
      el.classList.add("sel-solid");
    });
  }, 1000);
}
 function clearSolidFill(){
  if(solidTimer){
    clearTimeout(solidTimer);
    solidTimer = null;
  }
  document.querySelectorAll(".day.sel-solid").forEach(el=>{
    el.classList.remove("sel-solid");
  });
}
  // ------------------
  // CLICK ENGINE (range_selection_v2.10.3 semantics)
  // ------------------

  function invalidRangeFeedback(){
    alert("Ta termin žal ni na voljo.");
  }

  function handleDayClick(d){
  const clickDay = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0,0,0,0);

// DAY USE MODE: single-day select
if (DAY_USE_MODE && DAY_USE_SETTINGS.enabled) {
    const iso = ymd(clickDay);
    if (!isDayUseEligible(iso)) return;

    S.start = clickDay;
    S.end   = addDays(clickDay, 1); // end-exclusive, 1 "noč" zaradi vizualizacije
    repaintSelection();
    updateInfoPanelForDayUse(iso);
    persist();
    return;
}



    // CASE 1: no selection yet
    if(!S.start && !S.end){
      S.start = clickDay;
      S.end   = null;
      repaintSelection(false);
      updateInfoPanel();
      clearSolidFill();
      persist();
      return;
    }
    // CASE 2: imamo start, nimamo end
    if (S.start && !S.end) {
      if (sameDay(clickDay, S.start)) {

        // ⬇️ SPECIAL CASE: MIN_NIGHTS = 1 → drugi klik na isti dan potrdi 1 noč
        if (MIN_NIGHTS === 1) {
          // za vsak slučaj preverimo, da je enodnevni razpon prost: [D, D]
          const L = clickDay;
          const R = clickDay;

          if (!spanClear(L, R)) {
            invalidRangeFeedback();
            repaintSelection(false);
            updateInfoPanel();
            persist();
            return;
          }

          // potrdi 1 noč: end-exclusive → [D, D+1)
          S.start = clickDay;
          S.end   = addDays(clickDay, 1);
          repaintSelection(true);
          updateInfoPanel();
          persist();
          return;
        }

        // DEFAULT: MIN_NIGHTS > 1 → ob drugem kliku na isti dan reset
        S.start = null;
        S.end   = null;
        repaintSelection(false);
        updateInfoPanel();
        clearSolidFill();
        persist();
        return;
      }

      // normalni CASE 2: klikneš drugi dan → poskusi ustvariti razpon
      const L = (clickDay < S.start) ? clickDay : S.start;
      const R = (clickDay < S.start) ? S.start  : clickDay;

      if (!spanClear(L, R)) {
        invalidRangeFeedback();
        repaintSelection(false);
        updateInfoPanel();
        persist();
        return;
      }

      S.start = L;
      S.end   = addDays(R, 1); // end-exclusive
      repaintSelection(true);
      updateInfoPanel();
      persist();
      return;
    }


    // CASE 3: have start+end
    // click inside existing -> reset all
    if(clickDay >= S.start && clickDay < S.end){
      S.start = null;
      S.end   = null;
      repaintSelection(false);
      updateInfoPanel();
      clearSolidFill();
      persist();
      return;
    }

    // clicked before -> expand left
    if(clickDay < S.start){
      const L = clickDay;
      const R = addDays(S.start,-1);
      if(!spanClear(L, R)){
        invalidRangeFeedback();
        repaintSelection(false);
        updateInfoPanel();
        persist();
        return;
      }
      S.start = L;
      repaintSelection(true);
      updateInfoPanel();
      persist();
      return;
    }

    // clicked after or equal end -> expand right
    if(clickDay >= S.end){
      const L = S.end;
      const R = clickDay;
      if(!spanClear(L, R)){
        invalidRangeFeedback();
        repaintSelection(false);
        updateInfoPanel();
        persist();
        return;
      }
      S.end = addDays(clickDay,1);
      repaintSelection(true);
      updateInfoPanel();
      persist();
      return;
    }
  }

  // ------------------
  // INFO PANEL
  // ------------------

  function showInfo(){
    if(!infoBox) return;
    infoBox.hidden = false;
    infoBox.classList.add("show");
  }

  function hideInfo(){
    if(!infoBox) return;
    infoBox.hidden = true;
    infoBox.classList.remove("show");
  }

  function otherUnit(){
    if (!PUBLIC_UNITS.length) return null;

    // poskusi najti prvo drugo enoto
    for (const u of PUBLIC_UNITS) {
      if (u.id !== currentUnit) return u.id;
    }
    // če je samo ena, ni alt enote
    return null;
  }

  // total price for [start..end)
  function computeTotalForUnit(unit, startDate, endDateExclusive){
    if(!startDate || !endDateExclusive) return {ok:false};

    if(!fullRangeClearOnUnit(unit, startDate, endDateExclusive)){
      return {ok:false};
    }

    let total = 0;
    let cur = new Date(startDate.getTime());
    while(cur < endDateExclusive){
      const p = getDayPrice(unit, cur);
      if(p == null){
        return {ok:false};
      }
      total += p;
      cur = addDays(cur,1);
    }
    return {ok:true, total};
  }

  // async: po potrebi naloži podatke za alt enoto in posodobi alt-offer blok
  async function computeAndRenderAltOffer(altUnit, startDate, endDateExclusive){
    if (!infoBox) return;
    if (!altUnit || !startDate || !endDateExclusive) return;

    const container = infoBox.querySelector(`.alt-offer[data-alt-unit="${altUnit}"]`);
    if (!container) return;

    // po potrebi naloži occupancy + prices za alt enoto
    if (!OCC_CACHE[altUnit] || !PRICE_CACHE[altUnit]) {
      try {
        await loadDataForUnit(altUnit);
      } catch (e) {
        console.warn("[pubcal] alt-unit load failed", altUnit, e);
      }
    }

    const altTotal = computeTotalForUnit(altUnit, startDate, endDateExclusive);

    if (altTotal.ok) {
      container.classList.remove("alt-na", "alt-loading");
      container.innerHTML = `
        ${altUnit}: <strong>${altTotal.total}€</strong>
        <button type="button" class="swap-btn" data-target-unit="${altUnit}">
          Check ${altUnit}
        </button>
      `;
      // po spremembi HTML ponovno povežemo gumbe
      wireInfoBoxButtons();
    } else {
      container.classList.remove("alt-loading");
      container.classList.add("alt-na");
      container.textContent = `${altUnit}: ni na voljo za ta termin`;
    }
  }


  function updateInfoPanelForDayUse(iso){
    if (!infoBox) return;

    const t = (window.t || (k => k));

    const nice = iso.split("-").reverse().join(".");
    const from = DAY_USE_SETTINGS.from || "";
    const to   = DAY_USE_SETTINGS.to   || "";
    const maxP = DAY_USE_SETTINGS.max_persons || "";

    let extras = "";
    const txtTimeLabel = t("cal_dayuse_time_label");      // "Čas" / "Time"
    const txtMaxPersons = t("cal_dayuse_max_persons");    // "Max. osebe" / "Max. persons"

    if (from || to) {
      extras += `<span>${txtTimeLabel}: <strong>${from}–${to} </strong>`;
    }
    if (maxP) {
      extras += ` ${txtMaxPersons}: <strong>${maxP}</strong></span>`;
    }

    const txtTitle     = t("cal_dayuse_title");           // "Dnevni počitek" / "Day-use stay"
    const txtUnitLabel = t("cal_dayuse_unit_label");      // "Enota" / "Unit"
    const txtPriceHint = t("cal_dayuse_price_hint");      // namig o ceni
    const txtBtn       = t("cal_dayuse_btn");             // gumb

    infoBox.innerHTML = `
      <div class="info-main">
        <div class="info-dates">
          <div>${txtTitle}: <strong>${nice}</strong></div>
          <div>
            ${txtUnitLabel}: <strong>${currentUnit}</strong>
          </div>
        </div>
        <div class="info-side">
          <div class="info-price">
            <small>${txtPriceHint}</small>
          </div>
          <div class="info-actions">
            <button type="button" id="btnDayUseOffer" class="confirm-btn">
              ${txtBtn}
            </button>
          </div>
        </div>
      </div>
      <div class="info-sub">
        ${extras}
      </div>
    `;
    showInfo();
    // day-use offer gumb → offer_day.php
    const btn = infoBox.querySelector("#btnDayUseOffer");
    if (btn) {
      btn.addEventListener("click", () => {
        const offerUrl =
          `/app/public/offer_day.php`
          + `?unit=${encodeURIComponent(currentUnit)}`
          + `&date=${encodeURIComponent(iso)}`
          + `&lang=${encodeURIComponent(getCurrentLang())}`;
        window.location.href = offerUrl;
      });
    }
  }


  function updateInfoPanel(){
    if(!infoBox) return;

    // če smo v day_use modu in imamo izbran dan, uporabljamo poseben info
    if (DAY_USE_MODE && DAY_USE_SETTINGS.enabled && S.start) {
      const iso = ymd(S.start);
      updateInfoPanelForDayUse(iso);
      return;
    }


    // nothing selected
    if(!S.start && !S.end){
      hideInfo();
      return;
    }

    // only first day
    if (S.start && !S.end) {
      const t = (window.t || (k => k));

      const firstY = ymd(S.start);
      const p0 = getDayPrice(currentUnit, S.start);

      const txtPriceUnknown   = t("cal_price_on_request");   // "cena po povpraševanju" / "price on request"
      const txtCheckIn        = t("cal_checkin");            // "Prihod" / "Check-in"
      const txtSelectLast     = t("cal_select_last_night");  // "Izberite zadnjo noč…" / "Select last night…"
      const txtMinNightsLabel = t("cal_min_nights");         // "Min. noči" / "Min. nights"
      const txtUnitLabel      = t("cal_unit");               // "Enota" / "Unit"

      let priceLine;
      if (p0 != null) {
        const tmpl = t("cal_price_per_night");               // "{PRICE}€ / noč" / "{PRICE}€ / night"
        if (typeof tmpl === "string" && tmpl !== "cal_price_per_night" && tmpl.includes("{PRICE}")) {
          priceLine = `<strong>${tmpl.replace("{PRICE}", p0)}</strong>`;
        } else {
          priceLine = `<strong>${p0}€ / Night</strong>`;
        }
      } else {
        priceLine = `<strong>${txtPriceUnknown}</strong>`;
      }

      const niceStart = firstY.split("-").reverse().join(".");

      infoBox.innerHTML = `
        <div class="info-main">
          <div class="info-dates">
            <div>${txtCheckIn}: <strong>${niceStart}</strong></div>
            <div>${txtSelectLast}</div>
            <div>${txtMinNightsLabel}: <strong>${MIN_NIGHTS}</strong></div>
          </div>
          <div class="info-side">
            <div class="info-price">
              ${priceLine}
            </div>
          </div>
        </div>
        <div class="info-sub">
          <span>${txtUnitLabel}: <strong>${currentUnit}</strong></span>
        </div>
      `;
      showInfo();
      wireInfoBoxButtons();
      return;
    }

    // full range
    if (S.start && S.end) {
      const t = (window.t || (k => k));

      const fromY  = ymd(S.start);
      const toY    = ymd(S.end);
      const nights = daysBetween(S.start, S.end);

      const niceFrom = fromY.split("-").reverse().join(".");
      const niceTo   = toY.split("-").reverse().join(".");

      const txtPriceUnknown = t("cal_price_on_request");   // "cena po povpraševanju" / "price on request"
      const txtCheckIn      = t("cal_checkin");            // "Prihod" / "Check-in"
      const txtCheckOut     = t("cal_checkout");           // "Odhod" / "Check-out"
      const txtUnitLabel    = t("cal_unit");               // "Enota" / "Unit"
      const txtNightsLabel  = t("cal_nights");             // "Noči" / "Nights"
      const txtConfirmBtn   = t("cal_confirm_btn");        // "Pošlji povpraševanje" / "Get offer"
      const txtAltCalc      = t("cal_alt_calculating");    // "računam ponudbo …" / "calculating offer …"

      // current unit total
      const curTotal = computeTotalForUnit(currentUnit, S.start, S.end);
      const priceLine = curTotal.ok
        ? `<strong>${curTotal.total}€</strong>`
        : `<strong>${txtPriceUnknown}</strong>`;

      // other unit – placeholder, async izračun
      const altU = otherUnit();
      let altHtml = "";
      if (altU && altU !== currentUnit) {
        altHtml = `
          <div class="alt-offer alt-loading" data-alt-unit="${altU}">
            ${altU}: ${txtAltCalc}
          </div>
        `;
      }

      const offerURL = (cfg.OFFER_URL || "/app/public/offer.php")
        + `?unit=${encodeURIComponent(currentUnit)}`
        + `&from=${encodeURIComponent(fromY)}`
        + `&to=${encodeURIComponent(toY)}`
        + `&lang=${encodeURIComponent(getCurrentLang())}`;

      infoBox.innerHTML = `
        <div class="info-main">
          <div class="info-dates">
            <div>${txtCheckIn}: <strong>${niceFrom}</strong></div>
            <div>${txtCheckOut}: <strong>${niceTo}</strong></div>
            <div>${txtUnitLabel}: <strong>${currentUnit}</strong> ${txtNightsLabel}:<strong> ${nights}</strong></div>
          </div>

          <div class="info-side">
            <div class="info-price">
              ${priceLine}
            </div>
            <div class="info-actions">
              <a class="confirm-btn" href="${offerURL}">${txtConfirmBtn}</a>
            </div>
          </div>

          <div class="info-alt">
            ${altHtml}
          </div>
        </div>
      `;
      showInfo();
      wireInfoBoxButtons();

      // kickoff async alt calculation
      if (altU && altU !== currentUnit) {
        computeAndRenderAltOffer(altU, S.start, S.end).catch(console.error);
      }

      return;
    }


  }

  function wireInfoBoxButtons(){
    if (!infoBox) return;
    const sw = infoBox.querySelector(".swap-btn");
    if(sw){
      sw.addEventListener("click",()=>{
        const nu = sw.getAttribute("data-target-unit");
        if (nu) switchUnitKeepRange(nu);
      });
    }
  }

  // ------------------
  // UNIT SWITCH
  // ------------------

  async function switchUnitKeepRange(newUnit){
    if(!newUnit || newUnit===currentUnit) return;

    const oldStart = S.start;
    const oldEnd   = S.end;

    // sprejmemo samo enote, ki so v PUBLIC_UNITS (če je prazen, pustimo kot je)
    if (PUBLIC_UNITS.length && !findPublicUnit(newUnit)) {
      console.warn("[pubcal] switchUnitKeepRange: neznana enota", newUnit);
      return;
    }

    currentUnit = newUnit;
    if(unitSelect) unitSelect.value=currentUnit;

    await updateMonthsAheadForUnit(currentUnit);

    await loadDataCurrent(); // rerender for new unit

    // try keep old range if valid in new unit
    if(oldStart && oldEnd && fullRangeClearOnUnit(currentUnit, oldStart, oldEnd)){
      S.start = oldStart;
      S.end   = oldEnd;
      repaintSelection(true);
    } else {
      S.start = null;
      S.end   = null;
      repaintSelection(false); wireInfoBoxButtons();
      clearSolidFill();
    }

    updateInfoPanel();
    persist();
    scrollToSelectionStart();
  }

  // ------------------
  // URL / LOCALSTORAGE
  // ------------------

  function persist(){
    const st = {
      unit: currentUnit,
      from: S.start? ymd(S.start) :"",
      to:   S.end?   ymd(S.end)   :""
    };
    try {
      localStorage.setItem("lastRange", JSON.stringify(st));
    } catch(e){}

    const params = new URLSearchParams();
    if(st.unit) params.set("unit", st.unit);
    if(st.from) params.set("from", st.from);
    if(st.to)   params.set("to",   st.to);

    const newUrl = window.location.pathname + "?" + params.toString();
    window.history.replaceState({}, "", newUrl);
  }

  function restoreFromSeedObj(obj){
    if(!obj) return;

    if (obj.unit) {
      currentUnit = obj.unit;
    }
    if (obj.from && obj.to) {
      const s = parseISO(obj.from);
      const e = parseISO(obj.to);
      if (s && e && e > s) {
        S.start = s;
        S.end   = e;
      }
    }
  }


  function seedFromUrlOrStorage(){
    const params=new URLSearchParams(window.location.search);
    const urlU = params.get("unit");
    const urlF = params.get("from");
    const urlT = params.get("to");

    const isoOk=s=>/^\d{4}-\d{2}-\d{2}$/.test(s||"");

    if((isoOk(urlF)&&isoOk(urlT)) || urlU){
      restoreFromSeedObj({  // minimalno število nočitev za trenutno enoto (per-unit iz site_settings.json)
        unit:urlU || currentUnit,
        from:urlF,
        to:urlT
      });
      return;
    }

    if(SEED && (SEED.from||SEED.to||SEED.unit)){
      restoreFromSeedObj(SEED);
      return;
    }

    try{
      const raw=localStorage.getItem("lastRange");
      if(raw){
        restoreFromSeedObj(JSON.parse(raw));
      }
    }catch(e){}
  }

  // ------------------
  // SCROLL / HEADER OFFSET
  // ------------------

  function scrollToSelectionStart(){
    if(!S.start) return;
    const startYmd = ymd(S.start);
    const el = document.querySelector(`.day[data-ymd="${startYmd}"]`);
    if(el){
        el.scrollIntoView({behavior:"smooth",block:"center",inline:"center"});
    }
  }

  function scrollMonthCard(idx){
    const cards=document.querySelectorAll(".month-card");
    if(!cards.length) return;
    const clamp=Math.min(Math.max(idx,0),cards.length-1);
    cards[clamp].scrollIntoView({behavior:"smooth",block:"start",inline:"center"});
  }

  function updateHeaderOffset(){
    const hdr=document.querySelector(".cm-header");
    if(!hdr) return;
    const h=hdr.getBoundingClientRect().height;
    document.documentElement.style.setProperty("--header-h",h+"px");
  }

  // ------------------
  // NAV BUTTONS / UI BINDINGS
  // ------------------

  if(unitSelect){
    unitSelect.addEventListener("change",()=>{
      switchUnitKeepRange(unitSelect.value);
    });
  }

  if(btnPrev){
    btnPrev.addEventListener("click",()=>{
      currentMonthIdx=Math.max(0,currentMonthIdx-1);
      scrollMonthCard(currentMonthIdx);
    });
  }
  if(btnNext){
    btnNext.addEventListener("click",()=>{
      currentMonthIdx=Math.min(MONTHS_AHEAD-1,currentMonthIdx+1);
      scrollMonthCard(currentMonthIdx);
    });
  }
  if(btnToday){
    btnToday.addEventListener("click",()=>{
      const todayY=ymd(new Date());
      const el=document.querySelector(`.day[data-ymd="${todayY}"]`);
      if(el){
        el.scrollIntoView({behavior:"smooth",block:"center",inline:"center"});
      }
    });
  }
  if(btnClear){
    btnClear.addEventListener("click",()=>{
      S.start=null;
      S.end  =null;
      repaintSelection(false);
      clearSolidFill();
      hideInfo();
      persist();
    });
  }

  if(btnConfirm){
    btnConfirm.addEventListener("click",()=>{
      if(!S.start || !S.end){
        alert("Najprej izberite termin.");
        return;
      }

      const nights = daysBetween(S.start, S.end);
      if (MIN_NIGHTS && nights < MIN_NIGHTS) {
        alert(
          `Za izbrano enoto je minimalno število nočitev ${MIN_NIGHTS}. ` +
          `Izbranih je ${nights}.`
        );
        return;
      }

      const from = ymd(S.start);
      const to   = ymd(S.end);
      const offerURL = (cfg.OFFER_URL || "/app/public/offer.php")
        + `?unit=${encodeURIComponent(currentUnit)}`
        + `&from=${encodeURIComponent(from)}`
        + `&to=${encodeURIComponent(to)}`;
        + `&lang=${encodeURIComponent(getCurrentLang())}`;
      window.location.href = offerURL;
    });
  }

  // ------------------
  // DATA FLOW / INIT
  // ------------------

async function loadDataCurrent(){
    await loadDataForUnit(currentUnit);
    await loadSpecialOffersForUnit(currentUnit);
    render();
    updateInfoPanel();
    armSolidFill();
  }

  async function init(){
    window.addEventListener("resize", updateHeaderOffset);
    updateHeaderOffset();

    // 1) preberi public units iz manifest.json
    await loadPublicUnitsFromManifest();
    // 2) napolni <select> in porihtaj currentUnit, če je treba
    syncUnitSelectAndLabel();

    // 3) seed iz URL / localStorage – lahko popravlja currentUnit + S.start/S.end
    seedFromUrlOrStorage();

    // 4) še enkrat preveri, da je currentUnit veljaven
    if (PUBLIC_UNITS.length && !findPublicUnit(currentUnit)) {
      currentUnit = firstPublicUnitId();
      if (unitSelect) unitSelect.value = currentUnit;
    }
    // 4b) naloži per-unit months_ahead + MIN_NIGHTS
    await updateMonthsAheadForUnit(currentUnit);
    // 5) naloži podatke za izbrano enoto in render
    await loadDataCurrent();
    const chk = document.getElementById("chkDayUse");
    if (chk) {
      chk.addEventListener("change", () => {
        DAY_USE_MODE = chk.checked;

        // reset selection
        S.start = null;
        S.end   = null;

        repaintSelection();
        render(); // da nariše pikice
        updateInfoPanel();

        // prej: skrij / pokaži glavni "Potrdi" gumb
        // zdaj: gumb vedno ostane, v day_use je samo zatemnjen in nekliken
        if (btnConfirm) {
          if (DAY_USE_MODE) {
            btnConfirm.disabled = true;
            btnConfirm.classList.add("dimmed");
          } else {
            btnConfirm.disabled = false;
            btnConfirm.classList.remove("dimmed");
          }
        }
      });

      // initial sync (če je kljukica že vklopljena ob loadu)
      if (chk.checked && btnConfirm) {
        DAY_USE_MODE = true;
        btnConfirm.disabled = true;
        btnConfirm.classList.add("dimmed");
      }
    }




    repaintSelection(true);
    updateInfoPanel();
    persist();
    scrollToSelectionStart();

    console.log("[pubcal V5] ready", {
      unit: currentUnit,
      start: S.start && ymd(S.start),
      end:   S.end   && ymd(S.end),
      monthsAhead: MONTHS_AHEAD
    });
}
  init();

})();
// --- RETURN-TO-CALENDAR REHYDRATE (URL & sessionStorage) ---
(function(){
  function iso(s){ return /^\d{4}-\d{2}-\d{2}$/.test(s); }
  var url  = new URL(window.location.href);
  var unit = url.searchParams.get('unit') || '';
  var from = url.searchParams.get('from') || '';
  var to   = url.searchParams.get('to')   || '';
  var focus= url.searchParams.get('focus') === '1';

  function applySelection(u, f, t, doFocus){
    // nastavi enoto
    if (u) {
      if (typeof window.setCurrentUnit === 'function') { setCurrentUnit(u); }
      else if (typeof window.CURRENT_UNIT !== 'undefined') { window.CURRENT_UNIT = u; }
    }
    // nastavi izbor
    if (typeof window.S === 'object') {
      window.S.start = f; window.S.end = t;
    }

    // ponovno nariši izbor + solid fill
    if (typeof window.repaintSelection === 'function') window.repaintSelection(false);
    if (typeof window.clearSolidFill === 'function')   window.clearSolidFill();
    if (typeof window.solidFillRange === 'function')   window.solidFillRange(f, t);

    // scroll na prvi dan izbora
    if (doFocus) {
      var first = document.querySelector('[data-date="'+f+'"]');
      if (first && first.scrollIntoView) first.scrollIntoView({block:'center', inline:'center'});
    }

    // posodobi morebitni label enote – z guardom
    var unitAliasEl = document.getElementById('unitAlias') || document.querySelector('[data-role="unit-alias"]');
    if (unitAliasEl && u) unitAliasEl.textContent = u;
  }

  var used = false;
  if (unit && iso(from) && iso(to)) {
    try { sessionStorage.setItem('pubcal_state', JSON.stringify({unit:unit, from:from, to:to})); } catch(e){}
    applySelection(unit, from, to, focus);
    used = true;
  }

  if (!used) {
    try {
      var st = JSON.parse(sessionStorage.getItem('pubcal_state') || 'null');
      if (st && st.unit && iso(st.from) && iso(st.to)) {
        applySelection(st.unit, st.from, st.to, true);
        used = true;
      }
    } catch(e){}
  }
})();

