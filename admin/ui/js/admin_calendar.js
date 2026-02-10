/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/admin_calendar.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/admin_calendar.js
// AdminCalendar V4 – calendar renderer + layers (occupancy, local blocks, pending, day-use)
// Uses: manifest.json, unit settings API, list_inquiries.php, JSON files in /common/data/json/units

(function () {
  console.log("[admin_calendar] loaded");

  // --- Local safeFetchJson helper (independent from calendar_shell.js) ---
  async function safeFetchJson(url, options = {}) {
    try {
      const res = await fetch(url, options);
      if (!res.ok) {
        console.warn("[admin_calendar] safeFetchJson non-OK", url, res.status);
        return null;
      }
      const ct = res.headers.get("content-type") || "";
      if (ct.includes("application/json")) {
        return await res.json();
      }
      // If it's not JSON, still try to parse in case server forgot header
      try {
        return await res.json();
      } catch (_) {
        return null;
      }
    } catch (err) {
      console.error("[admin_calendar] safeFetchJson error", url, err);
      return null;
    }
  }

  const MANIFEST_URL = "/app/common/data/json/units/manifest.json";
  const UNITS_BASE = "/app/common/data/json/units";
  const LS_UNIT_KEY = "cm_current_unit";




  let CURRENT_UNIT = null;
  let MONTHS_TO_RENDER = 13; // default, overridden by site_settings.json
  let baseMonth = firstDayOfMonth(new Date());

  const CLEAN_BEFORE_CB = document.querySelector("#cal-clean-before");
  const CLEAN_AFTER_CB = document.querySelector("#cal-clean-after");
  const DAY_USE_CB = document.querySelector("#cal-day-use");
 

  let DAY_USE_ENABLED = false;
  let DAY_USE_MAX_DAYS_AHEAD = 7;

  // Special offers (akcije) – admin cache po enoti
  const SPECIAL_OFFERS_ADMIN = {};

  function getSpecialOffersUrl(unit) {
    return `${UNITS_BASE}/${encodeURIComponent(unit)}/special_offers.json?v=${Date.now()}`;
  }

  // Normalizacija special_offers.json -> array aktivnih intervalov
  function normalizeSpecialOffersAdmin(raw) {
    const out = [];
    if (!raw || !Array.isArray(raw.offers)) return out;

    raw.offers.forEach((offer) => {
      if (!offer) return;

      // disable if explicitly off
      if (offer.enabled === false) return;
      if (offer.active === false) return;

      // zberemo vse periode
      const ranges = [];
      if (Array.isArray(offer.periods) && offer.periods.length > 0) {
        offer.periods.forEach((p) => {
          if (p && p.start && p.end) {
            ranges.push({ from: p.start, to: p.end });
          }
        });
      } else if (offer.from && offer.to) {
        ranges.push({ from: offer.from, to: offer.to });
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
          if (!fromDate || !toDate) return;
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

  async function loadSpecialOffersForUnitAdmin(unit) {
    if (!unit) return;
    if (SPECIAL_OFFERS_ADMIN[unit]) return; // že naloženo ali poskus

    try {
      const raw = await fetchJson(getSpecialOffersUrl(unit));
      SPECIAL_OFFERS_ADMIN[unit] = normalizeSpecialOffersAdmin(raw || {});
    } catch (e) {
      console.warn("[admin_calendar] special_offers load failed for", unit, e);
      SPECIAL_OFFERS_ADMIN[unit] = [];
    }
  }

  function getSpecialOffersForDateAdmin(unit, isoDate) {
    const list = SPECIAL_OFFERS_ADMIN[unit] || [];
    if (!list.length) return [];
    const d = parseISO(isoDate);
    if (!d || isNaN(d.getTime())) return [];
    return list.filter((o) => d >= o.fromDate && d < o.toDate); // [from,to)
  }

  function hasSpecialOfferOnAdmin(unit, isoDate) {
    const list = getSpecialOffersForDateAdmin(unit, isoDate);
    return !!(list && list.length);
  }

  function buildSpecialOfferTooltipAdmin(unit, isoDate) {
    const list = getSpecialOffersForDateAdmin(unit, isoDate);
    if (!list.length) return "";

    // vzemi najvišji priority, fallback na prvega
    let best = list[0];
    for (let i = 1; i < list.length; i++) {
      if ((list[i].priority || 0) > (best.priority || 0)) {
        best = list[i];
      }
    }

    const name = best.name || "Posebna ponudba";
    const pct =
      typeof best.discount_percent === "number"
        ? ` – ${best.discount_percent}%`
        : "";
    const minN =
      typeof best.min_nights === "number" && best.min_nights > 0
        ? ` (min. ${best.min_nights} noči)`
        : "";

    return `${name}${pct}${minN}`;
  }



  // --------------------------
  // Helpers
  // --------------------------

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function firstDayOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }

  function addMonths(d, n) {
    return new Date(d.getFullYear(), d.getMonth() + n, 1);
  }

  function formatISO(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function addDaysISO(iso, n) {
    if (!iso) return iso;
    const d = new Date(iso + "T00:00:00");
    if (Number.isNaN(d.getTime())) return iso;
    d.setDate(d.getDate() + n);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function parseISO(iso) {
    if (!iso) return null;
    const parts = iso.split("-");
    if (parts.length !== 3) return null;
    const y = Number(parts[0]);
    const m = Number(parts[1]);
    const d = Number(parts[2]);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
  }


  function nightsBetweenISO(fromIso, toIso) {
    const a = new Date(fromIso + "T00:00:00");
    const b = new Date(toIso + "T00:00:00");
    const diff = Math.round((b - a) / 86400000);
    return diff >= 0 ? diff + 1 : 0;
  }

  function monthLabel(d) {
    return d.toLocaleString("sl-SI", { month: "long", year: "numeric" });
  }

  async function fetchJson(url) {
    const res = await fetch(url, { credentials: "same-origin" });
    if (!res.ok) {
      throw new Error("HTTP " + res.status + " for " + url);
    }
    return res.json();
  }

  // --------------------------
  // Units & settings
  // --------------------------

  // --------------------------
  // Units & settings
  // --------------------------

  async function loadUnitsIntoSelect() {
    const sel = qs("#admin-unit-select");
    if (!sel) {
      console.warn("[admin_calendar] #admin-unit-select not found");
      return null;
    }

    // 1) Manifest
    const manifest = await safeFetchJson(MANIFEST_URL);
    if (!manifest || !Array.isArray(manifest.units)) {
      console.error("[admin_calendar] Invalid or missing manifest.units");
      sel.innerHTML = '<option value="">No units</option>';
      return null;
    }

    // 2) Filtriraj veljavne enote (public + active)
    const units = manifest.units.filter((u) => {
      if (!u) return false;
      const id = typeof u === "string" ? u : u.id;
      if (!id) return false;
      // Če obstajata polja public/active, ju upoštevamo
      if (typeof u.public === "boolean" && u.public === false) return false;
      if (typeof u.active === "boolean" && u.active === false) return false;
      return true;
    });

    sel.innerHTML = "";

    if (!units.length) {
      console.warn("[admin_calendar] manifest.units is empty after filtering");
      sel.innerHTML = '<option value="">No units</option>';
      CURRENT_UNIT = null;
      localStorage.removeItem(LS_UNIT_KEY);
      return null;
    }

    // 3) Preberi zadnjo izbrano enoto iz localStorage
    const stored = localStorage.getItem(LS_UNIT_KEY) || "";

    // Seznam vseh veljavnih ID-jev
    const ids = units.map((u) => (typeof u === "string" ? String(u) : String(u.id)));

    // 4) Če stored ni več med ID-ji, ga ignoriramo
    let chosen = "";
    if (stored && ids.includes(stored)) {
      chosen = stored;
    } else {
      chosen = ids[0]; // prva veljavna enota iz manifesta
    }

    // 5) Napolni <select> z enotami, izberi "chosen"
    for (const u of units) {
      const id = typeof u === "string" ? String(u) : String(u.id);
      const label = typeof u === "string"
        ? id
        : (u.label || u.alias || u.name || id);

      const opt = document.createElement("option");
      opt.value = id;
      opt.textContent = label;
      if (id === chosen) opt.selected = true;
      sel.appendChild(opt);
    }

    // 6) Posodobi globalno stanje + localStorage
    CURRENT_UNIT = chosen;
    localStorage.setItem(LS_UNIT_KEY, CURRENT_UNIT);

    // 7) Obvesti ostale module, da se je enota spremenila
    window.dispatchEvent(
      new CustomEvent("cm:unitChanged", { detail: { unit: CURRENT_UNIT } })
    );

    console.log("[admin_calendar] loadUnitsIntoSelect -> CURRENT_UNIT =", CURRENT_UNIT);

    return CURRENT_UNIT;
  }

  async function loadBookingRulesForUnit(unit) {
    try {
      const url = `/app/admin/api/unit_settings_get.php?unit=${unit}`;
      const res = await safeFetchJson(url);
      if (!res || !res.ok) return;

      const mn = res.settings?.booking?.min_nights ?? 1;
      const input = document.getElementById("cal-min-nights");
      if (input) input.value = mn;
    } catch (e) {
      console.warn("[admin_calendar] booking rules load error", e);
    }
  }

  async function loadMonthRenderForUnit(unit) {
    try {
      const url = `${UNITS_BASE}/${unit}/site_settings.json?v=${Date.now()}`;
      const j = await fetchJson(url);
      const mr =
        (j &&
          j.display &&
          typeof j.display.month_render === "number" &&
          j.display.month_render) ||
        (typeof j.month_render === "number" && j.month_render) ||
        13;
      MONTHS_TO_RENDER = Math.max(1, Math.min(24, mr));
      console.log(
        "[admin_calendar] month_render for",
        unit,
        "→",
        MONTHS_TO_RENDER
      );
    } catch (e) {
      console.warn(
        "[admin_calendar] Using default MONTHS_TO_RENDER for",
        unit,
        e
      );
      MONTHS_TO_RENDER = 13;
    }
  }

  async function loadCleanFlagsForUnit(unit) {
    if (!unit) return;
    if (!CLEAN_BEFORE_CB && !CLEAN_AFTER_CB && !DAY_USE_CB) return;

    try {
      const url =
        "/app/admin/api/unit_settings_get.php?unit=" +
        encodeURIComponent(unit);
      const res = await fetch(url, { credentials: "same-origin" });
      if (!res.ok) {
        console.warn("[admin_calendar] loadCleanFlags HTTP", res.status);
        return;
      }
      const data = await res.json().catch(() => null);
      if (!data || !data.ok || !data.settings) {
        console.warn("[admin_calendar] loadCleanFlags bad JSON", data);
        return;
      }

      // auto_block (čiščenje)
      const autoBlock = data.settings.auto_block || {};
      if (CLEAN_BEFORE_CB) {
        CLEAN_BEFORE_CB.checked = !!autoBlock.before_arrival;
      }
      if (CLEAN_AFTER_CB) {
        CLEAN_AFTER_CB.checked = !!autoBlock.after_departure;
      }

      // day_use (blue dot)
      const dayUse = data.settings.day_use || {};
      DAY_USE_ENABLED = !!dayUse.enabled;
      const maxDays =
        typeof dayUse.max_days_ahead === "number" ? dayUse.max_days_ahead : 7;
      DAY_USE_MAX_DAYS_AHEAD = Math.max(0, maxDays || 0);

      if (DAY_USE_CB) {
        DAY_USE_CB.checked = DAY_USE_ENABLED;
      }
    } catch (e) {
      console.warn("[admin_calendar] loadCleanFlagsForUnit failed", e);
    }
  }

  async function saveCleanFlag(key, checked) {
    if (!CURRENT_UNIT) return;
    const body = new URLSearchParams();
    body.set("unit", CURRENT_UNIT);
    body.set("key", key); // "auto_block.before_arrival" ali ".after_departure"
    body.set("value", checked ? "1" : "0");

    try {
      const res = await fetch("/app/admin/api/unit_settings_update.php", {
        method: "POST",
        body,
        credentials: "same-origin",
      });
      if (!res.ok) {
        console.warn("[admin_calendar] saveCleanFlag HTTP", res.status);
        return;
      }
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        console.warn("[admin_calendar] saveCleanFlag bad JSON", data);
      }
    } catch (e) {
      console.warn("[admin_calendar] saveCleanFlag failed", e);
    }
  }

  async function saveDayUseEnabled(enabled) {
    if (!CURRENT_UNIT) return;
    const body = new URLSearchParams();
    body.set("unit", CURRENT_UNIT);
    body.set("key", "day_use.enabled");
    body.set("value", enabled ? "1" : "0");

    try {
      const res = await fetch("/app/admin/api/unit_settings_update.php", {
        method: "POST",
        body,
        credentials: "same-origin"
      });
      if (!res.ok) {
        console.warn("[admin_calendar] saveDayUseEnabled HTTP", res.status);
        return;
      }
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        console.warn("[admin_calendar] saveDayUseEnabled bad JSON", data);
      } else {
        console.log(
          "[admin_calendar] day_use.enabled updated →",
          enabled,
          "for",
          CURRENT_UNIT
        );
      }
    } catch (e) {
      console.warn("[admin_calendar] saveDayUseEnabled failed", e);
    }
  }


  function updateCurrentRangeLabel() {
    const el = qs("#cal-current-range");
    if (!el) return;

    const from = monthLabel(baseMonth);
    const lastMonth = addMonths(baseMonth, MONTHS_TO_RENDER - 1);
    const to = monthLabel(lastMonth);

    el.textContent = `${from} – ${to}`;
  }

  // --------------------------
  // Calendar rendering
  // --------------------------

    function buildMonth(firstOfMonth) {
    const Y = firstOfMonth.getFullYear();
    const M = firstOfMonth.getMonth();

    const wrap = document.createElement("section");
    wrap.className = "month-card";

    const title = document.createElement("header");
    title.className = "month-title";
    title.textContent = monthLabel(firstOfMonth);
    wrap.appendChild(title);

    const grid = document.createElement("div");
    grid.className = "calendar-grid";
    wrap.appendChild(grid);

    // 🔹 vrstica z dnevi v tednu (P T S Č P S N – ponedeljek-based)
    const dowShort = ["P", "T", "S", "Č", "P", "S", "N"];
    dowShort.forEach((label) => {
      const hd = document.createElement("div");
      hd.className = "dow";
      hd.textContent = label;
      grid.appendChild(hd);
    });

    const start = new Date(Y, M, 1);
    const end = new Date(Y, M + 1, 0);
    const daysInMonth = end.getDate();

    const todayIso = formatISO(new Date());

    // Monday-based offset (Mon=0)
    const offset = (start.getDay() + 6) % 7;
    for (let i = 0; i < offset; i++) {
      const empty = document.createElement("div");
      empty.className = "day empty";
      grid.appendChild(empty);
    }

    for (let d = 1; d <= daysInMonth; d++) {
      const date = new Date(Y, M, d);
      const iso = formatISO(date);

      const cell = document.createElement("div");
      cell.className = "day";
      cell.dataset.date = iso;

      // 🔸 today okvirček – class "today"
      if (iso === todayIso) {
        cell.classList.add("today");
      }

      const num = document.createElement("div");
      num.className = "day-num";
      num.textContent = String(d);
      cell.appendChild(num);

      const price = document.createElement("div");
      price.className = "price";
      cell.appendChild(price);

      grid.appendChild(cell);
    }

    return wrap;
  }

  function renderCalendar() {
    const root = qs("#calendar");
    if (!root) {
      console.warn("[admin_calendar] #calendar not found");
      return;
    }
    root.innerHTML = "";

    if (!CURRENT_UNIT) {
      console.warn("[admin_calendar] No CURRENT_UNIT – nothing to render");
      return;
    }

    const startMonth = baseMonth;
    for (let i = 0; i < MONTHS_TO_RENDER; i++) {
      const m = addMonths(startMonth, i);
      root.appendChild(buildMonth(m));
    }

    updateCurrentRangeLabel();

    // Inform range_select_admin.js that the DOM was rebuilt
    document.dispatchEvent(new CustomEvent("calendar:rendered"));

    // Apply layers asynchronously
    applyCalendarLayers(CURRENT_UNIT);
  }

  // --------------------------
  // Layers: occupancy + local admin blocks + prices + dots
  // --------------------------

  function eachDayISO(fromIso, toIsoExclusive, cb) {
    if (!fromIso || !toIsoExclusive) return;
    const start = new Date(fromIso + "T00:00:00");
    const end = new Date(toIsoExclusive + "T00:00:00");
    if (isNaN(start) || isNaN(end)) return;

    for (let d = new Date(start); d < end; d.setDate(d.getDate() + 1)) {
      cb(formatISO(d));
    }
  }

  function normalizeRangeRow(row) {
    if (!row || typeof row !== "object") return null;
    const start = row.start || row.from;
    const end = row.end || row.to;
    if (!start || !end) return null;
    const type = row.type || row.status || "";
    const status = row.status || row.type || "";
    return { start, end, type, status };
  }

  async function loadOccupancyMap(unit) {
    const url = `${UNITS_BASE}/${unit}/occupancy_merged.json?v=${Date.now()}`;
    let data;
    try {
      data = await fetchJson(url);
    } catch (e) {
      console.warn("[admin_calendar] No occupancy for", unit, e);
      return {};
    }

    const map = {};

    if (Array.isArray(data)) {
      // Array of ranges or daily entries
      for (const row of data) {
        const nr = normalizeRangeRow(row);
        if (nr) {
          const status = nr.status || nr.type || "busy";
          eachDayISO(nr.start, nr.end, (d) => {
            map[d] = status;
          });
        } else if (row && (row.date || row.day)) {
          const k = row.date || row.day;
          const s = row.status || row.type || "busy";
          map[k] = s;
        }
      }
    } else if (data && typeof data === "object") {
      // Already a date → status map
      return data;
    }

    return map;
  }

async function loadOccupancyMetaMap(unit) {
  const url = `${UNITS_BASE}/${unit}/occupancy_merged.json?v=${Date.now()}`;
  let data;
  try {
    data = await fetchJson(url);
  } catch (e) {
    return {};
  }

  const metaMap = {};
  if (!Array.isArray(data)) return metaMap;

  for (const row of data) {
    const nr = normalizeRangeRow(row);
    if (!nr) continue;

    const status = row.status || row.type || "";
    const isHard =
      status === "reserved" || status === "confirmed" || status === "booked";

    if (!isHard) continue;

    const meta = {
      status: String(status),
      source: String(row.source || ""),
      platform: String((row.meta && row.meta.platform) || row.platform || ""),
      lock: String(row.lock || ""),
      id: String(row.id || ""),
      summary: String((row.meta && row.meta.summary) || ""),
    };

    eachDayISO(nr.start, nr.end, (d) => {
      // če je več hard-lock virov na isti dan, naj ostane prvi (ali zamenjaj po želji)
      if (!metaMap[d]) metaMap[d] = meta;
    });
  }

  return metaMap;
}



  async function loadLocalAdminBlocksMap(unit) {
    const url = `${UNITS_BASE}/${unit}/local_bookings.json?v=${Date.now()}`;
    let data;
    try {
      data = await fetchJson(url);
    } catch (e) {
      // no local_bookings is OK – simply no admin blocks yet
      console.info("[admin_calendar] No local_bookings for", unit);
      return {};
    }

    const map = {};
    if (!Array.isArray(data)) return map;

    for (const row of data) {
      const nr = normalizeRangeRow(row);
      if (!nr) continue;

      const isAdminBlock =
        nr.type === "admin_block" || nr.status === "admin_block";
      if (!isAdminBlock) continue;

      eachDayISO(nr.start, nr.end, (d) => {
        map[d] = true;
      });
    }

    return map;
  }

  async function loadPricesMap(unit) {
    const url = `${UNITS_BASE}/${unit}/prices.json?v=${Date.now()}`;
    let data;
    try {
      data = await fetchJson(url);
    } catch (e) {
      console.info("[admin_calendar] No prices for", unit, e);
      return {};
    }

    if (!data || typeof data !== "object") {
      return {};
    }
    return data;
  }

  // --- Load pending inquiries for DOT layer (ALL days via endpoint list_inquiries.php) ---
  async function loadPendingMap(unit) {
    const map = {};
    if (!unit) return map;

    const url = `/app/admin/api/list_inquiries.php?status=pending&unit=${encodeURIComponent(
      unit
    )}&v=${Date.now()}`;
    let resp = null;

    try {
      resp = await safeFetchJson(url);
    } catch (e) {
      console.warn("[admin_calendar] failed to load pending via endpoint", e);
      return map;
    }

    if (!resp || !Array.isArray(resp.items)) {
      return map;
    }

    for (const item of resp.items) {
      if (!item || typeof item !== "object") continue;

      const fromRaw =
        item.from ||
        item.date_from ||
        (item.range && item.range.start) ||
        item.start_date ||
        null;

      const toRaw =
        item.to ||
        item.date_to ||
        (item.range && item.range.end) ||
        item.end_date ||
        null;

      if (!fromRaw || !toRaw) continue;

      const fromIso = String(fromRaw).slice(0, 10);
      const toIso = String(toRaw).slice(0, 10);

      const start = new Date(fromIso + "T00:00:00");
      const end = new Date(toIso + "T00:00:00");

      if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()))
        continue;

      // DOT na VSEH dneh pendinga (from ... day-1 of departure)
      for (let d = new Date(start); d < end; d.setDate(d.getDate() + 1)) {
        const iso = formatISO(d);
        map[iso] = true;
      }
    }

    return map;
  }

  // --- Load day-use entries for DOT layer ---
  async function loadDayUseMap(unit) {
    const url = `/app/common/data/json/units/${unit}/day_use.json`;
    let map = {};

    try {
      const data = await safeFetchJson(url);
      if (data && typeof data === "object") {
        for (const d in data) {
          if (Object.prototype.hasOwnProperty.call(data, d)) {
            map[d] = data[d];
          }
        }
      }
    } catch (e) {
      console.warn("[admin_calendar] no day_use.json for", unit, e);
    }

    // 🔵 synthetic fallback:
    // če je day-use vključen in v JSON ni nič,
    // generiramo "true" za [danes .. danes+max_days_ahead]
    if (DAY_USE_ENABLED && (!map || Object.keys(map).length === 0)) {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const todayIso = formatISO(today);

      const maxDays = Number.isFinite(DAY_USE_MAX_DAYS_AHEAD)
        ? Math.max(0, DAY_USE_MAX_DAYS_AHEAD)
        : 0;

      for (let i = 0; i <= maxDays; i++) {
        const iso = addDaysISO(todayIso, i);
        map[iso] = true;
      }
    }

    return map;
  }

  // --------------------------
  // Pending MARK overlay (golden frame for marked inquiries)
  // --------------------------

  /**
   * Prebere marked_pending.json in napolni PENDING_MARKED
   * za podano enoto.
   */
    // Preberi označene pending-e iz serverja (pending_mark_toggle.php)
  async function loadMarkedPendingFromJson(unit) {
    PENDING_MARKED.clear();
    if (!unit) {
      repaintPendingMarked();
      return;
    }

    try {
      const res = await fetch(
        `/app/admin/api/pending_mark_toggle.php?unit=${encodeURIComponent(
          unit
        )}&v=${Date.now()}`,
        {
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      if (!res.ok) {
        // 404 ali napaka → noben mark, samo pusti prazno mapo
        repaintPendingMarked();
        return;
      }

      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }

      // API lahko vrne { ok, items:[...] } ali kar array
      let items = [];
      if (Array.isArray(data)) {
        items = data;
      } else if (data && Array.isArray(data.items)) {
        items = data.items;
      } else {
        repaintPendingMarked();
        return;
      }

      items.forEach((item) => {
        if (!item || typeof item !== "object") return;
        const id   = item.id;
        const u    = item.unit || null;
        const from = item.from || null;
        const to   = item.to || null;

        if (!id || !from || !to) return;
        if (u && u !== unit) return;

        PENDING_MARKED.set(String(id), { unit: u, from, to });
      });
    } catch (err) {
      console.warn("[admin_calendar] loadMarkedPendingFromJson failed:", err);
    }

    repaintPendingMarked();
  }

  const PENDING_MARKED = new Map(); // id → { unit, from, to }

  function repaintPendingMarked() {
    // počisti stare okvirje
    qsa("#calendar .day.pending-marked").forEach((el) => {
      el.classList.remove("pending-marked");
    });

    if (!PENDING_MARKED.size || !CURRENT_UNIT) return;

    for (const [, entry] of PENDING_MARKED.entries()) {
      const unit = entry.unit;
      const fromIso = entry.from;
      const toIso = entry.to;

      if (!fromIso || !toIso) continue;
      if (unit && unit !== CURRENT_UNIT) continue;

      const start = new Date(fromIso + "T00:00:00");
      const end = new Date(toIso + "T00:00:00");
      if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        continue;
      }

      // zlatorumen okvir čez cel termin (from ... day-1 of departure)
      for (let d = new Date(start); d < end; d.setDate(d.getDate() + 1)) {
        const iso = formatISO(d);
        const cell = qs(`.day[data-date="${iso}"]`);
        if (cell) {
          cell.classList.add("pending-marked");
        }
      }
    }
  }

  document.addEventListener("pending:mark_add", function (e) {
    const detail = e.detail || {};
    if (!detail.id) return;

    const id = String(detail.id);
    const unit = detail.unit || null;
    const from = detail.from || null;
    const to = detail.to || null;

    if (!from || !to) return;

    PENDING_MARKED.set(id, { unit, from, to });
    repaintPendingMarked();
  });

  document.addEventListener("pending:mark_remove", function (e) {
    const detail = e.detail || {};
    if (!detail.id) return;

    const id = String(detail.id);
    if (PENDING_MARKED.has(id)) {
      PENDING_MARKED.delete(id);
      repaintPendingMarked();
    }
  });

  document.addEventListener("pending:clear", function () {
    PENDING_MARKED.clear();
    repaintPendingMarked();
  });

  // --------------------------
  // Focus range for hard_lock reservations
  // --------------------------

  function clearFocusRange() {
    qsa("#calendar .day.focus-range").forEach((el) => {
      el.classList.remove("focus-range");
    });
  }

  function highlightFocusRange(fromIso, toIso) {
    clearFocusRange();
    if (!fromIso || !toIso) return;

    const start = new Date(fromIso + "T00:00:00");
    const end = new Date(toIso + "T00:00:00");
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;

    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
      const iso = formatISO(d);
      const cell = qs(`.day[data-date="${iso}"]`);
      if (cell) {
        cell.classList.add("focus-range");
      }
    }
  }

  function expandHardLockRangeFrom(iso) {
    if (!iso) return null;

    let from = iso;
    let to = iso;

    // walk backwards
    while (true) {
      const prev = addDaysISO(from, -1);
      const cell = qs(`.day[data-date="${prev}"]`);
      if (!cell || cell.dataset.lockKind !== "hard_lock") break;
      from = prev;
    }

    // walk forwards
    while (true) {
      const next = addDaysISO(to, 1);
      const cell = qs(`.day[data-date="${next}"]`);
      if (!cell || cell.dataset.lockKind !== "hard_lock") break;
      to = next;
    }

    return { from, to };
  }

  // --------------------------
  // Apply calendar layers
  // --------------------------

  async function applyCalendarLayers(unit) {
    if (!unit) return;
    const days = qsa("#calendar .day");
    if (!days.length) return;

    // 1) Clear previous classes / data / prices / DOTs / offer frame
    days.forEach((el) => {
      el.classList.remove("reserved", "blocked", "busy", "hardlock");
      el.classList.remove("has-dayuse", "has-special-offer");
      el.classList.remove("pending-marked");

      delete el.dataset.occStatus;
      delete el.dataset.localBlock;
      delete el.dataset.lockKind;
      delete el.dataset.statusLabel;

      const priceEl = el.querySelector(".price");
      if (priceEl) priceEl.textContent = "";

      // odstrani stare pending / day-use DOT-e
      qsa(".dot-pending, .dot-dayuse", el).forEach((dot) => dot.remove());

      // počisti morebitni tooltip od prejšnjih offers
      if (el.title) el.title = "";
    });

    let occMap = {};
    let occMetaMap = {};
    let localMap = {};
    let pricesMap = {};
    let pendingMap = {};
    let dayUseMap = {};

    try {
      pricesMap = await loadPricesMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: prices failed", e);
    }

    try {
      occMap = await loadOccupancyMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: occupancy failed", e);
    }

    try {
    occMetaMap = await loadOccupancyMetaMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: occupancy failed", e);
    }


    try {
      localMap = await loadLocalAdminBlocksMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: local bookings failed", e);
    }

    try {
      pendingMap = await loadPendingMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: pendingMap failed", e);
    }

    try {
      dayUseMap = await loadDayUseMap(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: dayUseMap failed", e);
    }

    // special_offers: naloži in normaliziraj za to enoto
    try {
      await loadSpecialOffersForUnitAdmin(unit);
    } catch (e) {
      console.warn("[admin_calendar] applyLayers: special_offers failed", e);
    }

    // day_use prikaz: samo če je omogočen in v dovoljenem časovnem oknu
    if (!DAY_USE_ENABLED) {
      dayUseMap = {};
    } else if (dayUseMap && typeof dayUseMap === "object") {
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const maxDays = Number.isFinite(DAY_USE_MAX_DAYS_AHEAD)
        ? Math.max(0, DAY_USE_MAX_DAYS_AHEAD)
        : 0;
      let maxDate = null;
      if (maxDays > 0) {
        maxDate = new Date(today);
        maxDate.setDate(maxDate.getDate() + maxDays);
      }

      const filtered = {};
      for (const d in dayUseMap) {
        if (!Object.prototype.hasOwnProperty.call(dayUseMap, d)) continue;
        const dt = new Date(d + "T00:00:00");
        if (Number.isNaN(dt.getTime())) continue;

        // samo od danes naprej
        if (dt < today) continue;
        // in največ max_days_ahead (če je nastavljen)
        if (maxDate && dt > maxDate) continue;

        filtered[d] = dayUseMap[d];
      }
      dayUseMap = filtered;
    }

    // 4) Apply prices + occupancy + local admin blocks + offers to each day
    days.forEach((el) => {
      const d = el.dataset.date;
      if (!d) return;

      // prices (številka v vogalu; prikaz/skrivanje ureja CSS preko body.layer-prices-on)
      const priceEl = el.querySelector(".price");
      if (priceEl && Object.prototype.hasOwnProperty.call(pricesMap, d)) {
        const value = pricesMap[d];
        if (value !== null && typeof value !== "undefined") {
          priceEl.textContent = String(value);
        }
      }

      let lockKind = "";
      let statusLabel = "";

      // 1) Occupancy (ICS + reservations + auto blocks)
      const occ = occMap[d];
      if (occ) {
        const s = String(occ);
        el.dataset.occStatus = s;

        if (s === "reserved" || s === "confirmed" || s === "booked") {
          el.classList.add("reserved");
          lockKind = "hard_lock";
          statusLabel = "Reservation (hard-lock)";
       // NEW: meta za info panel (iz occupancy_merged)
	const meta = occMetaMap[d];
	if (meta) {
	  el.dataset.occStatus   = meta.status || s;
	  el.dataset.occSource   = meta.source || "";
	  el.dataset.occPlatform = meta.platform || "";
	  el.dataset.occLock     = meta.lock || "";
	  el.dataset.occId       = meta.id || "";
	  el.dataset.occSummary  = meta.summary || "";
	}

        } else if (
          s === "blocked" ||
          s === "cleaning" ||
          s === "maintenance"
        ) {
          el.classList.add("blocked");
          lockKind = "soft_block_auto";
          statusLabel = s + " (soft-lock / auto)";
        } else {
          el.classList.add("busy");
          lockKind = "busy";
          statusLabel = s;
        }
      }

      // 2) Local admin blocks (soft-lock, OVERLAY on top of occupancy)
      if (localMap[d]) {
        el.dataset.localBlock = "admin_block";

        // Local overlay NIKOLI ne sme degradirati hard_lock (ICS/reservation).
        // Če je hard_lock → ne spreminjaj lockKind/statusLabel in ne spreminjaj vizualnega stanja.
        const isHardHere = (lockKind === "hard_lock") || (el.dataset.lockKind === "hard_lock");

        el.classList.add("has-local-block");

        if (!isHardHere) {
          // wine-red (soft admin)
          el.classList.add("hardlock");
          lockKind = "soft_admin_block";
          statusLabel = "Admin block (soft-lock)";
        } else {
          // hard ostane hard tudi vizualno (brez wine-red obrobe)
          // (marker class je opcijski; če nima CSS-ja, ne vpliva na izgled)
          el.classList.add("has-local-block-on-hard");
        }
      } else {
        // Če ta dan ni več lokalno blokiran, počistimo oznake sloja
        if (el.dataset.localBlock === "admin_block") {
          delete el.dataset.localBlock;
        }
        el.classList.remove("hardlock", "has-local-block");
      }


	if (lockKind) {
	  el.dataset.lockKind = lockKind;
	}


      if (statusLabel) {
        el.dataset.statusLabel = statusLabel;
      }

      // 3) DAY-USE modre pike (če je v dayUseMap)
      if (dayUseMap && dayUseMap[d]) {
        el.classList.add("has-dayuse");
        const dot = document.createElement("div");
        dot.className = "dot-dayuse";
        el.appendChild(dot);
      }

      // 4) SPECIAL OFFERS – zelen okvir (neodvisno od zasedenosti)
      if (hasSpecialOfferOnAdmin(unit, d)) {
        el.classList.add("has-special-offer");
        const tip = buildSpecialOfferTooltipAdmin(unit, d);
        if (tip) {
          // ne povozimo obstoječega title, če bi ga kdaj dodali drugje
          el.title = el.title ? el.title + " • " + tip : tip;
        }
      }
    });

    // 5) Pending DOTs (yellow, bottom-right)
    if (pendingMap && typeof pendingMap === "object") {
      for (const d in pendingMap) {
        if (!Object.prototype.hasOwnProperty.call(pendingMap, d)) continue;
        const cell = qs(`.day[data-date="${d}"]`);
        if (cell) {
          const dot = document.createElement("div");
          dot.className = "dot-pending";
          cell.appendChild(dot);
        }
      }
    }
  await loadMarkedPendingFromJson(CURRENT_UNIT);
  // po vseh slojih ponovno nariši golden frame označenih pending-ov
  repaintPendingMarked();
  }

  // --------------------------
  // Navigation & unit wiring
  // --------------------------

  function wireNavigation() {
    const btnPrev = qs("#cal-btn-prev");
    const btnNext = qs("#cal-btn-next");
    const btnToday = qs("#cal-btn-today");

    if (btnPrev) {
      btnPrev.addEventListener("click", () => {
        baseMonth = addMonths(baseMonth, -1);
        renderCalendar();
      });
    }
    if (btnNext) {
      btnNext.addEventListener("click", () => {
        baseMonth = addMonths(baseMonth, 1);
        renderCalendar();
      });
    }
    if (btnToday) {
      btnToday.addEventListener("click", () => {
        baseMonth = firstDayOfMonth(new Date());
        renderCalendar();
      });
    }
  }

  async function initForCurrentUnit() {
    if (!CURRENT_UNIT) return;
    await loadMonthRenderForUnit(CURRENT_UNIT);
    await loadCleanFlagsForUnit(CURRENT_UNIT);
    await loadBookingRulesForUnit(CURRENT_UNIT);
    baseMonth = firstDayOfMonth(new Date());
    renderCalendar();
  }

  function wireUnitSelect() {
    const sel = qs("#admin-unit-select");
    if (!sel) return;

    sel.addEventListener("change", async () => {
      const v = sel.value || "";
      if (!v) return;

      CURRENT_UNIT = v;
      localStorage.setItem(LS_UNIT_KEY, CURRENT_UNIT);

      window.dispatchEvent(
        new CustomEvent("cm:unitChanged", { detail: { unit: CURRENT_UNIT } })
      );

      await initForCurrentUnit();
    });
  }

  function wireDayClickFocus() {
    const cal = qs("#calendar");
    if (!cal) return;

    cal.addEventListener("click", function (e) {
      const cell = e.target.closest(".day");
      if (!cell || !cell.dataset.date) return;

      const iso = cell.dataset.date;
      const lockKind = cell.dataset.lockKind || "";

      // Če to NI hard_lock → samo ugasni fokus-range in pusti event pri miru
      if (lockKind !== "hard_lock") {
        clearFocusRange();
        return; // range_select_admin.js bo normalno obdelal klik
      }

      // Special behaviour samo za hard_lock (reservations / ICS)
      e.preventDefault();
      e.stopPropagation();

      const range = expandHardLockRangeFrom(iso);
      if (!range) return;

      const nights = nightsBetweenISO(range.from, range.to);

      // Vizualno označi cel reservation blok
      highlightFocusRange(range.from, range.to);

      // Broadcast selection, da calendar_shell posodobi info bar
      document.dispatchEvent(
        new CustomEvent("admin-range-selected", {
          detail: {
            from: range.from,
            to: range.to,
            nights,
            source: "occupancy",
            lockKind: "hard_lock",
            occ: {
              status:   cell.dataset.occStatus   || "",
              source:   cell.dataset.occSource   || "",
              platform: cell.dataset.occPlatform || "",
              lock:     cell.dataset.occLock     || "",
              id:       cell.dataset.occId       || "",
              summary:  cell.dataset.occSummary  || ""
            }
          }
        })
      );
    });
  }
  function handleCalendarDataChanged(ev, kind) {
    const detail = (ev && ev.detail) || {};
    const unit = detail.unit || null;

    // Če event ni za trenutno enoto, ga ignoriramo
    if (unit && CURRENT_UNIT && unit !== CURRENT_UNIT) {
      return;
    }
    if (!CURRENT_UNIT) return;

    applyCalendarLayers(CURRENT_UNIT).catch((e) => {

      console.warn(`[admin_calendar] ${kind}: reload failed`, e);
    });
  }

  // CENE (Set price → prices.json)
  window.addEventListener("prices:changed", (ev) => {
    handleCalendarDataChanged(ev, "prices:changed");
  });

  // BLOKI (Block / Unblock → local_bookings.json)
  window.addEventListener("block:changed", (ev) => {
    handleCalendarDataChanged(ev, "block:changed");
  });

  // RESERVATIONS (Admin reserve → occupancy.json / occupancy_merged.json)
  window.addEventListener("reservation:changed", (ev) => {
    handleCalendarDataChanged(ev, "reservation:changed");
  });

  // --------------------------
  // Init
  // --------------------------

  async function init() {
    console.log("[admin_calendar] init");

    const unit = await loadUnitsIntoSelect();
    if (!unit) {
      console.warn("[admin_calendar] No units to initialize");
      return;
    }

    wireUnitSelect();
    wireNavigation();
    await initForCurrentUnit();

    if (CLEAN_BEFORE_CB) {
      CLEAN_BEFORE_CB.addEventListener("change", () => {
        saveCleanFlag("auto_block.before_arrival", CLEAN_BEFORE_CB.checked);
      });
    }
    if (CLEAN_AFTER_CB) {
      CLEAN_AFTER_CB.addEventListener("change", () => {
        saveCleanFlag("auto_block.after_departure", CLEAN_AFTER_CB.checked);
      });
    }
    if (DAY_USE_CB) {
      DAY_USE_CB.addEventListener("change", () => {
        DAY_USE_ENABLED = DAY_USE_CB.checked;
        saveDayUseEnabled(DAY_USE_ENABLED);
        if (CURRENT_UNIT) {
          // ponovno uporabi sloje, da takoj skrije/prikaže modre pike
          void applyCalendarLayers(CURRENT_UNIT);
        }
      });
    }

    wireDayClickFocus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
