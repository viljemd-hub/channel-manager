<?php
/**
 * CM Free / CM Plus / CM PRO - Channel Manager
 * File: admin/cm_companion_pairing.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Admin-facing "Pair a device" screen for CM Companion (github.com/
 * viljemd-hub/cm-companion) and any future third-party Bridge Protocol v1
 * client. Calls the existing admin/api/pairing_generate.php (backed by
 * common/lib/cm_bridge_pairing.php) - this page is presentation only, no
 * pairing logic lives here.
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/admin/.*$#', '', $scriptName);
if ($basePath === null || $basePath === '/') {
    $basePath = '';
}
?>
<!doctype html>
<html lang="sl">
<head>
<meta charset="utf-8">
<title>CM Companion - Pair a device</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { background:#0f1218; color:#e6e8ec; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; margin:0; }
  .wrap { max-width:640px; margin:0 auto; padding:32px 20px 60px; }
  .back { color:#8ea2c7; text-decoration:none; font-size:14px; }
  h1 { font-size:22px; margin:14px 0 6px; }
  .sub { color:#9aa4b2; font-size:14px; margin-bottom:24px; }
  fieldset { border:1px solid #2a2f3a; border-radius:10px; padding:16px 18px; margin-bottom:18px; }
  legend { padding:0 6px; color:#c8d0dc; font-weight:600; }
  .btn { background:#3d6bff; color:#fff; border:none; border-radius:8px; padding:10px 18px; font-size:14px; cursor:pointer; }
  .btn:disabled { opacity:.5; cursor:default; }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; }
  .code-box { background:#161b25; border:1px solid #2a2f3a; border-radius:10px; padding:18px; margin-top:16px; display:none; }
  .code-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px; }
  .code-row:last-child { margin-bottom:0; }
  .code-label { color:#9aa4b2; font-size:13px; flex:0 0 140px; }
  .code-value { font-size:14px; word-break:break-all; text-align:right; flex:1; }
  .code-big { font-size:26px; letter-spacing:2px; text-align:center; margin:10px 0 16px; color:#7fd3a0; }
  .copy { background:none; border:1px solid #3a4152; color:#c8d0dc; border-radius:6px; padding:3px 10px; font-size:12px; cursor:pointer; margin-left:8px; }
  .hint { color:#9aa4b2; font-size:13px; line-height:1.5; }
  .warn { color:#ffb15e; font-size:13px; margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">
  <p><a class="back" href="<?= h($basePath) ?>/admin/integrations.php">&larr; Integrations</a></p>
  <h1>CM Companion - Pair a device</h1>
  <p class="sub">Generiraj eno-kratno parjenje kodo za mobilno aplikacijo (ali kateregakoli drugega Bridge Protocol v1 klienta).</p>

  <fieldset>
    <legend>Nova naprava</legend>
    <p class="hint">
      V aplikaciji (CM Companion &rarr; Add CM installation) vnesi spodnje tri vrednosti ročno,
      ali - ko bo v appu zgrajen QR skener - poskeniraj prikazano kodo. Koda velja 10 minut in
      jo je mogoče uporabiti samo enkrat.
    </p>
    <button class="btn" id="generateBtn" type="button">Generate pairing code</button>
    <div class="warn" id="errorBox" style="display:none;"></div>

    <div class="code-box" id="codeBox">
      <div class="code-row" style="align-items:center;">
        <span class="code-big mono" id="codeBig" style="flex:1;"></span>
        <button class="copy" data-copy="codeBig" type="button">Copy code</button>
      </div>
      <p class="hint" style="margin:4px 0 14px;">
        To zgornjo kratko kodo vnesi v polje <strong>"Pairing code"</strong> v aplikaciji -
        <strong>ne</strong> spodnji Deep link (tisti je za bodočo QR/avtomatsko parjenje, cela
        vrstica ni veljavna koda).
      </p>
      <div id="qrWrap" style="text-align:center; margin-bottom:16px; display:none;">
        <img id="qrImg" alt="Pairing QR code" style="width:220px; height:220px; background:#fff; border-radius:8px; padding:8px;">
      </div>
      <div class="code-row">
        <span class="code-label">CM Bridge URL</span>
        <span class="code-value mono" id="valBridgeUrl"></span>
        <button class="copy" data-copy="valBridgeUrl" type="button">Copy</button>
      </div>
      <div class="code-row">
        <span class="code-label">Installation ID</span>
        <span class="code-value mono" id="valInstallationId"></span>
        <button class="copy" data-copy="valInstallationId" type="button">Copy</button>
      </div>
      <div class="code-row">
        <span class="code-label">Poteče</span>
        <span class="code-value" id="valExpires"></span>
      </div>
      <div class="code-row">
        <span class="code-label">Deep link (QR, ne za ročni vnos)</span>
        <span class="code-value mono" id="valDeepLink"></span>
        <button class="copy" data-copy="valDeepLink" type="button">Copy</button>
      </div>
    </div>
  </fieldset>
</div>

<script>
(function () {
  var btn = document.getElementById('generateBtn');
  var box = document.getElementById('codeBox');
  var errorBox = document.getElementById('errorBox');

  btn.addEventListener('click', function () {
    btn.disabled = true;
    errorBox.style.display = 'none';

    // Same convention as checkin_tt.php's own fetch calls: forward this
    // page's own ?key= (if any) rather than assume admin_key.txt is unset.
    var params = new URLSearchParams(window.location.search);
    var key = params.get('key') || '';
    var url = 'api/pairing_generate.php' + (key ? '?key=' + encodeURIComponent(key) : '');

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        if (!data.ok) {
          errorBox.textContent = 'Napaka: ' + (data.error || 'unknown_error');
          errorBox.style.display = 'block';
          return;
        }
        document.getElementById('codeBig').textContent = data.code;
        document.getElementById('valBridgeUrl').textContent = data.bridge_base_url;
        document.getElementById('valInstallationId').textContent = data.installation_id;
        document.getElementById('valExpires').textContent = data.expires_at;
        document.getElementById('valDeepLink').textContent = data.deep_link;

        var qrWrap = document.getElementById('qrWrap');
        if (data.qr_svg_data_uri) {
          document.getElementById('qrImg').src = data.qr_svg_data_uri;
          qrWrap.style.display = 'block';
        } else {
          // qrencode not installed on this host - code/URL/ID above still
          // work fine for manual entry, QR is a convenience, not required.
          qrWrap.style.display = 'none';
        }

        box.style.display = 'block';
      })
      .catch(function (e) {
        btn.disabled = false;
        errorBox.textContent = 'Napaka pri klicu: ' + e;
        errorBox.style.display = 'block';
      });
  });

  // navigator.clipboard.writeText() can silently reject (permissions
  // policy, page not focused, etc.) with no visible sign of failure - a
  // real report (2026-09-03) suspected the button copied the wrong field,
  // but the more likely cause was a silent write failure leaving an
  // earlier copy's content in the clipboard. Fallback via a hidden
  // textarea + execCommand('copy') covers that case; the button label
  // only flips to "Copied" once one of the two paths actually succeeds.
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return Promise.reject(new Error('clipboard API unavailable'));
  }

  function copyTextFallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  document.querySelectorAll('.copy').forEach(function (el) {
    el.addEventListener('click', function () {
      var target = document.getElementById(el.getAttribute('data-copy'));
      var text = target.textContent || '';
      var originalLabel = el.textContent;

      copyText(text).then(function () {
        el.textContent = 'Copied';
      }).catch(function () {
        el.textContent = copyTextFallback(text) ? 'Copied' : 'Copy failed - select manually';
      }).finally(function () {
        setTimeout(function () { el.textContent = originalLabel; }, 1500);
      });
    });
  });
})();
</script>
</body>
</html>
