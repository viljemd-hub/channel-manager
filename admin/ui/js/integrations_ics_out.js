/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_ics_out.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 */
(() => {
  'use strict';

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function slug(v) {
    return String(v || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_-]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 64);
  }

  function init() {
    const ctx = window.CM_INTEGRATIONS;
    if (!ctx || ctx.IcsOut) return;

    const CFG = ctx.CFG || {};
    const getCurrentUnit = ctx.getCurrentUnit;
    const safeFetchJson = ctx.helpers?.safeFetchJson;
    const card = document.getElementById('card-ics');
    const body = card?.querySelector('.card-body');
    if (!card || !body || !getCurrentUnit || !safeFetchJson) return;

    function domain() {
      const mf = ctx.state?.manifest || {};
      const meta = (mf.meta && typeof mf.meta === 'object') ? mf.meta : {};
      const raw = mf.base_url || meta.base_url || mf.domain || meta.domain || 'https://{YOUR_DOMAIN}';
      return String(raw).replace(/\/+$/, '');
    }

    function icsBase() {
      let p = CFG.api?.icsPhp || '/app/public/api/ics.php';
      if (p.includes('/app/admin/api/integrations/ics.php')) p = '/app/public/api/ics.php';
      return p.startsWith('http') ? p : domain() + p;
    }

    function apiUrl() {
      return CFG.api?.connectorOutUpdate || '/app/admin/api/integrations/connector_out_update.php';
    }

    function buildUrl(unit, connector, mode, key) {
      if (!key) return '';
      return `${icsBase()}?unit=${encodeURIComponent(unit)}&connector=${encodeURIComponent(connector)}&mode=${encodeURIComponent(mode)}&key=${encodeURIComponent(key)}`;
    }

    function modeKey(out, mode) {
      const m = out?.[mode];
      if (!m || typeof m !== 'object' || m.enabled === false) return '';
      return String(m.key || '').trim();
    }

    function enabled(out) {
      return !(out && out.enabled === false);
    }

    function statusEl(id) {
      const safe = (window.CSS && CSS.escape) ? CSS.escape(id) : id.replace(/[^a-zA-Z0-9_-]/g, '');
      return document.querySelector(`[data-ics-out-status="${safe}"]`);
    }

    function setStatus(id, text, cls) {
      const el = statusEl(id);
      if (!el) return;
      el.textContent = text || '';
      el.className = `ics-status ${cls || ''}`.trim();
    }

    async function post(action, connector, extra = {}) {
      const params = new URLSearchParams();
      params.set('unit', getCurrentUnit());
      params.set('key', CFG.adminKey || '');
      params.set('action', action);
      params.set('connector', connector);
      Object.entries(extra).forEach(([k, v]) => params.set(k, String(v)));

      const res = await fetch(apiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params,
      });
      const text = await res.text();
      let json;
      try { json = JSON.parse(text); } catch { json = { ok: false, error: text || `HTTP ${res.status}` }; }
      if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
      return json;
    }

    async function loadCfg() {
      const unit = getCurrentUnit();
      if (!unit) return { unit: '', connectors: [] };
      const cfg = await safeFetchJson(`/app/common/data/json/integrations/${encodeURIComponent(unit)}.json?_=${Date.now()}`);
      const root = cfg?.connectors && typeof cfg.connectors === 'object' ? cfg.connectors : {};
      const connectors = Object.entries(root)
        .filter(([name, c]) => name && c && typeof c === 'object' && c.out && typeof c.out === 'object')
        .map(([name, c]) => ({ name, out: c.out }))
        .sort((a, b) => String(a.out.label || a.name).localeCompare(String(b.out.label || b.name)));
      return { unit, connectors };
    }

    function modeRow(unit, connector, out, mode) {
      const key = modeKey(out, mode);
      const url = enabled(out) ? buildUrl(unit, connector, mode, key) : '';
      const label = mode === 'booked' ? 'Booked only' : 'Booked & Blocked';
      const help = mode === 'booked' ? 'samo potrjene rezervacije' : 'rezervacije + CM bloki + cleaning dnevi';
      const sid = `${connector}-${mode}`;
      return `
        <div class="row">
          <div class="row-col">
            <div class="lbl">${label}</div>
            <code class="code-url">${url ? esc(url) : '—'}</code>
            <p class="help small">${esc(help)}</p>
            <small class="ics-status" data-ics-out-status="${esc(sid)}"></small>
          </div>
          <div class="row-actions">
            <button type="button" class="btn small" data-ics-out-copy="${esc(url)}" ${url ? '' : 'disabled'}>Kopiraj URL</button>
            <button type="button" class="btn small" data-ics-out-test="${esc(url)}" data-status="${esc(sid)}" ${url ? '' : 'disabled'}>Testiraj</button>
          </div>
        </div>`;
    }

    async function render() {
      body.innerHTML = '<p class="muted small">Nalaganje ICS OUT connectorjev…</p>';
      const { unit, connectors } = await loadCfg();

      const intro = `
        <p class="muted small">
          <strong>Connector / platforma</strong> je zunanji sistem ali koledar, ki bere zasedenost iz CM.
          Vsaka platforma ima svoje URL-je in svoje tokene. Če eno povezavo ustaviš, izbrišeš ali rotiraš,
          ostale platforme ostanejo odprte.
        </p>
        <p class="muted tiny">
          <strong>Booked only</strong> = samo potrjene rezervacije.<br>
          <strong>Booked &amp; Blocked</strong> = rezervacije + CM bloki + cleaning dnevi.
        </p>
        <div class="row-actions" style="justify-content:flex-start; margin:10px 0 14px;">
          <button type="button" class="btn small primary" data-ics-out-add>+ Dodaj platformo / connector</button>
        </div>`;

      if (!unit) {
        body.innerHTML = intro + '<div class="note small">Izberi enoto.</div>';
        return;
      }
      if (!connectors.length) {
        body.innerHTML = intro + '<div class="note small">Ni nastavljenih ICS OUT connectorjev. Dodaj Booking, Airbnb, Google Calendar ali custom platformo.</div>';
        return;
      }

      const html = connectors.map(({ name, out }) => {
        const label = String(out.label || name);
        const isOn = enabled(out);
        return `
          <div class="ics-connector-row" style="border-top:1px solid rgba(255,255,255,.12); padding-top:12px; margin-top:12px;">
            <div style="display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-bottom:8px;">
              <div>
                <strong>${esc(label)}</strong>
                <span class="pill tiny ${isOn ? 'success' : 'error'}" style="margin-left:8px;">${isOn ? 'enabled' : 'disabled'}</span>
                <div class="muted tiny">Platform connector</div>
              </div>
              <div class="row-actions">
                <button type="button" class="btn small" data-ics-out-toggle="${esc(name)}" data-enabled="${isOn ? '0' : '1'}">${isOn ? 'Ustavi' : 'Omogoči'}</button>
                <button type="button" class="btn small" data-ics-out-rotate="${esc(name)}">Rotiraj tokene</button>
                <button type="button" class="btn small danger" data-ics-out-delete="${esc(name)}">Izbriši</button>
              </div>
            </div>
            <details class="muted tiny"><summary>Tehnični podatek</summary>Connector key: <code>${esc(name)}</code></details>
            ${modeRow(unit, name, out, 'booked')}
            ${modeRow(unit, name, out, 'blocked')}
          </div>`;
      }).join('');

      body.innerHTML = intro + html;
    }

    async function test(url, id) {
      if (!url) { setStatus(id, 'URL ni nastavljen.', 'error'); return; }
      setStatus(id, 'Preverjam…', 'pending');
      try {
        const res = await fetch(url, { method: 'GET' });
        setStatus(id, res.ok ? `Link OK (HTTP ${res.status})` : `Napaka (HTTP ${res.status})`, res.ok ? 'ok' : 'error');
      } catch (e) {
        setStatus(id, 'Napaka pri povezavi', 'error');
      }
    }

    card.addEventListener('click', async (ev) => {
      const add = ev.target.closest('[data-ics-out-add]');
      if (add) {
        const raw = window.prompt('Ime platforme / connector key\nPrimeri: booking, airbnb, googlecal, custom');
        const c = slug(raw);
        if (!c) return;
        const defaultLabel = c.replace(/[-_]+/g, ' ').replace(/\b\w/g, ch => ch.toUpperCase());
        const label = window.prompt('Prikazno ime platforme', defaultLabel) || defaultLabel;
        try { await post('add', c, { label }); await render(); } catch (e) { alert('Dodajanje ni uspelo: ' + e.message); }
        return;
      }

      const tog = ev.target.closest('[data-ics-out-toggle]');
      if (tog) {
        try { await post('toggle', tog.dataset.icsOutToggle, { enabled: tog.dataset.enabled }); await render(); } catch (e) { alert('Sprememba ni uspela: ' + e.message); }
        return;
      }

      const rot = ev.target.closest('[data-ics-out-rotate]');
      if (rot) {
        if (!window.confirm('Rotiram tokene samo za ta connector. Stari URL-ji za to platformo ne bodo več delovali. Nadaljujem?')) return;
        try { await post('rotate', rot.dataset.icsOutRotate); await render(); } catch (e) { alert('Rotacija ni uspela: ' + e.message); }
        return;
      }

      const del = ev.target.closest('[data-ics-out-delete]');
      if (del) {
        if (!window.confirm('Trajno izbrišem ta OUT connector? To ne izbriše ICS IN povezave.')) return;
        try { await post('delete', del.dataset.icsOutDelete); await render(); } catch (e) { alert('Brisanje ni uspelo: ' + e.message); }
        return;
      }

      const copy = ev.target.closest('[data-ics-out-copy]');
      if (copy) {
        const url = copy.dataset.icsOutCopy || '';
        if (!url) return;
        navigator.clipboard.writeText(url).then(() => {
          const old = copy.textContent;
          copy.textContent = 'Kopirano ✓';
          setTimeout(() => copy.textContent = old || 'Kopiraj URL', 1200);
        }).catch(() => alert('Kopiranje ni uspelo'));
        return;
      }

      const t = ev.target.closest('[data-ics-out-test]');
      if (t) test(t.dataset.icsOutTest || '', t.dataset.status || '');
    });

    document.getElementById('btnRefreshICS')?.addEventListener('click', () => render());

    ctx.IcsOut = { render };
    render();
  }

  if (window.CM_INTEGRATIONS) init();
  else window.addEventListener('cm-integrations-ready', init, { once: true });
})();
