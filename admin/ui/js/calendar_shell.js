/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/calendar_shell.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/calendar_shell.js
// "Možgani" admin koledarja – veže unit, selection, mode in gumbe v command baru

(function () {
  console.log("[calendar_shell] loaded");

  let currentUnit = null;
  let currentMode = "block"; // 'block' | 'price' | 'offer' | 'admin'
  let currentSelection = null; // { from, to, nights }

  // --------------------------
  // Helpers
  // --------------------------

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function getUnit() {
    const sel = qs("#admin-unit-select");
    return sel && sel.value ? sel.value : null;
  }

  function getMode() {
    const sel = qs("#cal-mode-select");
    if (!sel) return "block";
    const v = sel.value || "block";
    return v;
  }

  function ensureSelectionOrWarn() {
    if (!currentSelection) {
      console.warn("[calendar_shell] No selection – action ignored");
      alert("Najprej izberi razpon datumov v koledarju.");
      return false;
    }
    return true;
  }

  function requireUnitOrWarn() {
    currentUnit = getUnit();
    if (!currentUnit) {
      alert("Najprej izberi enoto (unit) v zgornjem levem kotu.");
      return false;
    }
    return true;
  }

  // ---- Set price (popravljena verzija) ----
   // ---- Set price (popravljena verzija + min_nights) ----
  async function handleSetPrice() {
    if (!ensureSelectionOrWarn()) return;
    if (!requireUnitOrWarn()) return;

    if (!currentSelection || !currentSelection.from || !currentSelection.to) {
      alert("Izbira razpona ni veljavna.");
      return;
    }

    const { from, to } = currentSelection;

    // Prompt za ceno (per-night)
    const raw = prompt(
      `Vnesi ceno na noč za razpon ${from} → ${to} (EUR):`,
      ""
    );
    if (raw == null) {
      // cancel
      return;
    }

    const normalized = String(raw).trim().replace(",", ".");
    const price = Number(normalized);
    if (!Number.isFinite(price) || price <= 0) {
      alert("Vnešena cena ni veljavno število.");
      return;
    }

    const unit = currentUnit || getUnit();
    if (!unit) {
      alert("Najprej izberi enoto (unit).");
      return;
    }

    const rangeEx = toExclusiveRange(currentSelection);
    if (!rangeEx) {
      alert("Razpon datumov ni veljaven.");
      return;
    }

    // --- MIN NIGHTS from header (cal-min-nights) – admin override allowed ---
    const minInput = document.getElementById("cal-min-nights");
    let runtimeMin = minInput ? parseInt(minInput.value, 10) : 0;
    if (!Number.isFinite(runtimeMin) || runtimeMin < 0) {
      runtimeMin = 0;
    }

    // Number of nights in selection (inclusive)
    const nights = nightsBetween(currentSelection.from, currentSelection.to);

    // For admin "Set price" we do NOT block shorter ranges based on min_nights.
    // Min nights is enforced only in the public offer flow, not in internal pricing.
    if (runtimeMin && nights < runtimeMin) {
      console.log(
        `[calendar_shell] handleSetPrice: selection shorter than min_nights `
        + `(${nights} < ${runtimeMin}) – admin override allowed`
      );
    }


    const payload = {
      unit,
      from: rangeEx.from,
      to: rangeEx.toEx, // END-exclusive za API
      price
    };

    try {
      const res = await fetch("/app/admin/api/pricing/set_prices.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
      });

      let json = null;
      try {
        json = await res.json();
      } catch (_) {
        // ignore JSON parse error, obravnavamo kot generic napako
      }

      if (!res.ok || (json && json.ok === false)) {
        const msg =
          (json && json.error) ||
          res.statusText ||
          "Neznana napaka pri shranjevanju cen.";
        throw new Error(msg);
      }

      console.log(
        "[calendar_shell] set_prices OK",
        unit,
        rangeEx.from,
        rangeEx.toEx,
        price
      );

      // obvestimo admin_calendar.js, da naj osveži layer
      window.dispatchEvent(
        new CustomEvent("prices:changed", {
          detail: {
            unit,
            from: rangeEx.from,
            to: rangeEx.toEx,
            price
          }
        })
      );
    } catch (err) {
      console.error("[calendar_shell] set_prices error", err);
      alert("Napaka pri shranjevanju cen: " + err.message);
    }
  }

  function logAction(actionType, extra) {
    const payload = {
      action: actionType,
      unit: currentUnit,
      mode: currentMode,
      selection: currentSelection,
      extra: extra || null
    };
    console.log("[calendar_shell] ACTION", payload);
  }

  function nightsBetween(from, to) {
    if (!from || !to) return 0;
    const a = new Date(from + "T00:00:00");
    const b = new Date(to + "T00:00:00");
    const diff = Math.round((b - a) / 86400000); // v dneh
    return diff >= 0 ? diff + 1 : 0; // inclusive
  }

  function addDaysISO(iso, n) {
    if (!iso) return iso;
    const d = new Date(iso + "T00:00:00");
    if (Number.isNaN(d.getTime())) {
      console.warn("[calendar_shell] addDaysISO: invalid date input:", iso);
      return iso;
    }
    d.setDate(d.getDate() + n);

    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  // selection: {from,to(inclusive),nights} → [from,toEx) za API
  function toExclusiveRange(sel) {
    if (!sel || !sel.from || !sel.to) return null;
    const from = sel.from;
    const toEx = addDaysISO(sel.to, 1); // END-exclusive
    const nights =
      sel.nights != null ? sel.nights : nightsBetween(sel.from, sel.to);
    return { from, toEx, nights };
  }

// Poskusimo poiskati rezervacijo za trenutni selection range
async function fetchReservationInfoForSelection() {
  if (!currentSelection || !currentSelection.from || !currentSelection.to) {
    return null;
  }

  const unit = getUnit() || currentUnit;
  if (!unit) return null;

  const rangeEx = toExclusiveRange(currentSelection);
  if (!rangeEx) return null;

  const params = new URLSearchParams({
    unit,
    from: rangeEx.from,
    to: rangeEx.toEx
  });

  try {
    const res = await fetch(
      `/app/admin/api/find_reservation_by_range.php?${params.toString()}`,
      {
        credentials: "same-origin"
      }
    );

    if (!res.ok) {
      console.warn("[calendar_shell] find_reservation_by_range HTTP", res.status);
      return null;
    }

    const json = await res.json().catch(() => null);
    if (!json) return null;

    // poskusimo normalizirati format
    const data = json.reservation || json.data || json;

    if (!data) return null;

    const id =
      data.id ||
      data.reservation_id ||
      data.resId ||
      data.code ||
      null;

    // Guest normalization (optional)
    let guestName = null;
    let guestPhone = null;
    let guestEmail = null;

    const g = data.guest ?? null;
    if (g) {
      if (typeof g === "string") {
        guestName = g;
      } else if (typeof g === "object") {
        guestName = g.name || g.full_name || g.fullName || null;
        guestPhone = g.phone || g.tel || null;
        guestEmail = g.email || null;
      }
    }
    if (!guestName) {
      guestName = data.guest_name || data.guestName || data.name || data.customer_name || null;
    }

    return {
      id,
      from: data.from || rangeEx.from,
      to: data.to || currentSelection.to,
      nights: data.nights || rangeEx.nights,
      guestName,
      guestPhone,
      guestEmail,
      type: data.type || "reservation"
    };
  } catch (err) {
    console.warn("[calendar_shell] fetchReservationInfoForSelection error", err);
    return null;
  }
}


  function updateSelectionLabel() {
    const labelEl = qs("#cal-selection-label");
    const metaEl = qs("#cal-selection-meta");

    if (!labelEl || !metaEl) return;

    if (!currentSelection) {
      labelEl.textContent = "No selection";
      metaEl.textContent = "";
      return;
    }

    const { from, to, nights } = currentSelection;
    if (!from || !to) {
      labelEl.textContent = "No selection";
      metaEl.textContent = "";
      return;
    }

    // "to" here is the last selected NIGHT (inclusive) - core CM logic
    // counts nights, not the departure day. For display, show the actual
    // departure date (to + 1) alongside the night count so the arrow
    // isn't mistaken for the checkout date itself.
    const n = typeof nights === "number" ? nights : nightsBetween(from, to);
    const departureDate = addDaysISO(to, 1);
    labelEl.textContent = from + " → " + departureDate + " | " + n + " Noči";
    metaEl.textContent = "";
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(payload || {})
    });

    const text = await res.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch (e) {
      console.error("[calendar_shell] Invalid JSON from", url, text);
    }

    if (!res.ok || !json || json.ok === false) {
      const msg =
        (json && json.error) ||
        res.statusText ||
        "Neznana napaka (" + res.status + ")";
      throw new Error(msg);
    }
    return json;
  }

  function eachDayISO(from, toInclusive) {
    if (!from || !toInclusive) return [];
    const days = [];

    const start = new Date(from + "T00:00:00");
    const end   = new Date(toInclusive + "T00:00:00");

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
      console.warn("[calendar_shell] eachDayISO: invalid input", from, toInclusive);
      return days;
    }

    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, "0");
      const day = String(d.getDate()).padStart(2, "0");
      days.push(`${y}-${m}-${day}`);
    }

    return days;
  }

  // (duplikat, a ga pustiva – tako si ga imel in dela)
  

