/**
 * CM Free / CM Plus – Channel Manager
 * File: public/reset_pubcal.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

 (function(){
  function cmHardReset() {
    try {
      const keysToNuke = new Set([
        "cm_restore", "cm_restore_payload",
        // dodaj tu še ključe, ki jih uporablja tvoj persist():
        "pubcal_selection", "pubcal_unit", "pubcal_start", "pubcal_end"
      ]);
      for (let i = sessionStorage.length - 1; i >= 0; i--) {
        const k = sessionStorage.key(i);
        if (!k) continue;
        if (k.startsWith("cm_") || k.startsWith("pubcal_") || keysToNuke.has(k)) {
          sessionStorage.removeItem(k);
        }
      }
    } catch(e) {}
  }

  // --- robustna zaznava okoliščin ---
  const usp = new URLSearchParams(location.search);
  const hasQuery = usp.has("unit") || usp.has("from") || usp.has("to") || usp.has("reset");
  const cameFromOffer = document.referrer && document.referrer.indexOf("/offer.php") !== -1;

  let navType = null;
  try {
    const nav = performance.getEntriesByType && performance.getEntriesByType("navigation")[0];
    navType = nav ? nav.type : (performance.navigation && performance.navigation.type);
    // normalize legacy numeric types
    if (typeof navType === "number") {
      navType = ({1:"reload",2:"back_forward"})[navType] || "navigate";
    }
  } catch(e) {}
  const isReload  = navType === "reload";
  const isBackFwd = navType === "back_forward";

  // --- pravila ---
  // 1) Če je ?reset=1 → hard reset + očisti URL.
  if (usp.get("reset") === "1") {
    cmHardReset();
    try { history.replaceState({}, "", location.pathname); } catch(e) {}
    return;
  }

  // 2) Če je OSVEŽITEV (reload) → hard reset + očisti URL (če so query parametri).
  if (isReload) {
    cmHardReset();
    if (hasQuery) {
      // včasih pomaga še enkrat po "ticku"
      try { history.replaceState({}, "", location.pathname); } catch(e) {}
      setTimeout(() => { try { history.replaceState({}, "", location.pathname); } catch(e) {} }, 0);
    }
    return;
  }

  // 3) Pri vračanju iz offer.php (back/forward) NE brišemo ničesar
  //    (tam želimo restore).
  if (isBackFwd && cameFromOffer) {
    return;
  }

  // 4) Če pridemo “na novo” na pubcal.php s parametri unit/from/to (npr. fallback link),
  //    jih lahko kozmetično skrijemo (URL očistimo), vendar NE resetiramo stanja.
  //    (Restore bo še vedno delal preko sessionStorage, če je bil nastavljen.)
  if (hasQuery && !cameFromOffer) {
    try { history.replaceState({}, "", location.pathname); } catch(e) {}
  }
})();

