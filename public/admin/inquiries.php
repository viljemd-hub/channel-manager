<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/admin/inquiries.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_key();

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana'));
$y = $_GET['y'] ?? $now->format('Y');
$m = $_GET['m'] ?? $now->format('m');
$status = $_GET['status'] ?? 'pending';
ensure_dirs($y,$m);

$items = list_inquiries($y,$m,$status);
$key_qs = isset($_GET['key']) ? '&key='.rawurlencode($_GET['key']) : '';
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <title>Admin • Povpraševanja (<?=h($status)?>)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#0b0f14;color:#e7eef7;margin:0;font:15px/1.4 system-ui,Segoe UI,Roboto,Arial;}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
    input,select{background:#0c1219;color:#e7eef7;border:1px solid #2b3e54;border-radius:8px;padding:6px 8px}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #1b2a3a;padding:8px;text-align:left}
    th{color:#9fb0c0}
    .tag{display:inline-block;border:1px solid #2b3e54;background:#132235;border-radius:999px;padding:2px 8px;font-size:12px}
    .btn{padding:6px 10px;border-radius:8px;border:1px solid #2b3e54;background:#132235;color:#dfe8f3;cursor:pointer;text-decoration:none}
    .btn.danger{border-color:#5a2b2b;background:#2a1212}
    .right{text-align:right}
    .muted{color:#9fb0c0}
    .row-actions{display:flex;gap:6px;flex-wrap:wrap}
    textarea{width:100%;min-height:56px}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Povpraševanja — <?=h(strtoupper($status))?></h1>
    <form class="top" method="get">
      <label>Leto <input type="text" name="y" value="<?=h($y)?>" size="4"></label>
      <label>Mesec <input type="text" name="m" value="<?=h($m)?>" size="2"></label>
      <label>Status
        <select name="status">
          <option <?=$status==='pending'?'selected':''?>>pending</option>
          <option <?=$status==='accepted'?'selected':''?>>accepted</option>
          <option <?=$status==='rejected'?'selected':''?>>rejected</option>
        </select>
      </label>
      <?php if(isset($_GET['key'])): ?>
        <input type="hidden" name="key" value="<?=h($_GET['key'])?>">
      <?php endif; ?>
      <button class="btn">Prikaži</button>
      <a class="btn" href="/app/public/index.html">Koledar</a>
    </form>

    <?php if (!$items): ?>
      <p class="muted">Ni zapisov v izbranem obdobju/statusu.</p>
    <?php else: ?>
      <table>
        <tr>
          <th>ID</th><th>Enota</th><th>Prihod</th><th>Odhod</th><th>Noči</th><th class="right">Skupaj (€)</th><th>Status</th><th class="right">Dejanje</th>
        </tr>
        <?php foreach ($items as $j): ?>
          <tr>
            <td><?=h($j['id'])?></td>
            <td><?=h($j['unit'])?></td>
            <td><?=h($j['from'])?></td>
            <td><?=h($j['to'])?></td>
            <td><?=h((string)($j['nights']??0))?></td>
            <td class="right"><?=number_format((float)($j['pricing']['final_total']??0),2)?></td>
            <td><span class="tag"><?=h($j['status']??'')?></span></td>
            <td class="right">
              <?php if (($j['status']??'')==='pending'): ?>
                <div class="row-actions">
                  <form method="post" action="/app/public/admin/inquiry_action.php?act=accept<?=$key_qs?>" style="display:inline">
                    <input type="hidden" name="id" value="<?=h($j['id'])?>">
                    <button class="btn" type="submit">Potrdi</button>
                  </form>
                  <form method="post" action="/app/public/admin/inquiry_action.php?act=reject<?=$key_qs?>" style="display:inline;min-width:260px">
                    <input type="hidden" name="id" value="<?=h($j['id'])?>">
                    <textarea name="reason" placeholder="Razlog zavrnitve (vidno v admin)"></textarea>
                    <button class="btn danger" type="submit">Zavrni</button>
                  </form>
                  <a class="btn" href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
                </div>
              <?php else: ?>
                <a class="btn" href="view.php?id=<?=rawurlencode($j['id'])?><?=$key_qs?>">JSON</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
