/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/locks_loader.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Hard-lock / local bookings loader for the admin calendar.
 *
 * Responsibilities:
 * - Load and parse hard-lock sources such as `local_bookings.json`
 *   and other internal lock files.
 * - Provide a normalized structure to admin_calendar.js that can be
 *   rendered as a dedicated layer (e.g. "hard lock" / "local only").
 * - Optionally merge or annotate locks with information from
 *   reservations JSON (for debugging and tooltips).
 *
 * Depends on:
 * - admin_api.js  → endpoints that expose local bookings / lock data.
 * - helpers.js    → date utilities and type guards.
 *
 * Used by:
 * - admin_calendar.js → to obtain and overlay the lock layer.
 * - admin_shell.js    → initial boot / reload on unit change.
 *
 * Notes:
 * - This module should be read-only with respect to the filesystem; writing
 *   new locks should go through dedicated admin_api endpoints.
 */


// /app/admin/ui/js/locks_loader.js
// Minimalen modul: ICS links + Locks list/akcije.
// Ne uporablja inline eventov; vse je CSP-safe.

const Paths = {
  unitsManifest: '/app/common/data/json/units/manifest.json',
  listReservations: '/app/admin/api/list_reservations.php',
  sendAcceptLink: '/app/admin/api/send_accept_link.php',
  // ICS endpoint: temelji na tvojih integracijah pod /admin/api/integrations/
  icsBase(unit, mode) {
    const base = `${window.location.origin}/app/admin/api/integrations/ics.php`;
    const u = encodeURIComponent(unit);
    const m = mode === 'blocked' ? 'blocked' : 'booked';
    return `${base}?unit=${u}&mode=${m}`;
  },
};

