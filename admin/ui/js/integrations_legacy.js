/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_legacy.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * /app/admin/ui/js/integrations.js
 *
 * Admin integrations console:
 * - ICS export links per unit
 * - Units management (manifest.json + site_settings.json)
 * - Promo codes (global JSON, LIVE + .bak)
 * - Special offers (per-unit JSON, LIVE + .bak)
 */

(() => {
  'use strict';

  // ---------- Helpers ----------

  function $(sel, root = document) {
    return root.querySelector(sel);
  }
  function $all(sel, root = document) {
    return Array.from(root.querySelectorAll(sel));
  }

  function readConfig() {
    const root = document.getElementById('integrations-root');
    if (!root) throw new Error('Missing #integrations-root');
    const raw = root.getAttribute('data-config') || '{}';
    try {
      return JSON.parse(raw);
    } catch (e) {
      console.error('Config parse error', e, raw);
      throw new Error('Neveljaven JSON v data-config');
    }
  }

  async function safeFetchJson(url, opts = {}) {
    const res = await fetch(url, opts);
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`HTTP ${res.status} for ${url}: ${txt.slice(0, 200)}`);
    }
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      return res.json();
    }
    const txt = await res.text();
    try {
      return JSON.parse(txt);
    } catch {
      return txt;
    }
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
    } finally {
      document.body.removeChild(ta);
    }
    return Promise.resolve();
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // --- Remember last selected unit ---
  const LS_UNIT_KEY = 'cm_integrations_last_unit';

  function saveLastUnit(unit) {
    try {
      localStorage.setItem(LS_UNIT_KEY, unit || '');
    } catch (e) {
      console.warn('[integrations] cannot save last unit', e);
    }
  }

  function loadLastUnit() {
    try {
      return localStorage.getItem(LS_UNIT_KEY) || '';
    } catch (e) {
      console.warn('[integrations] cannot load last unit', e);
      return '';
    }
  }

  // ---------- Global state ----------

  const CFG = readConfig();

  // Optional prefill iz admin koledarja (Set offer)
  const urlParams = new URLSearchParams(window.location.search || '');
  CFG.prefillOfferFrom = urlParams.get('from') || '';
  CFG.prefillOfferTo   = urlParams.get('to')   || '';
  CFG.prefillOfferOpen = urlParams.get('open') || '';

  const state = {
    units: [],
    currentUnit: CFG.unit || '',
    promo: {
      data: null,           // full {settings, codes[]}
      filteredIndexes: [],  // mapping visible index -> data.codes index
      selectedIndex: -1
    },
    offers: {
      byUnit: {},           // unit -> array of offers
      selectedIndex: -1
    },
    autopilot: {
       global: null,
    }
  };

  // ---------- DOM refs ----------

  const unitSelect      = $('#unitSelect');
  const btnAddUnit      = $('#btnAddUnit');
  const btnRefreshICS   = $('#btnRefreshICS');

  const icsBookedUrlEl  = $('#icsBookedUrl');
  const icsBlockedUrlEl = $('#icsBlockedUrl');
  const openIcsBooked   = $('#openIcsBooked');
  const openIcsBlocked  = $('#openIcsBlocked');

  const btnDiag         = $('#btnDiag');
  const diagOut         = $('#diagOut');

  const cardChannels    = $('#card-channels');
  const channelsInfo    = $('#channelsInfo');
  const channelsTbody   = $('#channelsTbody');

  const dlgAddUnit      = $('#dlgAddUnit');
  const formAddUnit     = $('#formAddUnit');
  const newUnitId       = $('#newUnitId');
  const newUnitLabel    = $('#newUnitLabel');
  const newPropertyId   = $('#newPropertyId');
  const newOwner        = $('#newOwner');
  const newUnitActive   = $('#newUnitActive');
  const newUnitPublic   = $('#newUnitPublic');
  const newMonthsAhead  = $('#newMonthsAhead');
  const btnCancelAdd    = $('#btnCancelAdd');

  // Units management DOM
  const unitsListBox       = $('#unitsListBox');
  const btnAddUnitOpen     = $('#btnAddUnitOpen');
  const btnRefreshUnits    = $('#btnRefreshUnits');
  const newUnitCleanBefore = $('#unit-clean-before');
  const newUnitCleanAfter  = $('#unit-clean-after');

  // Autopilot – global
  const apGlobalEnabled    = $('#ap-global-enabled');
  const apGlobalMode       = $('#ap-global-mode');
  const apGlobalMinDays    = $('#ap-global-min-days');
  const apGlobalMaxNights  = $('#ap-global-max-nights');
  const apGlobalSources    = $('#ap-global-sources');
  const btnApGlobalSave    = $('#btnAutopilotSave');
  const btnApGlobalReload  = $('#btnAutopilotReload');
  const apGlobalTestMode  = $('#ap-global-test-mode');
  const apGlobalTestUntil = $('#ap-global-test-until');
  const apGlobalTest15    = $('#ap-global-test-15');
  const apGlobalTestClear = $('#ap-global-test-clear');


  // Autopilot – per-unit modal
  const dlgApUnit          = $('#dlgAutopilotUnit');
  const formApUnit         = $('#formAutopilotUnit');
  const apUnitLabel        = $('#ap-unit-id-label');
  const apUnitEnabled      = $('#ap-unit-enabled');
  const apUnitMode         = $('#ap-unit-mode');
  const apUnitMinDays      = $('#ap-unit-min-days');
  const apUnitMaxNights    = $('#ap-unit-max-nights');
  const apUnitSources      = $('#ap-unit-sources');
  const apUnitCheckAcc     = $('#ap-unit-check-accept');
  const apUnitCheckGuest   = $('#ap-unit-check-guest');
  const btnApUnitCancel    = $('#btnAutopilotUnitCancel');


  // Promo DOM
  const promoTbody             = $('#promoCodesBody');
  const promoSelectAll         = $('#promoSelectAll');
  const btnAddPromo            = $('#btnAddPromo');
  const btnPromoDeleteSelected = $('#btnPromoDeleteSelected');
  const btnPromoPublish        = $('#btnPromoPublish');
  const promoDetail            = $('#promoDetail');
  const promoDetailText        = $('#promoDetailText');
  const promoToggleRaw         = $('#promoToggleRaw');
  const promoRaw               = $('#promoRaw');

  // Promo global settings (auto kupon)
  const autoCouponPercentInput   = $('#autoCouponPercent');
  const autoCouponValidDaysInput = $('#autoCouponValidDays');
  const autoCouponPrefixInput    = $('#autoCouponPrefix');


  // Offers DOM
  const offersTbody      = $('#specialOffersBody');
  const btnAddOffer      = $('#btnAddOffer');
  const btnOffersPublish = $('#btnOffersPublish');
  const offerDetail      = $('#offerDetail');
  const offerDetailText  = $('#offerDetailText');
  const offerToggleRaw   = $('#offerToggleRaw');
  const offerRaw         = $('#offerRaw');

  // JSON editor dialog
  const jsonEditDialog   = $('#jsonEditDialog');
  const jsonEditForm     = $('#jsonEditForm');
  const jsonEditTitle    = $('#jsonEditTitle');
  const jsonEditMeta     = $('#jsonEditMeta');
  const editNameInput    = $('#editName');
  const editCodeInput    = $('#editCode');
  const editPercentInput = $('#editPercent');
  const editFromInput    = $('#editFrom');
  const editToInput      = $('#editTo');
  const editActiveInput  = $('#editActive');
  const jsonEditTextarea = $('#jsonEditTextarea');
  const jsonEditDelete   = $('#jsonEditDelete');
  const jsonEditCancel   = $('#jsonEditCancel');

  const currentEdit = {
    type: null,   // 'promo' | 'offer'
    index: -1
  };

  // ---------- Units + ICS ----------

  async function loadUnitsList() {
    const manifestUrl = CFG.manifest;
    let data;
    try {
      data = await safeFetchJson(manifestUrl);
    } catch (e) {
      diagOut.textContent = `Napaka pri branju manifest.json: ${e.message}`;
      console.error(e);
      state.units = [];
      if (unitSelect) unitSelect.innerHTML = '';
      return;
    }

    const arr = Array.isArray(data.units) ? data.units : [];
    state.units = arr
      .map(u => ({
        id: u.id || u.unit || '',
        label: u.alias || u.label || u.name || (u.id || ''),
        raw: u
      }))
      .filter(u => u.id);

    if (!unitSelect) return;

    unitSelect.innerHTML = '';
    for (const u of state.units) {
      const opt = document.createElement('option');
      opt.value = u.id;
      opt.textContent = u.label || u.id;
      unitSelect.appendChild(opt);
    }

    // izberi enoto: najprej state.currentUnit, potem localStorage, drugače prvo
    let desired = state.currentUnit || loadLastUnit();
    if (!desired || !state.units.some(u => u.id === desired)) {
      desired = state.units.length ? state.units[0].id : '';
    }
    state.currentUnit = desired;
    if (desired) {
      unitSelect.value = desired;
    }
  }

  function getCurrentUnit() {
    return state.currentUnit || '';
  }

  function refreshIcsUrls() {
    const unit = getCurrentUnit();
    if (!unit) {
      if (icsBookedUrlEl)  icsBookedUrlEl.textContent  = '—';
      if (icsBlockedUrlEl) icsBlockedUrlEl.textContent = '—';
      if (openIcsBooked)   openIcsBooked.removeAttribute('href');
      if (openIcsBlocked)  openIcsBlocked.removeAttribute('href');
      return;
    }

    const base = `${CFG.api.icsPhp}?unit=${encodeURIComponent(unit)}`;
    const bookedUrl  = `${base}&mode=booked`;
    const blockedUrl = `${base}&mode=blocked_booked`;

    if (icsBookedUrlEl)  icsBookedUrlEl.textContent  = bookedUrl;
    if (icsBlockedUrlEl) icsBlockedUrlEl.textContent = blockedUrl;

    if (openIcsBooked)  openIcsBooked.href  = bookedUrl;
    if (openIcsBlocked) openIcsBlocked.href = blockedUrl;
  }

  // ---------- Channels / ICS IN (Booking) ----------

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, ch => {
      switch (ch) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return ch;
      }
    });
  }

  function isProdUnit(unit) {
    // Trenutno je A1 "sveta" produkcija; po potrebi seznam razširiš.
    return unit === 'A1';
  }

  async function loadUnitIntegration(unit) {
    if (!unit) return null;

    // dodamo timestamp, da fetch vedno dobi svežo verzijo
    const url = `/app/common/data/json/integrations/${encodeURIComponent(unit)}.json?_=${Date.now()}`;

    try {
      const data = await safeFetchJson(url);
      return (data && typeof data === 'object') ? data : null;
    } catch (e) {
      console.warn('[integrations] no per-unit integrations JSON for', unit, e);
      return null;
    }
  }


  function deriveBookingInState(cfg) {
    const out = {
      url: '',
      enabled: false,
      lastOk: null,
      lastError: null,
    };
    if (!cfg || !cfg.connections || !cfg.connections.booking) {
      return out;
    }
    const inCfg = cfg.connections.booking.in || {};
    const st    = cfg.connections.booking.status || {};

    out.url       = inCfg.ics_url || '';
    out.enabled   = !!inCfg.enabled;
    out.lastOk    = st.last_ok || null;
    out.lastError = st.last_error || null;
    return out;
  }

  function describeChannelStatus(st) {
    if (!st.url) {
      return { text: 'ni nastavljen', cls: 'pill neutral' };
    }
    if (st.lastError) {
      return { text: 'zadnja napaka: ' + st.lastError, cls: 'pill error' };
    }
    if (st.lastOk) {
      return { text: 'OK · zadnji pull ' + st.lastOk, cls: 'pill success' };
    }
    // URL je nastavljen, a še ni pull_now/apply – lab faza
    return { text: 'pripravljen (URL nastavljen)', cls: 'pill neutral' };
  }

  function buildSimulatorBadge(url) {
    if (!url) return '';
    const lower = url.toLowerCase();
    if (!lower.includes('ics_lab') && !lower.includes('sim1') && !lower.includes('sim2')) {
      return '';
    }
    let label = 'SIM';
    if (lower.includes('sim1')) label = 'SIM1';
    else if (lower.includes('sim2')) label = 'SIM2';
    return `<span class="pill tiny">🔧 ${label}</span>`;
  }

  async function refreshChannelsUi() {
    if (!cardChannels || !channelsTbody || !channelsInfo) return;

    const unit = getCurrentUnit();

    channelsTbody.innerHTML = '';
    channelsInfo.textContent = '';

    if (!unit) {
      channelsInfo.textContent = 'Najprej izberi enoto zgoraj levo.';
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 4;
      td.textContent = 'Ni izbrane enote.';
      tr.appendChild(td);
      channelsTbody.appendChild(tr);
      return;
    }

    // A1 = produkcija / legacy
    if (isProdUnit(unit)) {
      channelsInfo.innerHTML =
        'Enota <strong>A1</strong> je <strong>produkcijska</strong>. ' +
        'ICS uvoz za A1 trenutno upravljaš z obstoječo “ročni pull” skripto. ' +
        'Ta kartica (ICS IN) je za zdaj laboratorij za enote kot sta <code>A2</code> in <code>S1</code>.';

      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 4;
      td.textContent = 'ICS uvoz za A1 je trenutno označen kot legacy / read-only.';
      tr.appendChild(td);
      channelsTbody.appendChild(tr);
      return;
    }

    // LAB enote (A2, S1, …)
    const cfg = await loadUnitIntegration(unit);
    const bookingState = deriveBookingInState(cfg);
    const desc = describeChannelStatus(bookingState);

    const safeUrl = bookingState.url || '(ni nastavljen)';
    const statusLabelHtml =
      `<span class="${desc.cls}">${escapeHtml(desc.text)}</span>`;

    channelsInfo.innerHTML =
      `Enota <strong>${escapeHtml(unit)}</strong><br>` +
      `ICS URL: <code>${escapeHtml(safeUrl)}</code><br>` +
      `Status: ${statusLabelHtml}`;

    const tr = document.createElement('tr');

    // Channel
    const tdCh = document.createElement('td');
    tdCh.textContent = 'Booking (ICS IN)';
    tr.appendChild(tdCh);

    // ICS URL + SIM badge
    const tdUrl = document.createElement('td');
    tdUrl.className = 'channels-url-cell';

    const input = document.createElement('input');
    input.type = 'url';
    input.id = 'bookingInUrl';
    input.placeholder = 'https://… (ICS URL)';
    input.value = bookingState.url || '';
    input.autocomplete = 'off';
    tdUrl.appendChild(input);

    const badgeHtml = buildSimulatorBadge(bookingState.url);
    if (badgeHtml) {
      const span = document.createElement('span');
      span.className = 'channels-sim-badge';
      span.innerHTML = badgeHtml;
      tdUrl.appendChild(span);
    }

    tr.appendChild(tdUrl);

    // Status cell (pill)
    const tdStatus = document.createElement('td');
    const spanStatus = document.createElement('span');
    spanStatus.id = 'bookingInStatus';
    spanStatus.className = desc.cls;
    spanStatus.textContent = desc.text;
    tdStatus.appendChild(spanStatus);
    tr.appendChild(tdStatus);

    // Akcije: Save + Pull + Apply + diag
    const tdActions = document.createElement('td');
    tdActions.className = 'channels-actions-cell';

    const btnSave = document.createElement('button');
    btnSave.id = 'bookingInSave';
    btnSave.className = 'btn xs';
    btnSave.textContent = 'Save URL';

    const btnPull = document.createElement('button');
    btnPull.id = 'bookingInPull';
    btnPull.className = 'btn xs';
    btnPull.textContent = 'Pull now';

    const btnApply = document.createElement('button');
    btnApply.id = 'bookingInApply';
    btnApply.className = 'btn xs';
    btnApply.textContent = 'Apply to calendar';

    if (!CFG.adminKey) {
      btnSave.disabled = true;
      btnPull.disabled = true;
      btnApply.disabled = true;
      btnSave.title = btnPull.title = btnApply.title =
        'Manjka admin key; preveri admin_key.txt.';
    } else if (!bookingState.url) {
      btnPull.disabled = true;
      btnApply.disabled = true;
      btnPull.title = 'Najprej nastavi ICS URL.';
      btnApply.title = 'Najprej nastavi ICS URL.';
    }

    tdActions.appendChild(btnSave);
    tdActions.appendChild(btnPull);
    tdActions.appendChild(btnApply);

    const note = document.createElement('div');
    note.id = 'bookingInDiag';
    note.className = 'muted tiny';
    note.textContent = '';
    tdActions.appendChild(note);

    tr.appendChild(tdActions);

    channelsTbody.appendChild(tr);

    // ---------- Save URL ----------
    if (CFG.adminKey) {
      btnSave.addEventListener('click', async () => {
        const url = (input.value || '').trim();
        if (!url) {
          alert('Prosimo vnesi ICS URL.');
          return;
        }

        const formData = new FormData();
        formData.append('unit', unit);
        formData.append('platform', 'booking');
        formData.append('ics_url', url);
        formData.append('key', CFG.adminKey);

        spanStatus.className = 'pill neutral';
        spanStatus.textContent = 'Shranjujem…';
        note.textContent = '';

        try {
          const res = await fetch(CFG.api.integrationsSetInUrl, {
            method: 'POST',
            body: formData,
          });
          const data = await res.json().catch(() => ({}));

    if (!data || data.ok === false) {
      spanStatus.className = 'pill error';
      spanStatus.textContent = data?.error || 'Shranjevanje ni uspelo';
      note.textContent = JSON.stringify(data, null, 2);
      return;
    }

    // uspelo je
    note.textContent = '[SAVE OK] URL shranjen.';
          // Refresh after save – povzetek v channelsInfo se bo sam posodobil
          await refreshChannelsUi();

        } catch (e) {
          spanStatus.className = 'pill error';
          spanStatus.textContent = 'Napaka pri klicu API.';
          note.textContent = e.message || e;
        }
      });

      // ---------- Pull now ----------
      btnPull.addEventListener('click', async () => {
        note.textContent = 'Povlečem ICS…';
        spanStatus.className = 'pill neutral';
        spanStatus.textContent = 'Pull…';

        const q = new URLSearchParams({
          unit,
          platform: 'booking',
          key: CFG.adminKey,
        });

        try {
          const res = await fetch(`${CFG.api.integrationsPullNow}?${q.toString()}`);
          const data = await res.json().catch(() => ({}));

          if (!data || data.ok === false) {
            spanStatus.className = 'pill error';
            spanStatus.textContent = 'Pull error';
            note.textContent = JSON.stringify(data, null, 2);
            return;
          }

          note.textContent =
            `[PULL OK]\nFetched: ${data.fetched || 0}\nErrors: ${data.errors || 0}`;

          await refreshChannelsUi();

        } catch (e) {
          spanStatus.className = 'pill error';
          spanStatus.textContent = 'Network error';
          note.textContent = e.message || e;
        }
      });

      // ---------- Apply to calendar ----------
      btnApply.addEventListener('click', async () => {
        note.textContent = 'Procesiram ICS → occupancy…';
        spanStatus.className = 'pill neutral';
        spanStatus.textContent = 'Apply…';

        const q = new URLSearchParams({
          unit,
          key: CFG.adminKey,
        });

        try {
          const res = await fetch(`${CFG.api.integrationsApplyNow}?${q.toString()}`);
          const data = await res.json().catch(() => ({}));

          if (!data || data.ok === false) {
            spanStatus.className = 'pill error';
            spanStatus.textContent = 'Apply error';
            note.textContent = JSON.stringify(data, null, 2);
            return;
          }

          note.textContent =
            `[APPLY OK]\nAdded: ${data.added || 0}\nMerged: ${data.merged || 'n/a'}`;

          await refreshChannelsUi();

        } catch (e) {
          spanStatus.className = 'pill error';
          spanStatus.textContent = 'Network error';
          note.textContent = e.message || e;
        }
      });
    }
  }



  // Če smo prišli iz admin koledarja z ?from=&to=&open=new_offer,
  // samodejno ustvarimo novo akcijo in odpremo modal.
  function maybeOpenPrefilledOffer() {
    if (CFG.prefillOfferOpen !== 'new_offer') return;

    const from = CFG.prefillOfferFrom || '';
    const to   = CFG.prefillOfferTo   || '';
    const unit = CFG.unit || getCurrentUnit();

    if (!unit || !from || !to) return;

    // Resetiraj, da pri ročnem reloadu ne dodajamo vedno novih akcij
    CFG.prefillOfferOpen = '';
    CFG.prefillOfferFrom = '';
    CFG.prefillOfferTo   = '';

    // Poskrbi, da je v UI izbrana prava enota
    if (unitSelect && unitSelect.value !== unit) {
      unitSelect.value = unit;
      state.currentUnit = unit;
    }

    // Ustvari akcijo z obdobjem from→to in odpri modal
    Offers.addNewFromPrefill(from, to);
  }


  function onUnitChange() {
    state.currentUnit = unitSelect ? (unitSelect.value || '') : '';
    saveLastUnit(state.currentUnit);

    refreshIcsUrls();
    refreshChannelsUi().catch(err => {
      console.error(err);
      diagOut.textContent = `Napaka pri nalaganju ICS kanalov: ${err.message}`;
    });

    Promo.loadAndRender().catch(err => {
      console.error(err);
      diagOut.textContent = `Napaka pri nalaganju kuponov: ${err.message}`;
    });
    Offers.loadAndRender().catch(err => {
      console.error(err);
      diagOut.textContent = `Napaka pri nalaganju akcij: ${err.message}`;
    });
  }




  function openAddUnit() {
    if (!dlgAddUnit) return;

    dlgAddUnit.removeAttribute('data-edit-id');
    dlgAddUnit.setAttribute('data-mode', 'add');

    // reset booking fields
    const addMin      = document.getElementById('add-booking-min-nights');
    const addSame     = document.getElementById('add-booking-allow-same-day');
    const addCleaning = document.getElementById('add-booking-cleaning-fee');

    if (addMin)      addMin.value = '';
    if (addSame)     addSame.checked = false;
    if (addCleaning) addCleaning.value = '';


    // reset day-use fields
    const dayEnabled = document.getElementById('add-dayuse-enabled');
    const dayFrom    = document.getElementById('add-dayuse-from');
    const dayTo      = document.getElementById('add-dayuse-to');
    const dayMaxP    = document.getElementById('add-dayuse-max-persons');
    const dayMaxD    = document.getElementById('add-dayuse-max-days');

    if (dayEnabled) dayEnabled.checked = false;
    if (dayFrom)    dayFrom.value      = '14:00';
    if (dayTo)      dayTo.value        = '20:00';
    if (dayMaxP)    dayMaxP.value      = '';
    if (dayMaxD)    dayMaxD.value      = '';

    if (newUnitId) {
      newUnitId.disabled = false;
      newUnitId.value = '';
    }
    if (newUnitLabel) newUnitLabel.value = '';
    if (newPropertyId) newPropertyId.value = 'HOME';
    if (newOwner) newOwner.value = '';
    if (newUnitActive) newUnitActive.checked = true;
    if (newUnitPublic) newUnitPublic.checked = true;
    if (newMonthsAhead) newMonthsAhead.value = '12';

    if (typeof dlgAddUnit.showModal === 'function') {
      dlgAddUnit.showModal();
    } else {
      dlgAddUnit.setAttribute('open', 'open');
    }
  }

  function closeAddUnit() {
    if (!dlgAddUnit) return;
    dlgAddUnit.removeAttribute('data-edit-id');
    dlgAddUnit.removeAttribute('data-mode');
    dlgAddUnit.close();
  }

  async function openEditUnit(unitObj) {
    if (!dlgAddUnit || !unitObj) return;
    const raw = unitObj.raw || {};
    const id  = raw.id || unitObj.id;

    dlgAddUnit.setAttribute('data-mode', 'edit');
    dlgAddUnit.setAttribute('data-edit-id', id);

    if (newUnitId) {
      newUnitId.value = id;
      newUnitId.disabled = true;
    }
    if (newUnitLabel) newUnitLabel.value  = raw.alias || raw.label || raw.name || id;
    if (newPropertyId) newPropertyId.value = raw.property_id || 'HOME';
    if (newOwner) newOwner.value           = raw.owner || '';
    if (newUnitActive) newUnitActive.checked = (raw.active !== false);
    if (newUnitPublic) newUnitPublic.checked = (raw.public !== false);

    if (newMonthsAhead) newMonthsAhead.value = '12';
    if (newUnitCleanBefore) newUnitCleanBefore.checked = false;
    if (newUnitCleanAfter)  newUnitCleanAfter.checked  = false;

    // preberi site_settings.json
    try {
      const res = await fetch(`${CFG.unitsDir}/${id}/site_settings.json?cache=no-store`);
      if (res.ok) {
        const js = await res.json();
        if (js && typeof js.month_render === 'number' && newMonthsAhead) {
          newMonthsAhead.value = String(Math.max(1, Math.min(36, js.month_render)));
        }

        const autoBlock = js && js.auto_block ? js.auto_block : {};

        if (newUnitCleanBefore) {
          newUnitCleanBefore.checked = !!(autoBlock.before_arrival || js.clean_before);
        }
        if (newUnitCleanAfter) {
          newUnitCleanAfter.checked = !!(autoBlock.after_departure || js.clean_after);
        }
      }
    } catch (err) {
      console.warn('[integrations] Napaka pri branju site_settings.json:', err);
    }

    await fillAddUnitBookingFromSettings(id);
    await fillAddUnitDayUseFromSettings(id);
    await fillAddUnitCapacityFromSettings(id);



    if (typeof dlgAddUnit.showModal === 'function') {
      dlgAddUnit.showModal();
    } else {
      dlgAddUnit.setAttribute('open', 'open');
    }
  }

  async function submitAddUnit(ev) {
    ev.preventDefault();
    if (!dlgAddUnit) return;

    const mode   = dlgAddUnit.getAttribute('data-mode') || 'add';
    const isEdit = mode === 'edit';
    const editId = dlgAddUnit.getAttribute('data-edit-id') || '';

    const idRaw    = (newUnitId?.value || '').trim();
    const aliasRaw = (newUnitLabel?.value || '').trim();
    const propRaw  = (newPropertyId?.value || '').trim();
    const ownerRaw = (newOwner?.value || '').trim();

    const id = isEdit ? editId : idRaw;
    if (!id || !aliasRaw) return;

    const alias       = aliasRaw;
    const label       = aliasRaw;
    const property_id = propRaw || 'HOME';
    const owner       = ownerRaw || '';
    const active      = !!(newUnitActive && newUnitActive.checked);
    const isPublic    = !!(newUnitPublic && newUnitPublic.checked);

    let monthRender = 12;
    if (newMonthsAhead && newMonthsAhead.value !== '') {
      const v = parseInt(newMonthsAhead.value, 10);
      if (!Number.isNaN(v)) {
        monthRender = Math.max(1, Math.min(36, v));
      }
    }

    // 1) ADD: osnovna struktura
    if (!isEdit) {
      const payloadCreate = { id, label };
      try {
        const resCreate = await safeFetchJson(CFG.api.addUnit, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payloadCreate)
        });
        diagOut.textContent = `AddUnit OK: ${JSON.stringify(resCreate)}`;
      } catch (e) {
        diagOut.textContent = `Napaka pri dodajanju unita: ${e.message}`;
        console.error(e);
        return;
      }
    }

    // 2) meta podatki za manifest.json
    if (CFG.api && CFG.api.saveUnitMeta) {
      const payloadMeta = {
        id,
        alias,
        label,
        property_id,
        owner,
        active,
        public: isPublic
      };

      try {
        const resMeta = await safeFetchJson(CFG.api.saveUnitMeta, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payloadMeta)
        });
        diagOut.textContent += `\nSaveUnitMeta OK: ${JSON.stringify(resMeta)}`;
      } catch (e) {
        diagOut.textContent += `\nNapaka pri zapisovanju meta podatkov: ${e.message}`;
        console.error(e);
      }
    }

    // 3) site_settings.json: month_render
    try {
      await safeFetchJson('/app/admin/api/unit_settings_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          unit: id,
          month_render: monthRender
        })
      });
    } catch (e) {
      console.warn('Napaka pri zapisovanju site_settings.json (month_render):', e);
    }

    // 3b) cleaner flags
    try {
      const cleanBefore = !!(newUnitCleanBefore && newUnitCleanBefore.checked);
      const cleanAfter  = !!(newUnitCleanAfter && newUnitCleanAfter.checked);

      const formBefore = new FormData();
      formBefore.append('unit', id);
      formBefore.append('key', 'auto_block.before_arrival');
      formBefore.append('value', cleanBefore ? '1' : '0');
      await safeFetchJson('/app/admin/api/unit_settings_update.php', {
        method: 'POST',
        body: formBefore
      });

      const formAfter = new FormData();
      formAfter.append('unit', id);
      formAfter.append('key', 'auto_block.after_departure');
      formAfter.append('value', cleanAfter ? '1' : '0');
      await safeFetchJson('/app/admin/api/unit_settings_update.php', {
        method: 'POST',
        body: formAfter
      });
    } catch (e) {
      console.warn('Napaka pri zapisovanju cleaning nastavitev:', e);
    }


    // booking settings
    try {
      await saveBookingFromAddUnitForm(id);
    } catch (e) {
      console.warn('Napaka pri zapisovanju booking nastavitev:', e);
    }

    // day-use settings
    try {
      await saveDayUseFromAddUnitForm(id);
    } catch (e) {
      console.warn('Napaka pri zapisovanju day-use nastavitev:', e);
    }

    // capacity settings
    try {
      await saveCapacityFromAddUnitForm(id);
    } catch (e) {
      console.warn('Napaka pri zapisovanju capacity nastavitev:', e);
    }

    closeAddUnit();
    await loadUnitsList();
    renderUnitsList();
    refreshIcsUrls();
    Promo.loadAndRender().catch(console.error);
    Offers.loadAndRender().catch(console.error);
    await loadAutopilotGlobal();

  }

  // ---------- Promo module (global, LIVE + .bak) ----------

  const Promo = {
    async load() {
      const res = await safeFetchJson(CFG.api.promoGet);
      if (!res || !res.ok) {
        console.error('[Promo] load failed', res);
        state.promo.data = { settings: [], codes: [] };
      } else {
        let data = res.data || {};
        if (!Array.isArray(data.settings)) data.settings = [];
        if (!Array.isArray(data.codes)) data.codes = [];
        state.promo.data = data;
      }
      this.buildFilteredIndexes();
    },

    buildFilteredIndexes() {
      const unit = getCurrentUnit();
      const data = state.promo.data;
      const codes = (data && Array.isArray(data.codes)) ? data.codes : [];
      const idxs = [];

      codes.forEach((c, i) => {
        const u = (c && typeof c === 'object' && 'unit' in c) ? String(c.unit || '') : '';
        if (!u || u === unit) {
          idxs.push(i);
        }
      });

      state.promo.filteredIndexes = idxs;
      if (!idxs.includes(state.promo.selectedIndex)) {
        state.promo.selectedIndex = -1;
      }
    },

    renderSettings() {
      const data = state.promo.data || {};
      let s = data.settings;

      // settings je lahko [] (stari format) ali {} (nov)
      if (!s || typeof s !== 'object' || Array.isArray(s)) {
        s = {};
      }

      const percent = (typeof s.auto_reject_discount_percent === 'number')
        ? s.auto_reject_discount_percent
        : 15;

      const validDays = (typeof s.auto_reject_valid_days === 'number')
        ? s.auto_reject_valid_days
        : 180;

      const prefix = (typeof s.auto_reject_code_prefix === 'string')
        ? s.auto_reject_code_prefix
        : 'RETRY-';

      if (autoCouponPercentInput) {
        autoCouponPercentInput.value = String(percent);
      }
      if (autoCouponValidDaysInput) {
        autoCouponValidDaysInput.value = String(validDays);
      }
      if (autoCouponPrefixInput) {
        autoCouponPrefixInput.value = prefix;
      }
    },

    collectSettingsFromForm() {
      const data = state.promo.data || {};
      if (!data.settings || typeof data.settings !== 'object' || Array.isArray(data.settings)) {
        data.settings = {};
      }
      const s = data.settings;

      let percent = autoCouponPercentInput ? autoCouponPercentInput.value.trim() : '';
      let valid   = autoCouponValidDaysInput ? autoCouponValidDaysInput.value.trim() : '';
      let prefix  = autoCouponPrefixInput ? autoCouponPrefixInput.value.trim() : '';

      let pNum = parseFloat(percent);
      if (Number.isNaN(pNum) || pNum < 0 || pNum > 100) {
        pNum = 15;
      }

      let vNum = parseInt(valid, 10);
      if (Number.isNaN(vNum) || vNum < 1 || vNum > 730) {
        vNum = 180;
      }

      if (!prefix) {
        prefix = 'RETRY-';
      }

      s.auto_reject_discount_percent = pNum;
      s.auto_reject_valid_days       = vNum;
      s.auto_reject_code_prefix      = prefix;

      state.promo.data = data;
    },


    getAllCodes() {
      const d = state.promo.data;
      return (d && Array.isArray(d.codes)) ? d.codes : [];
    },

    getVisibleCodes() {
      const all = this.getAllCodes();
      return state.promo.filteredIndexes.map(i => all[i]);
    },

    async loadAndRender() {
      await this.load();
      this.renderSettings();  // novo
      this.renderList();
      this.renderDetail();
    },

    renderList() {
      if (!promoTbody) return;

      const codes = this.getVisibleCodes();
      const all   = this.getAllCodes();
      const selected = state.promo.selectedIndex;

      if (!codes.length) {
        promoTbody.innerHTML = `
          <tr class="muted">
            <td colspan="11">Ni definiranih kuponov za izbrano enoto.</td>
          </tr>`;
        return;
      }

      const rows = [];
      state.promo.filteredIndexes.forEach(realIdx => {
        const c = all[realIdx] || {};
        const isSelected = (realIdx === selected);

        const code = c.code || c.id || '';
        const name = c.name || '';
        const percent = (c.discount_percent != null)
          ? c.discount_percent
          : (c.percent != null ? c.percent : (c.value != null ? c.value : ''));
        const minN = c.min_nights != null ? c.min_nights : '';
        const maxN = c.max_nights != null ? c.max_nights : '';
        const validFrom = c.valid_from || '';
        const validTo   = c.valid_to || '';
        const active = (c.active !== false && c.enabled !== false);
        const unit = c.unit ? String(c.unit) : '';

        rows.push(`
          <tr data-real-index="${realIdx}" class="${isSelected ? 'selected' : ''}">
            <td><input type="checkbox" class="promo-row-select" data-real-index="${realIdx}"></td>
            <td>${escapeHtml(code)}</td>
            <td>${escapeHtml(name)}</td>
            <td>${percent !== '' ? escapeHtml(String(percent)) : ''}</td>
            <td>${minN !== '' ? escapeHtml(String(minN)) : ''}</td>
            <td>${maxN !== '' ? escapeHtml(String(maxN)) : ''}</td>
            <td>${escapeHtml(validFrom)}</td>
            <td>${escapeHtml(validTo)}</td>
            <td>${active ? '✓' : '—'}</td>
            <td>${unit ? escapeHtml(unit) : '<span class="muted small">Global</span>'}</td>
            <td>
              <button type="button"
                      class="btn xs promo-edit-btn"
                      data-real-index="${realIdx}">Uredi</button>
            </td>
          </tr>
        `);
      });

      promoTbody.innerHTML = rows.join('');

      // gumb "Uredi" → modal editor
      promoTbody.querySelectorAll('.promo-edit-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation(); // ne sproži klika na vrstico
          const idx = parseInt(btn.getAttribute('data-real-index') || '-1', 10);
          if (!Number.isNaN(idx)) {
            state.promo.selectedIndex = idx;
            this.renderList();
            this.renderDetail();
            this.openEditor(idx);
          }
        });
      });

      // klik na vrstico → spremeni "izbran kupon" (detajl spodaj)
      promoTbody.querySelectorAll('tr[data-real-index]').forEach(row => {
        row.addEventListener('click', (e) => {
          // če klikneš na gumb ali checkbox, to obravnavajo drugi handlerji
          if (e.target.closest('button, input[type="checkbox"]')) return;
          const idx = parseInt(row.getAttribute('data-real-index') || '-1', 10);
          if (!Number.isNaN(idx)) {
            state.promo.selectedIndex = idx;
            this.renderList();
            this.renderDetail();
          }
        });
      });

      // kljukica v stolpcu "izberi" – naj tudi premakne detail na ta kupon
      promoTbody.querySelectorAll('.promo-row-select').forEach(cb => {
        cb.addEventListener('change', () => {
          const idx = parseInt(cb.getAttribute('data-real-index') || '-1', 10);
          if (!Number.isNaN(idx)) {
            state.promo.selectedIndex = idx;
            this.renderDetail();
          }
        });
      });
    },


    renderDetail() {
      if (!promoDetail || !promoDetailText) return;

      const all = this.getAllCodes();
      const idx = state.promo.selectedIndex;
      if (idx < 0 || idx >= all.length) {
        promoDetailText.innerHTML = 'Ni izbranega kupona.';
        if (promoToggleRaw) promoToggleRaw.hidden = true;
        if (promoRaw) promoRaw.hidden = true;
        return;
      }

      const c = all[idx] || {};
      const unit = c.unit ? escapeHtml(String(c.unit)) : '<span class="muted small">Global</span>';

      promoDetailText.innerHTML = `
        <div><strong>Koda:</strong> ${escapeHtml(c.code || c.id || '')}</div>
        <div><strong>Opis:</strong> ${escapeHtml(c.name || '')}</div>
        <div><strong>Popust:</strong> ${escapeHtml(String(
          c.discount_percent ?? c.percent ?? c.value ?? ''
        ))} %</div>
        <div><strong>Enota:</strong> ${unit}</div>
        <div><strong>Velja:</strong> ${escapeHtml(c.valid_from || '')} → ${escapeHtml(c.valid_to || '')}</div>
        <div><strong>Status:</strong> ${(c.active === false || c.enabled === false) ? 'neaktivno' : 'aktivno'}</div>
      `;

      if (promoToggleRaw && promoRaw) {
        promoToggleRaw.hidden = false;
        promoRaw.hidden = true;
        promoRaw.textContent = JSON.stringify(c, null, 2);
      }
    },

    openEditor(realIdx) {
      const codes = this.getAllCodes();
      if (realIdx < 0 || realIdx >= codes.length) return;
      const c = codes[realIdx] || {};

      currentEdit.type = 'promo';
      currentEdit.index = realIdx;

      if (jsonEditTitle) jsonEditTitle.textContent = 'Uredi kupon';
      if (jsonEditMeta)  jsonEditMeta.textContent  = `Index: ${realIdx}`;

      const percentVal = (
        c.discount_percent ??
        c.percent ??
        c.value ??
        ''
      );

      if (editNameInput)    editNameInput.value    = c.name || '';
      if (editCodeInput)    editCodeInput.value    = c.code || c.id || '';
      if (editPercentInput) editPercentInput.value = percentVal !== '' ? String(percentVal) : '';
      if (editFromInput)    editFromInput.value    = c.valid_from || '';
      if (editToInput)      editToInput.value      = c.valid_to   || '';
      if (editActiveInput) {
        const active = !(c.active === false || c.enabled === false);
        editActiveInput.checked = active;
      }

      if (jsonEditTextarea) {
        jsonEditTextarea.value = JSON.stringify(c, null, 2);
      }

      if (jsonEditDialog) {
        if (typeof jsonEditDialog.showModal === 'function') {
          jsonEditDialog.showModal();
        } else {
          jsonEditDialog.setAttribute('open', 'open');
        }
      }
    },

    addNew() {
      const unit = getCurrentUnit();
      const all = this.getAllCodes();

      const now = new Date();
      const yyyy = now.getFullYear();
      const mm = String(now.getMonth() + 1).padStart(2, '0');
      const dd = String(now.getDate()).padStart(2, '0');

      const newCode = {
        id: '',
        code: '',
        name: '',
        unit: unit || undefined,
        discount_percent: 0,
        valid_from: `${yyyy}-${mm}-${dd}`,
        valid_to: `${yyyy + 1}-${mm}-${dd}`,
        active: true,
        enabled: true
      };

      all.push(newCode);
      state.promo.data.codes = all;
      state.promo.selectedIndex = all.length - 1;
      this.buildFilteredIndexes();
      this.renderList();
      this.renderDetail();
      this.openEditor(all.length - 1);
    },

    deleteSelected() {
      const all = this.getAllCodes();
      if (!all.length || !promoTbody) return;

      const cbs = promoTbody.querySelectorAll('.promo-row-select');
      const toDelete = new Set();
      cbs.forEach(cb => {
        if (cb.checked) {
          const realIdx = parseInt(cb.getAttribute('data-real-index') || '-1', 10);
          if (!Number.isNaN(realIdx)) {
            toDelete.add(realIdx);
          }
        }
      });

      if (!toDelete.size) return;

      const remaining = all.filter((_, idx) => !toDelete.has(idx));
      state.promo.data.codes = remaining;
      state.promo.selectedIndex = -1;
      this.buildFilteredIndexes();
      this.renderList();
      this.renderDetail();
    },

    async save() {
      const data = state.promo.data || { settings: [], codes: [] };
      const payload = {
        settings: Array.isArray(data.settings) ? data.settings : [],
        codes: Array.isArray(data.codes) ? data.codes : []
      };

      try {
        const res = await safeFetchJson(CFG.api.promoSave, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        if (!res || !res.ok) {
          diagOut.textContent = `Napaka pri shranjevanju kuponov: ${res && res.error ? res.error : 'neznana'}`;
        } else {
          diagOut.textContent = 'Kuponi shranjeni (LIVE + .bak).';
        }
      } catch (e) {
        console.error('[Promo] save failed', e);
        diagOut.textContent = `Napaka pri shranjevanju kuponov: ${e.message}`;
      }
    }
  };


  // ---------- Offers module (per-unit, LIVE + .bak) ----------

  const Offers = {
    getUnitOffers(unit) {
      unit = unit || getCurrentUnit();
      if (!unit) return [];
      if (!state.offers.byUnit[unit]) {
        state.offers.byUnit[unit] = [];
      }
      return state.offers.byUnit[unit];
    },

    setUnitOffers(unit, offersArr) {
      unit = unit || getCurrentUnit();
      if (!unit) return;
      state.offers.byUnit[unit] = Array.isArray(offersArr) ? offersArr : [];
    },

    async loadForCurrentUnit() {
      const unit = getCurrentUnit();
      if (!unit) {
        this.setUnitOffers('', []);
        this.renderList();
        this.renderDetail();
        return;
      }

      try {
        const res = await safeFetchJson(`${CFG.api.offersGet}?unit=${encodeURIComponent(unit)}`);
        if (!res || !res.ok) {
          console.error('[Offers] load failed', res);
          this.setUnitOffers(unit, []);
        } else {
          const data = res.data || {};
          const offers = Array.isArray(data.offers) ? data.offers : [];
          this.setUnitOffers(unit, offers);
        }
      } catch (e) {
        console.error('[Offers] load error', e);
        this.setUnitOffers(unit, []);
      }

      state.offers.selectedIndex = -1;
      this.renderList();
      this.renderDetail();
    },

    loadAndRender() {
      return this.loadForCurrentUnit();
    },

    renderList() {
      if (!offersTbody) return;

      const unit = getCurrentUnit();
      const offers = this.getUnitOffers(unit);
      const selected = state.offers.selectedIndex;

      if (!offers.length) {
        offersTbody.innerHTML = `
          <tr class="muted">
            <td colspan="7">Ni definiranih akcij za izbrano enoto.</td>
          </tr>`;
        return;
      }

      const rows = offers.map((o, idx) => {
        const label = (o.name && o.id)
          ? `${o.name} (${o.id})`
          : (o.name || o.id || '');
        const from = o.active_from || o.from || '';
        const to   = o.active_to   || o.to   || '';
        const discount = o.discount && typeof o.discount === 'object'
          ? o.discount.value
          : (o.discount_percent ?? '');
        const type = o.discount && typeof o.discount === 'object'
          ? (o.discount.type || 'percent')
          : (o.type || 'percent');
        const active = (o.active !== false && o.enabled !== false);

        return `
          <tr data-index="${idx}" class="${idx === selected ? 'selected' : ''}">
            <td>${escapeHtml(label)}</td>
            <td>${escapeHtml(from)}</td>
            <td>${escapeHtml(to)}</td>
            <td>${discount !== '' ? escapeHtml(String(discount)) : ''}</td>
            <td>${escapeHtml(type)}</td>
            <td>${active ? '✓' : '—'}</td>
            <td>
              <button type="button" class="btn xs offer-edit-btn" data-index="${idx}">Uredi</button>
            </td>
          </tr>
        `;
      });

      offersTbody.innerHTML = rows.join('');

      // gumb "Uredi" → modal
      offersTbody.querySelectorAll('.offer-edit-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation(); // da ne fire-a klika na vrstico
          const idx = parseInt(btn.getAttribute('data-index') || '-1', 10);
          if (!Number.isNaN(idx)) {
            state.offers.selectedIndex = idx;
            this.renderList();
            this.renderDetail();
            this.openEditor(idx);
          }
        });
      });

      // klik na vrstico → spremeni "Izbrana akcija" spodaj
      offersTbody.querySelectorAll('tr[data-index]').forEach(row => {
        row.addEventListener('click', (e) => {
          if (e.target.closest('button')) return;
          const idx = parseInt(row.getAttribute('data-index') || '-1', 10);
          if (!Number.isNaN(idx)) {
            state.offers.selectedIndex = idx;
            this.renderList();
            this.renderDetail();
          }
        });
      });
    },

    renderDetail() {
      if (!offerDetail || !offerDetailText) return;

      const unit = getCurrentUnit();
      const offers = this.getUnitOffers(unit);
      const idx = state.offers.selectedIndex;

      if (idx < 0 || idx >= offers.length) {
        offerDetailText.innerHTML = 'Ni izbrane akcije.';
        if (offerToggleRaw) offerToggleRaw.hidden = true;
        if (offerRaw) offerRaw.hidden = true;
        return;
      }

      const o = offers[idx] || {};
      const discountObj = o.discount && typeof o.discount === 'object'
        ? o.discount
        : { type: o.type || 'percent', value: o.discount_percent ?? 0 };

      offerDetailText.innerHTML = `
        <div><strong>Naziv:</strong> ${escapeHtml(o.name || '')}</div>
        <div><strong>Aktivna od/do:</strong> ${escapeHtml(o.active_from || o.from || '')} → ${escapeHtml(o.active_to || o.to || '')}</div>
        <div><strong>Obdobja:</strong> ${
          Array.isArray(o.periods) && o.periods.length
            ? o.periods.map(p => `${escapeHtml(p.start)} → ${escapeHtml(p.end)}`).join(', ')
            : '<span class="muted">brez posebnih obdobij</span>'
        }</div>
        <div><strong>Min. noči:</strong> ${escapeHtml(String(o.conditions?.min_nights ?? ''))}</div>
        <div><strong>Popust:</strong> ${escapeHtml(String(discountObj.value ?? ''))} (${escapeHtml(discountObj.type || 'percent')})</div>
        <div><strong>Status:</strong> ${(o.active === false || o.enabled === false) ? 'neaktivno' : 'aktivno'}</div>
      `;

      if (offerToggleRaw && offerRaw) {
        offerToggleRaw.hidden = false;
        offerRaw.hidden = true;

        // NOVO: kot preview prikažemo celoten payload offers_get.php
        // (tako kot se zapisuje v special_offers.json preko offers_save.php)
        const fullPayload = {
          offers: offers
        };
        offerRaw.textContent = JSON.stringify(fullPayload, null, 2);
      }

    },

    openEditor(idx) {
      const unit = getCurrentUnit();
      if (!unit) return;

      const offers = this.getUnitOffers(unit);
      if (idx < 0 || idx >= offers.length) return;
      const o = offers[idx] || {};

      currentEdit.type = 'offer';
      currentEdit.index = idx;

      if (jsonEditTitle) jsonEditTitle.textContent = 'Uredi akcijo';
      if (jsonEditMeta)  jsonEditMeta.textContent  = `Enota: ${unit}, index: ${idx}`;

      const discountObj = (o.discount && typeof o.discount === 'object')
        ? o.discount
        : { type: o.type || 'percent', value: o.discount_percent ?? 0 };

      if (editNameInput)    editNameInput.value    = o.name || '';
      if (editCodeInput)    editCodeInput.value    = '';
      if (editPercentInput) editPercentInput.value = discountObj.value != null ? String(discountObj.value) : '';
      if (editFromInput)    editFromInput.value    = o.active_from || o.from || '';
      if (editToInput)      editToInput.value      = o.active_to   || o.to   || '';
      if (editActiveInput) {
        const active = !(o.active === false || o.enabled === false);
        editActiveInput.checked = active;
      }

      if (jsonEditTextarea) {
        jsonEditTextarea.value = JSON.stringify(o, null, 2);
      }

      if (jsonEditDialog) {
        if (typeof jsonEditDialog.showModal === 'function') {
          jsonEditDialog.showModal();
        } else {
          jsonEditDialog.setAttribute('open', 'open');
        }
      }
    },

    addNew() {
      const unit = getCurrentUnit();
      if (!unit) return;

      const offers = this.getUnitOffers(unit);
      const now = new Date();
      const yyyy = now.getFullYear();
      const mm = String(now.getMonth() + 1).padStart(2, '0');
      const dd = String(now.getDate()).padStart(2, '0');

      const newOffer = {
        id: '',
        name: 'Nova akcija',
        active_from: `${yyyy}-${mm}-${dd}`,
        active_to: `${yyyy + 1}-${mm}-${dd}`,
        periods: [],
        conditions: {
          min_nights: 0,
          promo_code: null,
          stackable: false
        },
        discount: {
          type: 'percent',
          value: 0
        },
        priority: 0,
        enabled: true,
        active: true
      };

      offers.push(newOffer);
      this.setUnitOffers(unit, offers);
      state.offers.selectedIndex = offers.length - 1;
      this.renderList();
      this.renderDetail();
      this.openEditor(offers.length - 1);
    },

     // NOVO: za prefill iz admin koledarja (?from=&to=&open=new_offer)
    addNewFromPrefill(from, to) {
      const unit = getCurrentUnit();
      if (!unit) return;

      const offers = this.getUnitOffers(unit);

      const now = new Date();
      const yyyy = now.getFullYear();
      const mm = String(now.getMonth() + 1).padStart(2, '0');
      const dd = String(now.getDate() + 1).padStart(2, '0'); // uskladi z navadnim addNew

      const activeFrom = from || `${yyyy}-${mm}-${dd}`;
      const activeTo   = to   || `${yyyy + 1}-${mm}-${dd}`;

      const periods =
        from && to
          ? [{ start: activeFrom, end: activeTo }]
          : [];

      const newOffer = {
        id: '',
        name: 'Nova akcija',
        active_from: activeFrom,
        active_to: activeTo,
        periods,
        conditions: {
          min_nights: 0,
          promo_code: null,
          stackable: false
        },
        discount: {
          type: 'percent',
          value: 0
        },
        priority: 0,
        enabled: true,
        active: true
      };

      offers.push(newOffer);
      this.setUnitOffers(unit, offers);
      state.offers.selectedIndex = offers.length - 1;
      this.renderList();
      this.renderDetail();
      this.openEditor(offers.length - 1);
    },


    async save() {
      const unit = getCurrentUnit();
      if (!unit) {
        diagOut.textContent = 'Ni izbrane enote za shranjevanje akcij.';
        return;
      }

      const offers = this.getUnitOffers(unit);

      const payload = {
        unit,
        offers
      };

      try {
        const res = await safeFetchJson(CFG.api.offersSave, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        if (!res || !res.ok) {
          diagOut.textContent = `Napaka pri shranjevanju akcij: ${res && res.error ? res.error : 'neznana'}`;
        } else {
          diagOut.textContent = `Akcije shranjene za ${unit} (LIVE + .bak).`;
        }
      } catch (e) {
        console.error('[Offers] save failed', e);
        diagOut.textContent = `Napaka pri shranjevanju akcij: ${e.message}`;
      }
    }
  };

  // ---------- JSON editor helpers ----------
  function applyUserFieldsToObject(type, obj) {
    if (!obj || typeof obj !== 'object') return obj;

    const name       = (editNameInput?.value || '').trim();
    const code       = (editCodeInput?.value || '').trim();
    const percentStr = (editPercentInput?.value || '').trim();
    const fromStr    = (editFromInput?.value || '').trim();
    const toStr      = (editToInput?.value || '').trim();
    const isActive   = !!(editActiveInput && editActiveInput.checked);

    if (name) obj.name = name;
    if (type === 'promo' && code) {
      obj.code = code;
      if (!obj.id) obj.id = code;
    }

    if (percentStr !== '' && !Number.isNaN(+percentStr)) {
      const val = +percentStr;
      obj.discount_percent = val;
      if (type === 'offer') {
        obj.discount = obj.discount || {};
        obj.discount.type = obj.discount.type || 'percent';
        obj.discount.value = val;
      }
    }

    if (type === 'promo') {
      if (fromStr) obj.valid_from = fromStr;
      if (toStr)   obj.valid_to   = toStr;
      obj.active  = isActive;
      obj.enabled = isActive;
    } else if (type === 'offer') {
      // --- osnovna polja (active_from / active_to) ---
      if (fromStr) {
        obj.active_from = fromStr;
        if ('from' in obj) delete obj.from;
      }
      if (toStr) {
        obj.active_to = toStr;
        if ('to' in obj) delete obj.to;
      }
      obj.active  = isActive;
      obj.enabled = isActive;

      // --- periods: vedno uskladi 0 ali 1 period z active_from/to ---
      const baseFrom = obj.active_from;
      const baseTo   = obj.active_to;

      if (baseFrom && baseTo) {
        if (!Array.isArray(obj.periods) || obj.periods.length === 0) {
          // brez periods → ustvari en period iz active_from/active_to
          obj.periods = [
            { start: baseFrom, end: baseTo }
          ];
        } else if (obj.periods.length === 1) {
          // en period → vedno sinhroniziraj z active_from/active_to
          obj.periods[0].start = baseFrom;
          obj.periods[0].end   = baseTo;
        } else {
          // več periodov → pusti pri miru (napredna uporaba)
          // obj.periods ostane tak kot je
        }
      }
    }

    return obj;
  }

  function onJsonEditSubmit(ev) {
    ev.preventDefault();
    if (!currentEdit.type) {
      if (jsonEditDialog) jsonEditDialog.close();
      return;
    }

    let parsed;
    try {
      const raw = jsonEditTextarea.value || '{}';
      parsed = JSON.parse(raw);
    } catch (e) {
      alert('Napaka pri branju JSON-a: ' + e.message);
      return;
    }

    parsed = applyUserFieldsToObject(currentEdit.type, parsed);

    if (currentEdit.type === 'promo') {
      const codes = Promo.getAllCodes();
      if (currentEdit.index >= 0 && currentEdit.index < codes.length) {
        codes[currentEdit.index] = parsed;
      }
      Promo.buildFilteredIndexes();
      Promo.renderList();
      Promo.renderDetail();

      // Kuponi ostanejo na "publish" gumbu (Promo.save)
    } else if (currentEdit.type === 'offer') {
      const unit = getCurrentUnit();
      if (!unit) {
        alert('Ni izbrane enote.');
      } else {
        const offers = Offers.getUnitOffers(unit);
        if (currentEdit.index >= 0 && currentEdit.index < offers.length) {
          // posodobimo lokalni array
          offers[currentEdit.index] = parsed;
          Offers.setUnitOffers(unit, offers);
        }
        Offers.renderList();
        Offers.renderDetail();

        // NOVO: takojšen zapis v special_offers.json (LIVE + .bak)
        Offers.save().catch(err => {
          console.error('[Offers] auto-save after edit failed', err);
          diagOut.textContent = `Napaka pri shranjevanju akcij: ${err.message}`;
        });
      }
    }

    currentEdit.type = null;
    currentEdit.index = -1;
    if (jsonEditDialog) jsonEditDialog.close();
  }

  function onJsonEditDelete() {
    if (!currentEdit.type) {
      if (jsonEditDialog) jsonEditDialog.close();
      return;
    }
    if (!confirm('Želite res izbrisati ta zapis?')) return;

    if (currentEdit.type === 'promo') {
      const codes = Promo.getAllCodes();
      if (currentEdit.index >= 0 && currentEdit.index < codes.length) {
        codes.splice(currentEdit.index, 1);
        if (state.promo.data) {
          state.promo.data.codes = codes;
        }
        state.promo.selectedIndex = -1;
        Promo.buildFilteredIndexes();
        Promo.renderList();
        Promo.renderDetail();
      }
      // Promo.save() ostane ročni na gumbu
    } else if (currentEdit.type === 'offer') {
      const unit = getCurrentUnit();
      if (!unit) {
        alert('Ni izbrane enote.');
      } else {
        const offers = Offers.getUnitOffers(unit);
        if (currentEdit.index >= 0 && currentEdit.index < offers.length) {
          offers.splice(currentEdit.index, 1);
          Offers.setUnitOffers(unit, offers);
          state.offers.selectedIndex = -1;
          Offers.renderList();
          Offers.renderDetail();

          // NOVO: takojšen zapis po brisanju
          Offers.save().catch(err => {
            console.error('[Offers] auto-save after delete failed', err);
            diagOut.textContent = `Napaka pri shranjevanju akcij: ${err.message}`;
          });
        }
      }
    }

    currentEdit.type = null;
    currentEdit.index = -1;
    if (jsonEditDialog) jsonEditDialog.close();
  }


  function onJsonEditCancel() {
    currentEdit.type = null;
    currentEdit.index = -1;
    if (jsonEditDialog) jsonEditDialog.close();
  }

  // ---------- Units list (table) ----------
  // ---------- Units list (table) ----------

  function renderUnitsList() {
    if (!unitsListBox) return;

    const arr = state.units || [];

    const totalEl  = $('#unitsTotal');
    const activeEl = $('#unitsActive');

    const total  = arr.length;
    const active = arr.filter(u => (u.raw && u.raw.active !== false)).length;

    if (totalEl)  totalEl.textContent  = `${total} enot`;
    if (activeEl) activeEl.textContent = `${active} aktivnih`;

    if (!total) {
      unitsListBox.innerHTML = '<p class="muted">No units found.</p>';
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
            <th>AP</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
    `;

    for (const u of arr) {
      const id      = escapeHtml(u.id || '');
      const label   = escapeHtml(u.label || u.id || '');
      const prop    = escapeHtml(u.raw?.property_id || '');
      const owner   = escapeHtml(u.raw?.owner || '');
      const activeX = (u.raw?.active !== false) ? '✓' : '—';

      // zaenkrat globalni indikator (kasneje lahko per-unit)
      const apIcon  =
        (state.autopilot &&
         state.autopilot.global &&
         state.autopilot.global.enabled)
          ? '✓'
          : '—';

      html += `
        <tr data-unit-id="${id}">
          <td>${id}</td>
          <td>${label}</td>
          <td>${prop}</td>
          <td>${owner}</td>
          <td>${activeX}</td>
          <td>${apIcon}</td>
          <td>
            <button type="button" class="btn small unit-edit" data-unit-id="${id}">Edit</button>
            <button type="button" class="btn small unit-ap" data-unit-id="${id}">Autopilot</button>
            <button type="button" class="btn small danger unit-delete" data-unit-id="${id}">Del</button>
          </td>
        </tr>
      `;
    }

    html += '</tbody></table>';
    unitsListBox.innerHTML = html;
  }

  async function deleteUnitById(id) {
    if (!id) return;
    const ok = window.confirm(`Are you sure you want to delete unit "${id}"?\nThis cannot be undone.`);
    if (!ok) return;

    try {
      const res = await safeFetchJson('/app/admin/api/delete_unit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      diagOut.textContent = `Unit deleted: ${JSON.stringify(res)}`;
      await loadUnitsList();
      renderUnitsList();
    } catch (e) {
      console.error(e);
      diagOut.textContent = `Napaka pri brisanju enote: ${e.message}`;
      alert('Delete failed.');
    }
  }

  function onUnitsListClick(ev) {
    const row = ev.target.closest('tr[data-unit-id]');
    if (!row) return;
    const id = row.getAttribute('data-unit-id');
    if (!id) return;

    if (ev.target.closest('.unit-ap')) {
      openUnitAutopilot(id);
      return;
    }

    if (ev.target.closest('.unit-delete')) {
      deleteUnitById(id);
      return;
    }
    if (ev.target.closest('.unit-edit')) {
      const obj = state.units.find(u => u.id === id);
      if (obj) {
        openEditUnit(obj);
      }
    }
  }

  // ---------- Booking & day-use helpers ----------
async function fillAddUnitBookingFromSettings(unit) {
  if (!unit) {
    console.warn('[integrations] fillAddUnitBookingFromSettings: missing unit');
    return;
  }

  const minInput      = document.getElementById('add-booking-min-nights');
  const sameCheckbox  = document.getElementById('add-booking-allow-same-day');
  const cleaningInput = document.getElementById('add-booking-cleaning-fee');

  // zdaj zahtevamo samo minInput; checkbox je lahko tudi odstranjen iz UI
  if (!minInput) {
    console.warn('[integrations] booking inputs not found in formAddUnit (no min nights)');
    return;
  }

  try {
    const url = `/app/admin/api/unit_settings_get.php?unit=${encodeURIComponent(unit)}`;
    const res = await safeFetchJson(url);

    if (!res || !res.ok) {
      console.warn('[integrations] unit_settings_get failed', res);
      return;
    }

    const settings = res.settings || {};
    const booking  = settings.booking || {};

    const minNights =
      typeof booking.min_nights === 'number' ? booking.min_nights : '';

    const cleaningFee =
      typeof booking.cleaning_fee_eur === 'number'
        ? String(booking.cleaning_fee_eur)
        : (typeof booking.cleaning_fee_eur === 'string'
            ? booking.cleaning_fee_eur
            : '');

    minInput.value = minNights;

    // allow_same_day_departure je optional: nastavimo le, če checkbox obstaja
    if (sameCheckbox) {
      sameCheckbox.checked = !!booking.allow_same_day_departure;
    }

    if (cleaningInput) {
      cleaningInput.value = cleaningFee;
    }
  } catch (err) {
    console.error('[integrations] fillAddUnitBookingFromSettings error', err);
  }
}


async function fillAddUnitDayUseFromSettings(unit) {
  if (!unit) {
    console.warn('[integrations] fillAddUnitDayUseFromSettings: missing unit');
    return;
  }

  const enabledInput    = document.getElementById('add-dayuse-enabled');
  const fromInput       = document.getElementById('add-dayuse-from');
  const toInput         = document.getElementById('add-dayuse-to');
  const maxPersonsInput = document.getElementById('add-dayuse-max-persons');
  const maxDaysInput    = document.getElementById('add-dayuse-max-days');
  const priceInput      = document.getElementById('add-dayuse-price-person');

  if (!enabledInput || !fromInput || !toInput || !maxPersonsInput || !maxDaysInput || !priceInput) {
    console.warn('[integrations] day-use inputs not found in formAddUnit');
    return;
  }

  try {
    const url = `/app/admin/api/unit_settings_get.php?unit=${encodeURIComponent(unit)}`;
    const res = await safeFetchJson(url);

    if (!res || !res.ok) {
      console.warn('[integrations] unit_settings_get (day_use) failed', res);
      return;
    }

    const settings = res.settings || {};
    const dayUse   = settings.day_use || {};

    const enabled = !!dayUse.enabled;

    const from =
      typeof dayUse.from === 'string' && dayUse.from
        ? dayUse.from
        : '14:00';

    const to =
      typeof dayUse.to === 'string' && dayUse.to
        ? dayUse.to
        : '20:00';

    const maxPersons =
      typeof dayUse.max_persons === 'number'
        ? String(dayUse.max_persons)
        : (typeof dayUse.max_persons === 'string' ? dayUse.max_persons : '');

    const maxDays =
      typeof dayUse.max_days_ahead === 'number'
        ? String(dayUse.max_days_ahead)
        : (typeof dayUse.max_days_ahead === 'string' ? dayUse.max_days_ahead : '');

    const price =
      typeof dayUse.day_price_person === 'number'
        ? String(dayUse.day_price_person)
        : (typeof dayUse.day_price_person === 'string' ? dayUse.day_price_person : '');

    enabledInput.checked   = enabled;
    fromInput.value        = from;
    toInput.value          = to;
    maxPersonsInput.value  = maxPersons;
    maxDaysInput.value     = maxDays;
    priceInput.value       = price;
  } catch (err) {
    console.error('[integrations] fillAddUnitDayUseFromSettings error', err);
  }
}

async function fillAddUnitCapacityFromSettings(unit) {
  if (!unit) {
    console.warn('[integrations] fillAddUnitCapacityFromSettings: missing unit');
    return;
  }

  const maxGuestsEl  = document.getElementById('add-cap-max-guests');
  const maxBedsEl    = document.getElementById('add-cap-max-beds');
  const minAdultsEl  = document.getElementById('add-cap-min-adults');
  const maxKids06El  = document.getElementById('add-cap-max-kids-06');
  const maxKids712El = document.getElementById('add-cap-max-kids-712');
  const allowBabyEl  = document.getElementById('add-cap-allow-baby-bed');

  if (!maxGuestsEl || !maxBedsEl || !minAdultsEl || !maxKids06El || !maxKids712El || !allowBabyEl) {
    console.warn('[integrations] capacity inputs not found in formAddUnit');
    return;
  }

  try {
    const url = `/app/admin/api/unit_settings_get.php?unit=${encodeURIComponent(unit)}`;
    const res = await safeFetchJson(url);

    if (!res || !res.ok) {
      console.warn('[integrations] unit_settings_get (capacity) failed', res);
      return;
    }

    const settings = res.settings || {};
    const cap      = settings.capacity || {};

    const toStr = (v) => (
      typeof v === 'number'
        ? String(v)
        : (typeof v === 'string' ? v : '')
    );

    maxGuestsEl.value  = toStr(cap.max_guests);
    maxBedsEl.value    = toStr(cap.max_beds);
    minAdultsEl.value  = toStr(cap.min_adults);
    maxKids06El.value  = toStr(cap.max_children_0_6);
    maxKids712El.value = toStr(cap.max_children_7_12);
    allowBabyEl.checked = !!cap.allow_baby_bed;
  } catch (err) {
    console.error('[integrations] fillAddUnitCapacityFromSettings error', err);
  }
}


async function saveBookingFromAddUnitForm(unit) {
  const minInput      = document.getElementById('add-booking-min-nights');
  const sameCheckbox  = document.getElementById('add-booking-allow-same-day');
  const cleaningInput = document.getElementById('add-booking-cleaning-fee');

  if (!unit) {
    console.warn('[integrations] saveBookingFromAddUnitForm: missing unit');
    return;
  }
  // zahtevamo samo minInput; checkbox je lahko odsoten
  if (!minInput) {
    console.warn('[integrations] booking inputs not found in formAddUnit (no min nights)');
    return;
  }

  const rawMin = minInput.value.trim();
  let minNights = rawMin === '' ? null : parseInt(rawMin, 10);
  if (Number.isNaN(minNights)) {
    minNights = null;
  }

  if (minNights !== null && (minNights < 1 || minNights > 365)) {
    alert('Minimalno št. nočitev mora biti med 1 in 365.');
    return;
  }

  // --- CLEANING FEE ---
  let cleaningFee = null;
  if (cleaningInput) {
    const rawCleaning = cleaningInput.value.trim();
    if (rawCleaning !== '') {
      cleaningFee = parseFloat(rawCleaning.replace(',', '.'));
      if (Number.isNaN(cleaningFee) || cleaningFee < 0 || cleaningFee > 9999) {
        alert('Cleaning fee mora biti med 0 in 9999 €.');
        return;
      }
    }
  }

  const payload = { unit: unit };

  if (minNights !== null) {
    payload.booking_min_nights = minNights;
  }

  // allow_same_day je optional: pošljemo le, če checkbox obstaja
  if (sameCheckbox) {
    payload.booking_allow_same_day_departure = sameCheckbox.checked;
  }

  if (cleaningFee !== null) {
    payload.booking_cleaning_fee_eur = cleaningFee;
  }

  try {
    const res = await safeFetchJson('/app/admin/api/unit_settings_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!res || !res.ok) {
      console.warn('[integrations] unit_settings_save failed', res);
      return;
    }

    console.log('[integrations] booking settings saved', res.settings);
  } catch (err) {
    console.error('[integrations] saveBookingFromAddUnitForm error', err);
  }
}

  async function saveDayUseFromAddUnitForm(unit) {
    const enabledInput    = document.getElementById('add-dayuse-enabled');
    const fromInput       = document.getElementById('add-dayuse-from');
    const toInput         = document.getElementById('add-dayuse-to');
    const maxPersonsInput = document.getElementById('add-dayuse-max-persons');
    const maxDaysInput    = document.getElementById('add-dayuse-max-days');
    const priceInput      = document.getElementById('add-dayuse-price-person');

    if (!unit) {
      console.warn('[integrations] saveDayUseFromAddUnitForm: missing unit');
      return;
    }
    if (!enabledInput || !fromInput || !toInput || !maxPersonsInput || !maxDaysInput) {
      console.warn('[integrations] day-use inputs not found in formAddUnit');
      return;
    }

    const enabled       = !!enabledInput.checked;
    const from          = (fromInput.value || '').trim();
    const to            = (toInput.value || '').trim();
    const maxPersonsRaw = (maxPersonsInput.value || '').trim();
    const maxDaysRaw    = (maxDaysInput.value || '').trim();
    const priceRaw      = (priceInput.value || '').trim();


    let maxPersons = maxPersonsRaw === '' ? null : parseInt(maxPersonsRaw, 10);
    if (Number.isNaN(maxPersons)) {
      maxPersons = null;
    }

    let maxDays = maxDaysRaw === '' ? null : parseInt(maxDaysRaw, 10);
    if (Number.isNaN(maxDays)) {
      maxDays = null;
    }


    let price = priceRaw === '' ? null : parseFloat(priceRaw.replace(',', '.'));
    if (Number.isNaN(price)) {
      price = null;
    }

    if (enabled) {
      if (!from || !to) {
        alert('Za day-use nastavite začetno in končno uro.');
        return;
      }
      if (from >= to) {
        alert("Za day-use mora biti ura 'od' manjša od ure 'do'.");
        return;
      }
    }

    if (maxPersons !== null && (maxPersons < 1 || maxPersons > 10)) {
      alert('Max. št. oseb (day-use) mora biti med 1 in 10.');
      return;
    }

    if (maxDays !== null && (maxDays < 1 || maxDays > 10)) {
      alert('Max. št. dni vnaprej (day-use) mora biti med 1 in 10.');
      return;
    }

    if (price !== null && price < 0) {
      alert('Day-use cena na osebo ne sme biti negativna.');
      return;
    }

    const payload = { unit: unit };

    payload.day_use_enabled = enabled;

    if (enabled) {
      payload.day_use_from = from;
      payload.day_use_to   = to;
    }

    if (maxPersons !== null) {
      payload.day_use_max_persons = maxPersons;
    }

    if (maxDays !== null) {
      payload.day_use_max_days_ahead = maxDays;
    }

    if (price !== null) {
      payload.day_use_price_person = price;
    }

    try {
      const res = await safeFetchJson('/app/admin/api/unit_settings_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (!res || !res.ok) {
        console.warn('[integrations] unit_settings_save (day_use) failed', res);
        return;
      }

      console.log('[integrations] day-use BASIC settings saved', res.settings);
    } catch (err) {
      console.error('[integrations] saveDayUseFromAddUnitForm error', err);
    }
  }

    async function saveCapacityFromAddUnitForm(unit) {
    const $ = (sel) => document.querySelector(sel);

    const maxGuestsEl  = $('#add-cap-max-guests');
    const maxBedsEl    = $('#add-cap-max-beds');
    const minAdultsEl  = $('#add-cap-min-adults');
    const maxKids06El  = $('#add-cap-max-kids-06');
    const maxKids712El = $('#add-cap-max-kids-712');
    const allowBabyEl  = $('#add-cap-allow-baby-bed');

    const toIntOrNull = (el, min, max) => {
      if (!el) return null;
      const raw = el.value.trim();
      if (raw === '') return null;
      let v = parseInt(raw, 10);
      if (Number.isNaN(v)) return null;
      if (typeof min === 'number' && v < min) v = min;
      if (typeof max === 'number' && v > max) v = max;
      return v;
    };

    const maxGuests  = toIntOrNull(maxGuestsEl, 1, 50);
    const maxBeds    = toIntOrNull(maxBedsEl, 1, 50);
    const minAdults  = toIntOrNull(minAdultsEl, 1, 20);
    const maxKids06  = toIntOrNull(maxKids06El, 0, 20);
    const maxKids712 = toIntOrNull(maxKids712El, 0, 20);
    const allowBaby  = !!(allowBabyEl && allowBabyEl.checked);

    const anyNumeric =
      maxGuests !== null ||
      maxBeds   !== null ||
      minAdults !== null ||
      maxKids06 !== null ||
      maxKids712 !== null;

    if (!anyNumeric && !allowBaby) {
      // Ni nič nastavljenega → ne pošiljamo capacity bloka
      return;
    }

    const payload = { unit, capacity: {} };

    if (maxGuests !== null)  payload.capacity.max_guests        = maxGuests;
    if (maxBeds   !== null)  payload.capacity.max_beds          = maxBeds;
    if (minAdults !== null)  payload.capacity.min_adults        = minAdults;
    if (maxKids06 !== null)  payload.capacity.max_children_0_6  = maxKids06;
    if (maxKids712 !== null) payload.capacity.max_children_7_12 = maxKids712;

    // allow_baby_bed vedno pošljemo, da se lahko vklopi/izklopi
    payload.capacity.allow_baby_bed = allowBaby;

    try {
      const res = await safeFetchJson('/app/admin/api/unit_settings_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res || !res.ok) {
        console.warn('[integrations] unit_settings_save (capacity) failed', res);
      } else {
        console.log('[integrations] capacity settings saved', res.settings && res.settings.capacity);
      }
    } catch (err) {
      console.error('[integrations] saveCapacityFromAddUnitForm error', err);
    }
  }


  // ---------- Misc ----------

  function bindIcsCopyButtons() {
    $all('button.copy[data-copy-target]').forEach(btn => {
      btn.addEventListener('click', () => {
        const sel = btn.getAttribute('data-copy-target');
        const el = sel ? $(sel) : null;
        const text = el ? (el.textContent || '').trim() : '';
        if (!text) return;
        copyToClipboard(text).then(() => {
          diagOut.textContent = 'Kopirano v odložišče.';
        }).catch(err => {
          console.error(err);
          diagOut.textContent = 'Kopiranje ni uspelo.';
        });
      });
    });
  }

  function bindRawToggles() {
    if (promoToggleRaw && promoRaw) {
      promoToggleRaw.addEventListener('click', () => {
        const hidden = promoRaw.hidden;
        promoRaw.hidden = !hidden;
        promoToggleRaw.textContent = hidden ? 'Skrij surovi JSON' : 'Pokaži surovi JSON';
      });
    }
    if (offerToggleRaw && offerRaw) {
      offerToggleRaw.addEventListener('click', () => {
        const hidden = offerRaw.hidden;
        offerRaw.hidden = !hidden;
        offerToggleRaw.textContent = hidden ? 'Skrij surovi JSON' : 'Pokaži surovi JSON';
      });
    }
  }

  async function runDiagnostics() {
    const unit = getCurrentUnit();
    const parts = [];
    parts.push(`Unit: ${unit || '(none)'}`);
    parts.push(`Units count: ${state.units.length}`);
    try {
      const manifest = await safeFetchJson(CFG.manifest);
      parts.push('manifest.units: ' + (Array.isArray(manifest.units) ? manifest.units.length : 'n/a'));
    } catch (e) {
      parts.push('manifest error: ' + e.message);
    }
    diagOut.textContent = parts.join('\n');
  }

    // ----- Autopilot helpers -------------------------------------------------

  function autopilotApplyToForm(ap, scope) {
    ap = ap || {};
    const enabled   = !!ap.enabled;
    const mode      = ap.mode || 'auto_confirm_on_accept';
    const minDays   = typeof ap.min_days_before_arrival === 'number'
      ? ap.min_days_before_arrival : 2;
    const maxNights = typeof ap.max_nights === 'number'
      ? ap.max_nights : 14;
    const sourcesArr = Array.isArray(ap.allowed_sources) ? ap.allowed_sources : ['public', 'direct'];
    const sourcesStr = sourcesArr.join(', ');
    const checkAcc   = !!ap.check_ics_on_accept;
    const checkGuest = !!ap.check_ics_on_guest_confirm;

    if (scope === 'global') {
      if (!apGlobalEnabled) return;
      apGlobalEnabled.checked    = enabled;
      if (apGlobalMode)       apGlobalMode.value       = mode;
      if (apGlobalMinDays)    apGlobalMinDays.value    = String(minDays);
      if (apGlobalMaxNights)  apGlobalMaxNights.value  = String(maxNights);
      if (apGlobalSources)    apGlobalSources.value    = sourcesStr;
      if (apGlobalTestMode)  apGlobalTestMode.checked = !!ap.test_mode;
      if (apGlobalTestUntil) apGlobalTestUntil.value  = (ap.test_mode_until || '').trim();

         } else if (scope === 'unit') {
      if (!apUnitEnabled) return;
      apUnitEnabled.checked    = enabled;
      if (apUnitMode)      apUnitMode.value      = mode;
      if (apUnitMinDays)   apUnitMinDays.value   = String(minDays);
      if (apUnitMaxNights) apUnitMaxNights.value = String(maxNights);
      if (apUnitSources)   apUnitSources.value   = sourcesStr;

      // Najprej nastavimo vrednosti iz effective
      if (apUnitCheckAcc)   apUnitCheckAcc.checked   = checkAcc;
      if (apUnitCheckGuest) apUnitCheckGuest.checked = checkGuest;

      // --- UI LOCK: Production mode ---
      // Če je autopilot enabled in nismo v test_mode, so ICS checki obvezni
      const testMode = !!ap.test_mode;

      if (enabled && !testMode) {
        if (apUnitCheckAcc) {
          apUnitCheckAcc.checked  = true;
          apUnitCheckAcc.disabled = true;
          apUnitCheckAcc.title    = 'Production mode: ICS check is mandatory';
        }
        if (apUnitCheckGuest) {
          apUnitCheckGuest.checked  = true;
          apUnitCheckGuest.disabled = true;
          apUnitCheckGuest.title    = 'Production mode: ICS check is mandatory';
        }
      } else {
        // test mode (ali autopilot disabled) → admin ima kontrolo
        if (apUnitCheckAcc) {
          apUnitCheckAcc.disabled = false;
          apUnitCheckAcc.title    = '';
        }
        if (apUnitCheckGuest) {
          apUnitCheckGuest.disabled = false;
          apUnitCheckGuest.title    = '';
        }



      }
    }

  }

function autopilotFormToObj(scope) {
  if (scope === 'global') {
    if (!apGlobalEnabled) return null;

    const srcRaw = (apGlobalSources && apGlobalSources.value || '').trim();
    const srcArr = srcRaw ? srcRaw.split(',').map(s => s.trim()).filter(Boolean) : [];

    return {
      enabled: apGlobalEnabled.checked,
      mode: apGlobalMode ? apGlobalMode.value : 'auto_confirm_on_accept',
      min_days_before_arrival: apGlobalMinDays ? Number(apGlobalMinDays.value || 0) : 0,
      max_nights: apGlobalMaxNights ? Number(apGlobalMaxNights.value || 0) : 0,
      allowed_sources: srcArr,

      test_mode: apGlobalTestMode ? !!apGlobalTestMode.checked : false,
      test_mode_until: apGlobalTestUntil ? (apGlobalTestUntil.value || '').trim() : '',
    };
  }

  if (scope === 'unit') {
    if (!apUnitEnabled) return null;

    const srcRaw = (apUnitSources && apUnitSources.value || '').trim();
    const srcArr = srcRaw ? srcRaw.split(',').map(s => s.trim()).filter(Boolean) : [];

    return {
      enabled: apUnitEnabled.checked,
      mode: apUnitMode ? apUnitMode.value : 'auto_confirm_on_accept',
      min_days_before_arrival: apUnitMinDays ? Number(apUnitMinDays.value || 0) : 0,
      max_nights: apUnitMaxNights ? Number(apUnitMaxNights.value || 0) : 0,
      allowed_sources: srcArr,
      check_ics_on_accept: apUnitCheckAcc ? apUnitCheckAcc.checked : false,
      check_ics_on_guest_confirm: apUnitCheckGuest ? apUnitCheckGuest.checked : false,
    };
  }

  return null;
}
  async function loadAutopilotGlobal() {
    if (!CFG.api.autopilotGet || !apGlobalEnabled) return;
    try {
      const res = await safeFetchJson(CFG.api.autopilotGet);
      if (!res || !res.ok) throw new Error(res.error || 'autopilot_get failed');
      state.autopilot.global = res.global || {};
      autopilotApplyToForm(state.autopilot.global, 'global');
      diagOut.textContent = 'Autopilot (global) naložen.';
    } catch (err) {
      console.error('[Autopilot] loadGlobal', err);
      diagOut.textContent = `Napaka pri branju Autopilota (global): ${err.message}`;
    }
  }

  async function saveAutopilotGlobal() {
    const ap = autopilotFormToObj('global');
    if (!ap) return;
    try {
      const res = await safeFetchJson(CFG.api.autopilotSave, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope: 'global', autopilot: ap }),
      });
      if (!res || !res.ok) throw new Error(res.error || 'autopilot_save failed');
      state.autopilot.global = res.autopilot || ap;
      diagOut.textContent = 'Autopilot (global) shranjen.';
    } catch (err) {
      console.error('[Autopilot] saveGlobal', err);
      diagOut.textContent = `Napaka pri shranjevanju Autopilota (global): ${err.message}`;
    }
  }

  async function openUnitAutopilot(unitId) {
    if (!dlgApUnit || !unitId) return;
    dlgApUnit.dataset.unitId = unitId;
    if (apUnitLabel) apUnitLabel.textContent = unitId;

    try {
      const url = CFG.api.autopilotGet + '?unit=' + encodeURIComponent(unitId);
      const res = await safeFetchJson(url);
      if (!res || !res.ok) throw new Error(res.error || 'autopilot_get(unit) failed');
      const ap = res.effective || res.unit_settings || res.global || {};
      autopilotApplyToForm(ap, 'unit');
      dlgApUnit.showModal();
    } catch (err) {
      console.error('[Autopilot] openUnitAutopilot', err);
      alert('Napaka pri branju Autopilota za enoto.');
    }
  }

  async function saveUnitAutopilot(ev) {
    if (ev) ev.preventDefault();
    if (!dlgApUnit) return;
    const unitId = dlgApUnit.dataset.unitId;
    if (!unitId) {
      dlgApUnit.close();
      return;
    }
    const ap = autopilotFormToObj('unit');
    if (!ap) {
      dlgApUnit.close();
      return;
    }

    try {
      const res = await safeFetchJson(CFG.api.autopilotSave, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope: 'unit', unit: unitId, autopilot: ap }),
      });
      if (!res || !res.ok) throw new Error(res.error || 'autopilot_save(unit) failed');
      dlgApUnit.close();
      diagOut.textContent = `Autopilot za enoto ${unitId} shranjen.`;
    } catch (err) {
      console.error('[Autopilot] saveUnitAutopilot', err);
      alert('Napaka pri shranjevanju Autopilota za enoto.');
    }
  }


  // ---------- Init / bindings ----------

  function bindEvents() {
    if (unitSelect) {
      unitSelect.addEventListener('change', onUnitChange);
    }

    if (btnAddUnit) {
      btnAddUnit.addEventListener('click', openAddUnit);
    }
    if (btnAddUnitOpen) {
      btnAddUnitOpen.addEventListener('click', openAddUnit);
    }

    if (btnCancelAdd) {
      btnCancelAdd.addEventListener('click', closeAddUnit);
    }

    if (formAddUnit) {
      formAddUnit.addEventListener('submit', submitAddUnit);
    }

    if (btnRefreshUnits) {
      btnRefreshUnits.addEventListener('click', async () => {
        await loadUnitsList();
        renderUnitsList();
      });
    }

    if (btnRefreshICS) {
      btnRefreshICS.addEventListener('click', () => {
        refreshIcsUrls();
      });
    }

    if (btnDiag) {
      btnDiag.addEventListener('click', () => {
        runDiagnostics().catch(console.error);
      });
    }

    if (btnAddPromo) {
      btnAddPromo.addEventListener('click', () => {
        Promo.addNew();
      });
    }

    if (btnPromoDeleteSelected) {
      btnPromoDeleteSelected.addEventListener('click', () => {
        Promo.deleteSelected();
      });
    }

    if (btnPromoPublish) {
      btnPromoPublish.addEventListener('click', () => {
        Promo.save();
      });
    }

    if (btnAddOffer) {
      btnAddOffer.addEventListener('click', () => {
        Offers.addNew();
      });
    }

    if (btnOffersPublish) {
      btnOffersPublish.addEventListener('click', () => {
        Offers.save();
      });
    }

    if (promoToggleRaw || offerToggleRaw) {
      bindRawToggles();
    }

    if (jsonEditForm) {
      jsonEditForm.addEventListener('submit', onJsonEditSubmit);
    }
    if (jsonEditDelete) {
      jsonEditDelete.addEventListener('click', onJsonEditDelete);
    }
    if (jsonEditCancel) {
      jsonEditCancel.addEventListener('click', onJsonEditCancel);
    }

    bindIcsCopyButtons();

    if (unitsListBox) {
      unitsListBox.addEventListener('click', onUnitsListClick);
    }

    // Autopilot – global
    if (btnApGlobalSave) {
      btnApGlobalSave.addEventListener('click', () => {
        saveAutopilotGlobal().catch(console.error);
      });
    }
    if (btnApGlobalReload) {
      btnApGlobalReload.addEventListener('click', () => {
        loadAutopilotGlobal().catch(console.error);
      });
    }

    // Autopilot – per-unit modal
    if (formApUnit) {
      formApUnit.addEventListener('submit', saveUnitAutopilot);
    }
    if (btnApUnitCancel) {
      btnApUnitCancel.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (dlgApUnit) dlgApUnit.close();
      });
    }

         if (apGlobalTest15) {
      apGlobalTest15.addEventListener('click', () => {
        // nastavi test_mode + until = now + 15min (ISO z offsetom browserja)
        const now = new Date();
        const until = new Date(now.getTime() + 15 * 60 * 1000);
        if (apGlobalTestMode) apGlobalTestMode.checked = true;
        if (apGlobalTestUntil) apGlobalTestUntil.value = until.toISOString();
        diagOut.textContent = 'TEST MODE nastavljen za 15 minut (local ISO).';
      });
    }
    if (apGlobalTestClear) {
      apGlobalTestClear.addEventListener('click', () => {
        if (apGlobalTestUntil) apGlobalTestUntil.value = '';
        diagOut.textContent = 'TEST MODE datum počiščen.';
      });
    }


  }

  (async () => {
    try {
      const storedUnit = loadLastUnit();

      // Če je PHP/URL že nastavil enoto (CFG.unit → state.currentUnit),
      // NE prepisujemo z localStorage.
      if (!state.currentUnit && storedUnit) {
        state.currentUnit = storedUnit;
      }

      await loadUnitsList();
      renderUnitsList();
      refreshIcsUrls();
      await refreshChannelsUi();
      await Promo.loadAndRender();
      await Offers.loadAndRender();


      // Če smo prišli iz admin koledarja z ?open=new_offer,
      // odpri modal z novo, predizpolnjeno akcijo
      maybeOpenPrefilledOffer();
    } catch (e) {
      console.error(e);
      diagOut.textContent = `Napaka pri zagonu: ${e.message}`;
    }
    bindEvents();

  })();


})();