function markLocalRange(from, toInclusive) {
  eachDayISO(from, toInclusive).forEach((iso) => {
    const cell = document.querySelector(`.day[data-date="${iso}"]`);
    if (!cell) return;

    // vizualno
    cell.classList.add("hardlock");

    // ✅ logično: da gumbi takoj vidijo lock (brez refresh / brez čakanja na applyCalendarLayers)
    cell.dataset.lockKind = "soft_admin_block";
    cell.dataset.statusLabel = "Admin block (soft-lock)";
  });
}

function clearLocalRange(from, toInclusive) {
  eachDayISO(from, toInclusive).forEach((iso) => {
    const cell = document.querySelector(`.day[data-date="${iso}"]`);
    if (!cell) return;

    cell.classList.remove("hardlock");

    // ✅ pobriši samo naš soft_admin_block, ne dotikaj se hard_lock (reservations)
    if (cell.dataset.lockKind === "soft_admin_block") {
      delete cell.dataset.lockKind;
      delete cell.dataset.statusLabel;
    }
  });
}

  function setSelectionMeta(text) {
    const metaEl = qs("#cal-selection-meta");
    if (metaEl) {
      metaEl.textContent = text || "";
    }
  }

  // Deep-link from the calendar's info bar straight to the reservation's
  // detail/tools page, so the admin doesn't have to search by ID there.
  function setOpenReservationLink(id) {
    const linkEl = qs("#cal-open-reservation");
    if (!linkEl) return;

    if (!id) {
      linkEl.style.display = "none";
      linkEl.removeAttribute("href");
      return;
    }

    linkEl.href =
      "/app/admin/manage_reservations.php?id=" + encodeURIComponent(id);
    linkEl.style.display = "";
  }

  function describeAdminBlockSelection(sel) {
    if (!sel || !sel.from || !sel.to) return "";
    const nights = nightsBetween(sel.from, sel.to);
    const nightsLabel = nights === 1 ? "1 night" : `${nights} nights`;
    // Admin block = soft-lock (can be removed freely)
    return `${nightsLabel}, admin block (soft-lock)`;
  }

  function describeSingleDayStatus(iso) {
    if (!iso) return null;
    const cell = document.querySelector(`.day[data-date="${iso}"]`);
    if (!cell) return null;

    const label = cell.dataset.statusLabel || "";
    const lockKind = cell.dataset.lockKind || "";

    if (!label && !lockKind) {
      return "Free / no lock";
    }

    // label je že lep tekst, lockKind je bolj tehničen
    if (label) return label;

    if (lockKind === "soft_admin_block") return "Admin block (soft-lock)";
    if (lockKind === "soft_block_auto") return "Soft block (auto)";
    if (lockKind === "hard_lock") return "Reservation (hard-lock)";
    return lockKind;
  }

  function rangeAllSoftAdmin(fromIso, toIso) {
    if (!fromIso || !toIso) return false;
    const days = eachDayISO(fromIso, toIso);
    if (!days.length) return false;

    for (const d of days) {
      const cell = document.querySelector(`.day[data-date="${d}"]`);
      if (!cell || cell.dataset.lockKind !== "soft_admin_block") {
        return false;
      }
    }
    return true;
  }


function rangeHasAnyLock(fromIso, toIso) {
  if (!fromIso || !toIso) return false;

  const days = eachDayISO(fromIso, toIso);
  if (!days.length) return false;

  for (const d of days) {
    const cell = document.querySelector(`.day[data-date="${d}"]`);
    if (cell && cell.dataset.lockKind) return true; // hard ali soft (rdeče)
  }
  return false;
}

 
 function rangeHasAnyHardLock(fromIso, toIso) {
   if (!fromIso || !toIso) return false;
 
   const days = eachDayISO(fromIso, toIso);
   if (!days.length) return false;
 
   for (const d of days) {
     const cell = document.querySelector(`.day[data-date="${d}"]`);
     if (cell && cell.dataset.lockKind === "hard_lock") return true;
   }
   return false;
 }

 function updateBlockUnblockButtonState() {
   const btnBlock = qs('#cal-btn-block');
   const btnUnblock = qs('#cal-btn-unblock');

   if (!btnBlock && !btnUnblock) return;
 
    if (!currentSelection || !currentSelection.from || !currentSelection.to) {
     if (btnBlock) {
       btnBlock.disabled = true;
       btnBlock.title = 'Najprej izberi razpon datumov.';
     }
     if (btnUnblock) {
       btnUnblock.disabled = true;
      btnUnblock.title = 'Najprej izberi razpon datumov.';
     }
     return;
   }
 
   const from = currentSelection.from;
   const to   = currentSelection.to;
 
   // ✅ HARD-LOCK (ICS / accepted reservation): never allow block/unblock over it
   const anyHard =
     (currentSelection.occ && currentSelection.occ.lock === 'hard') ||
     rangeHasAnyHardLock(from, to);
 
   if (anyHard) {
     if (btnBlock) {
       btnBlock.disabled = true;
       btnBlock.title = 'Hard-lock (ICS / rezervacija) – blokiranje ni dovoljeno.';
     }
     if (btnUnblock) {
       btnUnblock.disabled = true;
       btnUnblock.title = 'Hard-lock (ICS / rezervacija) – unblock ni dovoljen.';
     }
     return;
   }
 
   // Soft/admin locks: block only if selection is not already locked; unblock only if range is purely soft_admin_block
   const anyLock = rangeHasAnyLock(from, to);
   const allSoftAdmin = rangeAllSoftAdmin(from, to);

   if (btnBlock) {
     btnBlock.disabled = anyLock;
     btnBlock.title = anyLock
       ? 'Izbrani razpon je že blokiran (soft). Najprej odstrani blokado.'
       : 'Block (soft admin)';
   }

    if (btnUnblock) {
      btnUnblock.disabled = !allSoftAdmin;
      btnUnblock.title = allSoftAdmin
       ? 'Unblock (odstrani soft admin block)'
       : 'Unblock je mogoč samo za razpon, ki je v celoti soft admin blokada.';
   }
}



