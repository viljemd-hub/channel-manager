<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/logs.php
 *
 * Admin operational logs viewer (under BasicAuth + require_key()).
 * - Lists logs from several whitelisted folders/files
 * - Allows filtering, viewing, downloading
 * - Allows deleting selected / filtered logs (safe realpath guard)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

/** ========= Config: allowed log roots + sources ========= */
require_once __DIR__ . '/api/_lib/paths.php';

$APP_ROOT = app_root();

$allowedRoots = [
  $APP_ROOT . '/logs',
  $APP_ROOT . '/mail/logs',
  $APP_ROOT . '/common/logs',
  $APP_ROOT . '/common/data/json/inquiries/logs',
  $APP_ROOT . '/common/data/json/logs',
  $APP_ROOT . '/common/data/json/reviews/logs',
];

/**
 * Build a flat list of log files.
 * Returns absolute paths.
 */
function cm_collect_log_files(string $appRoot): array {
  $files = [];

  // Single files (if present)
  $single = [
    $appRoot . '/logs/app.log',
    $appRoot . '/logs/msmtp.log',
    $appRoot . '/mail/logs/contact_form.log',
    $appRoot . '/common/logs/finalize_debug.log',
  ];
  foreach ($single as $p) {
    if (is_file($p)) { $files[] = $p; }
  }

  // Directory globs (if present)
  $globs = [
    $appRoot . '/common/data/json/inquiries/logs/*.log',
    $appRoot . '/common/data/json/logs/*.log',
  ];
  foreach ($globs as $g) {
    foreach (glob($g) ?: [] as $p) {
      if (is_file($p)) { $files[] = $p; }
    }
  }

  // Review audit logs: /reviews/logs/<YEAR>/*.review_log.json
  $reviewsRoot = $appRoot . '/common/data/json/reviews/logs';
  if (is_dir($reviewsRoot)) {
    foreach (glob($reviewsRoot . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
      foreach (glob($yearDir . '/*.review_log.json') ?: [] as $p) {
        if (is_file($p)) { $files[] = $p; }
      }
    }
  }

  // De-dupe
  $files = array_values(array_unique($files));
  return $files;
}

/** Infer a "type" from path. */
function cm_log_type(string $path): string {
  if (str_contains($path, '/logs/app.log')) return 'APP';
  if (str_contains($path, '/logs/msmtp.log')) return 'MAIL';
  if (str_contains($path, '/mail/logs/')) return 'MAIL';
  if (str_contains($path, '/common/logs/')) return 'DEBUG';
  if (str_contains($path, '/inquiries/logs/')) return 'INQUIRY';
  if (str_contains($path, '/json/logs/')) return 'LOG';
  if (str_contains($path, '/reviews/logs/')) return 'REVIEW';
  return 'OTHER';
}

/** Extract year for filtering (best effort). */
function cm_log_year(string $path): string {
  // reviews/logs/2026/...
  if (preg_match('~/reviews/logs/(\d{4})/~', $path, $m)) return $m[1];

  // if filename starts with YYYY...
  $base = basename($path);
  if (preg_match('/^(\d{4})\d{2}\d{2}/', $base, $m)) return $m[1];

  // fallback: mtime year
  $t = @filemtime($path);
  if (is_int($t) && $t > 0) return (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Ljubljana'))->format('Y');

  return '';
}

/** Safe: check that file is within allowed roots. */
function cm_is_allowed_path(string $absPath, array $allowedRoots): bool {
  $rp = realpath($absPath);
  if ($rp === false) return false;
  foreach ($allowedRoots as $root) {
    $rr = realpath($root);
    if ($rr === false) continue;
    if (str_starts_with($rp, $rr . DIRECTORY_SEPARATOR) || $rp === $rr) {
      return true;
    }
  }
  return false;
}

/** JSON response helper */
function cm_json(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** ========= API (same file): ?a=list|read|delete ========= */

$action = $_GET['a'] ?? '';
if ($action !== '') {
  if ($action === 'list') {
    $typeFilter = trim((string)($_GET['type'] ?? ''));
    $yearFilter = trim((string)($_GET['year'] ?? ''));
    $q          = trim((string)($_GET['q'] ?? ''));

    $files = cm_collect_log_files($APP_ROOT);
    $out = [];

    foreach ($files as $p) {
      if (!cm_is_allowed_path($p, $allowedRoots)) continue;

      $type = cm_log_type($p);
      $year = cm_log_year($p);

      if ($typeFilter !== '' && $typeFilter !== 'ALL' && $type !== $typeFilter) continue;
      if ($yearFilter !== '' && $yearFilter !== 'ALL' && $year !== $yearFilter) continue;

      $base = basename($p);
      $mtime = @filemtime($p);
      $size = @filesize($p);

      // preview: first 600 bytes (safe)
      $preview = '';
      $fh = @fopen($p, 'rb');
      if ($fh) {
        $preview = (string)fread($fh, 600);
        fclose($fh);
      }
      $preview = preg_replace('/[^\P{C}\t\r\n]/u', '', $preview) ?? ''; // strip control chars

      // search matches filename or preview
      if ($q !== '') {
        $hay = mb_strtolower($base . "\n" . $preview);
        if (!str_contains($hay, mb_strtolower($q))) continue;
      }

      $out[] = [
        'type' => $type,
        'year' => $year,
        'file' => $base,
        'path' => $p, // absolute (used for delete/read, validated server-side)
        'size' => is_int($size) ? $size : 0,
        'mtime' => is_int($mtime) ? $mtime : 0,
        'mtime_iso' => is_int($mtime) && $mtime > 0
          ? (new DateTimeImmutable('@' . $mtime))->setTimezone(new DateTimeZone('Europe/Ljubljana'))->format('Y-m-d H:i:s')
          : '',
        'preview' => $preview,
      ];
    }

    usort($out, fn($a, $b) => ($b['mtime'] <=> $a['mtime']));
    cm_json(['ok' => true, 'items' => $out]);
  }

  if ($action === 'read') {
    $path = (string)($_GET['path'] ?? '');
    if ($path === '' || !cm_is_allowed_path($path, $allowedRoots) || !is_file($path)) {
      cm_json(['ok' => false, 'error' => 'not_allowed'], 400);
    }

    // cap read size to avoid huge payloads
    $max = 200_000; // 200 KB
    $data = @file_get_contents($path, false, null, 0, $max);
    if ($data === false) {
      cm_json(['ok' => false, 'error' => 'read_failed'], 500);
    }

    cm_json([
      'ok' => true,
      'file' => basename($path),
      'type' => cm_log_type($path),
      'content' => $data,
      'truncated' => (is_int(@filesize($path)) && @filesize($path) > $max),
    ]);
  }

  if ($action === 'raw') {
    $path = (string)($_GET['path'] ?? '');
    if ($path === '' || !cm_is_allowed_path($path, $allowedRoots) || !is_file($path)) {
      http_response_code(400);
      echo "not_allowed";
      exit;
    }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }

  if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      cm_json(['ok' => false, 'error' => 'only_post_allowed'], 405);
    }
    $raw = (string)file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['paths']) || !is_array($j['paths'])) {
      cm_json(['ok' => false, 'error' => 'bad_json'], 400);
    }

    $deleted = [];
    $failed = [];

    foreach ($j['paths'] as $p) {
      $p = (string)$p;
      if ($p === '' || !cm_is_allowed_path($p, $allowedRoots) || !is_file($p)) {
        $failed[] = ['path' => $p, 'error' => 'not_allowed'];
        continue;
      }
      if (@unlink($p)) {
        $deleted[] = $p;
      } else {
        $failed[] = ['path' => $p, 'error' => 'unlink_failed'];
      }
    }

    cm_json(['ok' => true, 'deleted' => $deleted, 'failed' => $failed]);
  }

  cm_json(['ok' => false, 'error' => 'unknown_action'], 400);
}

