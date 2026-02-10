/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_channels.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_channels.js
 * Channels / ICS IN cards — multi-platform (x platforms per unit)
 *
 * Requires:
 *  - window.CM_INTEGRATIONS (from integrations_core.js)
 *  - DOM: #card-channels, #channelsInfo, #channelsTbody
 *
 * Backend:
 *  - CFG.api.integrationsSetInUrl  (POST: unit, platform, ics_url, key, enabled?)
 *  - CFG.api.integrationsPullNow  (GET: unit, platform, key)
 *  - CFG.api.integrationsApplyNow (GET: unit, key, platform?)  // apply per unit; platform optional
 */
(() => {
  'use strict';

  function boot() {
    const ctx = window.CM_INTEGRATIONS;
    if (!ctx) return;

    const { helpers, CFG, getCurrentUnit } = ctx;
    const { $, escapeHtml } = helpers;

    const cardChannels  = $('#card-channels');
    const channelsInfo  = $('#channelsInfo');
    const channelsTbody = $('#channelsTbody');

    function isProdUnit(unit) { return unit === 'A1'; }

    async function loadUnitIntegration(unit) {
      if (!unit) return null;
      const url = `/app/common/data/json/integrations/${encodeURIComponent(unit)}.json?_=${Date.now()}`;
      try {
        const data = await helpers.safeFetchJson(url);
        return (data && typeof data === 'object') ? data : null;
      } catch (e) {
        console.warn('[integrations][channels] no per-unit integrations JSON for', unit, e);
        return null;
      }
    }

    function normalizeRow(platform, cfg) {
      const c = cfg?.connections?.[platform] || {};
      const inCfg = c.in || {};
      const st = c.status || {};

      // podpiramo oba ključa (različne verzije):
      const lastOk = st.last_ok || st.lastOk || null;
      const lastError = st.last_error || st.lastError || st.last_err_msg || null;

      return {
        platform,
        url: (inCfg.ics_url || '').trim(),
        enabled: (inCfg.enabled !== undefined) ? !!inCfg.enabled : false,
        lastOk,
        lastError,
      };
    }

    function describeChannelStatus(row) {
      if (!row.url) return { text: 'ni nastavljen', cls: 'pill neutral' };
      if (row.lastError) return { text: 'zadnja napaka: ' + row.lastError, cls: 'pill error' };
      if (row.lastOk) return { text: 'OK · zadnji pull ' + row.lastOk, cls: 'pill success' };
      return { text: 'pripravljen (URL nastavljen)', cls: 'pill neutral' };
    }

    function buildSimulatorBadge(url) {
      if (!url) return '';
      const lower = url.toLowerCase();
      if (!lower.includes('ics_lab') && !lower.includes('icslab') && !lower.includes('sim1') && !lower.includes('sim2')) return '';
      let label = 'SIM';
      if (lower.includes('sim1')) label = 'SIM1';
      else if (lower.includes('sim2')) label = 'SIM2';
      return `<span class="pill tiny">🔧 ${label}</span>`;
    }

    function collectPlatforms(cfg) {
      const fromJson = Object.keys(cfg?.connections || {}).filter(Boolean);

      // LAB-first seznam: ti boš delal predvsem z lab* connectioni
      const lab = ['lab1', 'lab2', 'lab3', 'lab4', 'lab5'];

      // fallback / future real platforms (ostanejo na voljo)
      const known = ['booking', 'airbnb', 'googlecal', 'ics', 'custom'];

      const set = new Set([...lab, ...known, ...fromJson]);
      return Array.from(set);
    }

    function rowHtml(row) {
      const badge = buildSimulatorBadge(row.url);
      const ds = describeChannelStatus(row);

      return `
        <tr data-platform="${escapeHtml(row.platform)}">
          <td style="white-space:nowrap;">
            <strong>${escapeHtml(row.platform)}</strong> ${badge}
          </td>

          <td style="white-space:nowrap;">
            <label class="small muted" style="display:flex; gap:8px; align-items:center;">
              <input type="checkbox" class="chEnabled" ${row.enabled ? 'checked' : ''}>
              enabled
            </label>
          </td>

          <td style="min-width:320px;">
            <input class="inp mono chUrl" style="width:100%;" placeholder="https://..." value="${escapeHtml(row.url)}">
            <div class="muted small chNote"></div>
          </td>

          <td>
            <span class="chStatus ${ds.cls}">${escapeHtml(ds.text)}</span>
          </td>

          <td style="white-space:nowrap;">
            <button type="button" class="btn small chSave">Save</button>
            <button type="button" class="btn small chPull">Only Pull</button>
            <button type="button" class="btn small chApply">Pull &amp; Apply</button>
            <button type="button" class="btn small chRemove" title="Remove platform from integrations JSON">Remove</button>
          </td>

        </tr>
      `;
    }

    function footerHtml(platforms) {
      const opts = platforms
        .map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`)
        .join('');

      return `
        <tr class="channelsAddRow">
          <td colspan="5">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <span class="muted small">Dodaj platformo:</span>
              <select class="inp mono chAddPlatform" style="min-width:160px;">${opts}</select>
              <button type="button" class="btn small chAddBtn">Add platform</button>
              <span class="muted small">Namig: platforma se zapiše v JSON šele po <strong>Save</strong>.</span>
            </div>
          </td>
        </tr>
      `;
    }

    async function apiSave(unit, platform, url, enabled, noteEl, statusEl) {
      if (!CFG.adminKey) { noteEl.textContent = 'Manjka CFG.adminKey (auth).'; return false; }
      if (!platform) { noteEl.textContent = 'Manjka platform.'; return false; }
      if (!url) { noteEl.textContent = 'URL je prazen.'; return false; }

      const formData = new FormData();
      formData.append('unit', unit);
      formData.append('platform', platform);
      formData.append('ics_url', url);
      formData.append('enabled', enabled ? '1' : '0');
      formData.append('key', CFG.adminKey);

      statusEl.className = 'chStatus pill neutral';
      statusEl.textContent = 'Shranjujem…';
      noteEl.textContent = '';

      try {
        const res = await fetch(CFG.api.integrationsSetInUrl, { method: 'POST', body: formData });
        const data = await res.json().catch(() => ({}));

        if (!data || data.ok === false) {
          statusEl.className = 'chStatus pill error';
          statusEl.textContent = data?.error || 'Shranjevanje ni uspelo';
          noteEl.textContent = JSON.stringify(data, null, 2);
          return false;
        }

        noteEl.textContent = '[SAVE OK]';
        return true;
      } catch (e) {
        statusEl.className = 'chStatus pill error';
        statusEl.textContent = 'Napaka pri klicu API.';
        noteEl.textContent = e.message || String(e);
        return false;
      }
    }

    async function apiPull(unit, platform, noteEl, statusEl) {
      if (!CFG.adminKey) { noteEl.textContent = 'Manjka CFG.adminKey (auth).'; return false; }
      if (!platform) { noteEl.textContent = 'Manjka platform.'; return false; }

      noteEl.textContent = 'Povlečem ICS…';
      statusEl.className = 'chStatus pill neutral';
      statusEl.textContent = 'Pull…';

      const q = new URLSearchParams({ unit, platform, key: CFG.adminKey });

      try {
        const res = await fetch(`${CFG.api.integrationsPullNow}?${q.toString()}`);
        const data = await res.json().catch(() => ({}));

        if (!data || data.ok === false) {
          statusEl.className = 'chStatus pill error';
          statusEl.textContent = 'Pull error';
          noteEl.textContent = JSON.stringify(data, null, 2);
          return false;
        }

        noteEl.textContent = `[PULL OK]\nEvents: ${data.events ?? data.fetched ?? 0}\nPlatform: ${platform}`;
        return true;
      } catch (e) {
        statusEl.className = 'chStatus pill error';
        statusEl.textContent = 'Network error';
        noteEl.textContent = e.message || String(e);
        return false;
      }
    }

    async function apiApply(unit, platform, noteEl, statusEl) {
      if (!CFG.adminKey) { noteEl.textContent = 'Manjka CFG.adminKey (auth).'; return false; }

      noteEl.textContent = 'Apply…';
      statusEl.className = 'chStatus pill neutral';
      statusEl.textContent = 'Apply…';

      // apply je lahko per-unit, ampak platform pošljemo (če endpoint podpira)
      const q = new URLSearchParams({ unit, key: CFG.adminKey, platform });

      try {
        const res = await fetch(`${CFG.api.integrationsApplyNow}?${q.toString()}`);
        const data = await res.json().catch(() => ({}));

        if (!data || data.ok === false) {
          statusEl.className = 'chStatus pill error';
          statusEl.textContent = 'Apply error';
          noteEl.textContent = JSON.stringify(data, null, 2);
          return false;
        }

        noteEl.textContent = `[APPLY OK]\nAdded: ${data.added || 0}\nMerged regen: ${data.merged_regen ?? data.merged ?? 'n/a'}\nPlatform: ${platform}`;
        return true;
      } catch (e) {
        statusEl.className = 'chStatus pill error';
        statusEl.textContent = 'Network error';
        noteEl.textContent = e.message || String(e);
        return false;
      }
    }

async function apiRemove(unit, platform, noteEl, statusEl) {
  if (!CFG.adminKey) { noteEl.textContent = 'Manjka CFG.adminKey (auth).'; return false; }
  if (!platform) { noteEl.textContent = 'Manjka platform.'; return false; }

  const url = (CFG?.api?.integrationsRemovePlatform) || '/app/admin/api/integrations/remove_platform.php';

  noteEl.textContent = 'Removing…';
  statusEl.className = 'chStatus pill neutral';
  statusEl.textContent = 'Remove…';

  const formData = new FormData();
  formData.append('unit', unit);
  formData.append('platform', platform);
  formData.append('key', CFG.adminKey);

  try {
    const res = await fetch(url, { method: 'POST', body: formData });
    const data = await res.json().catch(() => ({}));

    if (!data || data.ok === false) {
      statusEl.className = 'chStatus pill error';
      statusEl.textContent = data?.error || 'Remove failed';
      noteEl.textContent = JSON.stringify(data, null, 2);
      return false;
    }

    noteEl.textContent = data.removed ? '[REMOVE OK]' : '[REMOVE OK] (already absent)';
    return true;
  } catch (e) {
    statusEl.className = 'chStatus pill error';
    statusEl.textContent = 'Network error';
    noteEl.textContent = e.message || String(e);
    return false;
  }
}



    function bindRowEvents(tr, unit) {
      const platform = tr.getAttribute('data-platform') || '';

      const urlInput  = tr.querySelector('.chUrl');
      const enabledEl = tr.querySelector('.chEnabled');
      const noteEl    = tr.querySelector('.chNote');
      const statusEl  = tr.querySelector('.chStatus');

      const btnSave  = tr.querySelector('.chSave');
      const btnPull  = tr.querySelector('.chPull');
      const btnApply = tr.querySelector('.chApply');
      const btnRemove= tr.querySelector('.chRemove');

      btnSave?.addEventListener('click', async () => {
        const url = (urlInput?.value || '').trim();
        const enabled = !!enabledEl?.checked;
        const ok = await apiSave(unit, platform, url, enabled, noteEl, statusEl);
        if (ok) await refresh();
      });

      btnPull?.addEventListener('click', async () => {
        const ok = await apiPull(unit, platform, noteEl, statusEl);
        if (ok) await refresh();
      });

      btnApply?.addEventListener('click', async () => {
        // First pull fresh ICS from the remote platform
        const pulled = await apiPull(unit, platform, noteEl, statusEl);
        if (!pulled) {
          // If pull fails, do not apply stale data
          return;
        }

        // Then apply ICS to occupancy (this will also clean stale ICS rows)
        const applied = await apiApply(unit, platform, noteEl, statusEl);
        if (applied) {
          await refresh();
        }
      });


      btnRemove?.addEventListener('click', async () => {
        if (!confirm(`Remove platform "${platform}" from ${unit} integrations JSON?\n\nThis deletes connections.${platform}.`)) return;
        const ok = await apiRemove(unit, platform, noteEl, statusEl);
        if (ok) await refresh();
      });


    }

    function bindFooterEvents(tfootTr, unit, platformsExisting) {
      const sel = tfootTr.querySelector('.chAddPlatform');
      const btn = tfootTr.querySelector('.chAddBtn');

      btn?.addEventListener('click', () => {
        const p = (sel?.value || '').trim();
        if (!p) return;

        if (platformsExisting.has(p)) {
          alert('Platforma je že prisotna.');
          return;
        }

        // Insert empty row (becomes real on Save)
        const wrap = document.createElement('tbody');
        wrap.innerHTML = rowHtml({ platform: p, url: '', enabled: false, lastOk: null, lastError: null }).trim();
        const tr = wrap.firstElementChild;

        tfootTr.parentElement?.insertBefore(tr, tfootTr);
        bindRowEvents(tr, unit);
        platformsExisting.add(p);
      });
    }

    async function refresh() {
      if (!cardChannels || !channelsTbody || !channelsInfo) return;

      const unit = getCurrentUnit();
      channelsTbody.innerHTML = '';
      channelsInfo.textContent = '';

      if (!unit) {
        channelsInfo.textContent = 'Najprej izberi enoto zgoraj levo.';
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.textContent = 'Ni izbrane enote.';
        tr.appendChild(td);
        channelsTbody.appendChild(tr);
        return;
      }

      if (isProdUnit(unit)) {
        channelsInfo.innerHTML =
          'Enota <strong>A1</strong> je <strong>produkcijska</strong>. ' +
          'Za LAB testiranje uporabi <code>A2</code>/<code>S1</code>/<code>X1</code>.';
      } else {
        channelsInfo.textContent = `Channels / ICS IN (multi-platform) · enota ${unit}`;
      }

      // endpoint sanity
      if (!CFG?.api?.integrationsSetInUrl || !CFG?.api?.integrationsPullNow || !CFG?.api?.integrationsApplyNow) {
        channelsInfo.textContent = 'CFG.api.* za integrations (set_in_url / pull_now / apply) manjka.';
        return;
      }

      const cfg = await loadUnitIntegration(unit) || {};
      const platforms = collectPlatforms(cfg);

      const present = new Set();

      for (const p of platforms) {
        const row = normalizeRow(p, cfg);

        const existsInJson = !!(cfg?.connections && Object.prototype.hasOwnProperty.call(cfg.connections, p));
        if (!existsInJson && !row.url && !row.enabled) continue;

        present.add(p);

        const wrap = document.createElement('tbody');
        wrap.innerHTML = rowHtml(row).trim();
        const tr = wrap.firstElementChild;

        channelsTbody.appendChild(tr);
        bindRowEvents(tr, unit);
      }

      // footer add row (always visible)
      const addChoices = ['lab1', 'lab2', 'lab3', 'lab4', 'lab5', 'booking', 'airbnb', 'custom', 'ics', 'googlecal'];
      const wrap2 = document.createElement('tbody');
      wrap2.innerHTML = footerHtml(addChoices).trim();
      const footerTr = wrap2.firstElementChild;
      channelsTbody.appendChild(footerTr);

      bindFooterEvents(footerTr, unit, present);
    }

    ctx.Channels = { refresh };
    refresh().catch(console.error);
  }

  if (window.CM_INTEGRATIONS) boot();
  else window.addEventListener('cm-integrations-ready', boot, { once: true });
})();
