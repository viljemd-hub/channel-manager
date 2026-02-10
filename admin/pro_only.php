<?php
/**
 * File: admin/pro_only.php
 * Shared PRO-only overlay page for stub endpoints in the public GitHub repo.
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

$feature = trim((string)($_GET['f'] ?? ''));
if ($feature === '') {
  // try infer from referer or uri if you want; keep simple:
  $feature = 'This feature';
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PRO only</title>

  <!-- keep admin shell styling consistent -->
  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css" />
  <link rel="stylesheet" href="/app/admin/ui/css/header.css" />

  <style>
    body{
      margin:0;
      background: rgba(0,0,0,.55);
      color:#e6f0f8;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    }
    .wrap{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 18px;
    }
    .card{
      width:min(640px, 96vw);
      background:#131a22;
      border:1px solid #233041;
      border-radius: 16px;
      box-shadow: 0 18px 60px rgba(0,0,0,.65);
      padding: 18px 18px 14px;
      position:relative;
    }
    .kicker{
      display:inline-block;
      font-size:.78rem;
      padding:.18rem .55rem;
      border-radius: 999px;
      border:1px solid rgba(250,204,21,.35);
      color:#facc15;
      background: rgba(250,204,21,.06);
      letter-spacing:.02em;
    }
    h1{
      margin:.7rem 0 .25rem;
      font-size: 1.35rem;
    }
    p{
      margin:.35rem 0;
      color:#9bb0c3;
      line-height:1.45;
    }
    .feat{
      margin-top:.6rem;
      padding:.55rem .65rem;
      background:#05070f;
      border:1px solid #2b3242;
      border-radius: 12px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size:.9rem;
      color:#e9edf7;
      word-break: break-word;
    }
    .hint{
      margin-top:.8rem;
      font-size:.9rem;
      color:#9bb0c3;
      opacity:.95;
    }
    .hint b{ color:#e6f0f8; }
    .close-x{
      position:absolute;
      top:10px;
      right:12px;
      width:34px; height:34px;
      border-radius: 10px;
      border:1px solid #233041;
      background:#17202b;
      color:#e6f0f8;
      cursor:pointer;
    }
    .close-x:hover{ background:#1e2937; }
  </style>
</head>
<body>
  <div class="wrap" id="overlay">
    <div class="card" id="card">
      <button class="close-x" id="btnClose" aria-label="Close">✕</button>

      <span class="kicker">PRO edition</span>
      <h1>Available in PRO</h1>
      <p>This page is a placeholder in the public repository to keep links working.</p>
      <p><b><?php echo htmlspecialchars($feature, ENT_QUOTES, 'UTF-8'); ?></b> is included in the PRO edition.</p>

      <div class="feat"><?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>

      <div class="hint">Click anywhere outside the card, or press <b>ESC</b> to close.</div>
    </div>
  </div>

  <script>
    const overlay = document.getElementById('overlay');
    const card = document.getElementById('card');
    const btn = document.getElementById('btnClose');

    function closeNow(){
      // If opened as a popup, try close; otherwise go back.
      try { window.close(); } catch(e){}
      if (history.length > 1) history.back();
      else location.href = '/app/admin/manage_reservations.php';
    }

    overlay.addEventListener('click', (e) => {
      if (!card.contains(e.target)) closeNow();
    });
    btn.addEventListener('click', closeNow);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeNow();
    });
  </script>
</body>
</html>
