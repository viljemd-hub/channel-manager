/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/admin_info_panel.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Admin calendar info panel logic.
 *
 * Responsibilities:
 * - Display detailed information for the currently selected date range:
 *   existing reservations, pending inquiries, blocks, pricing snippet, etc.
 * - Provide buttons/actions for the admin (accept inquiry, reject, block
 *   range, inspect reservation, open guest details).
 * - React to changes in selection and pending/reservation data.
 *
 * Depends on:
 * - admin_api.js          → to perform actions (accept/reject/block).
 * - admin_calendar.js     → to get context about layers on the selected range.
 * - range_select_admin.js → to know which dates are currently selected.
 *                       
 * - helpers.js            → formatting / DOM helpers.
 *
 * Used by:
 * - admin_shell.js → initInfoPanel / binding to calendar selection events.
 *
 * Notes:
 * - Keep UI logic and backend calls separated: build payload locally, then
 *   call admin_api.js functions.
 */


// /app/admin/ui/js/admin_info_panel.js
// import { addDays, parseISO, formatISO, differenceInCalendarDays } from "./helpers.js";
import { loadLocalBookings, loadOccupancyRanges, loadPendingRanges } from "./admin_api.js";

/** ---------------------------------------------------------------------------
 * Admin Info Panel (V4)
 * - END-exclusive razpon [from, to)
 * - Layer breakdown (pending / occupancy / local hardlock)
 * - Robustna posodobitev DOM (če ni tarč, tiho izpusti)
 * --------------------------------------------------------------------------*/

let CURRENT_UNIT = "A1";

export function setUnit(u){
  CURRENT_UNIT = u || CURRENT_UNIT || "A1";
}

// --- DOM helpers -------------------------------------------------------------
function q(sel){ return document.querySelector(sel); }
function setText(sel, txt){
  const el = q(sel);
  if (el) el.textContent = txt == null ? "—" : String(txt);
}
function setHTML(sel, html){
  const el = q(sel);
  if (el) el.innerHTML = html ?? "—";
}

// --- Date helpers ------------------------------------------------------------
function addDaysISO(iso, n){ const d=new Date(iso); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10); }
function eachDayISO(from, toEx){
  const out = [];
  if (!from || !toEx) return out;
  for (let d = new Date(from); d < new Date(toEx); d.setDate(d.getDate()+1)) {
    out.push(d.toISOString().slice(0,10));
  }
  return out;
}
function nightsCount(from, toEx){
  if (!from || !toEx) return 0;
  return Math.max(0, Math.round((new Date(toEx) - new Date(from))/(1000*60*60*24)));
}

// --- Hit util za test pripadnosti dneva v segmentu --------------------------
function inSeg(day, seg){ return day >= seg.start && day < seg.end; }

// --- Render breakdown --------------------------------------------------------
function renderBreakdown(days, occRanges, localList, pendRanges){
  let occDays=0, localDays=0, pendDays=0;
  const conflicts = [];

  for (const d of days){
    const isLocal = localList.some(seg => inSeg(d, seg));
    const isOcc   = occRanges.some(seg => inSeg(d, seg));
    const isPend  = pendRanges.some(seg => inSeg(d, seg));

    if (isLocal) localDays++;
    if (isOcc)   occDays++;
    if (isPend)  pendDays++;

    // konfliktna pravila (primer: karkoli != free je konflikt za prosto prodajo)
    const kinds = [];
    if (isLocal) kinds.push("hardlock");
    if (isOcc)   kinds.push("occupancy");
    if (isPend)  kinds.push("pending");
    if (kinds.length > 1) {
      conflicts.push({ date: d, kinds });
    }
  }

  return { occDays, localDays, pendDays, conflicts };
}

function htmlList(arr, empty="—"){
  if (!arr || !arr.length) return empty;
  return `<ul class="ip-list">${arr.map(x=>`<li>${x}</li>`).join("")}</ul>`;
}

