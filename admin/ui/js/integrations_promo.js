/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_promo.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_promo.js
 * Promo codes (GLOBAL) – renders existing promo card DOM.
 */
(() => {
  'use strict';
  const ctx = window.CM_INTEGRATIONS;
  if (!ctx) return;

  const { helpers } = ctx;
  const { escapeHtml } = helpers;

  // DOM (as in old monolith)
  const promoTbody = document.getElementById('promoCodesBody');
  const promoSelectAll = document.getElementById('promoSelectAll');
  const btnAddPromo = document.getElementById('btnAddPromo');
  const btnPromoDeleteSelected = document.getElementById('btnPromoDeleteSelected');
  const btnPromoPublish = document.getElementById('btnPromoPublish'); // ostane kot "ročni save"

  const autoCouponPercentInput   = document.getElementById('autoCouponPercent');
  const autoCouponValidDaysInput = document.getElementById('autoCouponValidDays');
  const autoCouponPrefixInput    = document.getElementById('autoCouponPrefix');
  const autoCouponEnabledInput   = document.getElementById('autoCouponEnabled');


  const promoDetailText = document.getElementById('promoDetailText');
  const promoToggleRaw  = document.getElementById('promoToggleRaw');
  const promoRaw        = document.getElementById('promoRaw');

  const state = {
    data: {
      settings: {},
      codes: [],
    },
    filteredIndexes: [],
    selectedIndex: -1,
  };

  function getAllCodes() {
    const codes = state.data.codes;
    return Array.isArray(codes) ? codes : [];
  }

  function buildFilteredIndexes() {
    const all = getAllCodes();
    state.filteredIndexes = all.map((_, i) => i);
  }

  function renderSettings() {
    let s = state.data.settings;
    if (!s || typeof s !== 'object' || Array.isArray(s)) s = {};

    const percent   = (typeof s.auto_reject_discount_percent === 'number')
      ? s.auto_reject_discount_percent
      : 15;
    const validDays = (typeof s.auto_reject_valid_days === 'number')
      ? s.auto_reject_valid_days
      : 180;
    const prefix    = (typeof s.auto_reject_code_prefix === 'string')
      ? s.auto_reject_code_prefix
      : 'RETRY-';

    // New: master toggle for auto coupon on reject
    const enabled = (typeof s.auto_reject_enabled === 'boolean')
      ? s.auto_reject_enabled
      : true; // default ON

    if (autoCouponPercentInput)   autoCouponPercentInput.value   = String(percent);
    if (autoCouponValidDaysInput) autoCouponValidDaysInput.value = String(validDays);
    if (autoCouponPrefixInput)    autoCouponPrefixInput.value    = prefix;
    if (autoCouponEnabledInput)   autoCouponEnabledInput.checked = enabled;
  }

  function collectSettingsFromForm() {
    if (!state.data.settings || typeof state.data.settings !== 'object' || Array.isArray(state.data.settings)) {
      state.data.settings = {};
    }
    const s = state.data.settings;

    let percent = autoCouponPercentInput ? autoCouponPercentInput.value.trim() : '';
    let valid   = autoCouponValidDaysInput ? autoCouponValidDaysInput.value.trim() : '';
    let prefix  = autoCouponPrefixInput ? autoCouponPrefixInput.value.trim() : '';

    const pNum = parseFloat(percent.replace(',', '.'));
    const vNum = parseInt(valid, 10);

    s.auto_reject_discount_percent = Number.isFinite(pNum) ? pNum : 15;
    s.auto_reject_valid_days       = Number.isFinite(vNum) ? vNum : 180;
    s.auto_reject_code_prefix      = prefix || 'RETRY-';

    // New: on/off toggle for auto coupon
    s.auto_reject_enabled = autoCouponEnabledInput
      ? !!autoCouponEnabledInput.checked
      : true;
  }


  function renderList() {
    if (!promoTbody) return;
    const codes = getAllCodes();

    if (!codes || codes.length === 0) {
      promoTbody.innerHTML = `
        <tr class="muted">
          <td colspan="11">Ni definiranih kuponov za izbrano enoto.</td>
        </tr>
      `;
      return;
    }

    const rows = codes.map((c, idx) => {
      const isSelected = idx === state.selectedIndex;
      const code = c.code || c.id || '';
      const percent = (typeof c.discount_percent === 'number')
        ? c.discount_percent
        : (typeof c.value === 'number' ? c.value : '');

      const validFrom = c.valid_from || '';
      const validTo   = c.valid_to   || '';

      const active = !(c.active === false || c.enabled === false);
      const isGlobal = !c.unit || c.unit === 'GLOBAL';

      return `
        <tr class="${isSelected ? 'sel' : ''}" data-real-index="${idx}">
          <td><input type="checkbox" class="promo-row-select" data-real-index="${idx}"></td>
          <td>${escapeHtml(code)}</td>
          <td>${escapeHtml(c.name || '')}</td>
          <td>${escapeHtml(String(percent ?? ''))}</td>
          <td>${escapeHtml(String(c.min_nights ?? ''))}</td>
          <td>${escapeHtml(String(c.max_nights ?? ''))}</td>
          <td>${escapeHtml(validFrom)}</td>
          <td>${escapeHtml(validTo)}</td>
          <td>${active ? 'DA' : 'NE'}</td>
          <td>${isGlobal ? 'GLOBAL' : escapeHtml(c.unit || '')}</td>
          <td>
            <button type="button"
                    class="btn xs promo-edit-btn"
                    data-real-index="${idx}">
              Uredi
            </button>
            <button type="button"
                    class="btn xs danger promo-delete-btn"
                    data-real-index="${idx}">
              Izbriši
            </button>
          </td>
        </tr>
      `;
    });

    promoTbody.innerHTML = rows.join('');

    promoTbody.querySelectorAll('.promo-edit-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const idx = parseInt(btn.getAttribute('data-real-index') || '-1', 10);
        if (!Number.isNaN(idx)) {
          state.selectedIndex = idx;
          renderList();
          renderDetail();
          openEditor(idx);
        }
      });
    });

    promoTbody.querySelectorAll('.promo-delete-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const idx = parseInt(btn.getAttribute('data-real-index') || '-1', 10);
        if (!Number.isNaN(idx)) {
          if (confirm('Res želiš izbrisati ta kupon?')) {
            deleteSingle(idx);
          }
        }
      });
    });

    promoTbody.querySelectorAll('tr[data-real-index]').forEach(row => {
      row.addEventListener('click', (e) => {
        if (e.target.closest('button, input[type="checkbox"]')) return;
        const idx = parseInt(row.getAttribute('data-real-index') || '-1', 10);
        if (!Number.isNaN(idx)) {
          state.selectedIndex = idx;
          renderList();
          renderDetail();
        }
      });
    });
  }

  function renderDetail() {
    if (!promoDetailText) return;

    const codes = getAllCodes();
    const idx = state.selectedIndex;
    if (idx < 0 || idx >= codes.length) {
      promoDetailText.innerHTML = 'Ni izbranega kupona.';
      if (promoToggleRaw) promoToggleRaw.hidden = true;
      if (promoRaw) promoRaw.hidden = true;
      return;
    }

    const c = codes[idx];
    const code = c.code || c.id || '';
    const percent = (typeof c.discount_percent === 'number')
      ? c.discount_percent
      : (typeof c.value === 'number' ? c.value : '');

    const validFrom = c.valid_from || '';
    const validTo   = c.valid_to   || '';

    const active = !(c.active === false || c.enabled === false);
    const isGlobal = !c.unit || c.unit === 'GLOBAL';

    promoDetailText.innerHTML = `
      <div><strong>Koda:</strong> ${escapeHtml(code)}</div>
      <div><strong>Naziv:</strong> ${escapeHtml(c.name || '')}</div>
      <div><strong>Popust %:</strong> ${escapeHtml(String(percent ?? ''))}</div>
      <div><strong>Min noči:</strong> ${escapeHtml(String(c.min_nights ?? ''))}</div>
      <div><strong>Max noči:</strong> ${escapeHtml(String(c.max_nights ?? ''))}</div>
      <div><strong>Velja:</strong> ${escapeHtml(validFrom)} → ${escapeHtml(validTo)}</div>
      <div><strong>Aktivno:</strong> ${active ? 'DA' : 'NE'}</div>
      <div><strong>Enota / Global:</strong> ${isGlobal ? 'GLOBAL' : escapeHtml(c.unit || '')}</div>
    `;

    if (promoToggleRaw && promoRaw) {
      promoToggleRaw.hidden = false;
      promoRaw.hidden = true;
      promoRaw.textContent = JSON.stringify(c, null, 2);
    }
  }

  function openEditor(realIdx) {
    const codes = getAllCodes();
    if (realIdx < 0 || realIdx >= codes.length) return;
    const c = codes[realIdx] || {};

    const codeVal = c.code || c.id || '';
    const percentVal = (typeof c.discount_percent === 'number')
      ? c.discount_percent
      : (c.value ?? c.discount?.value ?? '');
    const active = !(c.active === false || c.enabled === false);

    ctx.Editor?.open({
      type: 'promo',
      title: 'Uredi kupon',
      meta: `Index: ${realIdx}`,
      obj: c,
      fields: {
        name: c.name || '',
        code: codeVal,
        percent: percentVal,
        from: c.valid_from || '',
        to: c.valid_to || '',
        active
      },
      onSave: (obj) => {
        codes[realIdx] = obj;
        state.data.codes = codes;
        buildFilteredIndexes();
        renderSettings();
        renderList();
        renderDetail();
        save().catch(err => {
          console.error('[Promo] auto-save after edit failed', err);
          alert('Napaka pri shranjevanju kuponov: ' + err.message);
        });
      },
      onDelete: () => {
        codes.splice(realIdx, 1);
        state.data.codes = codes;
        state.selectedIndex = -1;
        buildFilteredIndexes();
        renderSettings();
        renderList();
        renderDetail();
        save().catch(err => {
          console.error('[Promo] auto-save after delete failed', err);
          alert('Napaka pri shranjevanju kuponov: ' + err.message);
        });
      }
    });

    // Promo vedno rabi polje "Koda (za kupon)" -> prikaži ga
    setTimeout(() => {
      const codeInput = document.getElementById('editCode');
      if (!codeInput) return;
      const field = codeInput.closest('.field');
      if (field) field.style.display = '';

      const label = document.querySelector('label[for="editCode"]');
      if (label) label.style.display = '';
    }, 0);


  }

  function addNew() {
    const unit = ctx.getCurrentUnit();
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');

    const newCode = {
      id: '',
      unit: unit || 'GLOBAL',
      name: '',
      description: '',
      code: '',
      discount_percent: 0,
      min_nights: 0,
      max_nights: null,
      valid_from: `${yyyy}-${mm}-${dd}`,
      valid_to: `${yyyy + 1}-${mm}-${dd}`,
      active: true,
      usage_limit: 1,
      used_count: 0
    };

    const all = getAllCodes().slice();
    all.push(newCode);
    state.data.codes = all;
    buildFilteredIndexes();
    state.selectedIndex = all.length - 1;
    renderSettings();
    renderList();
    renderDetail();
    openEditor(state.selectedIndex);
  }

  function deleteSingle(realIdx) {
    const all = getAllCodes();
    if (!Array.isArray(all) || realIdx < 0 || realIdx >= all.length) return;

    all.splice(realIdx, 1);
    state.data.codes = all;
    state.selectedIndex = -1;

    buildFilteredIndexes();
    renderSettings();
    renderList();
    renderDetail();

    save().catch(err => {
      console.error('[Promo] auto-save after delete failed', err);
      alert('Napaka pri shranjevanju kuponov: ' + err.message);
    });
  }

  function deleteSelected() {
    if (!promoTbody) return;
    const codes = getAllCodes();
    if (!codes.length) return;

    const checkboxes = promoTbody.querySelectorAll('.promo-row-select:checked');
    if (!checkboxes.length) {
      alert('Ni izbranih kuponov za brisanje.');
      return;
    }

    if (!confirm('Res želiš izbrisati vse označene kupone?')) return;

    const toDelete = new Set();
    checkboxes.forEach(cb => {
      const idx = parseInt(cb.getAttribute('data-real-index') || '-1', 10);
      if (!Number.isNaN(idx)) toDelete.add(idx);
    });

    const kept = codes.filter((_, idx) => !toDelete.has(idx));
    state.data.codes = kept;
    state.selectedIndex = -1;
    buildFilteredIndexes();
    renderSettings();
    renderList();
    renderDetail();

    save().catch(err => {
      console.error('[Promo] auto-save after bulk delete failed', err);
      alert('Napaka pri shranjevanju kuponov: ' + err.message);
    });
  }

  async function load() {
    const url = ctx.CFG.api?.promoGet;
    if (!url) throw new Error('CFG.api.promoGet missing');

    const res = await helpers.safeFetchJson(url);
    if (!res || !res.ok) {
      console.error('[Promo] load failed', res);
      state.data = { settings: {}, codes: [] };
    } else {
      // podpora za star (res.data.{...}) in nov (res.{...}) format
      const raw = (res.data && typeof res.data === 'object') ? res.data : res;
      let settings = raw.settings;
      let codes = raw.codes;

      if (!settings || typeof settings !== 'object' || Array.isArray(settings)) {
        settings = {};
      }
      if (!Array.isArray(codes)) {
        codes = [];
      }

      state.data = { settings, codes };
    }
    buildFilteredIndexes();
  }

  async function save() {
    collectSettingsFromForm();

    const url = ctx.CFG.api?.promoSave;
    if (!url) throw new Error('CFG.api.promoSave missing');

    const payload = {
      settings: state.data.settings || {},
      codes: getAllCodes(),
    };

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`promo_save failed: HTTP ${res.status} :: ${txt.slice(0, 200)}`);
    }

    const json = await res.json().catch(() => null);
    if (!json || json.ok !== true) {
      throw new Error('promo_save response not OK');
    }
  }

  async function loadAndRender() {
    await load();
    renderSettings();
    renderList();
    renderDetail();

    if (promoRaw) {
      const payload = {
        settings: state.data.settings || {},
        codes: getAllCodes(),
      };
      promoRaw.textContent = JSON.stringify(payload, null, 2);
    }
  }

  function bindUi() {
    btnAddPromo?.addEventListener('click', addNew);
    btnPromoDeleteSelected?.addEventListener('click', deleteSelected);
    btnPromoPublish?.addEventListener('click', () => save().catch(e => alert(e.message)));

    promoSelectAll?.addEventListener('change', () => {
      promoTbody?.querySelectorAll('.promo-row-select')?.forEach(cb => { cb.checked = promoSelectAll.checked; });
    });

    promoToggleRaw?.addEventListener('click', () => {
      if (!promoRaw) return;
      const hidden = promoRaw.hidden;
      promoRaw.hidden = !hidden;
      promoToggleRaw.textContent = hidden ? 'Skrij surovi JSON' : 'Pokaži surovi JSON';
    });
  }

  function onReady() {
    bindUi();
    loadAndRender().catch(err => {
      console.error('[Promo] initial load failed', err);
    });
  }

  if (window.CM_INTEGRATIONS) {
    onReady();
  } else {
    window.addEventListener('cm-integrations-ready', onReady, { once: true });
  }

  ctx.Promo = {
    load: loadAndRender,
    loadAndRender
  };
})();
