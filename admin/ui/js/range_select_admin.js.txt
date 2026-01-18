/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/range_select_admin.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/range_select_admin.js
// Range-select za ADMIN koledar na .day[data-date], brez debug logov

(function () {
  const calendarRootSelector = "#calendar";
  const daySelector = ".day[data-date]";

  let isDragging = false;
  let anchorDate = null; // Date
  let hoverDate = null;  // Date

  // trenutno izbran range (za toggle-off logiko)
  let currentRangeStart = null; // Date
  let currentRangeEnd = null;   // Date

  // ignoriraj prvi click po mouseup (da single-click ne resetira takoj)
  let suppressClickOnce = false;

  // --------------------------
  // Helpers
  // --------------------------

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function parseISODate(iso) {
    const parts = iso.split("-");
    if (parts.length !== 3) return null;
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10) - 1;
    const d = parseInt(parts[2], 10);
    return new Date(y, m, d);
  }

  function toISODate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return y + "-" + m + "-" + d;
  }

  function diffNights(from, to) {
    const ms = to.getTime() - from.getTime();
    return Math.round(ms / (1000 * 60 * 60 * 24)) + 1; // inclusive
  }

  function normalizeRange(a, b) {
    if (!a || !b) return null;
    if (a.getTime() <= b.getTime()) {
      return { start: a, end: b };
    } else {
      return { start: b, end: a };
    }
  }


   function isHardLockedCell(cell) {
     if (!cell) return false;
     // primarni marker
     if (cell.dataset && cell.dataset.lockKind === "hard_lock") return true;
     // fallback (če bi kdaj zmanjkalo dataset)
     if (cell.classList && cell.classList.contains("reserved")) return true;
     return false;
   }

   function clampHoverDateToHardStop(anchor, target) {
     // Vrne zadnji dovoljen datum na poti anchor -> target (vključno z anchor),
     // in se ustavi tik PRED hard_lock celico.
     if (!anchor || !target) return target;

     const dir = target >= anchor ? 1 : -1;
     let cur = new Date(anchor.getTime());
     let lastAllowed = new Date(anchor.getTime());

     while (true) {
       if (toISODate(cur) === toISODate(target)) break;
       cur.setDate(cur.getDate() + dir);

       const iso = toISODate(cur);
       const cell = document.querySelector(`${daySelector}[data-date="${iso}"]`);
       if (isHardLockedCell(cell)) break;
  
       lastAllowed = new Date(cur.getTime());
      }
 
     return lastAllowed;
   }



  function isWithinCurrentRange(date) {
    if (!currentRangeStart || !currentRangeEnd || !date) return false;
    const t = date.getTime();
    return (
      t >= currentRangeStart.getTime() &&
      t <= currentRangeEnd.getTime()
    );
  }

  // --------------------------
  // DOM updates
  // --------------------------

  function clearSelectionClasses() {
    qsa(".day").forEach(function (el) {
      el.classList.remove("sel", "selected-range", "selected-start", "selected-end");
    });
  }

  function applySelectionClasses(range) {
    if (!range) return;
    const { start, end } = range;

    const startISO = toISODate(start);
    const endISO = toISODate(end);

    const cells = qsa(daySelector, document);

    cells.forEach(function (cell) {
      const cellDateISO = cell.getAttribute("data-date");
      if (!cellDateISO) return;

      const cellDate = parseISODate(cellDateISO);
      if (!cellDate) return;
      const t = cellDate.getTime();

      if (t < start.getTime() || t > end.getTime()) {
        return;
      }

      // Glavno: označi celoten trak kot selected-range
      // (za cyan/yellow overlay iz calendar.css) + ohrani .sel
      cell.classList.add("sel", "selected-range");

      // robna dneva dobita dodatni highlight
      if (cellDateISO === startISO) {
        cell.classList.add("selected-start");
      } else if (cellDateISO === endISO) {
        cell.classList.add("selected-end");
      }
    });

  }

  function updateSelectionInfo(range) {
    const labelEl = qs("#cal-selection-label");
    const metaEl = qs("#cal-selection-meta");

    if (!range) {
      if (labelEl) labelEl.textContent = "No selection";
      if (metaEl) metaEl.textContent = "";
      return;
    }

    const { start, end } = range;
    const fromISO = toISODate(start);
    const toISO = toISODate(end);
    const nights = diffNights(start, end);

    if (labelEl) {
      if (fromISO === toISO) {
        labelEl.textContent = fromISO;
      } else {
        labelEl.textContent = fromISO + " → " + toISO;
      }
    }
    if (metaEl) {
      metaEl.textContent = nights + (nights === 1 ? " noč" : " noči");
    }
  }

  function dispatchRangeSelected(range) {
    if (!range) return;
    const { start, end } = range;

    // zapomnimo si current range za toggle-off
    currentRangeStart = new Date(start.getTime());
    currentRangeEnd = new Date(end.getTime());

    const detail = {
      from: toISODate(start),
      to: toISODate(end),
      nights: diffNights(start, end)
    };
    const event = new CustomEvent("admin-range-selected", { detail: detail });
    document.dispatchEvent(event);
  }

  function dispatchRangeCleared() {
    const event = new CustomEvent("admin-range-cleared");
    document.dispatchEvent(event);
  }

  function clearSelection(silent) {
    anchorDate = null;
    hoverDate = null;
    currentRangeStart = null;
    currentRangeEnd = null;
    suppressClickOnce = false; // po čiščenju nič več ignoriranja klika
    clearSelectionClasses();
    updateSelectionInfo(null);

    // če ni "silent" režim → javimo, da je selection globalno počistilo
    if (!silent) {
      dispatchRangeCleared();
    }
  }


  // --------------------------
  // Event handlers
  // --------------------------

  function handleMouseDown(event) {
    const cell = closestDayCell(event.target);
    if (!cell) return;

    // hard-lock (ICS/reservation): dovolimo klik (info), ne dovolimo pa drag/range starta
    if (isHardLockedCell(cell)) return;

    const iso = cell.getAttribute("data-date");
    if (!iso) return;

    const date = parseISODate(iso);
    if (!date) return;

    isDragging = true;
    anchorDate = date;
    hoverDate = date;

    clearSelectionClasses();
    const range = normalizeRange(anchorDate, hoverDate);
    applySelectionClasses(range);
    updateSelectionInfo(range);

    event.preventDefault(); // brez text selectiona
  }

  function handleMouseEnter(event) {
    if (!isDragging) return;

    const cell = closestDayCell(event.target);
    if (!cell) return;

    const iso = cell.getAttribute("data-date");
    if (!iso) return;

    const date = parseISODate(iso);
    if (!date) return;

    // hard-stop: ne dovoli razširit range čez hard_lock
    hoverDate = clampHoverDateToHardStop(anchorDate, date);

    clearSelectionClasses();
    const range = normalizeRange(anchorDate, hoverDate);
    applySelectionClasses(range);
    updateSelectionInfo(range);
  }

  function handleMouseUp() {
    if (!isDragging) return;

    isDragging = false;

    if (!anchorDate || !hoverDate) {
      clearSelection();
      return;
    }

    const range = normalizeRange(anchorDate, hoverDate);
    applySelectionClasses(range);
    updateSelectionInfo(range);
    dispatchRangeSelected(range);

    // prvi click po tem mouseUpu ignoriramo, da ne resetira enodnevnega selectiona
    suppressClickOnce = true;
  }

