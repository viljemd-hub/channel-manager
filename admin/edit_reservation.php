<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/edit_reservation.php
 * Purpose: Edit reservation guest/contact/guest-count/TT exemption data.
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();

function er_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function er_read_json(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function er_find_reservation(string $id): ?array {
    $id = trim($id);
    if ($id === '') return null;

    $root = realpath(__DIR__ . '/../common/data/json/reservations');
    if ($root === false) return null;

    $year = substr($id, 0, 4);
    $unit = '';
    if (preg_match('~-[0-9a-f]{4}-([A-Za-z0-9_-]+)$~', $id, $m)) {
        $unit = $m[1];
    }

    $candidates = [];

    if ($unit !== '') {
        $candidates[] = sprintf('%s/%s/%s/%s.json', $root, $year, $unit, $id);
    } else {
        $yearDir = $root . '/' . $year;
        if (is_dir($yearDir)) {
            foreach (glob($yearDir . '/*/' . $id . '.json') as $p) {
                $candidates[] = $p;
            }
        }
    }

    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $j = er_read_json($p);
        if (!is_array($j)) continue;
        $j['_file'] = $p;
        return $j;
    }

    return null;
}

$id = isset($_GET['id']) ? preg_replace('~[^0-9A-Za-z_-]~', '', (string)$_GET['id']) : '';
$error = '';
$res = null;

if ($id === '') {
    $error = 'Missing reservation ID.';
} else {
    $res = er_find_reservation($id);
    if (!$res) {
        $error = 'Reservation not found.';
    }
}

$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/admin/.*$#', '', $scriptName);
if (!is_string($basePath) || $basePath === '/' || $basePath === null) {
    $basePath = '';
}

$guest = (isset($res['guest']) && is_array($res['guest'])) ? $res['guest'] : [];

$guestName  = (string)($guest['name'] ?? ($res['guest_name'] ?? ''));
$guestEmail = (string)($guest['email'] ?? ($res['guest_email'] ?? ''));
$guestPhone = (string)($guest['phone'] ?? ($res['guest_phone'] ?? ''));
$guestNote  = (string)($guest['note'] ?? '');

$unit    = (string)($res['unit'] ?? '');
$status  = (string)($res['status'] ?? '');
$from    = (string)($res['from'] ?? '');
$to      = (string)($res['to'] ?? '');
$nights  = (int)($res['nights'] ?? 0);
$channel = (string)($res['channel'] ?? (($res['meta']['channel'] ?? '')));

$adults  = (int)($res['adults'] ?? 0);
$kids06  = (int)($res['kids06'] ?? ($res['kids_0_6'] ?? 0));
$kids712 = (int)($res['kids712'] ?? ($res['kids_7_12'] ?? 0));

