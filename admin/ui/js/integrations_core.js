/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_core.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_core.js
 * Core runtime + shared context + ready event
 */
(() => {
  'use strict';

  /* ---------------- helpers ---------------- */
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const escapeHtml = (s) =>
    String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  async function safeFetchJson(url, opts = {}) {
    const r = await fetch(url, opts);
    if (!r.ok) {
      const t = await r.text().catch(() => '');
      throw new Error(`HTTP ${r.status} @ ${url}${t ? ` :: ${t.slice(0, 200)}` : ''}`);
    }
    const t = await r.text();
    try { return JSON.parse(t); } catch { return t; }
  }

  function readConfig() {
    const el = document.getElementById('integrations-root');
    if (!el) throw new Error('#integrations-root missing');
    // podpira tako data-config kot dataset.config
    const raw = el.getAttribute('data-config') || el.dataset.config || '{}';
    return JSON.parse(raw);
  }

  /* ---------------- core state ---------------- */
  const CFG = readConfig();

  const LS_UNIT_KEY = 'cm_integrations_last_unit';
  const state = {
    units: [],
    currentUnit: CFG.unit || '',
    manifest: null,          // <-- tu bomo shranili manifest.json
  };


  /* ---------------- DOM ---------------- */
  const dom = {
    unitSelect: $('#unitSelect'),
    unitsListBox: $('#unitsListBox'),

    icsBookedUrlEl: $('#icsBookedUrl'),
    icsBlockedUrlEl: $('#icsBlockedUrl'),
    openIcsBooked: $('#openIcsBooked'),
    openIcsBlocked: $('#openIcsBlocked'),
    icsBookedStatusEl: $('#icsBookedStatus'),
    icsBlockedStatusEl: $('#icsBlockedStatus'),
  };

  /* ---------------- core logic ---------------- */
  function getCurrentUnit() {
    return state.currentUnit || '';
  }

  function loadLastUnit() {
    try { return localStorage.getItem(LS_UNIT_KEY) || ''; } catch { return ''; }
  }
  function saveLastUnit(u) {
    try { localStorage.setItem(LS_UNIT_KEY, u || ''); } catch {}
  }

  async function loadUnitsList() {
    const manifestUrl = CFG.manifest; // <-- manifest.json
    let data;

    data = await safeFetchJson(manifestUrl);

    // shrani cel manifest za kasnejšo uporabo (npr. base_url)
    state.manifest = (data && typeof data === 'object') ? data : null;

    const arr = Array.isArray(data?.units) ? data.units : [];
    state.units = arr
      .map(u => ({
        id: u.id || u.unit || '',
        label: u.alias || u.label || u.name || (u.id || ''),
        raw: u
      }))
      .filter(u => u.id);


    // select unit: najprej CFG.unit, potem LS, potem prva
    let desired = state.currentUnit || loadLastUnit();
    if (!desired || !state.units.some(x => x.id === desired)) {
      desired = state.units.length ? state.units[0].id : '';
    }
    state.currentUnit = desired;
    saveLastUnit(desired);

    // napolni select (tudi če Units modul še ni initan)
    if (dom.unitSelect) {
      dom.unitSelect.innerHTML = '';
      for (const u of state.units) {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = `${u.id} – ${u.label || ''}`.trim();
        if (u.id === desired) o.selected = true;
        dom.unitSelect.appendChild(o);
      }
    }
  }

async function loadIcsExportKeys(unit) {
  if (!unit) return { booked: '', blocked: '' };

  try {
    const url = `/app/common/data/json/integrations/${encodeURIComponent(unit)}.json?_=${Date.now()}`;
    const cfg = await safeFetchJson(url);

    // NEW format (preferred)
    const exp = cfg?.export?.ics || {};
    if (exp.booked?.key || exp.blocked?.key) {
      return {
        booked: exp.booked?.key || '',
        blocked: exp.blocked?.key || '',
      };
    }

    // LEGACY fallback (A1)
    const legacy = cfg?.keys || {};
    return {
      booked: legacy.reservations_out || '',
      blocked: legacy.calendar_out || '',
    };
  } catch (e) {
    console.warn('[ICS OUT] cannot load export keys for unit', unit, e);
    return { booked: '', blocked: '' };
  }
}


async function refreshIcsUrls() {
  const unit = getCurrentUnit();

  // reset
  if (!unit) {
    if (dom.icsBookedUrlEl) dom.icsBookedUrlEl.textContent = '—';
    if (dom.icsBlockedUrlEl) dom.icsBlockedUrlEl.textContent = '—';

    dom.openIcsBooked && dom.openIcsBooked.removeAttribute('href');
    dom.openIcsBlocked && dom.openIcsBlocked.removeAttribute('href');

    if (dom.openIcsBooked) dom.openIcsBooked.removeAttribute('data-ics-url');
    if (dom.openIcsBlocked) dom.openIcsBlocked.removeAttribute('data-ics-url');

    if (dom.icsBookedStatusEl) {
      dom.icsBookedStatusEl.textContent = '';
      dom.icsBookedStatusEl.className = 'ics-status';
    }
    if (dom.icsBlockedStatusEl) {
      dom.icsBlockedStatusEl.textContent = '';
      dom.icsBlockedStatusEl.className = 'ics-status';
    }

    return;
  }

  const keys = await loadIcsExportKeys(unit);

  // -----------------------------
  // Domena iz manifest.json (če obstaja)
  // -----------------------------
  let domain = '';

  const mf = state.manifest;
  if (mf && typeof mf === 'object') {
    // predlagana struktura:
    // { "base_url": "https://apartma-matevz.si", ... }
    // ali: { "meta": { "base_url": "https://..." } }
    const meta = (mf.meta && typeof mf.meta === 'object') ? mf.meta : null;

    const cand =
      (typeof mf.base_url === 'string' && mf.base_url.trim()) ? mf.base_url.trim() :
      (meta && typeof meta.base_url === 'string' && meta.base_url.trim()) ? meta.base_url.trim() :
      (typeof mf.domain === 'string' && mf.domain.trim()) ? mf.domain.trim() :
      (meta && typeof meta.domain === 'string' && meta.domain.trim()) ? meta.domain.trim() :
      '';

    if (cand) {
      domain = cand.replace(/\/+$/, ''); // brez trailing /
    }
  }

  // če v manifestu ni domene → fallback na placeholder
  if (!domain) {
    domain = 'https://{YOUR_DOMAIN}';
  }

  // Pot do ics.php iz CFG – običajno relativna, npr. "/app/admin/api/integrations/ics.php"
  const icsPath = CFG.api.icsPhp || '/app/admin/api/integrations/ics.php';

  // Če je v CFG.api.icsPhp že absoluten URL (za PRO/scenarije), ga pusti pri miru
  const icsBasePath = icsPath.startsWith('http')
    ? icsPath
    : domain + icsPath;

  const base = `${icsBasePath}?unit=${encodeURIComponent(unit)}`;

  // Booked only feed – ICS OUT expects mode=booked
  const bookedUrl = keys.booked
    ? `${base}&mode=booked&key=${encodeURIComponent(keys.booked)}`
    : '';

  // Calendar feed – booked + blocked (mode=blocked)
  const blockedUrl = keys.blocked
    ? `${base}&mode=blocked&key=${encodeURIComponent(keys.blocked)}`
    : '';



  if (dom.icsBookedUrlEl) dom.icsBookedUrlEl.textContent = bookedUrl || '—';
  if (dom.icsBlockedUrlEl) dom.icsBlockedUrlEl.textContent = blockedUrl || '—';

  if (dom.openIcsBooked) {
    if (bookedUrl) {
      dom.openIcsBooked.setAttribute('href', bookedUrl);
      dom.openIcsBooked.setAttribute('data-ics-url', bookedUrl);
    } else {
      dom.openIcsBooked.removeAttribute('href');
      dom.openIcsBooked.removeAttribute('data-ics-url');
    }
  }

  if (dom.openIcsBlocked) {
    if (blockedUrl) {
      dom.openIcsBlocked.setAttribute('href', blockedUrl);
      dom.openIcsBlocked.setAttribute('data-ics-url', blockedUrl);
    } else {
      dom.openIcsBlocked.removeAttribute('href');
      dom.openIcsBlocked.removeAttribute('data-ics-url');
    }
  }

  // clear previous status when unit/keys change
  if (dom.icsBookedStatusEl) {
    dom.icsBookedStatusEl.textContent = '';
    dom.icsBookedStatusEl.className = 'ics-status';
  }
  if (dom.icsBlockedStatusEl) {
    dom.icsBlockedStatusEl.textContent = '';
    dom.icsBlockedStatusEl.className = 'ics-status';
  }
}

  /**
   * Test ICS URL (booked/blocked) and show status in the UI.
   * kind = "booked" | "blocked"
   */
  async function testIcsUrl(kind) {
    const statusEl =
      kind === 'booked' ? dom.icsBookedStatusEl : dom.icsBlockedStatusEl;
    const openEl =
      kind === 'booked' ? dom.openIcsBooked : dom.openIcsBlocked;

    if (!openEl) return;

    const url = openEl.getAttribute('data-ics-url') || openEl.getAttribute('href') || '';

    if (!url || url === '—') {
      if (statusEl) {
        statusEl.textContent = 'URL ni nastavljen.';
        statusEl.className = 'ics-status error';
      }
      return;
    }

    if (statusEl) {
      statusEl.textContent = 'Preverjam…';
      statusEl.className = 'ics-status pending';
    }

    try {
      const res = await fetch(url, { method: 'GET' });

      if (statusEl) {
        if (res.ok) {
          statusEl.textContent = `Link OK (HTTP ${res.status})`;
          statusEl.className = 'ics-status ok';
        } else {
          statusEl.textContent = `Napaka (HTTP ${res.status})`;
          statusEl.className = 'ics-status error';
        }
      }
    } catch (e) {
      console.warn('[ICS OUT] testIcsUrl error', e);
      if (statusEl) {
        statusEl.textContent = 'Napaka pri povezavi';
        statusEl.className = 'ics-status error';
      }
    }
  }


  function onUnitChange() {
    state.currentUnit = dom.unitSelect?.value || '';
    saveLastUnit(state.currentUnit);

    refreshIcsUrls();

    // ob menjavi enote: osveži kartice, ki so per-unit
    window.CM_INTEGRATIONS?.Channels?.refresh?.();
    window.CM_INTEGRATIONS?.Promo?.load?.();
    window.CM_INTEGRATIONS?.Offers?.load?.();
    window.CM_INTEGRATIONS?.Autopilot?.loadGlobal?.(); // global, ampak lahko pustiš
  }

  /* ---------------- context ---------------- */
  const ctx = {
    CFG,
    state,
    dom,
    helpers: { $, $$, escapeHtml, safeFetchJson },
    getCurrentUnit,
    Units: null,
    Channels: null,
    Diagnostics: null,
    Promo: null,
    Offers: null,
    Autopilot: null,
  };

  window.CM_INTEGRATIONS = ctx;

  /* ---------------- init ---------------- */
  (async () => {
    await loadUnitsList();
    refreshIcsUrls();

    dom.unitSelect?.addEventListener('change', onUnitChange);

    // signal modulom: core je pripravljen
    window.dispatchEvent(new Event('cm-integrations-ready'));
  })();

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-copy-target]');
  if (!btn) return;

  const sel = btn.getAttribute('data-copy-target');
  const el = sel ? document.querySelector(sel) : null;
  if (!el) return;

  const text = (el.value || el.textContent || '').trim();

  if (!text || text === '—') return;

  navigator.clipboard.writeText(text)
    .then(() => {
      btn.textContent = 'Kopirano ✓';
      setTimeout(() => btn.textContent = 'Kopiraj URL', 1200);
    })
    .catch(() => alert('Kopiranje ni uspelo'));
});
document.getElementById('btnRefreshICS')?.addEventListener('click', () => {
  refreshIcsUrls();
});

document.getElementById('openIcsBooked')?.addEventListener('click', (ev) => {
  ev.preventDefault();
  testIcsUrl('booked');
});

document.getElementById('openIcsBlocked')?.addEventListener('click', (ev) => {
  ev.preventDefault();
  testIcsUrl('blocked');
});


})();