/** ========= UI (HTML) ========= */

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logs</title>

  <!-- keep admin shell consistent with manage_reservations.php -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <style>
    :root{
      --bg:#0e131a;
      --card:#131a22;
      --border:#233041;
      --muted:#9bb0c3;
      --text:#e6f0f8;
      --btn:#17202b;
      --btn2:#1e2937;
      --accent:#facc15;
      --danger:#b00020;
      --ok:#27c07d;
    }
    body.adm-shell.theme-dark{ background:var(--bg); color:var(--text); }

    #logs-root{ padding:16px; }

    .lg-toolbar{
      display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;
      padding:.6rem; border-bottom:1px solid var(--border);
      background:var(--bg);
      position:sticky; top:4.0rem; z-index:10;
    }
    .lg-toolbar .sp{ flex:1; }
    .lg-toolbar select,
    .lg-toolbar input[type="search"]{
      padding:.35rem .5rem;
      background:#0d1218;
      color:var(--text);
      border:1px solid var(--border);
      border-radius:.5rem;
      outline:none;
      min-height:34px;
    }
    .lg-btn{
      padding:.4rem .75rem;
      border-radius:.5rem;
      border:1px solid var(--border);
      background:var(--btn);
      color:var(--text);
      cursor:pointer;
      min-height:34px;
    }
    .lg-btn:hover{ background:var(--btn2); }
    .lg-btn.danger{
      background:var(--danger);
      border-color:var(--danger);
      color:#fff;
    }
    .lg-btn.primary{
      border-color:rgba(250,204,21,.35);
      box-shadow:0 0 0 1px rgba(250,204,21,.15) inset;
    }

    .lg-grid{
      display:grid;
      grid-template-columns: 1fr;
      gap:.75rem;
      padding: 12px 0;
    }

    .lg-card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:.75rem;
      padding:.75rem;
      box-shadow:0 8px 26px rgba(0,0,0,.35);
    }
    .lg-head{
      display:flex; gap:.75rem; align-items:flex-start; justify-content:space-between;
    }
    .lg-left{
      display:flex; gap:.6rem; align-items:flex-start;
      min-width:0;
    }
    .lg-check{
      margin-top:.15rem;
      transform: scale(1.1);
    }
    .lg-title{
      min-width:0;
    }
    .lg-file{
      font-weight:650;
      word-break:break-word;
    }
    .lg-meta{
      color:var(--muted);
      font-size:.88em;
      margin-top:.2rem;
      display:flex; flex-wrap:wrap; gap:.5rem;
    }
    .lg-badge{
      display:inline-block;
      font-size:.75em;
      padding:.14rem .45rem;
      border-radius:.45rem;
      border:1px solid var(--border);
      background:rgba(255,255,255,.03);
      color:var(--text);
    }
    .lg-badge.review{ border-color:rgba(250,204,21,.35); }
    .lg-badge.mail{ border-color:rgba(77,171,247,.35); }
    .lg-badge.debug{ border-color:rgba(155,176,195,.35); }

    .lg-actions{
      display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end;
      margin-left: .75rem;
      flex:0 0 auto;
    }

    .lg-preview{
      margin-top:.6rem;
      background:#05070f;
      border:1px solid #2b3242;
      border-radius:.6rem;
      padding:.55rem .65rem;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size:.78em;
      color:#e9edf7;
      max-height: 210px;
      overflow:auto;
      white-space:pre-wrap;
      word-break:break-word;
    }

    .lg-status{
      margin:10px 0 0;
      color: var(--muted);
      font-size:.9em;
    }

    /* Modal */
    .lg-modal{
      position:fixed; inset:0; background:rgba(0,0,0,.55);
      display:none; align-items:center; justify-content:center;
      padding:16px;
      z-index:999;
    }
    .lg-modal.open{ display:flex; }
    .lg-modal-card{
      width:min(1100px, 96vw);
      max-height: 86vh;
      overflow:hidden;
      background:var(--card);
      border:1px solid var(--border);
      border-radius: .9rem;
      box-shadow:0 18px 60px rgba(0,0,0,.65);
      display:flex;
      flex-direction:column;
    }
    .lg-modal-h{
      display:flex; align-items:center; justify-content:space-between;
      gap:.75rem;
      padding:.7rem .9rem;
      border-bottom:1px solid var(--border);
    }
    .lg-modal-h .t{ font-weight:650; min-width:0; word-break:break-word; }
    .lg-modal-body{
      padding:.8rem .9rem;
      overflow:auto;
    }
    .lg-modal-pre{
      background:#05070f;
      border:1px solid #2b3242;
      border-radius:.6rem;
      padding:.65rem .75rem;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size:.82em;
      color:#e9edf7;
      white-space:pre-wrap;
      word-break:break-word;
    }
    @media (min-width: 900px){
      .lg-grid{ grid-template-columns: repeat(auto-fill, minmax(520px, 1fr)); }
    }
  </style>
