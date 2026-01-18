/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/admin_api.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Admin API client for Channel Manager backend.
 *
 * Responsibilities:
 * - Provides small, focused helper functions for calling /admin/api/*.php
 *   endpoints (GET/POST JSON).
 * - Central place for building request URLs, handling JSON parsing and
 *   basic error reporting.
 * - Keeps all low-level fetch/XHR details out of the UI modules.
 *
 * Depends on:
 * - helpers.js → generic fetch/JSON helpers and small utilities.
 *
 * Used by:
 * - admin_shell.js          → to load initial data for panels.
 * - admin_calendar.js       → to read/write occupancy and pending layers.
 * - manage_reservations.js  → to list, filter and modify reservations.
 * - integrations.js         → to read and save integration (ICS) settings.
 * - admin_info_panel.js     → to perform actions on a selected range/inquiry.
 *
 * Notes:
 * - All API functions should be thin wrappers with clear names, e.g.
 *   `apiFetchOccupancy(unit, year, month)` or
 *   `apiAcceptInquiry(inquiryId, payload)`.
 * - Do not put UI logic in this file.
 */


// /app/admin/ui/js/admin_api.js

// Robustno branje JSON-a (odstrani BOM, whitespace, varno parse)
async function fetchJsonClean(url) {
  const res = await fetch(url, { cache: "no-store" });
  if (!res.ok) return null;
  const txt = await res.text();
  const clean = txt.replace(/^\uFEFF/, "").trim();
  try { return JSON.parse(clean); } catch { return null; }
}

// Pomočnik: razširi [start, end) v dnevno mapo
function expandRangesToMap(ranges, val = "busy") {
  const map = {};
  for (const r of ranges || []) {
    if (!r) continue;
    const s = (r.start || r.from || r.date || r.day);
    const e = (r.end   || r.to);
    const status = (r.status || r.state || val);
    if (!s || !e) continue;
    let d = new Date(s);
    const end = new Date(e);
    while (d < end) {
      const key = d.toISOString().slice(0,10);
      map[key] = status;
      d.setDate(d.getDate()+1);
    }
  }
  return map;
}

// --- PRICES ---
export async function loadPricesMap(unit) {
  const data = await fetchJsonClean(`/app/common/data/json/units/${unit}/prices.json`);
  if (!data) return {};
  if (Array.isArray(data)) {
    const map = {};
    for (const r of data) {
      if (!r) continue;
      const d = r.date ?? r.day ?? r.key;
      const p = r.price ?? r.value ?? r.amount;
      if (d && p != null) map[d] = Number(p);
    }
    return map;
  }
  if (typeof data === "object") return data;
  return {};
}

// --- OCCUPANCY (per-unit) ---
export async function loadOccupancy(unit) {
  const data = await fetchJsonClean(`/app/common/data/json/units/${unit}/occupancy_merged.json`);
  if (!data) return {};
  if (Array.isArray(data)) {
    const hasRange = data.some(r => (r?.end || r?.to));
    if (hasRange) return expandRangesToMap(data);
    const map = {};
    for (const r of data) {
      const d = r?.date || r?.day || r?.key;
      const s = r?.status || r?.value || r?.state || "busy";
      if (d) map[d] = s;
    }
    return map;
  }
  if (typeof data === "object") return data;
  return {};
}

// Ranges (raw) iz occupancy_merged.json – za “klik razširi”
export async function loadOccupancyRanges(unit) {
  const data = await fetchJsonClean(`/app/common/data/json/units/${unit}/occupancy_merged.json`);
  if (!Array.isArray(data)) return [];
  // normaliziramo ključna polja
  return data.map(r => ({
    start: r.start || r.from || r.date || r.day,
    end:   r.end   || r.to   || r.date || r.day, // če je single-day, end == start
    status: (r.status || r.state || "busy"),
    inquiry_id: r.inquiry_id || r.id || null,
    guest: r.guest || r.name || null
  })).filter(r => r.start && r.end);
}

// --- LOCAL BOOKINGS (hard-lock) ---
export async function loadLocalBookings(unit) {
  const data = await fetchJsonClean(`/app/common/data/json/units/${unit}/local_bookings.json`);
  if (!data) return { map:{}, list:[] };
  if (Array.isArray(data)) {
    return { map: expandRangesToMap(data, "hardlock"), list: data };
  }
  return { map: data || {}, list: [] };
}

