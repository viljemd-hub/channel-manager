/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_offers.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_offers.js
 * Special offers (PER UNIT) – admin card for special_offers.json
 */
(() => {
  'use strict';
  const ctx = window.CM_INTEGRATIONS;
  if (!ctx) return;

  const { helpers } = ctx;
  const { escapeHtml } = helpers;

  // DOM
  const offersTbody      = document.getElementById('specialOffersBody');
  const btnAddOffer      = document.getElementById('btnAddOffer');
  const btnOffersPublish = document.getElementById('btnOffersPublish');
  const offerDetailText  = document.getElementById('offerDetailText');
  const offerToggleRaw   = document.getElementById('offerToggleRaw');
  const offerRaw         = document.getElementById('offerRaw');

  const state = {
    perUnit: {},      // { [unit]: Offer[] }
    selectedIndex: -1
  };

  function getUnit() {
    // primarno iz core, fallback na CFG.unit
    if (ctx.getCurrentUnit) {
      const u = ctx.getCurrentUnit();
      if (u) return u;
    }
    if (ctx.CFG && ctx.CFG.unit) return ctx.CFG.unit;
    return '';
  }

  function getUnitOffers(unit) {
    if (!state.perUnit[unit]) {
      state.perUnit[unit] = { offers: [] };
    }
    const u = state.perUnit[unit];
    if (!Array.isArray(u.offers)) u.offers = [];
    return u.offers;
  }

  function setUnitOffers(unit, offers) {
    if (!state.perUnit[unit]) {
      state.perUnit[unit] = { offers: [] };
    }
    state.perUnit[unit].offers = Array.isArray(offers) ? offers : [];
  }

  function summarizeDates(o) {
    const periods = Array.isArray(o.periods) ? o.periods : [];
    const p0 = periods.length > 0 ? periods[0] : null;

    const from =
      (p0 && p0.start) ||
      o.active_from ||
      o.from ||
      '';

    const to =
      (p0 && p0.end) ||
      o.active_to ||
      o.to ||
      '';

    return { from, to };
  }

  function getDiscount(o) {
    if (o.discount && typeof o.discount === 'object') {
      const d = o.discount;
      const value = typeof d.value === 'number' ? d.value : Number(d.value) || 0;
      return {
        type: d.type || 'percent',
        value
      };
    }
    const value = typeof o.discount_percent === 'number'
      ? o.discount_percent
      : Number(o.discount_percent) || 0;
    return {
      type: o.type || 'percent',
      value
    };
  }

  function isActive(o) {
    if (o.enabled === false || o.active === false) return false;
    return true;
  }

  function renderList() {
    if (!offersTbody) return;

    const unit = getUnit();
    if (!unit) {
      offersTbody.innerHTML = `
        <tr>
          <td colspan="7" class="muted small">Ni izbrane enote.</td>
        </tr>
      `;
      if (offerDetailText) offerDetailText.innerHTML = 'Ni izbrane akcije.';
      return;
    }

    const offers = getUnitOffers(unit);
    if (!offers.length) {
      offersTbody.innerHTML = `
        <tr>
          <td colspan="7" class="muted small">Ni akcij za to enoto.</td>
        </tr>
      `;
      if (offerDetailText) offerDetailText.innerHTML = 'Ni izbrane akcije.';
      return;
    }

    const rows = offers.map((o, idx) => {
      const name = o.name || o.title || '';
      const { from, to } = summarizeDates(o);
      const discountObj = getDiscount(o);
      const active = isActive(o);
      const isSelected = idx === state.selectedIndex;

      return `
        <tr class="${isSelected ? 'sel' : ''}" data-index="${idx}">
          <td>${escapeHtml(name)}</td>
          <td>${escapeHtml(from || '—')}</td>
          <td>${escapeHtml(to || '—')}</td>
          <td>${escapeHtml(String(discountObj.value ?? ''))}</td>
          <td>${escapeHtml(discountObj.type || 'percent')}</td>
          <td>${active ? '✓' : '—'}</td>
          <td>
            <button type="button"
                    class="btn xs offer-edit-btn"
                    data-index="${idx}">
              Uredi
            </button>
            <button type="button"
                    class="btn xs ghost offer-del-btn"
                    data-index="${idx}">
              ✕
            </button>
          </td>
        </tr>
      `;
    });

    offersTbody.innerHTML = rows.join('');

    // select row
    offersTbody.querySelectorAll('tr[data-index]').forEach(row => {
      row.addEventListener('click', (e) => {
        if (e.target.closest('button')) return;
        const idx = parseInt(row.getAttribute('data-index') || '-1', 10);
        if (!Number.isNaN(idx)) {
          state.selectedIndex = idx;
          renderList();
          renderDetail();
        }
      });
    });

    // edit
    offersTbody.querySelectorAll('.offer-edit-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const idx = parseInt(btn.getAttribute('data-index') || '-1', 10);
        if (!Number.isNaN(idx)) {
          state.selectedIndex = idx;
          renderList();
          renderDetail();
          openEditor(idx);
        }
      });
    });

    // delete
    offersTbody.querySelectorAll('.offer-del-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const idx = parseInt(btn.getAttribute('data-index') || '-1', 10);
        if (Number.isNaN(idx)) return;
        const unit = getUnit();
        if (!unit) return;
        const offers = getUnitOffers(unit);
        if (idx < 0 || idx >= offers.length) return;

        if (!confirm('Res izbrišem to akcijo?')) return;
        offers.splice(idx, 1);
        state.selectedIndex = -1;
        renderList();
        renderDetail();
        save().catch(err => {
          console.error('[Offers] auto-save after delete failed', err);
          alert('Napaka pri shranjevanju akcij: ' + err.message);
        });
      });
    });
  }

  function renderDetail() {
    if (!offerDetailText) return;

    const unit = getUnit();
    const offers = unit ? getUnitOffers(unit) : [];
    const idx = state.selectedIndex;

    if (!unit || idx < 0 || idx >= offers.length) {
      offerDetailText.innerHTML = 'Ni izbrane akcije.';
      return;
    }

    const o = offers[idx];
    const name = o.name || o.title || '(brez naziva)';
    const { from, to } = summarizeDates(o);
    const discountObj = getDiscount(o);
    const active = isActive(o);

    const id         = o.id || '';
    const minNights  = o.conditions?.min_nights;
    const promoCode  = o.conditions?.promo_code;
    const stackable  = o.conditions?.stackable;
    const periods    = Array.isArray(o.periods) ? o.periods : [];

    const periodsHtml = periods.length
      ? '<ul class="small">' + periods.map(p => {
          const s = p.start || '';
          const e = p.end || '';
          return `<li>${escapeHtml(s)} → ${escapeHtml(e)}</li>`;
        }).join('') + '</ul>'
      : '<span class="muted small">Ni definiranih obdobij.</span>';

    offerDetailText.innerHTML = `
      <h3>${escapeHtml(name)}</h3>
      <p class="small mono">
        ${escapeHtml(from || '—')} → ${escapeHtml(to || '—')}
      </p>
      <table class="table table-compact">
        <tbody>
          ${id ? `<tr><th>ID</th><td class="mono">${escapeHtml(id)}</td></tr>` : ''}
          <tr><th>Popust</th><td>${escapeHtml(String(discountObj.value ?? ''))}</td></tr>
          <tr><th>Tip</th><td>${escapeHtml(discountObj.type || 'percent')}</td></tr>
          <tr><th>Min. noči</th><td>${minNights != null ? escapeHtml(String(minNights)) : '<span class="muted small">ni nastavljeno</span>'}</td></tr>
          <tr><th>Promo koda</th><td>${promoCode ? escapeHtml(String(promoCode)) : '<span class="muted small">ni nastavljeno</span>'}</td></tr>
          <tr><th>Stackable</th><td>${stackable === true ? 'DA' : 'NE'}</td></tr>
          <tr><th>Aktivno</th><td>${active ? 'DA' : 'NE'}</td></tr>
        </tbody>
      </table>
      <h4 class="small">Obdobja</h4>
      ${periodsHtml}
    `;
  }

  function openEditor(idx) {
    const unit = getUnit();
    if (!unit) return;

    const offers = getUnitOffers(unit);
    if (idx < 0 || idx >= offers.length) return;

    const o = offers[idx] || {};
    const initialDiscount = getDiscount(o);
    const { from, to } = summarizeDates(o);
    const minNights = o.conditions?.min_nights;
    const active = isActive(o);

    const editor = ctx.Editor || ctx.JsonEditor;
    editor?.open?.({
      type: 'offer',
      title: 'Uredi akcijo',
      meta: `Enota: ${unit}, index: ${idx}`,
      obj: o, // editor bo ta objekt POSODOBIL
      fields: {
        name: o.name || o.title || '',
        // koda za kupon pri offers ni relevantna, zato je ne uporabljamo
        from: from || '',
        to: to || '',
        percent: initialDiscount.value,
        type: initialDiscount.type || 'percent',
        min_nights: (minNights != null ? String(minNights) : ''),
        active,
        description: o.description || '',
      },
      // tu dobimo NAZAJ cel posodobljen objekt (tudi percent iz forme je že v discount/discount_percent)
      onSave: (obj) => {
        const src = (obj && typeof obj === 'object') ? obj : o;

        const disc = getDiscount(src);               // prebere updated discount (tudi iz discount_percent)
        const dates = summarizeDates(src);           // prebere updated from/to/periods
        const from2 = dates.from || from || '';
        const to2   = dates.to   || to   || '';

        const rawCond = (src.conditions && typeof src.conditions === 'object' && !Array.isArray(src.conditions))
          ? src.conditions
          : {};
        let minN = rawCond.min_nights;
        minN = parseInt(minN, 10);
        if (!Number.isFinite(minN) || minN <= 0) minN = 1;

        let periods = Array.isArray(src.periods) ? src.periods.slice() : [];
        if (!periods.length && from2 && to2) {
          periods = [{ start: from2, end: to2 }];
        } else if (periods.length) {
          periods[0] = {
            ...periods[0],
            start: periods[0].start || from2 || '',
            end:   periods[0].end   || to2   || '',
          };
        }

        const updatedOffer = {
          ...src,
          name: src.name || '',
          description: src.description || '',
          active_from: src.active_from || from2 || '',
          active_to:   src.active_to   || to2   || '',
          discount: {
            type: disc.type || 'percent',
            value: disc.value || 0,
          },
          enabled: src.enabled !== false && src.active !== false,
          active:  src.active !== false && src.enabled !== false,
          conditions: {
            ...rawCond,
            min_nights: minN,
            // promo_code in stackable pustimo takšna, kot sta v JSON (če jih kdaj ročno uporabljaš)
          },
          periods,
        };

        offers[idx] = updatedOffer;

        renderList();
        renderDetail();

        save().catch(err => {
          console.error('[Offers] auto-save after edit failed', err);
          alert('Napaka pri shranjevanju akcij: ' + err.message);
        });
      },
      onDelete: () => {
        offers.splice(idx, 1);
        state.selectedIndex = -1;
        renderList();
        renderDetail();

        save().catch(err => {
          console.error('[Offers] auto-save after delete failed', err);
          alert('Napaka pri shranjevanju akcij: ' + err.message);
        });
      },
    });

    // Offers ne uporabljajo kupon kode -> skrij polje "Koda (za kupon)" v skupnem modalu
    setTimeout(() => {
      const codeInput = document.getElementById('editCode');
      if (!codeInput) return;
      const field = codeInput.closest('.field');
      if (field) field.style.display = 'none';
      const label = document.querySelector('label[for="editCode"]');
      if (label) label.style.display = 'none';
    }, 0);
  }



  function addNew(prefill = {}) {
    const unit = getUnit();
    if (!unit) {
      alert('Ni izbrane enote.');
      return;
    }

    const offers = getUnitOffers(unit);

    const baseFrom = prefill.from || '';
    const baseTo   = prefill.to   || '';

    const now = new Date();
    const ts = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0'),
      String(now.getHours()).padStart(2, '0'),
      String(now.getMinutes()).padStart(2, '0'),
      String(now.getSeconds()).padStart(2, '0'),
    ].join('');

    const newOffer = {
      id: `${unit}-offer-${ts}`,
      name: '',
      description: '',
      active_from: baseFrom,
      active_to:   baseTo,
      periods: (baseFrom && baseTo)
        ? [{ start: baseFrom, end: baseTo }]
        : [],
      conditions: {
        min_nights: 1,
        promo_code: null,
        stackable: false,
      },
      discount: { type: 'percent', value: 0 },
      priority: 10,
      enabled: true,
      active: true,
    };

    const idx = offers.length;
    offers.push(newOffer);
    state.selectedIndex = idx;
    renderList();
    renderDetail();
    openEditor(idx);
  }

  async function loadForCurrentUnit() {
    const unit = getUnit();
    if (!unit) {
      renderList();
      renderDetail();
      return;
    }

    try {
      const baseUrl =
        (ctx.CFG && ctx.CFG.api && ctx.CFG.api.offersGet)
          ? ctx.CFG.api.offersGet
          : '/app/admin/api/offers_get.php';

      const url = `${baseUrl}?unit=${encodeURIComponent(unit)}`;
      const res = await helpers.safeFetchJson(url);

      const offers = Array.isArray(res?.offers)
        ? res.offers
        : Array.isArray(res?.data?.offers)
          ? res.data.offers
          : [];

      setUnitOffers(unit, offers);
      renderList();
      renderDetail();

      if (offerToggleRaw && offerRaw) {
        offerToggleRaw.hidden = false;
        offerRaw.hidden = true;
        offerRaw.textContent = JSON.stringify({ offers }, null, 2);
      }
    } catch (e) {
      console.error('[Offers] loadForCurrentUnit failed', e);
      if (offersTbody) {
        offersTbody.innerHTML = `
          <tr>
            <td colspan="7" class="error small">Napaka pri nalaganju akcij.</td>
          </tr>
        `;
      }
    }
  }

  async function save() {
    const unit = getUnit();
    if (!unit) return;

    const offers = getUnitOffers(unit);
    const baseUrl =
      (ctx.CFG && ctx.CFG.api && ctx.CFG.api.offersSave)
        ? ctx.CFG.api.offersSave
        : '/app/admin/api/offers_save.php';

    const resp = await fetch(baseUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ unit, offers }),
    });

    if (!resp.ok) {
      const txt = await resp.text().catch(() => '');
      throw new Error(`offers_save failed: HTTP ${resp.status} :: ${txt.slice(0, 200)}`);
    }

    const json = await resp.json().catch(() => null);
    if (!json || json.ok !== true) {
      throw new Error('offers_save response not OK');
    }

    if (offerRaw) {
      offerRaw.textContent = JSON.stringify({ offers }, null, 2);
    }
  }

  async function loadAndRender() {
    await loadForCurrentUnit();
  }

  // prefill iz CFG.seed (nastavljeno v integrations.php iz GET parametrov)
  function maybeOpenPrefilledOfferFromSeed() {
    const seed = (ctx.CFG && ctx.CFG.seed) ? ctx.CFG.seed : null;
    if (!seed) return;
    if (seed.open !== 'new_offer') return;

    const from = seed.new_offer_from || seed.from || '';
    const to   = seed.new_offer_to   || seed.to   || '';
    if (!from || !to) return;

    addNew({ from, to });
  }

  // prefill tudi neposredno iz URL ?open=new_offer&from=...&to=...
  function maybeOpenPrefilledOfferFromUrl() {
    try {
      const qs = window.location.search || '';
      if (!qs) return;

      const params = new URLSearchParams(qs);
      const open = params.get('open');
      if (open !== 'new_offer') return;

      const from = params.get('from') || '';
      const to   = params.get('to')   || '';
      if (!from || !to) return;

      // če je v URL tudi unit in se razlikuje, ga poskusimo nastavit
      const unitParam = params.get('unit') || '';
      const currentUnit = getUnit();
      if (unitParam && unitParam !== currentUnit && ctx.setCurrentUnit) {
        ctx.setCurrentUnit(unitParam);
      }

      addNew({ from, to });
    } catch (e) {
      console.error('[Offers] maybeOpenPrefilledOfferFromUrl failed', e);
    }
  }


  function bindUi() {
    btnAddOffer?.addEventListener('click', () => addNew());

    btnOffersPublish?.addEventListener('click', () => {
      save().catch(e => {
        console.error('[Offers] manual save failed', e);
        alert('Napaka pri shranjevanju akcij: ' + e.message);
      });
    });

    offerToggleRaw?.addEventListener('click', () => {
      if (!offerRaw) return;
      const hidden = offerRaw.hidden;
      offerRaw.hidden = !hidden;
      offerToggleRaw.textContent = hidden ? 'Skrij surovi JSON' : 'Pokaži surovi JSON';
    });
  }

  function onReady() {
    bindUi();
    loadAndRender()
       .then(() => {
        // najprej iz CFG.seed (če si ga nastavil v PHP),
        // nato še direktno iz URL-ja
        maybeOpenPrefilledOfferFromSeed();
        maybeOpenPrefilledOfferFromUrl();
      })
      .catch(err => {
        console.error('[Offers] initial load failed', err);
      });
  }

  // isti vzorec kot promo modul
  if (window.CM_INTEGRATIONS) {
    onReady();
  } else {
    window.addEventListener('cm-integrations-ready', onReady, { once: true });
  }

  ctx.Offers = {
    load: loadAndRender,
    loadAndRender,
  };
})();
