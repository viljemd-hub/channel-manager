<?php
declare(strict_types=1);
/*
|--------------------------------------------------------------------------
| CM-PRO-ONLY
|--------------------------------------------------------------------------
| Module: Analytics UI
| Feature: Charts + stats rendering
| Added: 2026-03
|--------------------------------------------------------------------------
*/
// Minimal standalone admin page for Analytics.
// We intentionally keep this page self-contained (own CSS/JS) so admin/ stays clean.

// Detect admin base (…/app_pro/admin) from current script path.
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$adminBase = preg_replace('~/analytics$~', '', $scriptDir) ?: $scriptDir;
$adminBase = rtrim($adminBase, '/');

$apiBase = $scriptDir . '/api';
$integrationsUrl = $adminBase . '/integrations.php';

// Optional admin key passthrough (if you use it via ?key=... in admin).
$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
$keyQs = $key !== '' ? ('?key=' . rawurlencode($key)) : '';

?><!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CM PRO – Analytics</title>

  <link rel="stylesheet" href="./analytics.css?v=1">
</head>
<body>

  <header class="topbar">
    <div class="wrap">
      <div class="brand">
        <div class="dot"></div>
        <div>
          <div class="title">CM PRO</div>
          <div class="subtitle">Analytics</div>
        </div>
      </div>

      <nav class="nav">
        <a class="btn" href="<?php echo htmlspecialchars($integrationsUrl . $keyQs, ENT_QUOTES); ?>">← Integrations</a>
        <button class="btn primary" id="btnRefresh" type="button">Refresh</button>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="heroCard">
        <div class="heroHeader">
          <h1>Analytics overview</h1>
          <div class="heroMeta">
            <span class="pill" id="pillRange">last 30d</span>
            <span class="muted" id="lastUpdated">—</span>
          </div>
        </div>

        <div class="statsGrid" id="statsGrid">
          <div class="stat">
            <div class="k">Inquiries (30d)</div>
            <div class="v" id="sInq30">—</div>
          </div>
          <div class="stat">
            <div class="k">Confirmed (30d)</div>
            <div class="v" id="sConf30">—</div>
          </div>
          <div class="stat">
            <div class="k">Avg nights (30d)</div>
            <div class="v" id="sAvgNights30">—</div>
          </div>
          <div class="stat">
            <div class="k">Cancel rate (90d)</div>
            <div class="v" id="sCancelRate90">—</div>
          </div>
        </div>

        <div class="charts">
          <div class="chartCard">
            <div class="chartHead">
              <div>
                <div class="chartTitle">Confirmed per week</div>
                <div class="muted">last 12 weeks</div>
              </div>
              <div class="muted small" id="c1Note">—</div>
            </div>
	<div class="chartBody">
 	 <canvas id="chartConfirmed" height="110"></canvas>
	</div>
          </div>

          <div class="chartCard">
            <div class="chartHead">
              <div>
                <div class="chartTitle">Funnel</div>
                <div class="muted">inquiries → accepted → confirmed (30d)</div>
              </div>
            </div>
            <div class="chartBody">
              <canvas id="chartFunnel" height="110"></canvas>
            </div>
          </div>
        </div>

        <div class="foot muted small">
          Data source: /common/data/json (inquiries, reservations, cancellations)
        </div>
      </div>
    </section>
  </main>


  <script>
    window.CM_ANALYTICS = {
      adminBase: <?php echo json_encode($adminBase); ?>,
      apiBase: <?php echo json_encode($apiBase); ?>,
      key: <?php echo json_encode($key); ?>
    };
  </script>
  <script src="./analytics.js?v=1"></script>
</body>
</html> 