function byId(id){ return document.getElementById(id); }
function el(tag, attrs={}, ...kids){
  const n = document.createElement(tag);
  for (const [k,v] of Object.entries(attrs)){
    if (k === 'class') n.className = v;
    else if (k === 'dataset') Object.assign(n.dataset, v);
    else if (k === 'html') n.innerHTML = v;
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v);
  }
  for (const k of kids) n.append(k);
  return n;
}
function fmtYMonth(d=new Date()){
  const y = d.getFullYear();
  const m = String(d.getMonth()+1).padStart(2,'0');
  return `${y}-${m}`;
}
async function fetchJSON(url, opts){
  const res = await fetch(url, opts);
  if(!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return await res.json();
}

async function loadUnits(selects){
  try{
    const data = await fetchJSON(Paths.unitsManifest);
    // manifest format: { units: [{id:"A1", name:"Apartma A1", ...}, ...] } ali array
    const units = Array.isArray(data) ? data : (data.units || []);
    for(const sel of selects){
      sel.innerHTML = '';
      for(const u of units){
        const id = u.id || u.unit || u.code || String(u);
        const name = u.name || u.title || id;
        sel.append(el('option', {value:id}, `${id} — ${name}`));
      }
    }
  }catch(err){
    console.error('Units manifest error:', err);
  }
}

/* ------------------------- INTEGRATIONS (ICS) ------------------------- */

function initIntegrations(){
  const selUnit = byId('ics-unit');
  const urlBooked = byId('ics-url-booked');
  const urlBlocked = byId('ics-url-blocked');
  const btnRefresh = byId('ics-refresh');

  if(!selUnit || !urlBooked || !urlBlocked || !btnRefresh) return;

  const refresh = ()=>{
    const unit = selUnit.value;
    if(!unit) return;
    urlBooked.textContent  = Paths.icsBase(unit, 'booked');
    urlBlocked.textContent = Paths.icsBase(unit, 'blocked');
  };

  btnRefresh.addEventListener('click', refresh);
  selUnit.addEventListener('change', refresh);

  // copy buttons
  for(const btn of document.querySelectorAll('[data-copy]')){
    btn.addEventListener('click', ()=>{
      const sel = btn.getAttribute('data-copy');
      const code = document.querySelector(sel);
      if(!code) return;
      const txt = code.textContent.trim();
      navigator.clipboard?.writeText(txt).then(()=>{
        btn.textContent = 'Kopirano ✓';
        setTimeout(()=>btn.textContent='Kopiraj URL', 1200);
      }).catch(()=>{ /* ignore */ });
    });
  }

  // init after units load
  (async ()=>{
    await loadUnits([selUnit]);
    refresh();
  })();
}

/* ----------------------------- LOCKS LIST ----------------------------- */

function initLocks(){
  const selUnit = byId('locks-unit');
  const inpMonth = byId('locks-month');
  const selStatus = byId('locks-status');
  const selSource = byId('locks-source');
  const cbSoft = byId('locks-include-soft');
  const inpSearch = byId('locks-search');
  const btnRefresh = byId('locks-refresh');
  const list = byId('locks-list');

  if(!selUnit || !inpMonth || !selStatus || !selSource || !cbSoft || !inpSearch || !btnRefresh || !list) return;

  inpMonth.value = fmtYMonth(new Date());

  const refresh = async ()=>{
    list.innerHTML = '<div class="muted">Loading…</div>';
    const params = new URLSearchParams();
    if(selUnit.value) params.set('unit', selUnit.value);
    if(inpMonth.value) params.set('ym', inpMonth.value);
    if(selStatus.value) params.set('status', selStatus.value);
    if(selSource.value) params.set('source', selSource.value);
    params.set('include_soft_hold', cbSoft.checked ? '1' : '0');
    if(inpSearch.value.trim()) params.set('q', inpSearch.value.trim());

    try{
      const data = await fetchJSON(`${Paths.listReservations}?${params.toString()}`);
      if(!data.ok){ throw new Error('API not ok'); }
      renderLocks(list, data.items || []);
    }catch(err){
      console.error(err);
      list.innerHTML = '<div class="error">Napaka pri branju rezervacij.</div>';
    }
  };

  btnRefresh.addEventListener('click', refresh);
  selUnit.addEventListener('change', refresh);
  inpMonth.addEventListener('change', refresh);
  selStatus.addEventListener('change', refresh);
  selSource.addEventListener('change', refresh);
  cbSoft.addEventListener('change', refresh);
  inpSearch.addEventListener('input', ()=>{
    // debounce light
    clearTimeout(inpSearch._t);
    inpSearch._t = setTimeout(refresh, 300);
  });

  (async ()=>{
    await loadUnits([selUnit]);
    refresh();
  })();
}

function renderLocks(container, items){
  if(!items.length){
    container.innerHTML = '<div class="muted">Ni najdenih zapisov.</div>';
    return;
  }
  container.innerHTML = '';
  for(const it of items){
    container.append(renderLockItem(it));
  }
}

function renderLockItem(it){
  // Pričakovana polja iz list_reservations.php:
  // id, unit, from, to, status, source, _bucket (soft_hold|hard_lock), guest{name,email,phone}, cancel_link?
  const hard = (it._bucket === 'hard_lock') || (it.lock === 'hard') || (it.type === 'reserved');
  const soft = (it._bucket === 'soft_hold') || (it.lock === 'soft') || (it.type === 'blocked');

  const card = el('div', {class:'card lock-card'});
  const header = el('div', {class:'card-header'});
  header.append(
    el('strong', {}, it.id || '(no-id)'),
    ' · ',
    el('span', {class:'tag'}, it.unit || ''),
    ' · ',
    el('span', {class: hard?'tag-hard':'tag-soft'}, hard?'HARD':'SOFT'),
  );
  const body = el('div', {class:'card-body'});
  const g = it.guest || {};
  const dateStr = `${it.from || ''} → ${it.to || ''}`;
  body.append(
    el('div', {class:'row'}, el('span', {class:'label'}, 'Termin:'), ' ', dateStr),
    el('div', {class:'row'}, el('span', {class:'label'}, 'Status:'), ' ', it.status || ''),
    el('div', {class:'row'}, el('span', {class:'label'}, 'Source:'), ' ', it.source || ''),
    el('div', {class:'row'}, el('span', {class:'label'}, 'Gost:'), ' ', `${g.name||''} · ${g.email||''}${g.phone?(' · '+g.phone):''}`),
  );

  const footer = el('div', {class:'card-footer', style:'display:flex;gap:.5rem;flex-wrap:wrap'});

  if(soft){
    // SOFT: accept link actions (preview/copy/send)
    const btnPreview = el('button', {class:'btn'}, 'Predogled linka');
    const btnCopy    = el('button', {class:'btn'}, 'Kopiraj link');
    const btnSend    = el('button', {class:'btn'}, 'Pošlji link');

    let cachedLink = '';

    btnPreview.addEventListener('click', async ()=>{
      try{
        const url = `${Paths.sendAcceptLink}?id=${encodeURIComponent(it.id)}&dry_run=1`;
        const resp = await fetchJSON(url);
        cachedLink = (resp.preview && resp.preview.link) ? resp.preview.link : (resp.link || '');
        if(!cachedLink) throw new Error('Ni povezave v odzivu.');
        btnPreview.textContent = 'Pridobljeno ✓';
        setTimeout(()=>btnPreview.textContent='Predogled linka', 1200);
      }catch(e){
        console.error(e);
        btnPreview.textContent = 'Napaka';
        setTimeout(()=>btnPreview.textContent='Predogled linka', 1200);
      }
    });

    btnCopy.addEventListener('click', async ()=>{
      try{
        if(!cachedLink){
          // če še ni preview, poskusi enkrat sam
          const url = `${Paths.sendAcceptLink}?id=${encodeURIComponent(it.id)}&dry_run=1`;
          const resp = await fetchJSON(url);
          cachedLink = (resp.preview && resp.preview.link) ? resp.preview.link : (resp.link || '');
          if(!cachedLink) throw new Error('Ni povezave v odzivu.');
        }
        await navigator.clipboard.writeText(cachedLink);
        btnCopy.textContent = 'Kopirano ✓';
        setTimeout(()=>btnCopy.textContent='Kopiraj link', 1200);
      }catch(e){
        console.error(e);
        btnCopy.textContent = 'Napaka';
        setTimeout(()=>btnCopy.textContent='Kopiraj link', 1200);
      }
    });

    btnSend.addEventListener('click', async ()=>{
      try{
        const url = `${Paths.sendAcceptLink}?id=${encodeURIComponent(it.id)}&dry_run=0`;
        const resp = await fetchJSON(url);
        if(resp.ok){
          btnSend.textContent = 'Poslano ✓';
        }else{
          btnSend.textContent = 'Napaka';
        }
        setTimeout(()=>btnSend.textContent='Pošlji link', 1200);
      }catch(e){
        console.error(e);
        btnSend.textContent = 'Napaka';
        setTimeout(()=>btnSend.textContent='Pošlji link', 1200);
      }
    });

    footer.append(btnPreview, btnCopy, btnSend);
  }

  if(hard){
    // HARD: cancel link copy (če obstaja)
    if(it.cancel_link){
      const btnCopyCancel = el('button', {class:'btn'}, 'Kopiraj cancel link');
      btnCopyCancel.addEventListener('click', async ()=>{
        try{
          await navigator.clipboard.writeText(it.cancel_link);
          btnCopyCancel.textContent = 'Kopirano ✓';
          setTimeout(()=>btnCopyCancel.textContent='Kopiraj cancel link', 1200);
        }catch(e){
          btnCopyCancel.textContent = 'Napaka';
          setTimeout(()=>btnCopyCancel.textContent='Kopiraj cancel link', 1200);
        }
      });
      footer.append(btnCopyCancel);
    }
    // pozneje: odpovej rezervacijo (direct/ICS force) preko cancel_reservation.php
  }

  card.append(header, body, footer);
  return card;
}

/* ------------------------------ BOOTSTRAP ------------------------------ */

window.addEventListener('DOMContentLoaded', ()=>{
  initIntegrations();
  initLocks();
});
