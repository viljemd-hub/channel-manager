<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/inquiries.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_key();

$now    = new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana'));
$y      = $_GET['y'] ?? $now->format('Y');
$m      = $_GET['m'] ?? $now->format('m');
$status = $_GET['status'] ?? 'pending'; // "pending", "accepted", "rejected"

ensure_dirs($y, $m);

$INQ_ROOT = $_SERVER['DOCUMENT_ROOT'] . '/app/common/data/json/inquiries';

// Preprosta lokalna funkcija za branje JSON-ov iz podmape (pending/accepted/rejected)
function cm_load_inquiries(string $root, string $y, string $m, string $subdir): array {
    $y = sprintf('%04d', (int)$y);
    $m = sprintf('%02d', (int)$m);
    $dir = "{$root}/{$y}/{$m}/{$subdir}";
    if (!is_dir($dir)) return [];

    $files = glob($dir . '/*.json', GLOB_NOSORT) ?: [];
    sort($files); // stabilen vrstni red

    $out = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!is_array($j)) continue;
        // fallback, če kdaj nima 'status'
        if (!isset($j['status'])) {
            $j['status'] = $subdir;
        }
        $out[] = $j;
    }
    return $out;
}

// Total EUR helper: podpira tako 'pricing.final_total' kot 'calc.final'
function cm_total_eur(array $j): string {
    $total = 0.0;
    if (isset($j['pricing']['final_total'])) {
        $total = (float)$j['pricing']['final_total'];
    } elseif (isset($j['calc']['final'])) {
        $total = (float)$j['calc']['final'];
    }
    return number_format($total, 2);
}

// Priprava setov glede na izbran status
$pendingItems  = [];
$acceptedItems = [];
$items         = [];

if ($status === 'pending') {
    $pendingItems  = cm_load_inquiries($INQ_ROOT, $y, $m, 'pending');
    $acceptedItems = cm_load_inquiries($INQ_ROOT, $y, $m, 'accepted');
} else {
    // accepted ali rejected – en sam sklop
    $sub = $status === 'accepted' ? 'accepted' : 'rejected';
    $items = cm_load_inquiries($INQ_ROOT, $y, $m, $sub);
}

