/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_units.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * /app/admin/ui/js/integrations_units.js
 * Units management (manifest.json + add_unit.php + save_unit_meta.php)
 */
(() => {
  'use strict';

  function boot() {
    const ctx = window.CM_INTEGRATIONS;
    if (!ctx) return;

    const { CFG, state, dom, helpers } = ctx;
    const { $, escapeHtml } = helpers;

    const diagOut = document.getElementById('diagOut');
    const baseUrlInput  = document.getElementById('cmBaseUrl');
    const btnSaveBaseUrl = document.getElementById('btnSaveBaseUrl');
    // null = ADD mode, 'A1' = EDIT mode
    let editingUnitId = null;

    // ---------- counters ----------
    function renderSummary() {
      const arr = state.units || [];
      const totalEl = document.getElementById('unitsTotal');
      const activeEl = document.getElementById('unitsActive');

      const total = arr.length;
      const active = arr.filter(u => (u.raw && u.raw.active !== false)).length;

      if (totalEl) totalEl.textContent = `${total} enot`;
      if (activeEl) activeEl.textContent = `${active} aktivnih`;
    }

    // ---------- Units table ----------
    function renderUnitsTable() {
      if (!dom.unitsListBox) return;

      const arr = state.units || [];
      renderSummary();

      if (!arr.length) {
        dom.unitsListBox.innerHTML = '<div class="muted">Ni enot.</div>';
        return;
      }

      let html = `
        <table class="table-units">
          <thead>
            <tr>
              <th>ID</th>
              <th>Label</th>
              <th>Property</th>
              <th>Owner</th>
              <th>Active</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
      `;

      for (const u of arr) {
        const id = escapeHtml(u.id || '');
        const label = escapeHtml(u.label || u.id || '');
        const prop = escapeHtml(u.raw?.property_id || '');
        const owner = escapeHtml(u.raw?.owner || '');
	let activeX = '✓';
	if (u.raw?.active === false) activeX = '—';
	else if (u.raw?.on_hold === true) activeX = '⏸'; // on hold


        html += `
          <tr data-unit-id="${id}">
            <td>${id}</td>
            <td>${label}</td>
            <td>${prop}</td>
            <td>${owner}</td>
	    <td class="unit-active-cell" data-unit-id="${id}">${activeX}</td>

            <td style="text-align:right; white-space:nowrap;">
              <button type="button" class="btn small unit-edit" data-unit-id="${id}">Edit</button>
              <button type="button" class="btn small unit-ap" data-unit-id="${id}">Autopilot</button>
              <button type="button" class="btn small danger unit-delete" data-unit-id="${id}">Del</button>
            </td>
          </tr>
        `;
      }

      html += '</tbody></table>';
      dom.unitsListBox.innerHTML = html;
    }

    // ---------- API ops ----------
    async function deleteUnitById(id) {
      if (!id) return;
      const ok = window.confirm(`Delete unit "${id}"?\nThis cannot be undone.`);
      if (!ok) return;

      try {
        const res = await helpers.safeFetchJson('/app/admin/api/delete_unit.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        });
        if (diagOut) diagOut.textContent = `Unit deleted: ${JSON.stringify(res)}`;
        await ctx.reloadUnits?.();
      } catch (e) {
        console.error(e);
        if (diagOut) diagOut.textContent = `Napaka pri brisanju enote: ${e.message}`;
        alert('Delete failed.');
      }
    }

    async function saveBaseUrl() {
      if (!baseUrlInput) return;

      let val = (baseUrlInput.value || '').trim();

      // Default for Free/Plus dev on GN7
      if (!val) {
        val = 'http://localhost';
        baseUrlInput.value = val;
      }

      // Ensure protocol is present
      if (!/^https?:\/\//i.test(val)) {
        val = 'https://' + val.replace(/^\/+/, '');
        baseUrlInput.value = val;
      }

      try {
        if (diagOut) diagOut.textContent = 'Shranjujem domeno…';

        const res = await helpers.safeFetchJson('/app/admin/api/manifest_set_base_url.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ base_url: val }),
        });

        if (!res || res.ok === false) {
          console.error('[units] saveBaseUrl failed', res);
          const msg = res?.message || res?.error || 'save_base_url_failed';
          if (diagOut) diagOut.textContent = `❌ Napaka pri shranjevanju domene: ${msg}`;
          alert('Napaka pri shranjevanju domene.\n\n' + msg);
          return;
        }

        if (diagOut) diagOut.textContent = `✅ Domena shranjena: ${val}`;

        // Reload units + base_url from manifest
        if (ctx.reloadUnits) {
          await ctx.reloadUnits();
        }
      } catch (err) {
        console.error('[units] saveBaseUrl error', err);
        if (diagOut) diagOut.textContent =
          `❌ Napaka pri shranjevanju domene: ${err?.message || err}`;
        alert('Napaka pri shranjevanju domene.\n\n' + (err?.message || err));
      }
    }


    // ---------- Modal wiring (IDs from integrations.php) ----------
    const btnAddUnitTop  = $('#btnAddUnit');
    const btnAddUnitCard = $('#btnAddUnitOpen');
    const btnRefreshUnits = $('#btnRefreshUnits');

    const dlgAddUnit     = $('#dlgAddUnit');
    const formAddUnit    = $('#formAddUnit');
    const btnCancelAdd   = $('#btnCancelAdd');

    const inId       = $('#newUnitId');
    const inLabel    = $('#newUnitLabel');
    const inTpl      = $('#addUnitTemplate');

    const inProp     = $('#newPropertyId');
    const inOwner    = $('#newOwner');
    const inMonths   = $('#newMonthsAhead');
    const inCleanB   = $('#unit-clean-before');
    const inCleanA   = $('#unit-clean-after');
    const inActive   = $('#newUnitActive');
    const inPublic   = $('#newUnitPublic');
    const inOnHold   = $('#newUnitOnHold');
    inOnHold?.addEventListener('change', syncOnHoldToPublicUI);
    const txtUnitPublic = document.getElementById('txtUnitPublic');


    const inMinNights = $('#add-booking-min-nights');
    const inCleaning  = $('#add-booking-cleaning-fee');
    const inWeeklyTh  = $('#add-booking-weekly-threshold');
    const inWeeklyPct = $('#add-booking-weekly-discount');
    const inLongTh    = $('#add-booking-long-threshold');
    const inLongPct   = $('#add-booking-long-discount');


    const duEnabled = $('#add-dayuse-enabled');
    const duFrom    = $('#add-dayuse-from');
    const duTo      = $('#add-dayuse-to');
    const duMaxP    = $('#add-dayuse-max-persons');
    const duMaxDays = $('#add-dayuse-max-days');
    const duPriceP  = $('#add-dayuse-price-person');

    const capGuests = $('#add-cap-max-guests');
    const capBeds   = $('#add-cap-max-beds');
    const capAdults = $('#add-cap-max-adults');
    const capBaby   = $('#add-cap-allow-baby');


async function loadAndFillSiteSettings(unitId) {
  // ✅ uporabi tvoj backend (normalizira legacy ključe + doda defaults)
  const url = `/app/admin/api/unit_settings_get.php?unit=${encodeURIComponent(unitId)}`;

  const res = await helpers.safeFetchJson(url, { cache: 'no-store' });
  if (!res || res.ok === false) throw new Error(res?.error || 'unit_settings_get_failed');

  const ss = res.settings || {};   // <-- KLJUČNI FIX

  // booking
  if (inMinNights) inMinNights.value = ss.booking?.min_nights ?? '';
  if (inCleaning)  inCleaning.value  = ss.booking?.cleaning_fee_eur ?? '';
  // weekly / long stay popusti (top-level ključi)
  if (inWeeklyTh)  inWeeklyTh.value  = ss.weekly_threshold ?? '';
  if (inWeeklyPct) inWeeklyPct.value = ss.weekly_discount_pct ?? '';
  if (inLongTh)    inLongTh.value    = ss.long_threshold ?? '';
  if (inLongPct)   inLongPct.value   = ss.long_discount_pct ?? '';


  // auto_block / cleaning days
  if (inCleanB) inCleanB.checked = !!ss.auto_block?.before_arrival;
  if (inCleanA) inCleanA.checked = !!ss.auto_block?.after_departure;

  // day_use
  if (duEnabled) duEnabled.checked = !!ss.day_use?.enabled;
  if (duFrom)    duFrom.value      = ss.day_use?.from ?? '';
  if (duTo)      duTo.value        = ss.day_use?.to ?? '';
  if (duMaxP)    duMaxP.value      = ss.day_use?.max_persons ?? '';
  if (duMaxDays) duMaxDays.value   = ss.day_use?.max_days_ahead ?? '';
  if (duPriceP)  duPriceP.value    = ss.day_use?.day_price_person ?? '';

  // capacity (če obstaja)
  if (capGuests) capGuests.value = ss.capacity?.max_guests ?? '';
  if (capBeds)   capBeds.value   = ss.capacity?.max_beds ?? '';
  if (capAdults) capAdults.value = ss.capacity?.min_adults ?? '';
  if (capBaby)   capBaby.checked = !!ss.capacity?.allow_baby_bed;

  return ss;
}


async function saveSiteSettingsFromModal(unitId) {
  const payload = { unit: unitId };

  // month_render
  if (inMonths && inMonths.value !== '') {
    const m = Number(inMonths.value);
    if (!Number.isNaN(m)) payload.month_render = m;
  }

  // booking.min_nights
  if (inMinNights && inMinNights.value !== '') {
    const v = Number(inMinNights.value);
    if (!Number.isNaN(v)) payload.booking_min_nights = v;
  }

  // day_use
  if (duEnabled) {
    payload.day_use_enabled = !!duEnabled.checked;
  }
  if (duFrom && duFrom.value) {
    payload.day_use_from = duFrom.value;
  }
  if (duTo && duTo.value) {
    payload.day_use_to = duTo.value;
  }
  if (duMaxP && duMaxP.value !== '') {
    const v = Number(duMaxP.value);
    if (!Number.isNaN(v)) payload.day_use_max_persons = v;
  }
  if (duPriceP && duPriceP.value !== '') {
    const v = Number(duPriceP.value);
    if (!Number.isNaN(v)) payload.day_use_price_person = v;
  }
  if (duMaxDays && duMaxDays.value !== '') {
    const v = Number(duMaxDays.value);
    if (!Number.isNaN(v)) payload.day_use_max_days_ahead = v;
  }

  // capacity
  const cap = {};
  let hasCap = false;

  if (capGuests && capGuests.value !== '') {
    const v = Number(capGuests.value);
    if (!Number.isNaN(v)) {
      cap.max_guests = v;
      hasCap = true;
    }
  }
  if (capBeds && capBeds.value !== '') {
    const v = Number(capBeds.value);
    if (!Number.isNaN(v)) {
      cap.max_beds = v;
      hasCap = true;
    }
  }
  if (capAdults && capAdults.value !== '') {
    const v = Number(capAdults.value);
    if (!Number.isNaN(v)) {
      // še vedno shranjujemo pod min_adults (kompatibilnost z ostalim delom sistema)
      cap.min_adults = v;
      hasCap = true;
    }
  }
  if (capBaby) {
    cap.allow_baby_bed = !!capBaby.checked;
    hasCap = true;
  }

  if (hasCap) {
    payload.capacity = cap;
  }

  // Če ni ničesar razen unit, ne kličemo API-ja
  if (Object.keys(payload).length <= 1) {
    return;
  }

  try {
    const res = await helpers.safeFetchJson('/app/admin/api/unit_settings_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    if (!res || res.ok === false) {
      console.warn('[units] unit_settings_save failed', res);
      if (diagOut) diagOut.textContent = `⚠️ unit_settings_save: ${res?.error || 'unknown_error'}`;
    }
  } catch (err) {
    console.error('[units] unit_settings_save error', err);
    setStatus(`❌ Napaka: ${err.message || 'network_error'}`);
    if (diagOut) diagOut.textContent = `⚠️ unit_settings_save: ${err.message}`;
  }
}


    // status line in modal
    let addStatus = $('#addUnitStatus');
    if (!addStatus && formAddUnit) {
      addStatus = document.createElement('div');
      addStatus.id = 'addUnitStatus';
      addStatus.className = 'muted small';
      addStatus.style.marginTop = '8px';
      formAddUnit.appendChild(addStatus);
    }
    const setStatus = (s) => { if (addStatus) addStatus.textContent = s || ''; };

    function closeModalReset() {
      editingUnitId = null;
      setStatus('');
      try { dlgAddUnit?.close(); } catch(_) {}
    }




function syncOnHoldToPublicUI() {
  if (!inOnHold || !inPublic) return;

  if (inOnHold.checked) {
    inPublic.checked = false;
    inPublic.disabled = true;
    if (txtUnitPublic) txtUnitPublic.textContent = 'Public (visible) — disabled by ON HOLD';
  } else {
    inPublic.disabled = false;
    if (txtUnitPublic) txtUnitPublic.textContent = 'Public (visible)';
  }
}


    function openAddUnit() {
      if (!dlgAddUnit) return;

      // ADD način: nov unit, brez site_settings fetch-a
      editingUnitId = null;
      setStatus('');

      // osnovni podatki
      if (inId) {
        inId.disabled = false;
        inId.value = '';
      }
      if (inLabel) inLabel.value = '';
      if (inTpl) inTpl.value = (inTpl.value || 'A2');

      if (inProp)  inProp.value  = 'HOME';
      if (inOwner) inOwner.value = '';
      if (inMonths) inMonths.value = '12';

      // cleaning toggle (auto_block) – nov unit = vse na OFF
      if (inCleanB) inCleanB.checked = false;
      if (inCleanA) inCleanA.checked = false;

      // status / vidnost
      if (inActive) inActive.checked = true;
      if (inPublic) inPublic.checked = true;
      if (inOnHold) inOnHold.checked = false;
      syncOnHoldToPublicUI();

      // booking pravila (min nights + cleaning fee)
      if (inMinNights) inMinNights.value = '';
      if (inCleaning)  inCleaning.value  = '';
      if (inWeeklyTh)  inWeeklyTh.value  = '';
      if (inWeeklyPct) inWeeklyPct.value = '';
      if (inLongTh)    inLongTh.value    = '';
      if (inLongPct)   inLongPct.value   = '';

      // day_use defaults
      if (duEnabled) duEnabled.checked = false;
      if (duFrom)    duFrom.value = '';
      if (duTo)      duTo.value   = '';
      if (duMaxP)    duMaxP.value = '';
      if (duMaxDays) duMaxDays.value = '';
      if (duPriceP)  duPriceP.value  = '';

      // capacity defaults
      if (capGuests) capGuests.value = '';
      if (capBeds)   capBeds.value   = '';
      if (capAdults) capAdults.value = '';
      if (capBaby)   capBaby.checked = false;

      dlgAddUnit.showModal();
      inId?.focus?.();
    }

    function openEditUnit(id) {
      if (!dlgAddUnit) return;
      const u = (state.units || []).find(x => x.id === id);
      if (!u) return;

      editingUnitId = id;
      setStatus('');

      // osnovni podatki
      if (inId) {
        inId.value = id;
        inId.disabled = true;
      }
      if (inLabel) {
        inLabel.value = (u.raw?.alias || u.raw?.label || u.raw?.name || u.label || id);
      }

      if (inTpl) inTpl.value = (inTpl.value || 'A2'); // template se pri edit ne uporablja

      if (inProp)  inProp.value  = (u.raw?.property_id || 'HOME');
      if (inOwner) inOwner.value = (u.raw?.owner || '');
      if (inMonths) inMonths.value = String(u.raw?.months_ahead ?? 12);

      // legacy cleaning flagi (če še kje obstajajo v manifestu) – potem jih prepiše site_settings
      if (inCleanB) inCleanB.checked = !!(u.raw?.clean_before);
      if (inCleanA) inCleanA.checked = !!(u.raw?.clean_after);

      // status / vidnost / ON HOLD iz manifest.json
      if (inActive) inActive.checked = (u.raw?.active !== false);
      if (inPublic) inPublic.checked = (u.raw?.public !== false);
      if (inOnHold) inOnHold.checked = (u.raw?.on_hold === true);
      syncOnHoldToPublicUI();

      // ✅ PRAVI del: naloži site_settings (auto_block, booking, day_use, capacity)
      loadAndFillSiteSettings(id).catch(err => {
        console.warn('[units] unit_settings_get failed', err);
        if (diagOut) diagOut.textContent = `❌ unit_settings_get: ${err.message}`;
      });

      dlgAddUnit.showModal();
      inLabel?.focus?.();
    }


    btnAddUnitTop?.addEventListener('click', (e) => { e.preventDefault(); openAddUnit(); });
    btnAddUnitCard?.addEventListener('click', (e) => { e.preventDefault(); openAddUnit(); });
    btnCancelAdd?.addEventListener('click', (e) => { e.preventDefault(); closeModalReset(); });

    btnSaveBaseUrl?.addEventListener('click', (e) => {
      e.preventDefault();
      saveBaseUrl();
    });


    // delegate table clicks
    dom.unitsListBox?.addEventListener('click', async (e) => {

     // klik na ✓ / ⏸ v stolpcu Active -> preklopi on_hold
    const cell = e.target?.closest?.('.unit-active-cell');
    if (cell) {
      const id = cell.getAttribute('data-unit-id') || '';
      const u = (state.units || []).find(x => x.id === id);
      if (!u) return;

      // če je enota hard-disabled (active=false), ne preklapljamo
      if (u.raw?.active === false) return;

      const nextOnHold = !(u.raw?.on_hold === true);

      try {
        // takoj pokaži spremembo (da se vidi, da je klik prijel)
       cell.textContent = nextOnHold ? '⏸' : '✓';

await helpers.safeFetchJson(ctx.CFG.api.saveUnitMeta, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    id,
    // ohrani obstoječe vrednosti, da endpoint ne pade na false
    active: (u.raw?.active !== false),
    public: (u.raw?.public !== false),

    // in pa naš toggle
    on_hold: nextOnHold,

    // OPTIONAL (ampak priporočam): ohrani še label/alias da nič ne “sprazni”
    alias: u.raw?.alias || u.raw?.label || u.label || id,
    label: u.raw?.label || u.raw?.alias || u.label || id,
    property_id: u.raw?.property_id || '',
    owner: u.raw?.owner || ''
  })
});
        // osveži seznam enot (da se uskladi števec "aktivnih" itd.)
        await ctx.reloadUnits?.();
      } catch (err) {
        console.error(err);
        // vrni nazaj, če je šlo kaj narobe
        cell.textContent = (u.raw?.on_hold === true) ? '⏸' : '✓';
        alert(`Napaka pri on_hold: ${err.message}`);
      }

      return; // pomembno: da klik ne gre dalje na gumbe
}
 

      const btn = e.target?.closest?.('button');
      if (!btn) return;

      const id = btn.getAttribute('data-unit-id') || btn.closest('tr')?.getAttribute('data-unit-id') || '';
      if (!id) return;

      if (btn.classList.contains('unit-edit')) {
        openEditUnit(id);
      } else if (btn.classList.contains('unit-delete')) {
        deleteUnitById(id);
      } else if (btn.classList.contains('unit-ap')) {
        // autopilot module listens on this event
        window.dispatchEvent(new CustomEvent('cm-open-autopilot-unit', { detail: { unit: id } }));
      }
    });

    // submit add/edit
    formAddUnit?.addEventListener('submit', async (ev) => {
      ev.preventDefault();

      const label = (inLabel?.value || '').trim();
      if (!label) return;

      const isEdit = !!editingUnitId;
      const idInput = (inId?.value || '').trim();
      const id = isEdit ? editingUnitId : idInput;
      if (!id) return;

      const months = inMonths?.value !== '' ? Number(inMonths.value) : 12;

      const url = isEdit ? CFG.api.saveUnitMeta : CFG.api.addUnit;
	const onHold = !!(inOnHold?.checked);
	const pub = onHold ? false : !!(inPublic?.checked);


      // ADD payload = add_unit.php (extended)
      const addPayload = {
        id,
        label,
        template_unit: (inTpl?.value || 'A2'),
        meta: {
          property_id: (inProp?.value || 'HOME').trim(),
          owner: (inOwner?.value || '').trim(),
          months_ahead: months,
          clean_before: (inCleanB?.checked ? 1 : 0),
          clean_after:  (inCleanA?.checked ? 1 : 0),
          active: !!(inActive ? inActive.checked : true),
          public: pub,
          on_hold: onHold,
          booking_min_nights:      inMinNights?.value !== '' ? Number(inMinNights.value) : null,
          booking_cleaning_fee_eur:inCleaning?.value  !== '' ? Number(inCleaning.value)  : null,
          weekly_threshold:        inWeeklyTh?.value  !== '' ? Number(inWeeklyTh.value)  : null,
          weekly_discount_pct:     inWeeklyPct?.value !== '' ? Number(inWeeklyPct.value) : null,
          long_threshold:          inLongTh?.value    !== '' ? Number(inLongTh.value)    : null,
          long_discount_pct:       inLongPct?.value   !== '' ? Number(inLongPct.value)   : null,

        }
      };

      // EDIT payload = save_unit_meta.php (flat keys)
      const editPayload = {
        id: editingUnitId,
        label,
        alias: label,
        property_id: (inProp?.value || '').trim(),
        owner: (inOwner?.value || '').trim(),
        active: !!(inActive ? inActive.checked : false),
        public: pub,
        months_ahead: months,
        clean_before: (inCleanB?.checked ? 1 : 0),
        clean_after:  (inCleanA?.checked ? 1 : 0),
        on_hold: onHold,
          booking_min_nights:      inMinNights?.value !== '' ? Number(inMinNights.value) : null,
          booking_cleaning_fee_eur:inCleaning?.value  !== '' ? Number(inCleaning.value)  : null,
          weekly_threshold:        inWeeklyTh?.value  !== '' ? Number(inWeeklyTh.value)  : null,
          weekly_discount_pct:     inWeeklyPct?.value !== '' ? Number(inWeeklyPct.value) : null,
          long_threshold:          inLongTh?.value    !== '' ? Number(inLongTh.value)    : null,
          long_discount_pct:       inLongPct?.value   !== '' ? Number(inLongPct.value)   : null,


      };

      const payload = isEdit ? editPayload : addPayload;

      try {
        setStatus(isEdit ? 'Shranjujem…' : 'Ustvarjam enoto…');

    const res = await helpers.safeFetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    // en sam error check
    if (!res || res.ok === false) {
      console.warn('[units] add/saveUnitMeta failed', res);
      setStatus(`❌ Napaka: ${res?.error || 'add_or_save_failed'}`);
      return;
    }

   // 🔁 Shrani per-unit site_settings (day_use, capacity, booking…)
    await saveSiteSettingsFromModal(id);

   // en sam success status
    setStatus(isEdit ? '✅ Shranjeno.' : `✅ Enota ustvarjena: ${id}`);

    closeModalReset();
        // reload & select
        await ctx.reloadUnits?.();

        if (ctx.dom.unitSelect) {
          ctx.dom.unitSelect.value = id;
          ctx.dom.unitSelect.dispatchEvent(new Event('change'));
        }

        closeModalReset();
      } catch (err) {
        console.error(err);
        setStatus(`❌ Napaka: ${err?.message || err}`);
      }
    });

    // ---------- reloadUnits (source of truth = manifest) ----------

    // ---------- reloadUnits (source of truth = manifest) ----------
    ctx.reloadUnits = async () => {
      // Important: avoid stale cache for manifest.json
      const data = await helpers.safeFetchJson(ctx.CFG.manifest, { cache: 'no-store' });

      const arr = Array.isArray(data?.units) ? data.units : [];
      ctx.state.units = arr
        .map(u => ({
          id: u.id || u.unit || '',
          label: u.alias || u.label || u.name || (u.id || ''),
          raw: u
        }))
        .filter(u => u.id);

      // Update global base URL input from manifest
      if (baseUrlInput) {
        let domain = '';

        if (data && typeof data === 'object') {
          const meta = (data.meta && typeof data.meta === 'object') ? data.meta : null;

          const cand =
            (typeof data.base_url === 'string' && data.base_url.trim()) ||
            (meta && typeof meta.base_url === 'string' && meta.base_url.trim()) ||
            (typeof data.domain === 'string' && data.domain.trim()) ||
            (meta && typeof meta.domain === 'string' && meta.domain.trim()) ||
            '';

          if (cand) {
            domain = cand.trim();
          }
        }

        // Default for Free/Plus if not set yet
        if (!domain) {
          domain = 'http://localhost';
        }

        baseUrlInput.value = domain;
      }

      // repopulate select
      if (ctx.dom.unitSelect) {
        const cur = ctx.getCurrentUnit();
        ctx.dom.unitSelect.innerHTML = '';
        for (const u of ctx.state.units) {
          const o = document.createElement('option');
          o.value = u.id;
          o.textContent = `${u.id} – ${u.label || ''}`.trim();
          if (u.id === cur) o.selected = true;
          ctx.dom.unitSelect.appendChild(o);
        }
      }

      renderUnitsTable();
    };


    btnRefreshUnits?.addEventListener('click', () => ctx.reloadUnits());

    // ---------- initial render ----------
    (async () => {
      try {
        if (diagOut) diagOut.textContent = 'Osvežujem enote…';
        await ctx.reloadUnits();
        if (diagOut) diagOut.textContent = '✅ Enote osvežene.';
      } catch (e) {
        console.error('[units] initial reloadUnits failed', e);
        if (diagOut) diagOut.textContent = `❌ Napaka: ${e.message}`;
        renderUnitsTable(); // fallback
      }
    })();
  }

  if (window.CM_INTEGRATIONS) boot();
  else window.addEventListener('cm-integrations-ready', boot, { once: true });
})();