function handleClick(event) {
  const cell = closestDayCell(event.target);
  if (!cell) return;

  const iso = cell.getAttribute("data-date");
  if (!iso) return;
  const date = parseISODate(iso);
  if (!date) return;

  // Če imamo že aktiven range...
  if (currentRangeStart && currentRangeEnd) {
    // klik znotraj trenutnega range-a → počisti
    if (isWithinCurrentRange(date)) {
      clearSelection();
      return;
    }
    // klik IZVEN trenutnega range-a → tudi samo počisti
    clearSelection();
    return;
  }

  // Če NI aktivnega range-a → ta klik začne nov enodnevni range
  anchorDate = date;
  hoverDate = date;

  clearSelectionClasses();
  const range = normalizeRange(anchorDate, hoverDate);
  applySelectionClasses(range);
  updateSelectionInfo(range);
  dispatchRangeSelected(range);
}

 
  function closestDayCell(el) {
    while (el && el !== document) {
      if (
        el.classList &&
        el.classList.contains("day") &&
        el.dataset.date
      ) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  // --------------------------
  // Init
  // --------------------------

  function initRangeSelect() {
    const calendar = qs(calendarRootSelector);
    if (!calendar) return;

    calendar.addEventListener("mousedown", handleMouseDown);
    calendar.addEventListener("mouseover", handleMouseEnter);
    document.addEventListener("mouseup", handleMouseUp);

    calendar.addEventListener("click", function (e) {
      if (suppressClickOnce) {
        suppressClickOnce = false;
        return;
      }
      if (!isDragging) {
        handleClick(e);
      }
    });

    const clearBtn =
      qs("#cal-btn-clear-selection") ||
      qs("#cal-btn-clear");

     if (clearBtn) {
       clearBtn.addEventListener("click", function () {
        clearSelection();
      });
    }

  }

  document.addEventListener("DOMContentLoaded", function () {
    initRangeSelect();
  });

  // expose za morebitno ročno uporabo
  window.AdminRangeSelect = {
    clearSelection: clearSelection
  };
})();
