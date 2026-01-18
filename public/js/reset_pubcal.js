/**
 * CM Free / CM Plus – Channel Manager
 * File: public/js/reset_pubcal.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/public/js/reset_pubcal.js
(() => {
  // *** NOVO: če je prisoten carry, NE resetiramo (povratek z offerja z izbranim razponom) ***
  try {
    const url = new URL(location.href);
    if (url.searchParams.has('carry')) {
      // optional: lahko že tu odstraniš carry iz URL-ja, če nočeš da ostane v naslovu:
      // url.searchParams.delete('carry');
      // history.replaceState(null, "", url.pathname + (url.searchParams.toString() ? ("?"+url.searchParams.toString()) : "") + url.hash);
      return; // *** ključni early exit ***
    }
  } catch (e) {}

  const STORAGE_KEY_PREFIXES = [
    "pubcal","pubcal.","pubcal_","calendar.selection","cal.sel",
    "selection","selectedRange","calendar.range","range","cm-info","cmInfo",
    "offer.intent"
  ];
  const STORAGE_KEY_EXACT = [
    "S.start","S.end",
    "pubcal.selection","pubcal.range","selectedRange","calendar.range",
    "cmInfoPos.v3","cmInfoPos.v2","cmInfoPos.v1"
  ];
  const URL_PARAMS_TO_STRIP = ["from","to","start","end","sel","selection"];

  // Tečemo samo na strani koledarja (pubcal.php)
  const isPubcal = /pubcal(\.php)?/i.test(location.pathname);
  if (!isPubcal || window.__PUBCAL_NO_RESET) return;

  const log = (...a)=>console.log("[reset_pubcal]", ...a);

  // 1) Strganje URL parametrov (brez reloada)
  try {
    const url = new URL(location.href);
    let changed = false;
    for (const k of URL_PARAMS_TO_STRIP) {
      if (url.searchParams.has(k)) { url.searchParams.delete(k); changed = true; }
    }
    if (changed) {
      const qs = url.searchParams.toString();
      history.replaceState(null, "", url.pathname + (qs ? ("?"+qs) : "") + url.hash);
      log("stripped params:", URL_PARAMS_TO_STRIP.join(", "));
    }
  } catch(e) { /* ignore */ }

  // 2) Čiščenje localStorage/sessionStorage
  function clearStorage(storage){
    try {
      const toRemove = [];
      for (let i = 0; i < storage.length; i++) {
        const key = storage.key(i);
        if (!key) continue;
        if (STORAGE_KEY_EXACT.includes(key)) { toRemove.push(key); continue; }
        if (STORAGE_KEY_PREFIXES.some(p => key.startsWith(p))) toRemove.push(key);
      }
      toRemove.forEach(k => storage.removeItem(k));
      if (toRemove.length) log("cleared", storage === localStorage ? "localStorage" : "sessionStorage", toRemove);
    } catch(e) { /* ignore */ }
  }
  clearStorage(localStorage);
  clearStorage(sessionStorage);

  // 3) Po DOM ready poskusi reset še na objektih/funkcijah koledarja
  function resetCalendarRuntime(){
    try { if (window.S) { S.start = null; S.end = null; } } catch {}
    const fns = ["repaintSelection","clearSolidFill","hideInfo","persist","updateInfoAndInlineCompare"];
    for (const fn of fns) { try { if (typeof window[fn] === "function") window[fn](false); } catch {} }
    log("runtime selection reset");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", resetCalendarRuntime, { once:true });
  } else {
    resetCalendarRuntime();
  }
})();