// --- Public API --------------------------------------------------------------
export async function fillInfo(from, toEx){
  setText("#infoUnit", CURRENT_UNIT);

  // prazen/nerelevanten izbor
  if (!from || !toEx || new Date(from) >= new Date(toEx)) {
    setText("#infoRange", "—");
    setText("#infoNights", "0");
    setHTML("#infoBreakdown", "—");
    const pre = q("#infoJson");
    if (pre) pre.textContent = "";
    return;
  }

  const nights = nightsCount(from, toEx);
  setText("#infoRange", `${from} → ${toEx}`);
  setText("#infoNights", String(nights));

  // naloži sloje za izbor enote
  const [occRanges, local, pendRanges] = await Promise.all([
    loadOccupancyRanges(CURRENT_UNIT),  // [{start,end}, ...] END-exclusive
    loadLocalBookings(CURRENT_UNIT),    // { list:[{start,end}, ...], map:{...} }
    loadPendingRanges(CURRENT_UNIT)     // [{start,end}, ...]
  ]);

  const days = eachDayISO(from, toEx);
  const { occDays, localDays, pendDays, conflicts } =
    renderBreakdown(days,
      Array.isArray(occRanges)?occRanges:[],
      Array.isArray(local?.list)?local.list:[],
      Array.isArray(pendRanges)?pendRanges:[]);

  const rows = [
    `<div class="ip-row"><span>Pending:</span><b>${pendDays}</b> dan(i)</div>`,
    `<div class="ip-row"><span>Occupancy:</span><b>${occDays}</b> dan(i)</div>`,
    `<div class="ip-row"><span>Hard-lock (local):</span><b>${localDays}</b> dan(i)</div>`
  ];

  if (conflicts.length) {
    const items = conflicts.slice(0, 20).map(c => `${c.date} · ${c.kinds.join(" + ")}`);
    rows.push(`<div class="ip-row ip-warn"><span>Konflikti:</span>${htmlList(items)}</div>`);
    if (conflicts.length > 20) rows.push(`<div class="ip-row ip-warn">… in še ${conflicts.length - 20}.</div>`);
  } else {
    rows.push(`<div class="ip-row ip-ok">Ni konfliktov v izbranem razponu.</div>`);
  }

  setHTML("#infoBreakdown", rows.join(""));

  // Dev izpis (če je prisoten)
  const pre = q("#infoJson");
  if (pre) {
    const dbg = {
      unit: CURRENT_UNIT,
      from, to: toEx, nights,
      days,
      stats: { pendDays, occDays, localDays, conflicts: conflicts.length }
    };
    pre.textContent = JSON.stringify(dbg, null, 2);
  }
}

export function wireButtons(){
  // Tu lahko navežemo gumbe, če so prisotni (varno: tiho preskoči, če ne obstajajo)
  // Primeri ID-jev (če jih dodaš v HTML):
  // #btnBlockRange, #btnUnblockRange, #btnConfirmPending, #btnRejectPending, ...
  const noop = (e)=>e && e.preventDefault();
  const byId = (id, fn)=>{ const btn=q(id); if(btn && !btn.__wired){ btn.__wired=true; btn.addEventListener("click", fn||noop); } };

  byId("#btnBlockRange");
  byId("#btnUnblockRange");
  byId("#btnConfirmPending");
  byId("#btnRejectPending");
}

// --- styling hook (opcijsko, če želiš osnovni videz) -------------------------
(function injectBasicStyles(){
  if (document.getElementById("ip-basic-styles")) return;
  const css = `
    .ip-row { display:flex; gap:.5rem; align-items:baseline; margin:.25rem 0; }
    .ip-row > span { opacity:.7; min-width:9rem; display:inline-block; }
    .ip-warn { color:#a33; }
    .ip-ok { color:#2a6; }
    .ip-list { margin:.25rem 0 .25rem 1.2rem; }
  `.trim();
  const style = document.createElement("style");
  style.id = "ip-basic-styles";
  style.textContent = css;
  document.head.appendChild(style);
})();
