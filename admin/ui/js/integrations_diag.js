/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_diag.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/integrations_diag.js
(function () {
  function qs(sel, root=document){ return root.querySelector(sel); }

  async function ping(url) {
    const t0 = performance.now();
    const r = await fetch(url, { cache: 'no-store' });
    const ms = Math.round(performance.now() - t0);
    return { ok: r.ok, status: r.status, ms };
  }

  function render(container, state, CFG) {
    container.innerHTML = `
      <div class="card">
        <div class="card-h">
          <div>
            <div class="h">Diagnostics</div>
            <div class="sub">Hitri testi ključnih endpointov (da takoj vidiš 404/500).</div>
          </div>
          <div class="actions">
            <button class="btn" id="btnRunDiag">Run</button>
          </div>
        </div>
        <div class="card-b">
          <div id="diagOut" class="mono" style="white-space:pre; overflow:auto; max-height:360px; border:1px solid #223; border-radius:10px; padding:10px;"></div>
        </div>
      </div>
    `;

    const out = qs('#diagOut', container);

    function line(s){ out.textContent += s + '\n'; }

    qs('#btnRunDiag', container).addEventListener('click', async () => {
      out.textContent = '';
      const u = state.currentUnit || 'A1';

      const tests = [
        ['units_list', CFG.api.unitsList],
        ['offers_get', `${CFG.api.offersGet}?unit=${encodeURIComponent(u)}`],
        ['promo_get',  CFG.api.promoGet],
        ['autopilot_get', CFG.api.autopilotGet],
        ['channels_get', `${CFG.api.channelsGet}?unit=${encodeURIComponent(u)}`],
      ];

      for (const [name, url] of tests) {
        try {
          const r = await ping(url);
          line(`${name}: ${r.ok ? 'OK' : 'FAIL'} (${r.status}) ${r.ms}ms  -> ${url}`);
        } catch (e) {
          line(`${name}: ERROR -> ${url} :: ${(e && e.message) ? e.message : String(e)}`);
        }
      }
    });
  }

  window.integrationsDiag = { render };
})();
