/**
 * CM Free / CM Plus – Channel Manager
 * File: public/js/range_selection_v2.8.6.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// range_selection_v2.10.3 – stabilna izbira + no-crossing + eventi + setRangeISO
(function(){
  const S = {
    start:null, end:null, cells:[],
    disableDeparture:true,
    isBusy:()=>false, isNoPrice:()=>false, isDeparture:null
  };

  function atMidnight(d){ return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate())); }
  function addDaysUTC(d,n){ const x=new Date(d); x.setUTCDate(x.getUTCDate()+n); return x; }
  function iso(d){ return d.toISOString().slice(0,10); }
  function parseISO(s){ if(!s) return null; const [y,m,d]=s.split('-').map(Number); return atMidnight(new Date(Date.UTC(y,m-1,d))); }

  function isBlocked(date){
    if (S.isBusy(date) || S.isNoPrice(date)) return true;
    if (S.disableDeparture && S.isDeparture && S.isDeparture(date)) return true;
    return false;
  }
  function spanClear(a,b){
    let d = atMidnight(a); const stop = atMidnight(b);
    while(d.getTime() <= stop.getTime()){
      if (isBlocked(d)) return false;
      d = addDaysUTC(d,1);
    }
    return true;
  }
  function emit(name){
    const payload = { start: S.start ? iso(S.start) : null, end: S.end ? iso(S.end) : null };
    window.__rangeSelection = payload;
    try { window.dispatchEvent(new CustomEvent(name || 'cm:range-changed', { detail: payload })); } catch(e){}
  }
  function paint(){
    const sMs = S.start ? S.start.getTime() : NaN;
    const eMs = S.end   ? S.end.getTime()   : NaN;
    for(const c of S.cells){
      const u = new Date(c.dataset.date+"T00:00:00Z").getTime();
      c.classList.toggle('selected', Number.isFinite(sMs) && Number.isFinite(eMs) && u>=sMs && u<eMs);
      c.classList.toggle('start', Number.isFinite(sMs) && !Number.isFinite(eMs) && u===sMs);
    }
    emit('cm:range-changed');
  }
  function invalid(){ emit('cm:range-invalid'); }

  function onDayClick(date){
    const d = atMidnight(date);
    if (isBlocked(d)) return;

    if (!S.start && !S.end){ S.start = d; S.end = null; paint(); return; }

    if (S.start && !S.end){
      if (d.getTime() === S.start.getTime()){ S.start = null; S.end = null; paint(); return; }
      const L = d < S.start ? d : S.start;
      const R = d < S.start ? S.start : d;
      if (!spanClear(L, R)){ invalid(); return; }
      S.start = L; S.end = addDaysUTC(R,1); paint(); return;
    }

    const inRange = (d >= S.start && d < S.end);
    if (inRange){ S.start = null; S.end = null; paint(); return; }

    if (d < S.start){
      const L = d, R = addDaysUTC(S.start,-1);
      if (!spanClear(L, R)){ invalid(); return; }
      S.start = L; paint(); return;
    }
    if (d >= S.end){
      const L = S.end, R = d;
      if (!spanClear(L, R)){ invalid(); return; }
      S.end = addDaysUTC(d,1); paint(); return;
    }
  }

  function attachCells(cells, opts){
    S.cells = cells;
    S.disableDeparture = !!(opts && opts.disableDeparture);
    S.isBusy = (opts && opts.isBusy) || S.isBusy;
    S.isNoPrice = (opts && opts.isNoPrice) || S.isNoPrice;
    S.isDeparture = (opts && opts.isDeparture) || null;
    for(const cell of cells){
      const dt = new Date(cell.dataset.date+"T00:00:00Z");
      cell.addEventListener('click', function(){ onDayClick(dt); }, {passive:true});
    }
  }
  function getRange(){ return (S.start && S.end) ? {start:S.start, end:S.end} : null; }

  // NEW: programatska nastavitev razpona (ISO YYYY-MM-DD); preveri blokade
  function setRangeISO(startIso, endIso){
    const s = parseISO(startIso), e = parseISO(endIso);
    if (!s || !e || e <= s) { S.start = null; S.end = null; paint(); return false; }
    const last = addDaysUTC(e,-1);
    if (!spanClear(s, last)) { emit('cm:range-invalid'); return false; }
    S.start = s; S.end = e; paint(); return true;
  }

  window.CM_RANGE = { attachCells, renderSelection:paint, getRange, setRangeISO };
  console.log("[range_v2.10.3] ok");
})();
