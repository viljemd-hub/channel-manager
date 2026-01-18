/**
 * CM Free / CM Plus – Channel Manager
 * File: public/js/info_drag.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/public/js/info_drag.js
(() => {
  const ID = "cm-info";
  const STORAGE_KEY = "cmInfoPos.v3";

  const box = document.getElementById(ID);
  if (!box) return;

  // --- helpers ---
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const getP = (e) => (e.touches && e.touches[0])
    ? { x: e.touches[0].clientX, y: e.touches[0].clientY }
    : { x: e.clientX, y: e.clientY };

function isMobile() {
  // Prvo preverimo coarse pointer → telefon ali tablica
  if (window.matchMedia("(pointer: coarse)").matches) return true;

  // Potem klasični width check
  if (window.matchMedia("(max-width: 900px)").matches) return true;

  // potem fallback na userAgent
  const ua = navigator.userAgent.toLowerCase();
  if (ua.includes("iphone") || ua.includes("android")) return true;

  return false;
}



  // Poiščemo dno headerja (podpira .topbar, .header ali <header>)
  const HEADER_SELECTORS = [".topbar", ".header", "header"];
  function headerBottomPx() {
    for (const sel of HEADER_SELECTORS) {
      const el = document.querySelector(sel);
      if (el) return Math.round(el.getBoundingClientRect().bottom);
    }
    // fallback: poskusi prebrati CSS var(--header-h)
    const v = getComputedStyle(document.documentElement).getPropertyValue("--header-h").trim();
    if (v.endsWith("px")) return parseFloat(v);
    return 0;
  }
  function minTopPx() { return headerBottomPx() + 8; } // +8px od headerja

  // Vstavi ročaj in content wrapper, ne glede na to, ali drugi skripti prepisujejo innerHTML
  function ensureHandle() {
    let handle = box.querySelector(".cm-info__handle");
    if (!handle) {
      handle = document.createElement("div");
      handle.className = "cm-info__handle";
      handle.tabIndex = 0;
      handle.setAttribute("aria-label", "Povleci za premik");
      const dots = document.createElement("span");
      dots.className = "cm-info__dots";
      dots.setAttribute("aria-hidden", "true");
      dots.textContent = "⋮⋮⋮";
      handle.appendChild(dots);
      handle.appendChild(document.createTextNode(" Premakni/Move"));
      box.prepend(handle);
    }
    if (!box.querySelector(".cm-info__content")) {
      const content = document.createElement("div");
      content.className = "cm-info__content";
      Array.from(box.childNodes).forEach(n => { if (n !== handle) content.appendChild(n); });
      box.appendChild(content);
    }
  }

  function applySavedPos() {
    // Na mobilcu ne uporabljamo custom pozicij – pustimo CSS, da ga prilepi na dno
    if (isMobile()) {
      box.style.left = "";
      box.style.top  = "";
      return;
    }

    try {
      const s = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
      if (s && Number.isFinite(s.x) && Number.isFinite(s.y)) {
        box.style.left = s.x + "px";
        box.style.top  = s.y + "px";
      }
    } catch {}
    enforceBounds(); // po nalaganju takoj poravnaj pod header
  }


  function enforceBounds() {
    // Na mobilcu nič ne računamo – CSS ga drži pri dnu
    if (isMobile()) {
      box.style.left = "";
      box.style.top  = "";
      return;
    }

    const r = box.getBoundingClientRect();
    const vw = window.innerWidth, vh = window.innerHeight;
    const minLeft = 4, minTop = minTopPx();
    const left = clamp(r.left, minLeft, vw - r.width - 4);
    const top  = clamp(r.top,  minTop,  vh - r.height - 4);
    box.style.left = Math.round(left) + "px";
    box.style.top  = Math.round(top)  + "px";
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ x: Math.round(left), y: Math.round(top) }));
  }

  // Drag
  let dragging = false, start = null;

  function onDown(e){
    if (isMobile()) return; // na telefonu ne dovolimo draga, box je sidran spodaj
    const t = e.target.closest(".cm-info__handle");
    if (!t) return;
    if (e.type === "mousedown" && e.button !== 0) return;

    const p = getP(e);
    const r = box.getBoundingClientRect();
    start = { x:p.x, y:p.y, left:r.left, top:r.top, w:r.width, h:r.height };
    dragging = true;
    box.classList.add("cm-info--dragging");
    document.body.style.userSelect = "none";

    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", onUp);
    window.addEventListener("touchmove", onMove, { passive:false });
    window.addEventListener("touchend", onUp);
    if (e.cancelable) e.preventDefault();
  }

  function onMove(e){
    if (!dragging || !start) return;
    const p = getP(e);
    const dx = p.x - start.x, dy = p.y - start.y;
    const vw = window.innerWidth, vh = window.innerHeight;

    const nextLeft = clamp(start.left + dx, 4, vw - start.w - 4);
    const nextTop  = clamp(start.top  + dy,  minTopPx(),  vh - start.h - 4);

    box.style.left = Math.round(nextLeft) + "px";
    box.style.top  = Math.round(nextTop)  + "px";
    if (e.cancelable) e.preventDefault();
  }

  function onUp(){
    if (!dragging) return;
    dragging = false;
    box.classList.remove("cm-info--dragging");
    document.body.style.userSelect = "";
    window.removeEventListener("mousemove", onMove);
    window.removeEventListener("mouseup", onUp);
    window.removeEventListener("touchmove", onMove);
    window.removeEventListener("touchend", onUp);
    enforceBounds(); // shranjevanje vključen znotraj
  }

  box.addEventListener("mousedown", onDown);
  box.addEventListener("touchstart", onDown, { passive:false });

  // Tipkovnica (na ročaju): puščice (Shift=30), R=reset — z upoštevanjem minTop
  box.addEventListener("keydown", (e) => {
    if (!e.target.closest(".cm-info__handle")) return;
    const step = e.shiftKey ? 30 : 10;
    const r = box.getBoundingClientRect();
    const vw = window.innerWidth, vh = window.innerHeight;
    let left = r.left, top = r.top;

    if (e.key === "ArrowLeft")      left -= step;
    else if (e.key === "ArrowRight") left += step;
    else if (e.key === "ArrowUp")    top  -= step;
    else if (e.key === "ArrowDown")  top  += step;
    else if (e.key.toLowerCase() === "r") {
      localStorage.removeItem(STORAGE_KEY);
      box.style.left = ""; box.style.top = "";
      // po resetu poravnaj na privzeto (pod header)
      setTimeout(enforceBounds, 0);
      return;
    } else return;

    left = clamp(left, 4, vw - r.width - 4);
    top  = clamp(top,  minTopPx(),  vh - r.height - 4);

    box.style.left = Math.round(left) + "px";
    box.style.top  = Math.round(top)  + "px";
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ x: Math.round(left), y: Math.round(top) }));
    e.preventDefault();
  });

  // Reagiraj, če drugi skripti prepisujejo vsebino ali kažejo/skrijejo box
  const mo = new MutationObserver(() => {
    ensureHandle();
    // če se header spremeni višinsko (npr. na mobilni meni), poravnaj
    enforceBounds();
  });
  mo.observe(box, { childList:true, subtree:false, attributes:true });

  // inicializacija
  ensureHandle();
  applySavedPos();

  // Ob spremembi velikosti okna poravnaj znotraj meja (tudi pod header)
  window.addEventListener("resize", enforceBounds);
})();