// --- PENDING (global file, filtriramo po unit) ---
// === Pending ranges for calendar layer (canonical) ===
export async function loadPendingRanges(unit) {
  // poskus 1: skupna datoteka
  try {
    const r = await fetch('/app/common/data/json/units/pending_requests.json', { credentials: 'same-origin' });
    if (r.ok) {
      const data = await r.json();
      let raw = [];
      if (Array.isArray(data)) {
        raw = data.filter(x => !unit || x.unit === unit);
      } else if (data && data[unit] && Array.isArray(data[unit])) {
        raw = data[unit];
      }
      const ranges = raw.map(x => ({
        from: x.from ?? x.start ?? null,
        to:   x.to   ?? x.end   ?? null
      })).filter(x => x.from && x.to);
      if (ranges.length) return ranges;
    }
  } catch(_) { /* ignore → fallback */ }

  // poskus 2: API fallback (array obliko vrne naša listInquiries)
  const items = await listInquiries({ unit, stage: 'pending' }); // <- array
  return items
    .filter(it => (unit ? it.unit === unit : true))
    .map(it => ({ from: it.from, to: it.to }))
    .filter(x => x.from && x.to);
}



// --- SETTINGS ---
export async function loadSiteSettings(unit) {
  const perUnit = await fetchJsonClean(`/app/common/data/json/units/${unit}/site_settings.json`);
  if (perUnit && typeof perUnit === "object") {
    const fee = perUnit.cleaning_fee_eur ?? perUnit.cleaning_fee ?? 0;
    return { cleaning_fee: Number(fee) || 0, raw: perUnit };
  }
  const global = await fetchJsonClean(`/app/common/data/json/units/site_settings.json`);
  if (global && typeof global === "object") {
    const fee = global.cleaning_fee_eur ?? global.cleaning_fee ?? 0;
    return { cleaning_fee: Number(fee) || 0, raw: global };
  }
  return { cleaning_fee: 0, raw: {} };
}

// ---------- Pricing apply ----------
export async function setPrices(unit, from, to, price) {
  // POST na obstoječi endpoint (JSON body)
  const url = `/app/admin/api/pricing/set_prices.php`;
  const body = JSON.stringify({ unit, from, to, price });
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    body
  });
  let data = null;
  try { data = await res.json(); } catch { /* ignore */ }
  return (data && data.ok) ? data : { ok: false, error: "pricing_apply_failed" };
}

// ---------- LOCAL ADMIN BLOCKS (soft blocks) ----------

// ---------- LOCAL ADMIN BLOCKS (soft blocks) ----------
export async function addLocalBlock(unit, from, to) {
  // Admin UI dela z INKLUZIVNIM "to" (1 dan = from == to).
  // Endpoint pa pričakuje [from, to) in zahteva $from < $to.
  let fromISO = from;
  let toISO   = to;

  // Backwards‑compatible trik:
  // - če je from == to  → obravnavamo to kot 1‑dnevni blok
  //   in to pošljemo +1 dan, da dobi [from, toExclusive)
  // - če je from < to   → pustimo pri miru (stari klici ostanejo OK)
  if (fromISO && toISO && fromISO === toISO) {
    const d = new Date(toISO + "T00:00:00");
    d.setDate(d.getDate() + 1);
    toISO = d.toISOString().slice(0, 10);
  }

  const url  = `/app/admin/api/local_block_add.php`;
  const body = JSON.stringify({ unit, from: fromISO, to: toISO });

  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    body
  });

  let data = null;
  try { data = await res.json(); } catch { /* ignore */ }
  return data || { ok: false, error: "local_block_failed" };
}

export async function removeLocalBlock(unit, from, to) {
  // Enako – če dobimo from == to (admin selection),
  // pretvorimo v [from, toExclusive), da se ujema z zapisom v JSON.
  let fromISO = from;
  let toISO   = to;

  if (fromISO && toISO && fromISO === toISO) {
    const d = new Date(toISO + "T00:00:00");
    d.setDate(d.getDate() + 1);
    toISO = d.toISOString().slice(0, 10);
  }

  const url  = `/app/admin/api/local_block_remove.php`;
  const body = JSON.stringify({ unit, from: fromISO, to: toISO });

  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    body
  });

  let data = null;
  try { data = await res.json(); } catch { /* ignore */ }
  return data || { ok: false, error: "local_unblock_failed" };
}

// --- INQUIRIES (minimal) ---
export async function listInquiries(params = {}) {
  // { stage:"pending"|"accepted", unit:"A1"|..., limit?, offset?, order? }
  const qs = new URLSearchParams(params).toString();
  const url = `/app/admin/api/list_inquiries.php${qs ? `?${qs}` : ""}`;
  const data = (typeof window.fetchJsonClean === "function")
    ? await window.fetchJsonClean(url)
    : await (await fetch(url, { credentials: "same-origin" })).json();
  return data && data.ok && Array.isArray(data.items) ? data.items : [];
}


export async function getInquiry(inquiryId, opts = {}) {
  // pravilni parametri: inquiry_id (required), stage (optional)
  const params = { inquiry_id: inquiryId, ...("stage" in opts ? { stage: opts.stage } : {}) };
  const qs = new URLSearchParams(params).toString();
  const url = `/app/admin/api/get_inquiry.php?${qs}`;
  const data = (typeof window.fetchJsonClean === "function")
    ? await window.fetchJsonClean(url)
    : await (await fetch(url, { credentials: "same-origin" })).json();
  return (data && data.ok) ? data : null;
}
