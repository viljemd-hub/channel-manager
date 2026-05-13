<?php
// admin/reviews/admin_reviews.php
require_once __DIR__ . '/../_common.php';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Moderacija ocen — CM Plus</title>
<style>
  body{
    font-family:system-ui,sans-serif;
    margin:0; padding:0;
    background:#f7f7f8;
  }
  header{
    background:#1a73e8;
    color:white;
    padding:16px 24px;
    font-size:18px;
    font-weight:600;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }
  header .btn{
    background:#ffffff22;
    color:white;
    border:1px solid #ffffff55;
    border-radius:6px;
    padding:6px 12px;
    cursor:pointer;
    font-weight:600;
  }
  header .btn:hover{ background:#ffffff33; }

  #filters{
    background:white;
    padding:12px 20px;
    border-bottom:1px solid #ddd;
    display:flex;
    gap:20px;
    align-items:center;
    flex-wrap:wrap;
    position:sticky;
    top:0;
    z-index:10;
  }
  #reviews{ padding:20px; }

  .review-item{
    background:white;
    padding:16px;
    margin-bottom:16px;
    border-radius:10px;
    border-left:6px solid #999;
    box-shadow:0 1px 3px rgba(0,0,0,0.08);
  }
  .status-approved{ border-left-color:#4caf50; }
  .status-pending{ border-left-color:#ff9800; }
  .status-quarantine{ border-left-color:#f44336; }
  .status-rejected{ border-left-color:#757575; }

  .top-line{
    display:flex;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:8px;
  }
  .rating{ font-size:18px; font-weight:700; }
  .meta{ font-size:12px; color:#777; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }

  .text{ margin-top:8px; white-space:pre-wrap; }
  .toxicity{ font-size:13px; margin-top:6px; color:#444; }
  .flag{
    background:#fce4ec;
    color:#c2185b;
    padding:3px 8px;
    border-radius:6px;
    font-size:12px;
    display:inline-block;
    margin-top:6px;
  }

  .actions button{
    margin-right:6px;
    padding:6px 10px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
  }
  .btn-approve{ background:#4caf50; color:white; }
  .btn-quarantine{ background:#e53935; color:white; }

  /* Status badges */
  .status-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:2px 8px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:11px;
    font-weight:600;
    line-height:1.4;
    color:#222;
    background:#eee;
  }
  .status-badge.approved{
    background:#e0f7e9;
    border-color:#b2dfdb;
    color:#137333;
  }
  .status-badge.quarantine{
    background:#ffe9e9;
    border-color:#ffcdd2;
    color:#c5221f;
  }
  .status-badge.rejected{
    background:#f3e5f5;
    border-color:#e1bee7;
    color:#6a1b9a;
  }
  .status-badge.pending{
    background:#fff8e1;
    border-color:#ffe082;
    color:#b26a00;
  }

  /* AI modal */
  #aiModal{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.55);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
    padding:16px;
  }
  #aiModal .card{
    background:white;
    padding:24px;
    width:min(420px, 96vw);
    border-radius:10px;
    box-shadow:0 4px 18px rgba(0,0,0,0.25);
  }
</style>
</head>
<body>

<header>
  <button id="btnRunAuto" class="btn" type="button">Run 48h auto-moderation</button>
  <div>Moderacija ocen (AI-ready) — CM Plus</div>
  <button id="btnAiSettings" class="btn" type="button">⚙️ AI Settings</button>
</header>

<div id="filters">
  <label>Status:
    <select id="f_status">
      <option value="">Vsi</option>
      <option value="approved">Approved</option>
      <option value="pending">Pending</option>
      <option value="quarantine">Quarantine</option>
      <option value="rejected">Rejected</option>
    </select>
  </label>

  <label>Ocena:
    <select id="f_rating">
      <option value="">Vse</option>
      <option value="5">5★</option>
      <option value="4">4★</option>
      <option value="3">3★</option>
      <option value="2">2★</option>
      <option value="1">1★</option>
    </select>
  </label>

  <label>AI Kategorija:
    <select id="f_ai">
      <option value="">Vse</option>
      <option value="normal">normal</option>
      <option value="complaint">complaint</option>
      <option value="insult">insult</option>
      <option value="hate">hate</option>
      <option value="spam">spam</option>
      <option value="unclear">unclear</option>
    </select>
  </label>

  <button type="button" id="btnReviewsRefresh">🔄 Osveži</button>
</div>

<div id="reviews"></div>

<!-- AI SETTINGS MODAL -->
<div id="aiModal">
  <div class="card">
    <h2>AI Moderation Settings</h2>

    <label style="display:block; margin-top:10px;">
      <input type="checkbox" id="ai_enabled"> Enable AI moderation
    </label>

    <label style="display:block; margin-top:14px;">
      Provider:<br>
      <select id="ai_provider" style="width:100%; padding:6px;">
        <option value="none">None</option>
        <option value="openai">OpenAI</option>
        <option value="groq">Groq</option>
        <option value="ollama">Ollama (local)</option>
      </select>
    </label>

    <label style="display:block; margin-top:14px;">
      OpenAI Key:
      <input type="password" id="ai_openai_key" style="width:100%; padding:6px;">
    </label>

    <label style="display:block; margin-top:10px;">
      Groq Key:
      <input type="password" id="ai_groq_key" style="width:100%; padding:6px;">
    </label>

    <label style="display:block; margin-top:10px;">
      Ollama URL:
      <input type="text" id="ai_ollama_url" placeholder="http://localhost:11434" style="width:100%; padding:6px;">
    </label>

    <div style="text-align:right; margin-top:20px;">
      <button id="aiTestBtn" style="padding:6px 12px; margin-right:10px; background:#444; color:white; border-radius:6px; border:0;">Test</button>
      <button id="aiSaveBtn" style="padding:6px 14px; border:0; background:#1a73e8; color:white; border-radius:6px;">Save</button>
      <button id="aiCloseBtn" style="padding:6px 12px; margin-left:10px;">Close</button>
    </div>
  </div>
</div>

<script src="admin_reviews.js"></script>
<script>
  // Optional: wire the "Run 48h auto-moderation" button if endpoint exists
  (function () {
    const btn = document.getElementById("btnRunAuto");
    if (!btn) return;
    btn.addEventListener("click", async () => {
      if (!confirm("Run auto-moderation for last 48h now?")) return;
      try {
        const res = await fetch("../api/cron_reviews_autocheck.php");
        const js = await res.json().catch(() => ({}));
        if (js.ok) {
          alert("✅ Auto-moderation done.");
          if (typeof loadUI === "function") loadUI();
        } else {
          alert("❌ Auto-moderation failed: " + (js.error || "unknown"));
        }
      } catch (e) {
        alert("❌ Auto-moderation request failed.");
      }
    });
  })();
</script>

</body>
</html>