function updateAdminReserveButtonState() {
  const btn = qs('#cal-btn-admin-reserve');
  if (!btn) return;

  if (!currentSelection || !currentSelection.from || !currentSelection.to) {
    btn.disabled = true;
    btn.title = 'Najprej izberi razpon datumov.';
    return;
  }

  const blocked = rangeHasAnyLock(currentSelection.from, currentSelection.to);
  btn.disabled = blocked;
  btn.title = blocked
    ? 'Izbrani razpon je že blokiran (hard / soft). Najprej odstrani blokado.'
    : 'Admin reserve';

}


function rangeAllHardLock(fromIso, toIso) {
  if (!fromIso || !toIso) return false;
  const days = eachDayISO(fromIso, toIso);
  if (!days.length) return false;

  for (const d of days) {
    const cell = document.querySelector(`.day[data-date="${d}"]`);
    if (!cell || cell.dataset.lockKind !== "hard_lock") {
      return false;
    }
  }
  return true;
}



  // --------------------------
  // Event wiring
  // --------------------------

  function wireUnitSelect() {
    const sel = qs("#admin-unit-select");
    if (!sel) return;

    currentUnit = getUnit();
    console.log("[calendar_shell] initial unit:", currentUnit);

    sel.addEventListener("change", function () {
      currentUnit = getUnit();
      console.log("[calendar_shell] unit changed →", currentUnit);

      // reset selection, ker mreža datumov ni več ista
      currentSelection = null;
      updateSelectionLabel();
      updateAdminReserveButtonState();
      updateBlockUnblockButtonState();


      // počisti selection v range_select, če je na voljo
      if (window.AdminRangeSelect && typeof window.AdminRangeSelect.clearSelection === "function") {
        window.AdminRangeSelect.clearSelection(true); // tiho
      }

      // broadcast za druge module (če jih boš dodal kasneje)
      document.dispatchEvent(
        new CustomEvent("cal-unit-changed", { detail: { unit: currentUnit } })
      );
    });
  }

  function wireModeSelect() {
    const sel = qs("#cal-mode-select");
    if (!sel) return;

    currentMode = getMode();

    sel.addEventListener("change", function () {
      currentMode = getMode();
      logAction("mode_changed");
    });
  }

  function wireSelectionEvents() {
    // iz range_select_admin.js: inclusive {from,to,nights}
	document.addEventListener("admin-range-selected", function (e) {
	  const detail = e.detail || {};

	  if (!detail.from || !detail.to) {
	    currentSelection = null;
	  } else {
	    currentSelection = {
	      from: detail.from,
	      to: detail.to,
	      nights:
	        typeof detail.nights === "number"
	          ? detail.nights
	          : nightsBetween(detail.from, detail.to),

	      // ✅ KLJUČNO: ohrani occupancy meta (ICS / hard-lock)
	      occ: detail.occ || null
	    };
	  }

  logAction("selection_changed", currentSelection?.occ || null);
  updateSelectionLabel();
  updateSelectionMeta();
  updateAdminReserveButtonState();
  updateBlockUnblockButtonState();

});


    document.addEventListener("admin-range-cleared", function () {
      currentSelection = null;
      logAction("selection_cleared");
      updateSelectionLabel();
      setSelectionMeta("");
      updateAdminReserveButtonState();
      updateBlockUnblockButtonState();
      

    });
  }

  async function handleBlockRange() {
    if (!requireUnitOrWarn()) return;
    if (!ensureSelectionOrWarn()) return;

    const rangeEx = toExclusiveRange(currentSelection);
    if (!rangeEx) return;

    const txt =
      "Blokiram enoto " +
      currentUnit +
      ":\n" +
      currentSelection.from +
      " → " +
      currentSelection.to +
      " (" +
      rangeEx.nights +
      " noči)?";

    if (!window.confirm(txt)) {
      logAction("block_cancelled");
      return;
    }

    logAction("block_request", {
      unit: currentUnit,
      from: rangeEx.from,
      toEx: rangeEx.toEx
    });

    try {
      const res = await postJson("/app/admin/api/local_block_add.php", {
        unit: currentUnit,
        from: rangeEx.from,
        to: rangeEx.toEx
      });
      console.log("[calendar_shell] block_range result:", res);
      logAction("block_success", res);

      // Visually mark the selected range as local admin block (wine red)
      if (currentSelection && currentSelection.from && currentSelection.to) {
        markLocalRange(currentSelection.from, currentSelection.to);
        setSelectionMeta(describeAdminBlockSelection(currentSelection));
      }

      // event za druge module (locks_loader, info panel, ...)
      document.dispatchEvent(
        new CustomEvent("block:changed", {
          detail: {
            unit: currentUnit,
            from: rangeEx.from,
            toEx: rangeEx.toEx,
            nights: rangeEx.nights,
            type: "admin_block",
            action: "add"
          }
        })
      );

      alert("Razpon je blokiran (local hard-lock zapis v local_bookings.json).");
      // full reload = always consistent layers/colors
      const btnB = qs("#cal-btn-block");
      const btnU = qs("#cal-btn-unblock");
      if (btnB) btnB.disabled = true;
      if (btnU) btnU.disabled = true;
      window.location.reload();

    } catch (err) {
      console.error("[calendar_shell] block_range failed:", err);
      logAction("block_error", { message: err.message });
      alert("Napaka pri blokiranju: " + err.message);
    }
  }


