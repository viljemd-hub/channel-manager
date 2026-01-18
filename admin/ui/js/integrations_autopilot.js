/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_autopilot.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * /app/admin/ui/js/integrations_autopilot.js
 * Global + per-unit Autopilot UI
 */
(() => {
  'use strict';

  function boot() {
    const ctx = window.CM_INTEGRATIONS;
    if (!ctx) return;

    const { CFG, helpers } = ctx;

    // Global form DOM
    const apGlobalEnabled  = document.getElementById('ap-global-enabled');
    const apGlobalMode     = document.getElementById('ap-global-mode');
    const apGlobalMinDays  = document.getElementById('ap-global-min-days');
    const apGlobalMaxNights= document.getElementById('ap-global-max-nights');
    const apGlobalSources  = document.getElementById('ap-global-sources');
    const apGlobalTestMode = document.getElementById('ap-global-test-mode');

    const btnApGlobalSave   = document.getElementById('btnAutopilotSave');
    const btnApGlobalReload = document.getElementById('btnAutopilotReload');

    const diagOut = document.getElementById('diagOut');

    // Per-unit modal (if exists in integrations.php)
    const dlgUnitAp   = document.getElementById('dlgAutopilotUnit');
    const formUnitAp  = document.getElementById('formAutopilotUnit');

    // If your modal fields exist, wire them; if not, we still keep global working.
    const apUnitTitle = document.getElementById('ap-unit-id-label');
    const apUnitEnabled  = document.getElementById('ap-unit-enabled');
    const apUnitMode     = document.getElementById('ap-unit-mode');
    const apUnitMinDays  = document.getElementById('ap-unit-min-days');
    const apUnitMaxNights= document.getElementById('ap-unit-max-nights');
    const apUnitSources  = document.getElementById('ap-unit-sources');

    const btnUnitSave     = document.getElementById('btnAutopilotUnitSave');
    const btnUnitCancel   = document.getElementById('btnAutopilotUnitCancel');

    let currentUnit = '';

    function setDiag(msg) {
      if (diagOut) diagOut.textContent = msg || '';
    }

    // 🔒 Edition / plan detection (Free vs Plus)
    const planRaw = String(CFG.plan || CFG.edition || '').toLowerCase();
    const isPlusEdition = !!CFG.isPlus || planRaw === 'plus' || planRaw === 'pro';

    // DEBUG: log to console so we see what UI detected
    console.log('[autopilot] plan =', planRaw, 'isPlusEdition =', isPlusEdition);

    // In CM Free, Autopilot UI is locked and configuration is read-only.
    if (!isPlusEdition) {
      // Root Autopilot card (HTML: <article class="card" id="card-autopilot">)
      const root = document.getElementById('card-autopilot');

      if (root) {
        root.classList.add('cm-autopilot-locked');

        // Disable all controls in the card
        const controls = root.querySelectorAll(
          'input, select, textarea, button'
        );
        controls.forEach((el) => {
          el.disabled = true;
        });
      }

      // Diagnostic/info line for the user
      setDiag(
        '🔒 Avtopilot je na voljo samo v CM Plus. V CM Free je vedno izklopljen.'
      );

      // Skip loading/saving any config in Free mode
      return;
    }


    function readGlobalPayload() {
      const srcArr = (apGlobalSources?.value || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean);

      const autopilot = {
        enabled: apGlobalEnabled?.checked || false,
        mode: apGlobalMode ? apGlobalMode.value : 'auto_confirm_on_accept',
        test_mode: apGlobalTestMode ? (apGlobalTestMode.value || 'off') : 'off',
        min_days_before_arrival: apGlobalMinDays ? Number(apGlobalMinDays.value || 0) : 0,
        max_nights: apGlobalMaxNights ? Number(apGlobalMaxNights.value || 0) : 0,
        allowed_sources: srcArr,
      };

      return {
        scope: 'global',
        autopilot,
      };
    }


    function fillGlobalForm(data) {
      const g = data?.global || data?.autopilot?.global || data || {};
      if (apGlobalEnabled) apGlobalEnabled.checked = !!g.enabled;
      if (apGlobalMode && g.mode) apGlobalMode.value = String(g.mode);
      if (apGlobalTestMode && g.test_mode) apGlobalTestMode.value = String(g.test_mode);
      if (apGlobalMinDays) apGlobalMinDays.value = String(g.min_days_before_arrival ?? 0);
      if (apGlobalMaxNights) apGlobalMaxNights.value = String(g.max_nights ?? 0);
      if (apGlobalSources) apGlobalSources.value = Array.isArray(g.allowed_sources) ? g.allowed_sources.join(', ') : '';
    }

    async function loadGlobal() {
      const res = await helpers.safeFetchJson(CFG.api.autopilotGet, { cache: 'no-store' });
      if (!res || res.ok === false) throw new Error(res?.error || 'autopilot_get_failed');
      fillGlobalForm(res);
      return res;
    }

    async function saveGlobal() {
      const payload = readGlobalPayload();
      const res = await helpers.safeFetchJson(CFG.api.autopilotSave, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res || res.ok === false) throw new Error(res?.error || 'autopilot_save_failed');
      return res;
    }

    // ----- per-unit modal helpers -----
function openUnitModal(unit, data) {
  currentUnit = unit || '';
  if (!dlgUnitAp) {
    setDiag(`(info) Autopilot per-unit UI modal še ni v HTML. Unit: ${currentUnit}`);
    return;
  }

  // v span-u prikažemo ID enote
  if (apUnitTitle) apUnitTitle.textContent = currentUnit;

  // najprej poskusi per-unit override, potem effective, nazadnje global
  const u =
    (data && (data.unit_settings || data.effective || data.global)) ||
    {};

  if (apUnitEnabled) apUnitEnabled.checked = !!u.enabled;
  if (apUnitMode && u.mode) apUnitMode.value = String(u.mode);
  if (apUnitMinDays)
    apUnitMinDays.value = String(u.min_days_before_arrival ?? 0);
  if (apUnitMaxNights)
    apUnitMaxNights.value = String(u.max_nights ?? 0);
  if (apUnitSources)
    apUnitSources.value = Array.isArray(u.allowed_sources)
      ? u.allowed_sources.join(', ')
      : '';

  dlgUnitAp.showModal();
}


    function readUnitPayload() {
      const srcArr = (apUnitSources?.value || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean);

      const autopilot = {
        enabled: apUnitEnabled?.checked || false,
        mode: apUnitMode ? apUnitMode.value : 'auto_confirm_on_accept',
        min_days_before_arrival: apUnitMinDays ? Number(apUnitMinDays.value || 0) : 0,
        max_nights: apUnitMaxNights ? Number(apUnitMaxNights.value || 0) : 0,
        allowed_sources: srcArr,
      };

      return {
        scope: 'unit',
        unit: currentUnit,
        autopilot,
      };
    }

    async function loadUnit(unit) {
      const res = await helpers.safeFetchJson(`${CFG.api.autopilotGet}?unit=${encodeURIComponent(unit)}`, { cache: 'no-store' });
      if (!res || res.ok === false) throw new Error(res?.error || 'autopilot_get_unit_failed');
      return res;
    }

    async function saveUnit() {
      const payload = readUnitPayload();
      const res = await helpers.safeFetchJson(CFG.api.autopilotSave, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res || res.ok === false) throw new Error(res?.error || 'autopilot_save_unit_failed');
      return res;
    }

    // ----- wiring -----
    btnApGlobalReload?.addEventListener('click', async () => {
      try {
        setDiag('Osvežujem Autopilot (global)…');
        await loadGlobal();
        setDiag('✅ Autopilot (global) osvežen.');
      } catch (e) {
        console.error(e);
        setDiag(`❌ Autopilot reload failed: ${e.message}`);
      }
    });

    btnApGlobalSave?.addEventListener('click', async () => {
      try {
        setDiag('Shranjujem Autopilot (global)…');
        await saveGlobal();
        setDiag('✅ Autopilot (global) shranjen.');
      } catch (e) {
        console.error(e);
        setDiag(`❌ Autopilot save failed: ${e.message}`);
      }
    });

// per-unit modal buttons (if exist)
btnUnitCancel?.addEventListener('click', (e) => {
  e.preventDefault();
  try { dlgUnitAp?.close(); } catch (_) {}
});

// handle submit of the per-unit form (click on "Shrani")
formUnitAp?.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    setDiag(`Shranjujem Autopilot (${currentUnit})…`);
    await saveUnit();
    setDiag(`✅ Autopilot (${currentUnit}) shranjen.`);
    try { dlgUnitAp?.close(); } catch (_) {}
  } catch (err) {
    console.error(err);
    setDiag(`❌ Autopilot unit save failed: ${err.message}`);
  }
});

    // event from Units table: open per-unit autopilot
    window.addEventListener('cm-open-autopilot-unit', async (ev) => {
      const unit = ev?.detail?.unit || '';
      if (!unit) return;

      try {
        setDiag(`Nalagam Autopilot (${unit})…`);
        const res = await loadUnit(unit);
        openUnitModal(unit, res);
        setDiag(`✅ Autopilot (${unit}) naložen.`);
      } catch (e) {
        console.error(e);
        setDiag(`❌ Autopilot unit load failed: ${e.message}`);
      }
    });
    // 🔒 Edition / plan detection (Free vs Plus)
    const integCtx = window.CM_INTEGRATIONS || {};
    const rawPlan =
      integCtx.plan ||
      integCtx.edition ||
      integCtx.mode ||
      integCtx.tier ||
      '';
   
    // In CM Free, Autopilot UI is locked and configuration is read-only.
    if (!isPlusEdition) {
      const root =
        document.querySelector('[data-cm-autopilot-root]') ||
        document.getElementById('cmAutopilotCard');

      if (root) {
        root.classList.add('cm-autopilot-locked');


        // Disable all controls in the card
        const controls = root.querySelectorAll(
          'input, select, textarea, button'
        );
        controls.forEach((el) => {
          el.disabled = true;
        });
      }

      // Diagnostic/info line for the user
      if (typeof setDiag === 'function') {
        setDiag(
          '🔒 Avtopilot je na voljo samo v CM Plus. V CM Free je vedno izklopljen.'
        );
      }

      // Skip loading/saving any config in Free mode
      return;
    }

    // initial global load
    (async () => {
      try {
        await loadGlobal();
      } catch (e) {
        console.error(e);
        setDiag(`❌ Autopilot init failed: ${e.message}`);
      }
    })();
  }

  if (window.CM_INTEGRATIONS) boot();
  else window.addEventListener('cm-integrations-ready', boot, { once: true });
})();
