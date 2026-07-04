<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: scripts/upgrade_to_plus.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Informational page for CM Free users: explains what CM Plus adds and
 * how to request it. CM Plus itself is an on-request overlay upgrade,
 * not something this Free install can activate on its own — no forms,
 * no settings are written here.
 */

$APP_ROOT = realpath(__DIR__ . '/..');
if ($APP_ROOT === false) {
    $APP_ROOT = __DIR__ . '/..';
}

// BASIC PROTECTION (admin key) — same convention as the rest of scripts/.
$ADMIN_KEY_PATH = $APP_ROOT . '/common/data/admin_key.txt';
$validKey = trim((string)@file_get_contents($ADMIN_KEY_PATH));
$givenKey = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));

if ($validKey === '' || !hash_equals($validKey, $givenKey)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once $APP_ROOT . '/common/lib/datetime_fmt.php';
$licenseTier = function_exists('cm_get_product_tier') ? cm_get_product_tier() : 'free';

$CONTACT_EMAIL = 'viljem.d@gmail.com';
$DOWNLOAD_URL  = 'https://apartmamatevz.si/cmfree/download.html';
?>
<!doctype html>
<html lang="sl">
<head>
    <meta charset="utf-8">
    <title>CM Plus – nadgradnja na zahtevo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f5f7; margin: 0; padding: 0; }
        .shell { max-width: 720px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); padding: 20px 20px 18px; }
        h1 { margin: 0 0 8px; font-size: 1.6rem; }
        .subtitle { margin: 0 0 16px; color: #555; font-size: 0.95rem; }
        ul.features { margin: 0 0 20px; padding-left: 20px; }
        ul.features li { margin-bottom: 8px; line-height: 1.4; }
        .contact-box { background: #f5f5f7; border-radius: 12px; padding: 14px 16px; margin-top: 8px; }
        .contact-box a { color: #007bff; text-decoration: none; }
        .contact-box a:hover { text-decoration: underline; }
        .tier-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.8rem; background: #f0f0f0; color: #555; margin-left: 6px; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <h1>CM Plus
            <span class="tier-badge">Trenutno: <?php echo h(strtoupper($licenseTier)); ?></span>
        </h1>
        <p class="subtitle">
            CM Plus je nadgradnja nad CM Free, ki jo namestimo na zahtevo — ni samodejnega
            vklopa iz tega Free namestitve.
        </p>

        <h2>Kaj CM Plus doda</h2>
        <ul class="features">
            <li><strong>Reviews</strong> — gostje lahko oddajo oceno po bivanju, ti pa jo upravljaš in objaviš.</li>
            <li><strong>AutoPilot v1</strong> — pomoč pri samodejnem urejanju povpraševanj in pravil.</li>
            <li><strong>SEPA plačila + EPC QR koda</strong> — gost dobi bančne podatke in QR kodo za enostavno nakazilo.</li>
        </ul>

        <h2>Kako do CM Plus</h2>
        <div class="contact-box">
            <p style="margin:0 0 8px;">
                Piši na <a href="mailto:<?php echo h($CONTACT_EMAIL); ?>"><?php echo h($CONTACT_EMAIL); ?></a>
                za namestitev CM Plus na tvoj Free install.
            </p>
            <p style="margin:0;">
                Več o paketih: <a href="<?php echo h($DOWNLOAD_URL); ?>" target="_blank" rel="noopener"><?php echo h($DOWNLOAD_URL); ?></a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
