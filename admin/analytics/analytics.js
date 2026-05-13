/*
|--------------------------------------------------------------------------
| CM-PRO-ONLY
|--------------------------------------------------------------------------
| Module: Analytics UI
| Feature: Charts + stats rendering
| Added: 2026-03
|--------------------------------------------------------------------------
*/
// CM PRO – Analytics frontend (no external libs)
// Fetch stats + series from /admin/api/analytics_get.php and render dashboard with custom canvas charts.

(function () {
  const cfg = window.CM_ANALYTICS || {};
  const apiUrl =
    (cfg.apiBase || 'api') +
    '/analytics_get.php' +
    (cfg.key ? ('?key=' + encodeURIComponent(cfg.key)) : '');

  const el = (id) => document.getElementById(id);

  const dom = {
    btnRefresh: el('btnRefresh'),
    lastUpdated: el('lastUpdated'),
    sInq30: el('sInq30'),
    sConf30: el('sConf30'),
    sAvgNights30: el('sAvgNights30'),
    sCancelRate90: el('sCancelRate90'),
    c1Note: el('c1Note'),
    chartConfirmed: el('chartConfirmed'),
    chartFunnel: el('chartFunnel'),
  };

  function fmtNum(n) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return String(n);
  }

  function fmtPct(n) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return (Math.round(n * 10) / 10).toFixed(1) + '%';
  }

  function setLoading(isLoading) {
    if (!dom.btnRefresh) return;
    dom.btnRefresh.disabled = isLoading;
    dom.btnRefresh.textContent = isLoading ? 'Loading…' : 'Refresh';
  }

  function setCanvasSize(canvas) {
    // Make canvas crisp on HiDPI while keeping CSS size
    if (!canvas) return null;
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(10, Math.floor(rect.width));
    const h = Math.max(10, Math.floor(rect.height));
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, w, h };
  }

  function clear(ctx, w, h) {
    ctx.clearRect(0, 0, w, h);
  }

  function drawFrame(ctx, x, y, w, h) {
    // no explicit colors requested; using default strokeStyle (browser default) would be ugly,
    // but we need *some* visibility. We'll use currentColor-ish via CSS? Canvas can't.
    // We'll pick neutral rgba without trying to match branding.
    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,0.12)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x, y, w, h);
    ctx.restore();
  }

  function drawGrid(ctx, x, y, w, h, rows) {
    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,0.10)';
    ctx.lineWidth = 1;
    for (let i = 1; i < rows; i++) {
      const yy = y + (h * i) / rows;
      ctx.beginPath();
      ctx.moveTo(x, yy);
      ctx.lineTo(x + w, yy);
      ctx.stroke();
    }
    ctx.restore();
  }

  function drawLineChart(canvas, labels, values, opts) {
    const m = { l: 34, r: 10, t: 10, b: 22, ...(opts && opts.margin) };
    const rows = (opts && opts.gridRows) || 4;

    const sized = setCanvasSize(canvas);
    if (!sized) return;
    const { ctx, w, h } = sized;

    clear(ctx, w, h);

    const px = m.l;
    const py = m.t;
    const pw = w - m.l - m.r;
    const ph = h - m.t - m.b;

    drawGrid(ctx, px, py, pw, ph, rows);
    drawFrame(ctx, px, py, pw, ph);

    const n = values.length;
    if (!n) return;

    let maxV = 0;
    for (const v of values) maxV = Math.max(maxV, Number(v) || 0);
    // Keep some headroom
    const yMax = Math.max(1, Math.ceil(maxV * 1.1));

    // Y ticks
    ctx.save();
    ctx.fillStyle = 'rgba(255,255,255,0.65)';
    ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (let i = 0; i <= rows; i++) {
      const t = rows - i;
      const v = Math.round((yMax * t) / rows);
      const yy = py + (ph * i) / rows;
      ctx.fillText(String(v), px - 6, yy);
    }
    ctx.restore();

    const xAt = (i) => px + (pw * (n === 1 ? 0 : i / (n - 1)));
    const yAt = (v) => py + ph - (ph * (Number(v) || 0)) / yMax;

    // Line
    ctx.save();
    ctx.strokeStyle = '#c93048';
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (let i = 0; i < n; i++) {
      const xx = xAt(i);
      const yy = yAt(values[i]);
      if (i === 0) ctx.moveTo(xx, yy);
      else ctx.lineTo(xx, yy);
    }
    ctx.stroke();

    // Points
    ctx.fillStyle = '#c93048';
    for (let i = 0; i < n; i++) {
      const xx = xAt(i);
      const yy = yAt(values[i]);
      ctx.beginPath();
      ctx.arc(xx, yy, 2.6, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.restore();

    // Minimal X labels (first, middle, last)
    const pick = new Set([0, Math.floor((n - 1) / 2), n - 1]);
    ctx.save();
    ctx.fillStyle = 'rgba(255,255,255,0.55)';
    ctx.font = '11px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (const i of pick) {
      const lab = labels[i] || '';
      ctx.fillText(lab, xAt(i), py + ph + 6);
    }
    ctx.restore();
  }

  function drawBarChart(canvas, labels, values, opts) {
    const m = { l: 26, r: 10, t: 10, b: 26, ...(opts && opts.margin) };
    const rows = (opts && opts.gridRows) || 4;

    const sized = setCanvasSize(canvas);
    if (!sized) return;
    const { ctx, w, h } = sized;

    clear(ctx, w, h);

    const px = m.l;
    const py = m.t;
    const pw = w - m.l - m.r;
    const ph = h - m.t - m.b;

    drawGrid(ctx, px, py, pw, ph, rows);
    drawFrame(ctx, px, py, pw, ph);

    const n = values.length;
    if (!n) return;

    let maxV = 0;
    for (const v of values) maxV = Math.max(maxV, Number(v) || 0);
    const yMax = Math.max(1, Math.ceil(maxV * 1.1));

    const gap = Math.max(6, Math.floor(pw * 0.04));
    const barW = Math.max(10, Math.floor((pw - gap * (n - 1)) / n));

    ctx.save();

const COLORS = {
  inquiries: '#f4c430',   // warm yellow
  accepted:  '#1f8f3a',   // green (confirm button style)
  confirmed: '#7a1e2c'    // bordo red
};

for (let i = 0; i < n; i++) {
  const v = Number(values[i]) || 0;
  const bh = (ph * v) / yMax;
  const x = px + i * (barW + gap);
  const y = py + ph - bh;

  const label = (labels[i] || '').toLowerCase();
  ctx.fillStyle = COLORS[label] || 'rgba(255,255,255,0.8)';

  // draw bar
  ctx.fillRect(x, y, barW, bh);

  // draw value above bar
  ctx.save();
  ctx.fillStyle = 'rgba(255,255,255,0.85)';
  ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'bottom';
  ctx.fillText(String(v), x + barW / 2, y - 4);
  ctx.restore();
}
ctx.restore();

    // Labels under bars
    ctx.save();
    ctx.fillStyle = 'rgba(255,255,255,0.60)';
    ctx.font = '11px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (let i = 0; i < n; i++) {
      const x = px + i * (barW + gap) + barW / 2;
      ctx.fillText(labels[i] || '', x, py + ph + 6);
    }
    ctx.restore();
  }

  async function load() {
    setLoading(true);

    try {
      const res = await fetch(apiUrl, { credentials: 'same-origin' });
      const j = await res.json();

      if (!res.ok || !j || j.ok !== true) {
        throw new Error((j && j.error) ? j.error : 'analytics_get not ok');
      }

      renderStats(j);
      renderCharts(j);

      if (dom.lastUpdated) dom.lastUpdated.textContent = 'updated: ' + (j.generated || '—');
    } catch (e) {
      console.error('[analytics] load failed', e);
      if (window.CMUI && typeof window.CMUI.modalAlert === 'function') {
        window.CMUI.modalAlert({ title: 'Analytics error', text: String(e && e.message ? e.message : e) });
      } else {
        alert('Analytics error: ' + String(e && e.message ? e.message : e));
      }
    } finally {
      setLoading(false);
    }
  }

  function renderStats(j) {
    const s = j.stats || {};
    if (dom.sInq30) dom.sInq30.textContent = fmtNum(s.inquiries_30d);
    if (dom.sConf30) dom.sConf30.textContent = fmtNum(s.confirmed_30d);
    if (dom.sAvgNights30) dom.sAvgNights30.textContent = fmtNum(s.avg_nights_30d);
    if (dom.sCancelRate90) dom.sCancelRate90.textContent = fmtPct(s.cancel_rate_90d);
  }

  function renderCharts(j) {
    const c1 = (j.series && j.series.confirmed_per_week) ? j.series.confirmed_per_week : { labels: [], values: [] };
    if (dom.c1Note) dom.c1Note.textContent = c1.labels.length ? ('weeks: ' + c1.labels.length) : '—';
    drawLineChart(dom.chartConfirmed, c1.labels, c1.values, { gridRows: 4 });

    const f = (j.series && j.series.funnel_30d) ? j.series.funnel_30d : { labels: [], values: [] };
    drawBarChart(dom.chartFunnel, f.labels, f.values, { gridRows: 4 });
  }

  function onResize() {
    // re-draw charts with last loaded payload would be best,
    // but for MVP we just reload.
    load();
  }

  if (dom.btnRefresh) dom.btnRefresh.addEventListener('click', load);

  window.addEventListener('resize', () => {
    // cheap debounce
    clearTimeout(window.__cmAnaResizeT);
    window.__cmAnaResizeT = setTimeout(onResize, 200);
  });

  load();
})();