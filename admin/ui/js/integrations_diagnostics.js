/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_diagnostics.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_diagnostics.js
 */
(() => {
  'use strict';

  function boot() {
    const ctx = window.CM_INTEGRATIONS;
    if (!ctx) return;

    const { helpers, state, getCurrentUnit, CFG } = ctx;
    const { $ } = helpers;

    const btnDiag = $('#btnDiag');
    const diagOut = $('#diagOut');

    async function run() {
      const unit = getCurrentUnit();
      const parts = [];
      parts.push(`Unit: ${unit || '(none)'}`);
      parts.push(`Units count: ${state.units.length}`);
      try {
        const manifest = await helpers.safeFetchJson(CFG.manifest);
        parts.push('manifest.units: ' + (Array.isArray(manifest.units) ? manifest.units.length : 'n/a'));
      } catch (e) {
        parts.push('manifest error: ' + e.message);
      }
      if (diagOut) diagOut.textContent = parts.join('\n');
    }

    ctx.Diagnostics = { run };

    btnDiag?.addEventListener('click', () => run().catch(console.error));
  }

  if (window.CM_INTEGRATIONS) boot();
  else window.addEventListener('cm-integrations-ready', boot, { once: true });
})();