$key_qs = isset($_GET['key']) ? '&key=' . rawurlencode($_GET['key']) : '';
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <title>Admin • Povpraševanja (<?=h($status)?>)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{
      background:#0b0f14;
      color:#e7eef7;
      margin:0;
      font:15px/1.4 system-ui,Segoe UI,Roboto,Arial;
    }
    .wrap{
      max-width:1100px;
      margin:24px auto;
      padding:0 16px;
    }
    .top{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
      margin-bottom:12px;
    }
    input,select{
      background:#0c1219;
      color:#e7eef7;
      border:1px solid #2b3e54;
      border-radius:8px;
      padding:6px 8px;
    }
    table{
      width:100%;
      border-collapse:collapse;
      margin-top:8px;
    }
    th,td{
      border-bottom:1px solid #1b2a3a;
      padding:8px;
      text-align:left;
    }
    th{
      color:#9fb0c0;
    }
    .tag{
      display:inline-block;
      border:1px solid #2b3e54;
      background:#132235;
      border-radius:999px;
      padding:2px 8px;
      font-size:12px;
    }
    .btn{
      padding:6px 10px;
      border-radius:8px;
      border:1px solid #2b3e54;
      background:#132235;
      color:#dfe8f3;
      cursor:pointer;
      text-decoration:none;
      display:inline-block;
    }
    .btn.danger{
      border-color:#5a2b2b;
      background:#2a1212;
    }
    .right{
      text-align:right;
    }
    .muted{
      color:#9fb0c0;
    }
    .row-actions{
      display:flex;
      gap:6px;
      flex-wrap:wrap;
    }
    textarea{
      width:100%;
      min-height:56px;
    }
    h2{
      margin-top:24px;
      margin-bottom:4px;
      font-size:18px;
    }
    .section-caption{
      font-size:13px;
      color:#9fb0c0;
      margin-bottom:4px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Povpraševanja — <?=h(strtoupper($status))?></h1>

    <form class="top" method="get">
      <label>Leto
        <input type="text" name="y" value="<?=h($y)?>" size="4">
      </label>
      <label>Mesec
        <input type="text" name="m" value="<?=h($m)?>" size="2">
      </label>
      <label>Status
        <select name="status">
          <option value="pending"  <?=$status==='pending'?'selected':''?>>pending</option>
          <option value="accepted" <?=$status==='accepted'?'selected':''?>>accepted</option>
          <option value="rejected" <?=$status==='rejected'?'selected':''?>>rejected</option>
        </select>
      </label>
      <?php if (isset($_GET['key'])): ?>
        <input type="hidden" name="key" value="<?=h($_GET['key'])?>">
      <?php endif; ?>
      <button class="btn">Prikaži</button>
      <a class="btn" href="/app/admin/index_admin.html">Index Admin</a>
    </form>

    <?php if ($status !== 'pending'): ?>

      <?php if (!$items): ?>
        <p class="muted">Ni zapisov v izbranem obdobju/statusu.</p>
      <?php else: ?>
        <table>
          <tr>
            <th>ID</th>
            <th>Enota</th>
            <th>Prihod</th>
            <th>Odhod</th>
            <th>Noči</th>
            <th class="right">Skupaj (€)</th>
            <th>Status</th>
            <th class="right">Dejanje</th>
          </tr>
          <?php foreach ($items as $j): ?>
            <tr>
              <td><?=h($j['id'])?></td>
              <td><?=h($j['unit'])?></td>
              <td><?=h($j['from'])?></td>
              <td><?=h($j['to'])?></td>
              <td><?=h((string)($j['nights'] ?? 0))?></td>
              <td class="right">
                <?=cm_total_eur($j)?>
              </td>
              <td>
                <span class="tag"><?=h($j['status'] ?? '')?></span>
              </td>
              <td class="right">
                <?php if (($j['status'] ?? '') === 'pending'): ?>
                  <div class="row-actions">
                    <form method="post"
                          action="/app/admin/inquiry_action.php?act=accept<?=$key_qs?>"
                          style="display:inline">
                      <input type="hidden" name="id" value="<?=h($j['id'])?>">
                      <button class="btn" type="submit">Potrdi</button>
                    </form>
                    <form method="post"
                          action="/app/admin/inquiry_action.php?act=reject<?=$key_qs?>"
                          style="display:inline;min-width:260px">
                      <input type="hidden" name="id" value="<?=h($j['id'])?>">
                      <textarea name="reason"
                        placeholder="Razlog zavrnitve (vidno v admin)"></textarea>
                      <button class="btn danger" type="submit">Zavrni</button>
                    </form>
                    <a class="btn"
                       href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
                  </div>
                <?php else: ?>
                  <a class="btn"
                     href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>

    <?php else: ?>

      <!-- Sekcija 1: PENDING povpraševanja -->
      <h2>Pending povpraševanja</h2>
      <p class="section-caption">Nova povpraševanja, ki še čakajo na tvojo odločitev.</p>

      <?php if (!$pendingItems): ?>
        <p class="muted">Ni pending povpraševanj v izbranem obdobju.</p>
      <?php else: ?>
        <table>
          <tr>
            <th>ID</th>
            <th>Enota</th>
            <th>Prihod</th>
            <th>Odhod</th>
            <th>Noči</th>
            <th class="right">Skupaj (€)</th>
            <th>Status</th>
            <th class="right">Dejanje</th>
          </tr>
          <?php foreach ($pendingItems as $j): ?>
            <tr>
              <td><?=h($j['id'])?></td>
              <td><?=h($j['unit'])?></td>
              <td><?=h($j['from'])?></td>
              <td><?=h($j['to'])?></td>
              <td><?=h((string)($j['nights'] ?? 0))?></td>
              <td class="right">
                <?=cm_total_eur($j)?>
              </td>
              <td>
                <span class="tag"><?=h($j['status'] ?? '')?></span>
              </td>
              <td class="right">
                <div class="row-actions">
                  <form method="post"
                        action="/app/admin/inquiry_action.php?act=accept<?=$key_qs?>"
                        style="display:inline">
                    <input type="hidden" name="id" value="<?=h($j['id'])?>">
                    <button class="btn" type="submit">Potrdi</button>
                  </form>
                  <form method="post"
                        action="/app/admin/inquiry_action.php?act=reject<?=$key_qs?>"
                        style="display:inline;min-width:260px">
                    <input type="hidden" name="id" value="<?=h($j['id'])?>">
                    <textarea name="reason"
                      placeholder="Razlog zavrnitve (vidno v admin)"></textarea>
                    <button class="btn danger" type="submit">Zavrni</button>
                  </form>
                  <a class="btn"
                     href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>

      <!-- Sekcija 2: ACCEPTED soft-hold povpraševanja -->
      <h2>Accepted pendings – waiting for guest confirmation</h2>
      <p class="section-caption">
        Povpraševanja, ki si jih že sprejel (soft-hold) in čakajo na potrditev gosta.
      </p>

      <?php if (!$acceptedItems): ?>
        <p class="muted">Ni accepted povpraševanj v izbranem obdobju.</p>
      <?php else: ?>
        <table>
          <tr>
            <th>ID</th>
            <th>Enota</th>
            <th>Prihod</th>
            <th>Odhod</th>
            <th>Noči</th>
            <th class="right">Skupaj (€)</th>
            <th>Status</th>
            <th class="right">Info</th>
          </tr>
          <?php foreach ($acceptedItems as $j): ?>
            <tr>
              <td><?=h($j['id'])?></td>
              <td><?=h($j['unit'])?></td>
              <td><?=h($j['from'])?></td>
              <td><?=h($j['to'])?></td>
              <td><?=h((string)($j['nights'] ?? 0))?></td>
              <td class="right">
                <?=cm_total_eur($j)?>
              </td>
              <td>
                <span class="tag"><?=h($j['status'] ?? 'accepted')?></span>
              </td>
              <td class="right">
                <a class="btn"
                   href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</body>
</html>