$disabledExemptCount = 0;
if (isset($res['tt']) && is_array($res['tt']) && isset($res['tt']['disabled_exempt_count']) && is_numeric($res['tt']['disabled_exempt_count'])) {
    $disabledExemptCount = max(0, (int)$res['tt']['disabled_exempt_count']);
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit reservation</title>
  <style>
    :root{
      --bg:#020617;
      --panel:#0b1120;
      --panel2:#111827;
      --border:#243041;
      --text:#e5e7eb;
      --muted:#94a3b8;
      --accent:#38bdf8;
      --danger:#f87171;
      --ok:#34d399;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      color:var(--text);
      background:radial-gradient(circle at top, #1f2937 0, #020617 60%, #020617 100%);
    }
    .wrap{
      max-width:980px;
      margin:24px auto;
      padding:0 16px 32px;
    }
    .card{
      background:linear-gradient(180deg, rgba(15,23,42,.97), rgba(2,6,23,.98));
      border:1px solid var(--border);
      border-radius:18px;
      padding:18px;
      box-shadow:0 16px 50px rgba(0,0,0,.35);
    }
    h1{
      margin:0 0 6px;
      font-size:22px;
    }
    .sub{
      color:var(--muted);
      font-size:13px;
      margin:0 0 16px;
    }
    .meta{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
      margin-bottom:16px;
    }
    .meta-box{
      background:rgba(15,23,42,.75);
      border:1px solid rgba(148,163,184,.22);
      border-radius:14px;
      padding:12px;
      font-size:13px;
    }
    .meta-box strong{
      display:block;
      margin-bottom:6px;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:var(--muted);
    }
    .meta-row{
      margin:3px 0;
    }
    form{
      display:grid;
      gap:14px;
    }
    .grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:14px;
    }
    .field{
      display:flex;
      flex-direction:column;
      gap:6px;
    }
    .field label{
      font-size:12px;
      color:var(--muted);
    }
    .field input,
    .field textarea{
      width:100%;
      border-radius:12px;
      border:1px solid rgba(148,163,184,.28);
      background:rgba(2,6,23,.8);
      color:var(--text);
      padding:10px 12px;
      font:inherit;
    }
    .field textarea{
      min-height:110px;
      resize:vertical;
    }
    .field input:focus,
    .field textarea:focus{
      outline:2px solid rgba(56,189,248,.4);
      border-color:transparent;
    }
    .full{
      grid-column:1 / -1;
    }
    .actions{
      display:flex;
      gap:10px;
      justify-content:flex-end;
      align-items:center;
      margin-top:6px;
    }
    .btn{
      border:0;
      border-radius:999px;
      padding:10px 14px;
      font:inherit;
      font-weight:700;
      cursor:pointer;
    }
    .btn-primary{
      background:var(--accent);
      color:#02111d;
    }
    .btn-ghost{
      background:rgba(15,23,42,.7);
      color:var(--text);
      border:1px solid rgba(148,163,184,.3);
    }
    .msg{
      display:none;
      padding:10px 12px;
      border-radius:12px;
      font-size:13px;
    }
    .msg.ok{
      display:block;
      background:rgba(52,211,153,.12);
      border:1px solid rgba(52,211,153,.28);
      color:#bbf7d0;
    }
    .msg.err{
      display:block;
      background:rgba(248,113,113,.12);
      border:1px solid rgba(248,113,113,.28);
      color:#fecaca;
    }
    @media (max-width: 760px){
      .meta,.grid{grid-template-columns:1fr;}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Edit reservation</h1>
      <p class="sub">Edit guest/contact data, guest counts and TT exemption for the selected reservation.</p>

      <?php if ($error !== ''): ?>
        <div class="msg err" style="display:block;"><?=er_h($error)?></div>
      <?php else: ?>
        <div class="meta">
          <div class="meta-box">
            <strong>Reservation</strong>
            <div class="meta-row">ID: <?=er_h($id)?></div>
            <div class="meta-row">Unit: <?=er_h($unit !== '' ? $unit : '—')?></div>
            <div class="meta-row">Status: <?=er_h($status !== '' ? $status : '—')?></div>
            <div class="meta-row">Channel: <?=er_h($channel !== '' ? $channel : '—')?></div>
          </div>
          <div class="meta-box">
            <strong>Stay</strong>
            <div class="meta-row">From: <?=er_h($from !== '' ? $from : '—')?></div>
            <div class="meta-row">To: <?=er_h($to !== '' ? $to : '—')?></div>
            <div class="meta-row">Nights: <?=er_h((string)$nights)?></div>
          </div>
        </div>

        <div id="er-msg"></div>

        <form id="er-form">
          <input type="hidden" name="id" value="<?=er_h($id)?>">

          <div class="grid">
            <div class="field">
              <label for="guest_name">Guest name</label>
              <input id="guest_name" name="guest_name" type="text" value="<?=er_h($guestName)?>">
            </div>

            <div class="field">
              <label for="guest_email">Guest e-mail</label>
              <input id="guest_email" name="guest_email" type="email" value="<?=er_h($guestEmail)?>">
            </div>

            <div class="field">
              <label for="guest_phone">Guest phone</label>
              <input id="guest_phone" name="guest_phone" type="text" value="<?=er_h($guestPhone)?>">
            </div>

            <div class="field">
              <label for="disabled_exempt_count">TT disabled exemption count</label>
              <input id="disabled_exempt_count" name="disabled_exempt_count" type="number" min="0" step="1" value="<?=er_h((string)$disabledExemptCount)?>">
            </div>

            <div class="field">
              <label for="adults">Adults</label>
              <input id="adults" name="adults" type="number" min="0" step="1" value="<?=er_h((string)$adults)?>">
            </div>

            <div class="field">
              <label for="kids06">Kids 0–6</label>
              <input id="kids06" name="kids06" type="number" min="0" step="1" value="<?=er_h((string)$kids06)?>">
            </div>

            <div class="field">
              <label for="kids712">Kids 7–18 / TT-discounted</label>
              <input id="kids712" name="kids712" type="number" min="0" step="1" value="<?=er_h((string)$kids712)?>">
            </div>

            <div class="field full">
              <label for="guest_note">Guest note</label>
              <textarea id="guest_note" name="guest_note"><?=er_h($guestNote)?></textarea>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-ghost" id="er-btn-back">Back</button>
            <button type="submit" class="btn btn-primary" id="er-btn-save">Save reservation</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php if ($error === ''): ?>
<script>
(function () {
  const form = document.getElementById('er-form');
  const msg = document.getElementById('er-msg');
  const saveBtn = document.getElementById('er-btn-save');
  const backBtn = document.getElementById('er-btn-back');
  if (!form) return;

  function showMsg(type, text) {
    msg.className = 'msg ' + type;
    msg.textContent = text;
    msg.style.display = 'block';
  }

  backBtn.addEventListener('click', function () {
    window.close();
    if (!window.closed) {
      window.location.href = <?=json_encode($basePath . '/admin/manage_reservations.php')?>;
    }
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    msg.className = '';
    msg.textContent = '';
    msg.style.display = 'none';

    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());

    try {
      const resp = await fetch(<?=json_encode($basePath . '/admin/api/update_reservation.php')?>, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const json = await resp.json();
      if (!json || json.ok === false) {
        const details = json && json.details ? JSON.stringify(json.details) : '';
        throw new Error((json && (json.error || json.message)) ? (json.error || json.message) + (details ? ' ' + details : '') : 'save_failed');
      }

      showMsg('ok', 'Reservation updated successfully.');
    } catch (err) {
      showMsg('err', 'Save failed: ' + (err && err.message ? err.message : String(err)));
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save reservation';
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>