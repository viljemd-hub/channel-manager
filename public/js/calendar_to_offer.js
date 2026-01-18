/**
 * CM Free / CM Plus – Channel Manager
 * File: public/js/calendar_to_offer.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/public/ui/js/calendar_to_offer.js

// Helper: zgradi URL do offer.php
function buildOfferUrl({ unit, from, to, adults = 2, kids06 = 0, kids712 = 0, promo = "", keycards = 0, weekly = 0, babybed = 0 }) {
  const p = new URLSearchParams({
    unit, from, to,
    adults: String(adults),
    kids06: String(kids06),
    kids712: String(kids712),
    promo,
    keycards: String(keycards),
    weekly: String(weekly),
    babybed: String(babybed)
  });
  return `/app/public/offer.php?${p.toString()}`;
}

// (A) VEZAVA NA CUSTOM EVENT iz koledarja
// Ko tvoj koledar potrdi izbor, naj sproži nekaj takega:
// document.dispatchEvent(new CustomEvent('calendar:range:committed', { detail: { unit:'A1', from:'YYYY-MM-DD', to:'YYYY-MM-DD' } }));
document.addEventListener('calendar:range:committed', (ev) => {
  const { unit, from, to } = ev.detail || {};
  if (!unit || !from || !to) return;
  const url = buildOfferUrl({ unit, from, to });
  window.location.href = url;
});

// (B) ALI pa to funkcijo pokličeš direktno iz svojega koledarja
// npr. onMouseUpCommit(...) { goToOffer(CURRENT_UNIT, selFrom, selTo); }
window.goToOffer = function(unit, from, to, opts = {}) {
  if (!unit || !from || !to) return;
  const url = buildOfferUrl({ unit, from, to, ...opts });
  window.location.href = url;
};