async function handleUnblockRange() {
    if (!requireUnitOrWarn()) return;
    if (!ensureSelectionOrWarn()) return;

    const sel = currentSelection;
    if (!sel || !sel.from || !sel.to) {
        alert("Izbira razpona ni veljavna.");
        return;
    }

    const { from, to } = sel;

    //
    // ---------------------------------------------
    // 1) PRE-CHECK: ALI JE RAZPON HARD-LOCK (REZERVACIJA)?
    // ---------------------------------------------
    //
    if (rangeAllHardLock(from, to)) {
        // poskusi pridobiti ID rezervacije
        const info = await fetchReservationInfoForSelection();

        if (info && info.id) {
            alert(
                "Ta razpon je del sprejete/potrjene rezervacije.\n" +
                "ID rezervacije: " + info.id + "\n\n" +
                "Takšnih terminov ni mogoče 'unblock-at' v koledarju.\n" +
                "Uredi v meniju: Manage Reservations."
            );
        } else {
            alert(
                "Ta razpon je del rezervacije (hard-lock).\n" +
                "Takšnih terminov ni mogoče 'unblock-at' v koledarju.\n" +
                "Uredi v meniju: Manage Reservations."
            );
        }
        return; // 💥 blokiramo nadaljevanje
    }

    //
    // ---------------------------------------------
    // 2) PRE-CHECK: ALI JE CEL RAZPON ADMIN SOFT BLOCK?
    // ---------------------------------------------
    //
    const allSoft = rangeAllSoftAdmin(from, to);

    if (!allSoft) {
        // MEŠAN RAZPON → ni čist admin block
        const info = await fetchReservationInfoForSelection();

        if (info && info.id) {
            // obstaja rezervacija, samo ni celoten razpon hard-lock
            alert(
                "V izbranem razponu se nahaja rezervacija.\n" +
                "ID rezervacije: " + info.id + "\n\n" +
                "Admin 'Unblock' odstrani samo lokalne admin blocke.\n" +
                "Rezervacij ni mogoče odstranjevati s tem gumbom."
            );
        } else {
            alert(
                "V izbranem razponu ni čistih admin blokad.\n" +
                "Gumb 'Unblock' odstrani le admin block (vino-rdeči okvir).\n" +
                "Rezervacije ali sistemske blokade se urejajo v Manage Reservations."
            );
        }

        return; // 💥 nič ne brišemo
    }

    //
    // ---------------------------------------------
    // 3) NORMALNI ADMIN UNBLOCK FLOW (SOFT ONLY)
    // ---------------------------------------------
    //
    const rangeEx = toExclusiveRange(sel);
    if (!rangeEx) return;

    const txt =
        "Odstranim admin blocke za enoto " +
        currentUnit +
        " v razponu:\n" +
        sel.from + " → " + sel.to + "?";

    if (!window.confirm(txt)) {
        logAction("unblock_cancelled");
        return;
    }

    logAction("unblock_request", {
        unit: currentUnit,
        from: rangeEx.from,
        toEx: rangeEx.toEx
    });

    try {
        const res = await postJson("/app/admin/api/local_block_remove.php", {
            unit: currentUnit,
            from: rangeEx.from,
            to: rangeEx.toEx
        });
        console.log("[calendar_shell] unblock_range result:", res);
        logAction("unblock_success", res);

        // event za druge module
        document.dispatchEvent(
            new CustomEvent("block:changed", {
                detail: {
                    unit: currentUnit,
                    from: rangeEx.from,
                    toEx: rangeEx.toEx,
                    nights: rangeEx.nights,
                    type: "admin_block",
                    action: "remove"
                }
            })
        );

                alert("Admin blocki v razponu so odstranjeni.");
        // full reload = always consistent layers/colors
        const btnB = qs("#cal-btn-block");
        const btnU = qs("#cal-btn-unblock");
        if (btnB) btnB.disabled = true;
        if (btnU) btnU.disabled = true;
        window.location.reload();

    } catch (err) {
        console.error("[calendar_shell] unblock_range failed:", err);
        logAction("unblock_error", { message: err.message });
        alert("Napaka pri odstranjevanju blockov: " + err.message);
    }
}



  // --------------------------
  // Copy prices modal
  // --------------------------

  function addYearsISO(iso, years) {
    if (!iso) return "";
    const d = new Date(iso + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    d.setFullYear(d.getFullYear() + years);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function ensureCopyPricesModal() {
    let modal = document.getElementById("copy-prices-modal");
    if (modal) return modal;

    const style = document.createElement("style");
    style.textContent = `
      #copy-prices-modal {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(0,0,0,.68);
        padding: 18px;
      }
      #copy-prices-modal.open {
        display: flex;
      }
      #copy-prices-modal .cpm-box {
        width: min(880px, 96vw);
        max-height: 92vh;
        overflow: auto;
        border-radius: 18px;
        border: 1px solid rgba(255,255,255,.16);
        background: #101827;
        color: #eef5ff;
        box-shadow: 0 20px 80px rgba(0,0,0,.55);
      }
      #copy-prices-modal .cpm-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid rgba(255,255,255,.12);
        background: rgba(255,255,255,.04);
      }
      #copy-prices-modal .cpm-head h2 {
        margin: 0;
        font-size: 20px;
      }
      #copy-prices-modal .cpm-body {
        padding: 18px;
      }
      #copy-prices-modal .cpm-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }
      #copy-prices-modal label {
        display: block;
        font-size: 13px;
        color: #b8c7dc;
        margin-bottom: 5px;
      }
      #copy-prices-modal input,
      #copy-prices-modal select {
        width: 100%;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,.16);
        background: #0b1220;
        color: #eef5ff;
        padding: 9px 10px;
      }
      #copy-prices-modal .cpm-row {
        margin-bottom: 12px;
      }
      #copy-prices-modal .cpm-muted {
        color: #91a3bb;
        font-size: 13px;
        line-height: 1.45;
      }
      #copy-prices-modal .cpm-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
      }
      #copy-prices-modal button {
        border: 0;
        border-radius: 999px;
        padding: 10px 14px;
        font-weight: 700;
        cursor: pointer;
      }
      #copy-prices-modal button.primary {
        background: #4da3ff;
        color: #06111f;
      }
      #copy-prices-modal button.good {
        background: #2ad17d;
        color: #06111f;
      }
      #copy-prices-modal button.ghost {
        background: rgba(255,255,255,.08);
        color: #eef5ff;
        border: 1px solid rgba(255,255,255,.14);
      }
      #copy-prices-modal button:disabled {
        opacity: .45;
        cursor: not-allowed;
      }
      #copy-prices-modal .cpm-summary {
        margin-top: 14px;
        padding: 12px;
        border-radius: 12px;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.10);
        color: #dcecff;
        font-size: 13px;
        line-height: 1.55;
      }
      #copy-prices-modal table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
        font-size: 12px;
      }
      #copy-prices-modal th,
      #copy-prices-modal td {
        border-bottom: 1px solid rgba(255,255,255,.10);
        padding: 6px 5px;
        text-align: left;
        vertical-align: top;
      }
      #copy-prices-modal th {
        color: #b8c7dc;
        font-weight: 700;
      }
      #copy-prices-modal .cpm-warn {
        color: #ffd166;
      }
      #copy-prices-modal .cpm-ok {
        color: #9ff2c5;
      }
      @media (max-width: 720px) {
        #copy-prices-modal .cpm-grid {
          grid-template-columns: 1fr;
        }
      }
    `;
    document.head.appendChild(style);

    modal = document.createElement("div");
    modal.id = "copy-prices-modal";
    modal.innerHTML = `
      <div class="cpm-box" role="dialog" aria-modal="true" aria-labelledby="cpm-title">
        <div class="cpm-head">
          <h2 id="cpm-title">Copy prices / Kopiraj cene</h2>
          <button type="button" class="ghost" id="cpm-close">Close/Zapri</button>
        </div>
        <div class="cpm-body">
          <div class="cpm-row">
            <div class="cpm-muted" id="cpm-source-label">Source range: —</div>
          </div>

          <div class="cpm-row">
            <label>Copy mode / Način kopiranja</label>
            <div class="cpm-muted">
              <label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;">
                <input type="radio" name="cpm-mode" value="selected" checked>
                Selected range / Izbrano obdobje
              </label>
              <label style="display:inline-flex;align-items:center;gap:6px;">
                <input type="radio" name="cpm-mode" value="whole_year">
                Whole year / Celo leto
              </label>
            </div>
          </div>

          <div class="cpm-grid" id="cpm-year-grid" style="display:none;">
            <div class="cpm-row">
              <label for="cpm-source-year">Source year / Izvorno leto</label>
              <input type="number" id="cpm-source-year" min="2000" max="2100" step="1">
            </div>

            <div class="cpm-row">
              <label for="cpm-target-year">Target year / Ciljno leto</label>
              <input type="number" id="cpm-target-year" min="2000" max="2100" step="1">
            </div>
          </div>

          <div class="cpm-grid">
            <div class="cpm-row" id="cpm-target-from-row">
              <label for="cpm-target-from">Target start date / Začetni ciljni datum</label>
              <input type="date" id="cpm-target-from">
            </div>

            <div class="cpm-row">
              <label for="cpm-percent">Percent adjustment / Popravek v %</label>
              <input type="number" id="cpm-percent" step="0.1" value="0">
              <div class="cpm-muted">Use negative values for lower prices, e.g. -10.</div>
            </div>

            <div class="cpm-row">
              <label for="cpm-round-to">Round to / Zaokroži na</label>
              <select id="cpm-round-to">
                <option value="1">1 €</option>
                <option value="5">5 €</option>
                <option value="10">10 €</option>
              </select>
            </div>

            <div class="cpm-row">
              <label for="cpm-missing-mode">Missing source price / Če source cena manjka</label>
              <select id="cpm-missing-mode">
                <option value="skip">Skip missing days / Preskoči</option>
                <option value="use_previous" selected>Use previous price / Uporabi prejšnjo znano ceno</option>
                <option value="fixed">Use fallback price / Uporabi ročno ceno</option>
              </select>
            </div>

            <div class="cpm-row" id="cpm-fallback-row">
              <label for="cpm-fallback-price">Fallback price / Ročna cena</label>
              <input type="number" id="cpm-fallback-price" step="1" min="0" value="">
              <div class="cpm-muted">Used only when source price is missing and mode is “Use fallback price”.</div>
            </div>
          </div>

          <div class="cpm-row">
            <label>
              <input type="checkbox" id="cpm-overwrite">
              Overwrite existing target prices / Prepiši obstoječe ciljne cene
            </label>
          </div>

          <div class="cpm-actions">
            <button type="button" class="primary" id="cpm-preview">Preview / Predogled</button>
            <button type="button" class="good" id="cpm-apply" disabled>Apply / Uporabi</button>
            <button type="button" class="ghost" id="cpm-cancel">Cancel / Prekliči</button>
          </div>

          <div id="cpm-summary" class="cpm-summary" style="display:none;"></div>
          <div id="cpm-preview-table"></div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector("#cpm-close").addEventListener("click", closeCopyPricesModal);
    modal.querySelector("#cpm-cancel").addEventListener("click", closeCopyPricesModal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeCopyPricesModal();
    });

    modal.querySelector("#cpm-preview").addEventListener("click", () => {
      runCopyPricesRequest(true);
    });

    modal.querySelector("#cpm-apply").addEventListener("click", () => {
      runCopyPricesRequest(false);
    });

    ["#cpm-target-from", "#cpm-percent", "#cpm-round-to", "#cpm-missing-mode", "#cpm-fallback-price", "#cpm-overwrite"].forEach((sel) => {
      const el = modal.querySelector(sel);
      if (!el) return;
      el.addEventListener("input", () => {
        refreshCopyPricesModeUi(modal);
        const apply = modal.querySelector("#cpm-apply");
        if (apply) apply.disabled = true;
      });
      el.addEventListener("change", () => {
        refreshCopyPricesModeUi(modal);
        const apply = modal.querySelector("#cpm-apply");
        if (apply) apply.disabled = true;
      });
    });

    modal.querySelectorAll('input[name="cpm-mode"]').forEach((el) => {
      el.addEventListener("change", () => {
        refreshCopyPricesModeUi(modal);
        const apply = modal.querySelector("#cpm-apply");
        if (apply) apply.disabled = true;
      });
    });

    refreshCopyPricesModeUi(modal);

    return modal;
  }

  function getCopyPricesMode(modal) {
    const checked = modal.querySelector('input[name="cpm-mode"]:checked');
    return checked ? checked.value : "selected";
  }

  function refreshCopyPricesModeUi(modal) {
    if (!modal) return;

    const mode = getCopyPricesMode(modal);
    const yearGrid = modal.querySelector("#cpm-year-grid");
    const targetRow = modal.querySelector("#cpm-target-from-row");
    const sourceLabel = modal.querySelector("#cpm-source-label");

    if (yearGrid) {
      yearGrid.style.display = mode === "whole_year" ? "grid" : "none";
    }

    if (targetRow) {
      targetRow.style.display = mode === "whole_year" ? "none" : "block";
    }

    if (sourceLabel) {
      if (mode === "whole_year") {
        const sy = modal.querySelector("#cpm-source-year")?.value || "";
        const ty = modal.querySelector("#cpm-target-year")?.value || "";
        sourceLabel.textContent = sy && ty
          ? `Whole year: ${sy}-01-01 → ${Number(sy) + 1}-01-01, target ${ty}-01-01, unit ${modal.dataset.unit || currentUnit || ""}`
          : "Whole year mode";
      } else {
        const from = modal.dataset.sourceFrom || "";
        const toIncl = modal.dataset.sourceToInclusive || "";
        const unit = modal.dataset.unit || currentUnit || "";
        sourceLabel.textContent = from && toIncl
          ? `Source: ${from} → ${toIncl}, unit ${unit}`
          : "Source range: —";
      }
    }
  }

  function closeCopyPricesModal() {
    const modal = document.getElementById("copy-prices-modal");
    if (modal) modal.classList.remove("open");
  }

  function openCopyPricesModal() {
    if (!ensureSelectionOrWarn()) return;
    if (!requireUnitOrWarn()) return;

    const rangeEx = toExclusiveRange(currentSelection);
    if (!rangeEx) {
      alert("Izbira razpona ni veljavna.");
      return;
    }

    const modal = ensureCopyPricesModal();
    modal.dataset.sourceFrom = rangeEx.from;
    modal.dataset.sourceTo = rangeEx.toEx;
    modal.dataset.sourceToInclusive = currentSelection.to;
    modal.dataset.unit = currentUnit;

    const sourceYear = Number(String(rangeEx.from).slice(0, 4));
    const sourceYearEl = modal.querySelector("#cpm-source-year");
    const targetYearEl = modal.querySelector("#cpm-target-year");

    if (sourceYearEl && Number.isFinite(sourceYear)) {
      sourceYearEl.value = String(sourceYear);
    }

    if (targetYearEl && Number.isFinite(sourceYear)) {
      targetYearEl.value = String(sourceYear + 1);
    }

    const target = modal.querySelector("#cpm-target-from");
    if (target && !target.value) {
      target.value = addYearsISO(rangeEx.from, 1);
    }

    const selectedRadio = modal.querySelector('input[name="cpm-mode"][value="selected"]');
    if (selectedRadio) selectedRadio.checked = true;

    refreshCopyPricesModeUi(modal);

    const summary = modal.querySelector("#cpm-summary");
    if (summary) {
      summary.style.display = "none";
      summary.textContent = "";
    }

    const table = modal.querySelector("#cpm-preview-table");
    if (table) table.innerHTML = "";

    const apply = modal.querySelector("#cpm-apply");
    if (apply) apply.disabled = true;

    modal.classList.add("open");
  }

  function getCopyPricesPayload(preview) {
    const modal = ensureCopyPricesModal();

    const mode = getCopyPricesMode(modal);
    let sourceFrom = modal.dataset.sourceFrom || "";
    let sourceTo = modal.dataset.sourceTo || "";
    let targetFrom = (modal.querySelector("#cpm-target-from")?.value || "").trim();

    if (mode === "whole_year") {
      const sourceYear = parseInt(modal.querySelector("#cpm-source-year")?.value || "", 10);
      const targetYear = parseInt(modal.querySelector("#cpm-target-year")?.value || "", 10);

      if (!Number.isFinite(sourceYear) || sourceYear < 2000 || sourceYear > 2100) {
        alert("Vnesi veljavno source year / izvorno leto.");
        return null;
      }

      if (!Number.isFinite(targetYear) || targetYear < 2000 || targetYear > 2100) {
        alert("Vnesi veljavno target year / ciljno leto.");
        return null;
      }

      sourceFrom = `${sourceYear}-01-01`;
      sourceTo = `${sourceYear + 1}-01-01`;
      targetFrom = `${targetYear}-01-01`;
    }

    const percent = Number((modal.querySelector("#cpm-percent")?.value || "0").replace(",", "."));
    const roundTo = parseInt(modal.querySelector("#cpm-round-to")?.value || "1", 10);
    const overwrite = !!modal.querySelector("#cpm-overwrite")?.checked;
    const missingMode = modal.querySelector("#cpm-missing-mode")?.value || "skip";
    const fallbackRaw = (modal.querySelector("#cpm-fallback-price")?.value || "").trim();
    const fallbackPrice = fallbackRaw === "" ? null : Number(fallbackRaw.replace(",", "."));

    if (!sourceFrom || !sourceTo) {
      alert("Source range ni veljaven.");
      return null;
    }

    if (!targetFrom) {
      alert("Vnesi target start date / začetni ciljni datum.");
      return null;
    }

    if (!Number.isFinite(percent)) {
      alert("Percent adjustment ni veljavno število.");
      return null;
    }

    if (missingMode === "fixed") {
      if (fallbackPrice === null || !Number.isFinite(fallbackPrice) || fallbackPrice < 0) {
        alert("Vnesi fallback price / ročno ceno za manjkajoče source dni.");
        return null;
      }
    }

    return {
      unit: modal.dataset.unit || currentUnit,
      source_from: sourceFrom,
      source_to: sourceTo,
      target_from: targetFrom,
      percent,
      round_to: roundTo,
      overwrite,
      preview,
      missing_source_mode: missingMode,
      fallback_price: fallbackPrice
    };
  }

  function renderCopyPricesResult(json, preview) {
    const modal = ensureCopyPricesModal();
    const summaryEl = modal.querySelector("#cpm-summary");
    const tableEl = modal.querySelector("#cpm-preview-table");
    const applyBtn = modal.querySelector("#cpm-apply");

    const s = (json && json.summary) || {};
    const items = Array.isArray(json.items) ? json.items : [];

    if (summaryEl) {
      summaryEl.style.display = "block";
      summaryEl.innerHTML =
        `<strong>${preview ? "Preview" : "Applied"}:</strong><br>` +
        `Source days: ${s.source_days || 0}<br>` +
        `Would write: ${s.would_write || 0}<br>` +
        `Copied: ${s.copied || 0}<br>` +
        `Filled from previous: ${s.filled_from_previous || 0}<br>` +
        `Filled from fixed: ${s.filled_from_fixed || 0}<br>` +
        `Skipped missing source: ${s.skipped_missing_source || 0}<br>` +
        `Skipped existing target: ${s.skipped_existing_target || 0}<br>` +
        `Skipped invalid price: ${s.skipped_invalid_price || 0}` +
        (json.backup ? `<br>Backup: <code>${String(json.backup)}</code>` : "");
    }

    if (tableEl) {
      const sample = items.slice(0, 80);
      const rows = sample.map((it) => {
        const cls = String(it.action || "").includes("previous") ? "cpm-warn" : "cpm-ok";
        return `
          <tr>
            <td>${it.source_date || ""}</td>
            <td>${it.target_date || ""}</td>
            <td>${it.source_price ?? ""}</td>
            <td>${it.fallback_price ?? ""}</td>
            <td>${it.target_old_price ?? ""}</td>
            <td>${it.target_new_price ?? ""}</td>
            <td class="${cls}">${it.action || ""}</td>
          </tr>
        `;
      }).join("");

      tableEl.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Source</th>
              <th>Target</th>
              <th>Source €</th>
              <th>Fallback €</th>
              <th>Old €</th>
              <th>New €</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
        ${items.length > sample.length ? `<div class="cpm-muted">Showing first ${sample.length} of ${items.length} rows.</div>` : ""}
      `;
    }

    if (applyBtn) {
      const wouldWrite = Number(s.would_write || 0);
      applyBtn.disabled = !preview || wouldWrite <= 0;
    }
  }

  async function runCopyPricesRequest(preview) {
    const payload = getCopyPricesPayload(preview);
    if (!payload) return;

    const modal = ensureCopyPricesModal();
    const previewBtn = modal.querySelector("#cpm-preview");
    const applyBtn = modal.querySelector("#cpm-apply");

    if (!preview) {
      const ok = confirm("Apply copied prices now? This will write to prices.json.");
      if (!ok) return;
    }

    try {
      if (previewBtn) previewBtn.disabled = true;
      if (applyBtn) applyBtn.disabled = true;

      const json = await postJson("/app/admin/api/pricing/copy_prices.php", payload);
      renderCopyPricesResult(json, preview);

      if (!preview) {
        window.dispatchEvent(
          new CustomEvent("prices:changed", {
            detail: {
              unit: payload.unit,
              from: payload.target_from,
              to: json.source_to || null,
              action: "copy_prices"
            }
          })
        );

        alert("Copy prices completed.");
      }
    } catch (err) {
      console.error("[calendar_shell] copy_prices failed:", err);
      alert("Napaka pri kopiranju cen: " + err.message);
    } finally {
      if (previewBtn) previewBtn.disabled = false;
    }
  }


  function wireCommandButtons() {
    const btnBlock = qs("#cal-btn-block");
    const btnUnblock = qs("#cal-btn-unblock");
    const btnPrice = qs("#cal-btn-set-price");   // popravljeno
    const btnCopyPrices = qs("#cal-btn-copy-prices");
    const btnOffer = qs("#cal-btn-set-offer");   // popravljeno
    const btnAdmin = qs("#cal-btn-admin-reserve");
    // Clear selection ureja range_select_admin.js; tukaj ga ne podvajava

    if (btnBlock) {
      btnBlock.addEventListener("click", function (e) {
        e.preventDefault();
        handleBlockRange();
      });
    }

    if (btnUnblock) {
      btnUnblock.addEventListener("click", function (e) {
        e.preventDefault();
        handleUnblockRange();
      });
    }

    if (btnPrice) {
      btnPrice.addEventListener("click", function (e) {
        e.preventDefault();
        if (!ensureSelectionOrWarn()) return;
        if (!requireUnitOrWarn()) return;

        logAction("set_price_clicked");
        handleSetPrice();
      });
    }

    if (btnCopyPrices) {
      btnCopyPrices.addEventListener("click", function (e) {
        e.preventDefault();
        if (!ensureSelectionOrWarn()) return;
        if (!requireUnitOrWarn()) return;

        logAction("copy_prices_clicked");
        openCopyPricesModal();
      });
    }

    if (btnOffer) {
      btnOffer.addEventListener("click", function (e) {
        e.preventDefault();

        // brez izbire v koledarju nima smisla
        if (!ensureSelectionOrWarn()) return;
        if (!requireUnitOrWarn()) return;

        const sel = currentSelection;
        const unit = currentUnit;

        if (!sel || !sel.from || !sel.to || !unit) {
          alert("Napaka: ni veljavne izbire termina ali enote.");
          return;
        }

        const url =
          "/app/admin/integrations.php" +
          `?unit=${encodeURIComponent(unit)}` +
          `&from=${encodeURIComponent(sel.from)}` +
          `&to=${encodeURIComponent(sel.to)}` +
          `&open=new_offer`;

        logAction("set_offer_navigate", {
          unit,
          from: sel.from,
          to: sel.to,
          url
        });

        window.location.href = url;
      });
    }

    if (btnAdmin) {
      btnAdmin.addEventListener("click", async function (e) {
        e.preventDefault();

        if (!ensureSelectionOrWarn()) return;
        if (!requireUnitOrWarn()) return;

        const sel = currentSelection;
        const unit = currentUnit || getUnit();

        if (!sel || !sel.from || !sel.to || !unit) {
          alert("Napaka: ni veljavne izbire termina ali enote.");
          return;
        }

        // 1) izbira načina: hard / soft
        let mode = window.prompt(
          "Admin rezervacija – način:\n" +
            "hard = takoj potrjena (brez e-mail potrditve)\n" +
            "soft = soft-hold + e-mail z linkom za potrditev",
          "hard"
        );

        if (!mode) {
          // user cancel
          return;
        }
        mode = String(mode).trim().toLowerCase();
        if (mode !== "hard" && mode !== "soft") {
          // kakršenkoli drugi vnos → privzeto hard
          mode = "hard";
        }

	// 2) osnovni podatki o gostu

	// Ime – Cancel = prekini cel postopek
	let guestName = window.prompt(
	  "Ime gosta (poljubno, za lažjo orientacijo):",
	  ""
	);
	if (guestName === null) {
	  return; // user cancel → brez rezervacije
	}
	guestName = guestName.trim();

	// E-mail – Cancel = prekini cel postopek
	let guestEmail = window.prompt(
	  mode === "soft"
	    ? "E-mail gosta (OBVEZNO za soft-hold):"
	    : "E-mail gosta (neobvezno):",
	  ""
	);
	if (guestEmail === null) {
	  return;
	}
	guestEmail = guestEmail.trim();
	
	if (mode === "soft") {
	  if (!guestEmail || !guestEmail.includes("@")) {
	    alert("Za soft admin rezervacijo je e-mail obvezen in mora vsebovati '@'.");
	    return;
	  }
	}

	// Telefon – optional, ampak Cancel = prekini cel postopek
	let guestPhone = window.prompt("Telefon gosta (neobvezno):", "");
	if (guestPhone === null) {
	  return;
	}
	guestPhone = guestPhone.trim();

	// Opomba – optional, Cancel = prekini cel postopek
	let note = window.prompt("Opomba (neobvezno):", "");
	if (note === null) {
	  return;
	}
	note = note.trim();

	// Skupna cena – optional, Cancel = prekini cel postopek
	let totalStr = window.prompt(
	  "Skupna cena (neobvezno – samo informativno, brez TT):",
	  ""
	);
	if (totalStr === null) {
	  return;
	}

	let totalPrice = 0;
        if (totalStr) {
          totalStr = totalStr.replace(",", ".").trim();
          const num = parseFloat(totalStr);
          if (!isNaN(num) && isFinite(num)) {
            totalPrice = num;
          }
        }

        const payload = {
          unit: unit,
          from: sel.from,
          to: sel.to,
          guest_name: guestName,
          guest_email: guestEmail,
          guest_phone: guestPhone,
          note: note,
          total_price: totalPrice
        };

        try {
          if (mode === "hard") {
            logAction("admin_reserve_hard_request", payload);
            const res = await postJson("/app/admin/api/admin_reserve.php", payload);
            console.log("[calendar_shell] admin_reserve (hard) result:", res);
            alert("Admin rezervacija (HARD) ustvarjena.\nID: " + (res.id || "unknown"));

          } else {
            logAction("admin_reserve_soft_request", payload);
            const res = await postJson("/app/admin/api/admin_reserve_soft.php", payload);
            console.log("[calendar_shell] admin_reserve (soft) result:", res);

            const mailInfo = res.mail || {};
            let msg =
              "Soft admin rezervacija je ustvarjena kot soft-hold.\n" +
              "ID: " + (res.id || "unknown") + "\n";

            if (mailInfo.sent) {
              msg += "Gost je prejel e-mail z linkom za potrditev.";
            } else {
              msg += "POZOR: mail ni bil poslan (" + (mailInfo.error || "neznana napaka") + ").";
            }

            alert(msg);
          }

          // 🔄 Po novi rezervaciji obvestimo admin_calendar.js, naj osveži sloje
          const evUnit = unit || getUnit();
          if (evUnit) {
            window.dispatchEvent(
              new CustomEvent("reservation:changed", {
                detail: { unit: evUnit }
              })
            );
          }
        } catch (err) {
          console.error("[calendar_shell] admin_reserve failed:", err);
          alert("Napaka pri admin rezervaciji: " + err.message);
        }
      });
    }


  }

  function wireLayerToggles() {
    const map = {
      "layer-prices": "layer-prices-on",
      "layer-occupancy": "layer-occupancy-on",
      "layer-local": "layer-local-on",
      "layer-offers": "layer-offers-on",
      "layer-pending": "layer-pending-on"
    };

    const boxes = Array.from(
      document.querySelectorAll('input[type="checkbox"][id^="layer-"]')
    );
    if (!boxes.length) return;

    // Initial sync: set body classes according to current checkbox states
    boxes.forEach((el) => {
      const cls = map[el.id];
      if (cls) {
        document.body.classList.toggle(cls, !!el.checked);
      }
    });

    boxes.forEach((el) => {
      el.addEventListener("change", () => {
        const id = el.id;
        const checked = !!el.checked;
        const cls = map[id];

        if (cls) {
          document.body.classList.toggle(cls, checked);
        }

        console.log(
          "[calendar_shell] layer toggle",
          id,
          "→",
          checked ? "ON" : "OFF"
        );

        // Generic notification hook for other modules if needed
        window.dispatchEvent(
          new CustomEvent("layers:changed", {
            detail: { id, enabled: checked }
          })
        );
      });
    });
  }

  // -----------------------------------------
  // Header cleaning toggles (Clean before/after)
  // Sync per-unit via unit_settings_get/update
  // -----------------------------------------
  function wireCleanHeaderToggles() {
    const cbBefore = qs("#cal-clean-before");
    const cbAfter = qs("#cal-clean-after");

    // If there are no checkboxes in this view, nothing to do
    if (!cbBefore && !cbAfter) {
      console.log("[calendar_shell] no clean-before/after checkboxes present");
      return;
    }

    // Read per-unit site_settings via unit_settings_get.php
    async function fetchUnitSettings(unit) {
      const safeUnit = encodeURIComponent(unit || "A1");
      const url = `/app/admin/api/unit_settings_get.php?unit=${safeUnit}`;

      try {
        const res = await fetch(url, {
          cache: "no-store",
          credentials: "same-origin",
        });
        if (!res.ok) {
          console.warn(
            "[calendar_shell] unit_settings_get HTTP error",
            res.status,
          );
          return null;
        }
        const j = await res.json().catch(() => null);
        if (!j || !j.ok || !j.settings) {
          console.warn(
            "[calendar_shell] unit_settings_get logical error",
            j,
          );
          return null;
        }
        return j.settings;
      } catch (err) {
        console.warn("[calendar_shell] unit_settings_get fetch error", err);
        return null;
      }
    }

    // Update checkbox states for given unit from auto_block.*
    async function syncForUnit(unit) {
      const s = await fetchUnitSettings(unit);
      if (!s) {
        // If nothing, fall back to unchecked
        if (cbBefore) cbBefore.checked = false;
        if (cbAfter) cbAfter.checked = false;
        return;
      }

      const ab = s.auto_block || {};
      if (cbBefore) {
        cbBefore.checked = !!ab.before_arrival;
      }
      if (cbAfter) {
        cbAfter.checked = !!ab.after_departure;
      }
    }

    // Save a single setting into site_settings.json via unit_settings_update.php
    async function saveSetting(key, checked) {
      const unit = getUnit() || "A1";
      const body = new URLSearchParams();
      body.set("unit", unit);
      body.set("key", key); // e.g. "auto_block.before_arrival"
      body.set("value", checked ? "1" : "0");

      try {
        const res = await fetch("/app/admin/api/unit_settings_update.php", {
          method: "POST",
          body,
          credentials: "same-origin",
        });
        if (!res.ok) {
          console.warn(
            "[calendar_shell] unit_settings_update HTTP error",
            res.status,
          );
          return;
        }
        const j = await res.json().catch(() => null);
        if (!j || !j.ok) {
          console.warn(
            "[calendar_shell] unit_settings_update logical error",
            j,
          );
        }
      } catch (err) {
        console.warn(
          "[calendar_shell] unit_settings_update fetch error",
          err,
        );
      }
    }

    // on-change → write directly into auto_block.*
    if (cbBefore) {
      cbBefore.addEventListener("change", () =>
        saveSetting("auto_block.before_arrival", cbBefore.checked),
      );
    }
    if (cbAfter) {
      cbAfter.addEventListener("change", () =>
        saveSetting("auto_block.after_departure", cbAfter.checked),
      );
    }

    // Listen to cm:unitChanged (emitted by admin_calendar.js)
    window.addEventListener("cm:unitChanged", (ev) => {
      const unit =
        (ev.detail && ev.detail.unit) ||
        getUnit() ||
        "A1";
      syncForUnit(unit);
    });

    // Initial sync for currently selected unit
    const initialUnit = getUnit() || "A1";
    syncForUnit(initialUnit);
  }

async function fetchReservationInfoForSelection() {
    if (!currentSelection || !currentSelection.from || !currentSelection.to) {
        return null;
    }

    const unit = getUnit() || currentUnit;
    if (!unit) return null;

    const rangeEx = toExclusiveRange(currentSelection);
    if (!rangeEx) return null;

    const params = new URLSearchParams({
        unit,
        from: rangeEx.from,
        to: rangeEx.toEx
    });

    try {
        const res = await fetch(
            `/app/admin/api/find_reservation_by_range.php?${params.toString()}`,
            { credentials: "same-origin" }
        );

        if (!res.ok) return null;
        const json = await res.json().catch(() => null);
        if (!json) return null;

        const data = json.reservation || json.data || json;

        const id =
            data.id ||
            data.reservation_id ||
            data.resId ||
            data.code ||
            null;

        // Guest normalization (optional)
        let guestName = null;
        let guestPhone = null;
        let guestEmail = null;

        const g = data.guest ?? null;
        if (g) {
          if (typeof g === "string") {
            guestName = g;
          } else if (typeof g === "object") {
            guestName = g.name || g.full_name || g.fullName || null;
            guestPhone = g.phone || g.tel || null;
            guestEmail = g.email || null;
          }
        }
        if (!guestName) {
          guestName = data.guest_name || data.guestName || data.name || data.customer_name || null;
        }

        return {
            id,
            from: data.from || rangeEx.from,
            to: data.to || currentSelection.to,
            nights: data.nights || rangeEx.nights,
            guestName,
            guestPhone,
            guestEmail,
            type: data.type || "reservation"
        };
    } catch (err) {
        console.warn("fetchReservationInfoForSelection error", err);
        return null;
    }
}

function rangeAllHardLock(fromIso, toIso) {
    if (!fromIso || !toIso) return false;
    const days = eachDayISO(fromIso, toIso);
    if (!days.length) return false;

    for (const d of days) {
        const cell = document.querySelector(`.day[data-date="${d}"]`);
        if (!cell || cell.dataset.lockKind !== "hard_lock") {
            return false;
        }
    }
    return true;
}



async function updateSelectionMeta() {
    if (!currentSelection || !currentSelection.from || !currentSelection.to) {
        setSelectionMeta("");
        setOpenReservationLink(null);
        return;
    }

    const { from, to } = currentSelection;

    // ---- SINGLE DAY ----
    if (from === to) {
        const desc = describeSingleDayStatus(from) || "";
        const cell = document.querySelector(`.day[data-date="${from}"]`);

        if (cell && cell.dataset.lockKind === "hard_lock") {
            const info = await fetchReservationInfoForSelection();
            if (info && info.id) {
                const daysLabel = info.nights + " dni";
                setSelectionMeta(`${desc} | ${daysLabel} | ID: ${info.id}${info.guestName ? ' | Gost: ' + info.guestName : ''}`);
                setOpenReservationLink(info.id);
                return;
            }
        }

        setSelectionMeta(desc);
        setOpenReservationLink(null);
        return;
    }

    // ---- RANGE ----
    const nights = nightsBetween(from, to);
    const isHard = rangeAllHardLock(from, to);

    if (isHard) {
        const info = await fetchReservationInfoForSelection();
        if (info && info.id) {
            // Show the reservation's own from/to (checkout date, exclusive
            // of the last night) rather than the calendar selection's
            // to (last occupied night, inclusive) - avoids an apparent
            // "one night short" mismatch against the reservation record.
            const displayFrom = info.from || from;
            const displayTo = info.to || to;
            const displayNights = info.nights || nights;
            setSelectionMeta(
                `Reservation (hard-lock) | ${displayFrom} → ${displayTo} | ${displayNights} dni | ID: ${info.id}${info.guestName ? ' | Gost: ' + info.guestName : ''}`
            );
            setOpenReservationLink(info.id);
            return;
        }
    }

    setOpenReservationLink(null);

    let txt = `${nights} night${nights === 1 ? "" : "s"}`;

    if (rangeAllSoftAdmin(from, to)) {
        txt += ", admin block (soft-lock)";
    }
// NEW: occupancy meta (ICS/direct)
const occ = currentSelection?.occ;
if (occ) {
  const bits = [];
  if (occ.source)   bits.push(`src:${occ.source}`);
  if (occ.platform) bits.push(`plat:${occ.platform}`);
  if (occ.lock)     bits.push(`lock:${occ.lock}`);
  if (occ.status)   bits.push(`st:${occ.status}`);
  if (occ.id)       bits.push(`id:${occ.id}`);
  if (occ.summary)  bits.push(occ.summary);

  if (bits.length) txt += " — " + bits.join(" • ");
}


    setSelectionMeta(txt);
}

  // --------------------------
  // Init
  // --------------------------

  function init() {
    console.log("[calendar_shell] init");
    currentUnit = getUnit();
    currentMode = getMode();
    updateSelectionLabel();
    updateAdminReserveButtonState();
    updateBlockUnblockButtonState();


    wireUnitSelect();
    wireModeSelect();
    wireSelectionEvents();
    wireCommandButtons();
    wireLayerToggles();
    wireCleanHeaderToggles(); // sync Clean before/after per unit
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
