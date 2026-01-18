<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/opening_wizard.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/opening_wizard.php
declare(strict_types=1);

require_once __DIR__ . '/api/_lib/json_io.php';

$root = '/var/www/html/app';
$instanceFile = $root . '/common/data/json/instance.json';

$instance = null;
if (is_file($instanceFile)) {
  $instance = read_json($instanceFile);
  if (is_array($instance) && ($instance['initialized'] ?? false) === true) {
    header('Location: /app/admin/');
    exit;
  }
}

// detect host/path as helpful defaults
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = '/app'; // your install path
$autoBaseUrl = $scheme . '://' . $host;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CM – First Use Wizard</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; margin: 0; background: #0b0f14; color: #e9eef5; }
    .wrap { max-width: 880px; margin: 24px auto; padding: 16px; }
    .card { background: #121826; border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 18px; }
    h1 { font-size: 20px; margin: 0 0 10px; }
    p  { color: rgba(233,238,245,0.8); margin: 8px 0 14px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    label { display:block; font-size: 13px; color: rgba(233,238,245,0.85); margin-bottom: 6px;}
    input, select { width: 100%; padding: 10px 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.12); background:#0f1522; color:#e9eef5; }
    .row { margin: 10px 0; }
    .btns { display:flex; gap:10px; margin-top: 14px; flex-wrap: wrap; }
    button { padding: 10px 12px; border-radius: 12px; border: 0; cursor:pointer; font-weight: 600; }
    .primary { background: #3b82f6; color: #081018; }
    .ghost { background: rgba(255,255,255,0.08); color:#e9eef5; }
    .small { font-size: 12px; opacity: 0.85; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .modal { position: fixed; inset: 0; display:none; align-items:center; justify-content:center; background: rgba(0,0,0,0.6); }
    .modal.open { display:flex; }
    .modal .box { width: min(720px, 95vw); background:#0f1522; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; overflow:hidden; }
    .modal header { padding: 10px 12px; display:flex; justify-content:space-between; align-items:center; background:#121826; }
    .modal iframe { width: 100%; height: 420px; border: 0; background:#000; }
    .note { padding: 10px 12px; color: rgba(233,238,245,0.85); }
    .ok { color: #86efac; }
    .err{ color: #fca5a5; }
    @media (max-width: 760px){ .grid{ grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>First use – osnovna nastavitev CM</h1>
      <p>Vnesi minimalne podatke. CM bo ustvaril predloge JSON-ov, da admin koledar takoj pokaže življenje.</p>

      <div class="grid">
        <div class="row">
          <label>Ime objekta (label)</label>
          <input id="owner_name" placeholder="npr. Apartma Matevž">
        </div>

        <div class="row">
          <label>Admin e-mail</label>
          <input id="owner_email" placeholder="npr. info@domena.si">
        </div>

        <div class="row">
          <label>Domena (neobvezno)</label>
          <input id="domain" placeholder="npr. mycm.duckdns.org">
          <div class="small">Če pustiš prazno, lahko uporabiš zunanji IP.</div>
        </div>

        <div class="row">
          <label>Zunanji IP (neobvezno)</label>
          <input id="public_ip" class="mono" placeholder="npr. 203.0.113.10">
          <div class="btns">
            <button class="ghost" id="btnIpHelp" type="button">Preveri zunanji IP</button>
          </div>
        </div>

        <div class="row">
          <label>Install path</label>
          <input id="install_path" class="mono" value="<?=h($basePath)?>">
        </div>

        <div class="row">
          <label>Timezone</label>
          <select id="timezone">
            <option value="Europe/Ljubljana" selected>Europe/Ljubljana</option>
            <option value="Europe/Vienna">Europe/Vienna</option>
            <option value="Europe/Rome">Europe/Rome</option>
            <option value="Europe/Berlin">Europe/Berlin</option>
          </select>
        </div>

        <div class="row">
          <label>Prva enota (UNIT id)</label>
          <input id="unit_id" class="mono" placeholder="npr. A1">
          <div class="small">Priporočilo: kratko, brez presledkov.</div>
        </div>

        <div class="row">
          <label>Prva enota (label)</label>
          <input id="unit_label" placeholder="npr. Apartma A1">
        </div>
      </div>

      <div class="btns">
        <button class="primary" id="btnInit" type="button">Ustvari osnovno strukturo</button>
        <button class="ghost" id="btnTestIcs" type="button">Test ICS preview</button>
      </div>

      <p id="status" class="small"></p>
      <p class="small">
        Auto-detected base URL: <span class="mono"><?=h($autoBaseUrl)?></span>
      </p>
    </div>
  </div>

  <div class="modal" id="ipModal" aria-hidden="true">
    <div class="box">
      <header>
        <div>Zunanji IP (pomoč)</div>
        <button class="ghost" id="btnCloseIp" type="button">Zapri</button>
      </header>
      <iframe src="https://api.ipify.org/" title="IP check"></iframe>
      <div class="note">
        Stran navadno pokaže samo IP. Skopiraj IP in ga prilepi v polje <b>Zunanji IP</b>.
        <div class="small">Alternativa: ifconfig.me, whatismyipaddress.com</div>
      </div>
    </div>
  </div>

<script>
(function(){
  const $ = (id) => document.getElementById(id);

  function setStatus(msg, ok){
    const el = $("status");
    el.textContent = msg || "";
    el.className = "small " + (ok ? "ok" : (ok===false ? "err" : ""));
  }

  $("btnIpHelp").addEventListener("click", () => {
    $("ipModal").classList.add("open");
  });
  $("btnCloseIp").addEventListener("click", () => {
    $("ipModal").classList.remove("open");
  });

  async function postJson(url, payload){
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload || {})
    });
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e){}
    if (!res.ok || !j || j.ok === false){
      throw new Error((j && j.error) || res.statusText || ("HTTP " + res.status));
    }
    return j;
  }

  $("btnInit").addEventListener("click", async () => {
    const payload = {
      owner_name: $("owner_name").value.trim(),
      owner_email: $("owner_email").value.trim(),
      domain: $("domain").value.trim(),
      public_ip: $("public_ip").value.trim(),
      install_path: $("install_path").value.trim() || "/app",
      timezone: $("timezone").value,
      unit_id: $("unit_id").value.trim(),
      unit_label: $("unit_label").value.trim()
    };

    if (!payload.unit_id){
      setStatus("Vnesi UNIT id (npr. A1).", false); return;
    }

    try{
      setStatus("Ustvarjam…", null);
      const r = await postJson("/app/admin/api/first_use_init.php", payload);
      setStatus("OK. Osnova ustvarjena. Preusmerjam v admin…", true);
      setTimeout(() => { window.location.href = "/app/admin/"; }, 700);
    }catch(e){
      console.error(e);
      setStatus("Napaka: " + e.message, false);
    }
  });

  $("btnTestIcs").addEventListener("click", () => {
    const unit = $("unit_id").value.trim() || "A1";
    // preview endpoint returns text/plain so it shows in browser
    window.open(`/app/admin/api/integrations/ics_preview.php?unit=${encodeURIComponent(unit)}&mode=booked`, "_blank");
  });

})();
</script>
</body>
</html>
