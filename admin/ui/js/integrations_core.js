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
 *
 * ICS OUT model:
 *  - legacy/global URLs remain visible: mode=booked / mode=blocked
 *  - if integrations/<UNIT>.json contains connectors.<name>.out,
 *    the ICS export card also shows per-connector URLs:
 *      connector=<name>&mode=booked
 *      connector=<name>&mode=blocked
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
    const raw = el.getAttribute('data-config') || el.dataset.config || '{}';
    return JSON.parse(raw);
  }

  function normalizePublicIcsPath(path) {
    const p = String(path || '').trim();
    if (!p) return '/app/public/api/ics.php';

    // Old Free/Plus admin endpoint is not suitable for external channels because /admin/ may be protected.
    if (p.includes('/app/admin/api/integrations/ics.php')) {
      return '/app/public/api/ics.php';
    }

    return p;
  }

  function connectorEntries(cfg) {
    const root = cfg?.connectors;
    if (!root || typeof root !== 'object') return [];

    return Object.entries(root)
      .filter(([name, c]) => name && c && typeof c === 'object' && c.out && typeof c.out === 'object')
      .map(([name, c]) => ({ name, out: c.out }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }

  function outModeKey(out, mode) {
    const m = out?.[mode];
    if (!m || typeof m !== 'object') return '';
    if (m.enabled === false) return '';
    return String(m.key || '').trim();
  }

  function outEnabled(out) {
    return !(out && out.enabled === false);
  }

  function ensureConnectorBox() {
    let box = document.getElementById('icsConnectorsOutBox');
    if (box) return box;

    const card = document.getElementById('card-ics');
    const body = card?.querySelector('.card-body');
    if (!body) return null;

    box = document.createElement('div');
    box.id = 'icsConnectorsOutBox';
    box.className = 'note small';
    box.style.marginTop = '16px';
    body.appendChild(box);
    return box;
  }

  function shortModeLabel(mode) {
    return mode === 'booked' ? 'Booked only' : 'Booked & Blocked';
  }

  function buildUrl(base, unit, mode, key, connector = '') {
    if (!key) return '';
    const parts = [
      `unit=${encodeURIComponent(unit)}`,
    ];
    if (connector) parts.push(`connector=${encodeURIComponent(connector)}`);
    parts.push(`mode=${encodeURIComponent(mode)}`);
    parts.push(`key=${encodeURIComponent(key)}`);
    return `${base}?${parts.join('&')}`;
  }

  function renderConnectorBox(connectors, base, unit) {
    const box = ensureConnectorBox();
    if (!box) return;

    if (!connectors.length) {
      box.innerHTML = `
        <p class="muted tiny">
          Connector OUT seznam še ni nastavljen. Trenutno delujeta zgornja legacy URL-ja.
        </p>
      `;
      return;
    }

    const rows = [];
    for (const { name, out } of connectors) {
      const label = String(out.label || name);
      const enabled = outEnabled(out);
      const bookedKey = outModeKey(out, 'booked');
      const blockedKey = outModeKey(out, 'blocked');
      const bookedUrl = enabled ? buildUrl(base, unit, 'booked', bookedKey, name) : '';
      const blockedUrl = enabled ? buildUrl(base, unit, 'blocked', blockedKey, name) : '';

      rows.push(`
        <div class="ics-connector-row" style="border-top:1px solid rgba(255,255,255,.12); padding-top:10px; margin-top:10px;">
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:6px;">
            <strong>${escapeHtml(label)}</strong>
            <code>${escapeHtml(name)}</code>
            <span class="pill tiny ${enabled ? 'success' : 'error'}">${enabled ? 'enabled' : 'disabled'}</span>
          </div>

          <div class="row" style="margin:6px 0;">
            <div class="row-col">
              <div class="lbl">${shortModeLabel('booked')}</div>
              <code class="code-url">${bookedUrl ? escapeHtml(bookedUrl) : '—'}</code>
              <small class="ics-status" data-ics-status-for="${escapeHtml(name)}-booked"></small>
            </div>
            <div class="row-actions">
              <button type="button" class="btn small" data-ics-copy-url="${escapeHtml(bookedUrl)}" ${bookedUrl ? '' : 'disabled'}>Kopiraj URL</button>
              <button type="button" class="btn small" data-ics-test-url="${escapeHtml(bookedUrl)}" data-ics-test-status="${escapeHtml(name)}-booked" ${bookedUrl ? '' : 'disabled'}>Testiraj</button>
            </div>
          </div>

          <div class="row" style="margin:6px 0;">
            <div class="row-col">
              <div class="lbl">${shortModeLabel('blocked')}</div>
              <code class="code-url">${blockedUrl ? escapeHtml(blockedUrl) : '—'}</code>
              <small class="ics-status" data-ics-status-for="${escapeHtml(name)}-blocked"></small>
            </div>
            <div class="row-actions">
              <button type="button" class="btn small" data-ics-copy-url="${escapeHtml(blockedUrl)}" ${blockedUrl ? '' : 'disabled'}>Kopiraj URL</button>
              <button type="button" class="btn small" data-ics-test-url="${escapeHtml(blockedUrl)}" data-ics-test-status="${escapeHtml(name)}-blocked" ${blockedUrl ? '' : 'disabled'}>Testiraj</button>
            </div>
          </div>
        </div>
      `);
    }

    box.innerHTML = `
      <hr class="sep" />
      <p><strong>ICS OUT connectorji</strong></p>
      <p class="muted tiny">
        Vsak connector ima svoj par tokenov. Če kanal odstraniš, blokiraš ali rotiraš, ostali kanali ostanejo odprti.
      </p>
      ${rows.join('')}
    `;
  }

  /* ---------------- core state ---------------- */
  const CFG = readConfig();

  const LS_UNIT_KEY = 'cm_integrations_last_unit';
  const state = {
    units: [],
    currentUnit: CFG.unit || '',
    manifest: null,
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
    const manifestUrl = CFG.manifest;
    const data = await safeFetchJson(manifestUrl);

    state.manifest = (data && typeof data === 'object') ? data : null;

    const arr = Array.isArray(data?.units) ? data.units : [];
    state.units = arr
      .map(u => ({
        id: u.id || u.unit || '',
        label: u.alias || u.label || u.name || (u.id || ''),
        raw: u,
      }))
      .filter(u => u.id);

    let desired = state.currentUnit || loadLastUnit();
    if (!desired || !state.units.some(x => x.id === desired)) {
      desired = state.units.length ? state.units[0].id : '';
    }
    state.currentUnit = desired;
    saveLastUnit(desired);

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

  async function loadIcsExportConfig(unit) {
    if (!unit) return { cfg: null, booked: '', blocked: '', connectors: [] };

    try {
      const url = `/app/common/data/json/integrations/${encodeURIComponent(unit)}.json?_=${Date.now()}`;
      const cfg = await safeFetchJson(url);

      const exp = cfg?.export?.ics || {};
      const legacy = cfg?.keys || {};

      return {
        cfg,
        booked: exp.booked?.key || legacy.reservations_out || '',
        blocked: exp.blocked?.key || legacy.calendar_out || '',
        connectors: connectorEntries(cfg),
      };
    } catch (e) {
      console.warn('[ICS OUT] cannot load export config for unit', unit, e);
      return { cfg: null, booked: '', blocked: '', connectors: [] };
    }
  }

  function currentDomain() {
    let domain = '';
    const mf = state.manifest;
    if (mf && typeof mf === 'object') {
      const meta = (mf.meta && typeof mf.meta === 'object') ? mf.meta : null;
      const cand =
        (typeof mf.base_url === 'string' && mf.base_url.trim()) ? mf.base_url.trim() :
        (meta && typeof meta.base_url === 'string' && meta.base_url.trim()) ? meta.base_url.trim() :
        (typeof mf.domain === 'string' && mf.domain.trim()) ? mf.domain.trim() :
        (meta && typeof meta.domain === 'string' && meta.domain.trim()) ? meta.domain.trim() :
        '';
      if (cand) domain = cand.replace(/\/+$/, '');
    }
    if (!domain) domain = 'https://{YOUR_DOMAIN}';
    return domain;
  }

  function currentIcsBasePath() {
    const icsPath = normalizePublicIcsPath(CFG.api?.icsPhp || '/app/public/api/ics.php');
    return icsPath.startsWith('http') ? icsPath : currentDomain() + icsPath;
  }

  function setLegacyLink(kind, url) {
    const isBooked = kind === 'booked';
    const urlEl = isBooked ? dom.icsBookedUrlEl : dom.icsBlockedUrlEl;
    const openEl = isBooked ? dom.openIcsBooked : dom.openIcsBlocked;
    const statusEl = isBooked ? dom.icsBookedStatusEl : dom.icsBlockedStatusEl;

    if (urlEl) urlEl.textContent = url || '—';
    if (openEl) {
      if (url) {
        openEl.setAttribute('href', url);
        openEl.setAttribute('data-ics-url', url);
      } else {
        openEl.removeAttribute('href');
        openEl.removeAttribute('data-ics-url');
      }
    }
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.className = 'ics-status';
    }
  }

  async function refreshIcsUrls() {
    const unit = getCurrentUnit();

    if (!unit) {
      setLegacyLink('booked', '');
      setLegacyLink('blocked', '');
      const box = ensureConnectorBox();
      if (box) box.innerHTML = '';
      return;
    }

    const cfg = await loadIcsExportConfig(unit);
    const basePath = currentIcsBasePath();

    const bookedUrl = buildUrl(basePath, unit, 'booked', cfg.booked);
    const blockedUrl = buildUrl(basePath, unit, 'blocked', cfg.blocked);

    setLegacyLink('booked', bookedUrl);
    setLegacyLink('blocked', blockedUrl);
    renderConnectorBox(cfg.connectors, basePath, unit);
  }

  async function testUrl(url, statusEl) {
    if (!url) {
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
      console.warn('[ICS OUT] testUrl error', e);
      if (statusEl) {
        statusEl.textContent = 'Napaka pri povezavi';
        statusEl.className = 'ics-status error';
      }
    }
  }

  async function testIcsUrl(kind) {
    const statusEl = kind === 'booked' ? dom.icsBookedStatusEl : dom.icsBlockedStatusEl;
    const openEl = kind === 'booked' ? dom.openIcsBooked : dom.openIcsBlocked;
    const url = openEl?.getAttribute('data-ics-url') || openEl?.getAttribute('href') || '';
    await testUrl(url, statusEl);
  }

  function onUnitChange() {
    state.currentUnit = dom.unitSelect?.value || '';
    saveLastUnit(state.currentUnit);

    refreshIcsUrls();

    window.CM_INTEGRATIONS?.Channels?.refresh?.();
    window.CM_INTEGRATIONS?.Promo?.load?.();
    window.CM_INTEGRATIONS?.Offers?.load?.();
    window.CM_INTEGRATIONS?.Autopilot?.loadGlobal?.();
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

    window.dispatchEvent(new Event('cm-integrations-ready'));
  })();

  document.addEventListener('click', (e) => {
    const copyBtn = e.target.closest('[data-copy-target]');
    if (copyBtn) {
      const sel = copyBtn.getAttribute('data-copy-target');
      const el = sel ? document.querySelector(sel) : null;
      const text = (el?.value || el?.textContent || '').trim();
      if (!text || text === '—') return;
      navigator.clipboard.writeText(text)
        .then(() => {
          const old = copyBtn.textContent;
          copyBtn.textContent = 'Kopirano ✓';
          setTimeout(() => copyBtn.textContent = old || 'Kopiraj URL', 1200);
        })
        .catch(() => alert('Kopiranje ni uspelo'));
      return;
    }

    const copyUrlBtn = e.target.closest('[data-ics-copy-url]');
    if (copyUrlBtn) {
      const text = copyUrlBtn.getAttribute('data-ics-copy-url') || '';
      if (!text) return;
      navigator.clipboard.writeText(text)
        .then(() => {
          const old = copyUrlBtn.textContent;
          copyUrlBtn.textContent = 'Kopirano ✓';
          setTimeout(() => copyUrlBtn.textContent = old || 'Kopiraj URL', 1200);
        })
        .catch(() => alert('Kopiranje ni uspelo'));
      return;
    }

    const testBtn = e.target.closest('[data-ics-test-url]');
    if (testBtn) {
      const url = testBtn.getAttribute('data-ics-test-url') || '';
      const statusKey = testBtn.getAttribute('data-ics-test-status') || '';
      const statusEl = statusKey ? document.querySelector(`[data-ics-status-for="${CSS.escape(statusKey)}"]`) : null;
      testUrl(url, statusEl);
    }
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