</head>
<body class="adm-shell theme-dark">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">Logs</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="/app/admin/manage_reservations.php" title="Reservations">← Reservations</a>
      <a class="btn small" href="/app/admin/admin_calendar.php" title="Calendar">Calendar</a>
    </div>
  </header>

  <main id="logs-root">
    <div class="lg-toolbar">
      <select id="lg-type">
        <option value="ALL">All types</option>
        <option value="APP">APP</option>
        <option value="MAIL">MAIL</option>
        <option value="INQUIRY">INQUIRY</option>
        <option value="DEBUG">DEBUG</option>
        <option value="LOG">LOG</option>
        <option value="REVIEW">REVIEW</option>
        <option value="OTHER">OTHER</option>
      </select>

      <select id="lg-year">
        <option value="ALL">All years</option>
      </select>

      <input id="lg-q" type="search" placeholder="Search (id / email / token / text…)" />

      <button class="lg-btn primary" id="lg-refresh">Refresh</button>

      <span class="sp"></span>

      <button class="lg-btn" id="lg-select-all">Select all</button>
      <button class="lg-btn" id="lg-select-none">Select none</button>
      <button class="lg-btn danger" id="lg-del-selected">Delete selected</button>
      <button class="lg-btn danger" id="lg-del-filtered">Delete all filtered</button>
    </div>

    <div class="lg-status" id="lg-status"></div>
    <div class="lg-grid" id="lg-grid"></div>
  </main>

  <div class="lg-modal" id="lg-modal" role="dialog" aria-modal="true">
    <div class="lg-modal-card">
      <div class="lg-modal-h">
        <div class="t" id="lg-modal-title">Log</div>
        <div>
          <button class="lg-btn" id="lg-modal-close">Close</button>
        </div>
      </div>
      <div class="lg-modal-body">
        <pre class="lg-modal-pre" id="lg-modal-content"></pre>
      </div>
    </div>
  </div>

  <script>
    const $ = (id) => document.getElementById(id);

    const state = {
      items: [],
      selected: new Set(),
    };

    function fmtBytes(n){
      if (!n || n < 0) return '0 B';
      const u = ['B','KB','MB','GB'];
      let i = 0, v = n;
      while (v >= 1024 && i < u.length - 1){ v /= 1024; i++; }
      return (i === 0 ? String(v) : v.toFixed(1)) + ' ' + u[i];
    }

    function badgeClass(type){
      const t = String(type || '').toLowerCase();
      if (t === 'review') return 'review';
      if (t === 'mail') return 'mail';
      if (t === 'debug') return 'debug';
      return '';
    }

    function setStatus(msg){ $('lg-status').textContent = msg || ''; }

    function buildYearOptions(items){
      const years = new Set();
      for (const it of items){
        if (it.year) years.add(it.year);
      }
      const sel = $('lg-year');
      const current = sel.value || 'ALL';

      // rebuild
      sel.innerHTML = '<option value="ALL">All years</option>';
      [...years].sort().reverse().forEach(y => {
        const opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y;
        sel.appendChild(opt);
      });

      // restore if possible
      if ([...sel.options].some(o => o.value === current)) sel.value = current;
    }

    async function apiList(){
      const type = $('lg-type').value;
      const year = $('lg-year').value;
      const q = $('lg-q').value.trim();

      const url = new URL(location.href);
      url.searchParams.set('a', 'list');
      url.searchParams.set('type', type);
      url.searchParams.set('year', year);
      url.searchParams.set('q', q);

      const res = await fetch(url.toString(), { headers: { 'Accept':'application/json' } });
      if (!res.ok) throw new Error('list_http_' + res.status);
      const j = await res.json();
      if (!j || j.ok !== true) throw new Error('list_failed');
      return j.items || [];
    }

    async function apiRead(path){
      const url = new URL(location.href);
      url.searchParams.set('a', 'read');
      url.searchParams.set('path', path);
      const res = await fetch(url.toString(), { headers: { 'Accept':'application/json' } });
      const j = await res.json().catch(() => null);
      if (!res.ok || !j || j.ok !== true) {
        throw new Error(j?.error || ('read_http_' + res.status));
      }
      return j;
    }

    async function apiDelete(paths){
      const url = new URL(location.href);
      url.searchParams.set('a', 'delete');
      const res = await fetch(url.toString(), {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
        body: JSON.stringify({ paths }),
      });
      const j = await res.json().catch(() => null);
      if (!res.ok || !j || j.ok !== true) {
        throw new Error(j?.error || ('delete_http_' + res.status));
      }
      return j;
    }

    function render(){
      const grid = $('lg-grid');
      grid.innerHTML = '';

      for (const it of state.items){
        const card = document.createElement('div');
        card.className = 'lg-card';

        const checked = state.selected.has(it.path);

        card.innerHTML = `
          <div class="lg-head">
            <div class="lg-left">
              <input class="lg-check" type="checkbox" ${checked ? 'checked':''} />
              <div class="lg-title">
                <div class="lg-file">${escapeHtml(it.file || '')}</div>
                <div class="lg-meta">
                  <span class="lg-badge ${badgeClass(it.type)}">${escapeHtml(it.type || 'OTHER')}</span>
                  ${it.year ? `<span class="lg-badge">${escapeHtml(it.year)}</span>` : ''}
                  <span>${escapeHtml(it.mtime_iso || '')}</span>
                  <span>•</span>
                  <span>${escapeHtml(fmtBytes(it.size || 0))}</span>
                </div>
              </div>
            </div>
            <div class="lg-actions">
              <button class="lg-btn" data-act="view">View</button>
              <a class="lg-btn" href="${buildDownloadUrl(it.path)}" target="_blank" rel="noopener">Download</a>
            </div>
          </div>
          <div class="lg-preview">${escapeHtml(it.preview || '')}</div>
        `;

        // checkbox handler
        const cb = card.querySelector('.lg-check');
        cb.addEventListener('change', () => {
          if (cb.checked) state.selected.add(it.path);
          else state.selected.delete(it.path);
        });

        // view
        card.querySelector('[data-act="view"]').addEventListener('click', async () => {
          try{
            setStatus('Loading…');
            const j = await apiRead(it.path);
            setStatus('');
            openModal(`${it.type || 'LOG'} • ${it.file}`, j.content + (j.truncated ? "\n\n[TRUNCATED]" : ""));
          }catch(e){
            setStatus('❌ Read failed: ' + (e?.message || e));
          }
        });

        grid.appendChild(card);
      }

      setStatus(`Showing ${state.items.length} log file(s). Selected: ${state.selected.size}.`);
    }

    function escapeHtml(s){
      return String(s || '').replace(/[&<>"']/g, (c) => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[c]));
    }

    function buildDownloadUrl(path){
      const url = new URL(location.href);
      url.searchParams.set('a', 'read');     // reuse read, but we want raw
      url.searchParams.set('path', path);
      // we'll just open modal with read; for download, use a raw endpoint:
      // simplest: read JSON -> not ideal for "download". We'll provide a raw link:
      const raw = new URL(location.href);
      raw.searchParams.set('a', 'raw');
      raw.searchParams.set('path', path);
      return raw.toString();
    }

    // Provide a raw download mode (same PHP file, action=raw)
    (function patchRaw(){
      // nothing here; server side implements ?a=raw below (see PHP).
    })();

    function openModal(title, content){
      $('lg-modal-title').textContent = title;
      $('lg-modal-content').textContent = content;
      $('lg-modal').classList.add('open');
    }
    function closeModal(){
      $('lg-modal').classList.remove('open');
    }

    $('lg-modal-close').addEventListener('click', closeModal);
    $('lg-modal').addEventListener('click', (e) => {
      if (e.target === $('lg-modal')) closeModal();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeModal();
    });

    async function refresh(){
      try{
        setStatus('Loading…');
        state.selected.clear();
        const items = await apiList();
        state.items = items;
        buildYearOptions(items);
        render();
      }catch(e){
        setStatus('❌ Load failed: ' + (e?.message || e));
      }
    }

    $('lg-refresh').addEventListener('click', refresh);
    $('lg-type').addEventListener('change', refresh);
    $('lg-year').addEventListener('change', refresh);
    $('lg-q').addEventListener('input', () => {
      // tiny debounce
      clearTimeout(window.__lg_t);
      window.__lg_t = setTimeout(refresh, 250);
    });

    $('lg-select-all').addEventListener('click', () => {
      for (const it of state.items) state.selected.add(it.path);
      render();
    });
    $('lg-select-none').addEventListener('click', () => {
      state.selected.clear();
      render();
    });

    $('lg-del-selected').addEventListener('click', async () => {
      if (state.selected.size === 0) { setStatus('No selection.'); return; }
      if (!confirm(`Delete ${state.selected.size} file(s)?`)) return;
      try{
        setStatus('Deleting…');
        const paths = [...state.selected];
        const res = await apiDelete(paths);
        const fail = res.failed?.length || 0;
        setStatus(fail ? `Deleted ${res.deleted.length}, failed ${fail}.` : `Deleted ${res.deleted.length}.`);
        await refresh();
      }catch(e){
        setStatus('❌ Delete failed: ' + (e?.message || e));
      }
    });

    $('lg-del-filtered').addEventListener('click', async () => {
      if (state.items.length === 0) { setStatus('Nothing to delete.'); return; }
      if (!confirm(`Delete ALL ${state.items.length} filtered file(s)?`)) return;
      try{
        setStatus('Deleting…');
        const paths = state.items.map(x => x.path);
        const res = await apiDelete(paths);
        const fail = res.failed?.length || 0;
        setStatus(fail ? `Deleted ${res.deleted.length}, failed ${fail}.` : `Deleted ${res.deleted.length}.`);
        await refresh();
      }catch(e){
        setStatus('❌ Delete failed: ' + (e?.message || e));
      }
    });

    // initial
    refresh();
  </script>
</body>
</html>
