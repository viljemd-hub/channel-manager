<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: ics_lab/index.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const CACHE_DIR = __DIR__ . '/cache';

function cm_icslab_sanitize_unit(string $unit): string {
    $u = preg_replace('/[^A-Za-z0-9_-]/', '', $unit);
    return $u !== '' ? $u : 'SIM1';
}

function cm_icslab_load_unit(string $unit): array {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $unit = cm_icslab_sanitize_unit($unit);
    $file = DATA_DIR . '/' . $unit . '.json';
    if (!is_file($file)) {
        return [
            'unit'     => $unit,
            'segments' => [],
        ];
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return [
            'unit'     => $unit,
            'segments' => [],
        ];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return [
            'unit'     => $unit,
            'segments' => [],
        ];
    }
    if (!isset($j['unit']) || !is_string($j['unit'])) {
        $j['unit'] = $unit;
    }
    if (!isset($j['segments']) || !is_array($j['segments'])) {
        $j['segments'] = [];
    }
    return $j;
}

function cm_icslab_save_unit(array $payload): bool {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $unit = (string)($payload['unit'] ?? 'SIM1');
    $unit = cm_icslab_sanitize_unit($unit);
    $file = DATA_DIR . '/' . $unit . '.json';

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $r = file_put_contents($file, $json);
    if ($r === false) {
        error_log("ICS LAB ERROR: Cannot write to $file");
    }

    return (bool)$r;
}

function cm_icslab_valid_date(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

// ---------- CM feed pull (imported) ----------

function cm_icslab_cache_init(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
}

function cm_icslab_is_http_url(string $url): bool {
    if ($url === '') return false;
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) return false;
    $scheme = strtolower((string)$parts['scheme']);
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * Fetch URL with cURL when available, fallback to file_get_contents.
 * Returns raw body or null on failure. Fills $meta with status/error.
 */
function cm_icslab_http_get(string $url, array &$meta): ?string {
    $meta = [
        'url' => $url,
        'ok' => false,
        'http_code' => 0,
        'error' => '',
        'fetched_at' => gmdate('c'),
        'bytes' => 0,
    ];

    // Try cURL first (better errors/timeouts)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, // demo/lab friendly (LAN/self-signed)
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'CM-ICS-LAB/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $meta['http_code'] = $code;
        if ($body === false) {
            $meta['error'] = $err ?: 'curl_exec_failed';
            return null;
        }
        $meta['bytes'] = strlen((string)$body);
        $meta['ok'] = ($code >= 200 && $code < 300);
        if (!$meta['ok']) {
            $meta['error'] = 'http_' . $code;
        }
        return (string)$body;
    }

    // Fallback: file_get_contents
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "User-Agent: CM-ICS-LAB/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $meta['error'] = 'file_get_contents_failed';
        return null;
    }
    $meta['bytes'] = strlen((string)$body);
    $meta['ok'] = true;
    return (string)$body;
}

/** Unfold iCalendar lines (RFC 5545 line folding). */
function cm_icslab_ics_unfold(string $ics): array {
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $lines = explode("\n", $ics);
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (!empty($out) && (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t"))) {
            $out[count($out)-1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }
    return $out;
}

function cm_icslab_parse_dt(string $raw): array {
    // returns ['raw'=>..., 'date'=> 'YYYY-MM-DD' or '']
    $raw = trim($raw);
    // DATE format: YYYYMMDD
    if (preg_match('/^\d{8}$/', $raw)) {
        $y = substr($raw, 0, 4);
        $m = substr($raw, 4, 2);
        $d = substr($raw, 6, 2);
        return ['raw' => $raw, 'date' => "{$y}-{$m}-{$d}"];
    }
    // DATETIME format: YYYYMMDDTHHMMSS(Z)?
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T\d{6}Z?$/', $raw, $m)) {
        return ['raw' => $raw, 'date' => "{$m[1]}-{$m[2]}-{$m[3]}"];
    }
    return ['raw' => $raw, 'date' => ''];
}

/**
 * Parse a subset of ICS: VEVENT UID, SUMMARY, DTSTART, DTEND.
 * Returns array of events with raw + normalized dates when possible.
 */
function cm_icslab_parse_ics_events(string $ics): array {
    $lines = cm_icslab_ics_unfold($ics);
    $events = [];
    $cur = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === 'BEGIN:VEVENT') {
            $cur = ['uid'=>'', 'summary'=>'', 'dtstart_raw'=>'', 'dtend_raw'=>'', 'dtstart'=>'', 'dtend'=>''];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if (is_array($cur)) {
                $ds = cm_icslab_parse_dt($cur['dtstart_raw']);
                $de = cm_icslab_parse_dt($cur['dtend_raw']);
                $cur['dtstart'] = $ds['date'];
                $cur['dtend']   = $de['date'];
                $events[] = $cur;
            }
            $cur = null;
            continue;
        }
        if (!is_array($cur)) continue;

        // Split PROPERTY;PARAM=...:VALUE
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) continue;
        $left = $parts[0];
        $val  = $parts[1];

        $prop = strtoupper(explode(';', $left, 2)[0]);

        if ($prop === 'UID') {
            $cur['uid'] = $val;
        } elseif ($prop === 'SUMMARY') {
            $cur['summary'] = $val;
        } elseif ($prop === 'DTSTART') {
            $cur['dtstart_raw'] = $val;
        } elseif ($prop === 'DTEND') {
            $cur['dtend_raw'] = $val;
        }
    }

    return $events;
}

function cm_icslab_calc_nights(array $ev): int {
    // Prefer normalized YYYY-MM-DD (already computed by parser)
    $ds = (string)($ev['dtstart'] ?? '');
    $de = (string)($ev['dtend'] ?? '');

    // fallback: try raw formats YYYYMMDD / YYYYMMDDTHHMMSSZ
    if ($ds === '') {
        $ds2 = cm_icslab_parse_dt((string)($ev['dtstart_raw'] ?? ''));
        $ds = (string)($ds2['date'] ?? '');
    }
    if ($de === '') {
        $de2 = cm_icslab_parse_dt((string)($ev['dtend_raw'] ?? ''));
        $de = (string)($de2['date'] ?? '');
    }

    if ($ds === '' || $de === '') return 0;

    try {
        $a = new DateTime($ds);
        $b = new DateTime($de);
        $diff = $a->diff($b);
        return max(0, (int)$diff->days); // ICS DTEND is exclusive => days diff == nights
    } catch (Throwable $t) {
        return 0;
    }
}


function cm_icslab_cache_paths(string $unit): array {
    $u = cm_icslab_sanitize_unit($unit);
    return [
        'ics'  => CACHE_DIR . "/import_{$u}.ics",
        'json' => CACHE_DIR . "/import_{$u}.json",
        'meta' => CACHE_DIR . "/import_{$u}_meta.json",
    ];
}

function cm_icslab_cache_load(string $unit): array {
    cm_icslab_cache_init();
    $p = cm_icslab_cache_paths($unit);

    $meta = [];
    $events = [];
    $rawIcs = '';

    if (is_file($p['meta'])) {
        $j = json_decode((string)file_get_contents($p['meta']), true);
        if (is_array($j)) $meta = $j;
    }
    if (is_file($p['json'])) {
        $j = json_decode((string)file_get_contents($p['json']), true);
        if (is_array($j)) $events = $j;
    }
    if (is_file($p['ics'])) {
        $rawIcs = (string)file_get_contents($p['ics']);
    }

    return ['meta'=>$meta, 'events'=>$events, 'raw'=>$rawIcs];
}

function cm_icslab_cache_save(string $unit, string $ics, array $events, array $meta): bool {
    cm_icslab_cache_init();
    $p = cm_icslab_cache_paths($unit);

    $ok1 = file_put_contents($p['ics'], $ics) !== false;
    $ok2 = file_put_contents($p['json'], json_encode($events, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) !== false;
    $ok3 = file_put_contents($p['meta'], json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) !== false;
    return $ok1 && $ok2 && $ok3;
}

// ---------- Request handling ----------

$errorMsg = '';
$pullErr = '';
$pullInfo = [];

$unit = cm_icslab_sanitize_unit($_GET['unit'] ?? 'SIM1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $postUnit = cm_icslab_sanitize_unit($_POST['unit'] ?? $unit);
    $unit     = $postUnit; // keep UI in sync

    $data     = cm_icslab_load_unit($postUnit);
    $segments = $data['segments'];

    if ($action === 'add') {
        $from = trim((string)($_POST['from'] ?? ''));
        $to   = trim((string)($_POST['to'] ?? ''));
        $type = trim((string)($_POST['type'] ?? 'booking'));
        $note = trim((string)($_POST['note'] ?? ''));

        if (!cm_icslab_valid_date($from) || !cm_icslab_valid_date($to)) {
            $errorMsg = 'Vnesi veljaven datum (YYYY-MM-DD).';
        } elseif ($from > $to) {
            $errorMsg = 'Končni datum ne sme biti pred začetnim.';
        } else {
            if ($type !== 'block') {
                $type = 'booking';
            }

            $segments[] = [
                'from'  => $from,
                'to'    => $to,
                'type'  => $type,
                'note'  => $note,
            ];

            $data['unit']     = $postUnit;
            $data['segments'] = $segments;

            cm_icslab_save_unit($data);

            // uspešno dodano → redirect da se izognemo re-postu
            header('Location: ' . $_SERVER['PHP_SELF'] . '?unit=' . urlencode($postUnit));
            exit;
        }
    } elseif ($action === 'pull') {
        $icsUrl = trim((string)($_POST['ics_url'] ?? ''));
        if (!cm_icslab_is_http_url($icsUrl)) {
            $pullErr = 'Vpiši veljaven URL (http/https).';
        } else {
            $meta = [];
            $body = cm_icslab_http_get($icsUrl, $meta);
            if ($body === null || $body === '') {
                $pullErr = 'Pull failed: ' . ($meta['error'] ?? 'unknown_error');
            } else {
                $events = cm_icslab_parse_ics_events($body);
                $meta['ok'] = (bool)($meta['ok'] ?? false);
                $meta['events'] = count($events);
                $meta['unit'] = $postUnit;

                if (!cm_icslab_cache_save($postUnit, $body, $events, $meta)) {
                    $pullErr = 'Pull OK, ampak cache write failed (permissions?)';
                } else {
                    // success → redirect (PRG) to avoid re-post
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?unit=' . urlencode($postUnit) . '&pulled=1');
                    exit;
                }
            }
            $pullInfo = $meta;
        }
    } elseif ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);

        if ($idx >= 0 && isset($segments[$idx])) {
            unset($segments[$idx]);
            $data['segments'] = array_values($segments);
            cm_icslab_save_unit($data);
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?unit=' . urlencode($postUnit));
        exit;
    }
}

// GET (or POST with validation error)
$data     = cm_icslab_load_unit($unit);
$segments = $data['segments'];

// imported cache (CM pull)
$importCache = cm_icslab_cache_load($unit);
$importMeta = $importCache['meta'];
$importEvents = $importCache['events'];

// build ICS URLs (relative)
$basePath  = '/app/ics_lab/ics.php';
$icsBooked = $basePath . '?unit=' . urlencode($unit) . '&mode=booked';
$icsAll    = $basePath . '?unit=' . urlencode($unit) . '&mode=all';

?>
<!DOCTYPE html>

<html lang="sl">
<head>
  <meta charset="utf-8" />
  <title>ICS LAB – simulator (<?= htmlspecialchars($unit, ENT_QUOTES) ?>)</title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 2rem;
      background: #111;
      color: #eee;
    }
    h1, h2 {
      margin: 0 0 0.5rem;
    }
    .wrap {
      max-width: 960px;
      margin: 0 auto;
    }
    a { color: #9ad1ff; }
    code, pre { background: rgba(255,255,255,0.06); padding: 0.12rem 0.3rem; border-radius: 6px; }
    pre { padding: 0.75rem; }
    .muted { color: rgba(255,255,255,0.7); font-size: 0.95rem; }
    .row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
    .section { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 1rem 1.25rem; margin: 1rem 0; }
    .card { background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.10); border-radius: 14px; padding: 1rem; }
    .pill { display: inline-block; padding: 0.12rem 0.5rem; border-radius: 999px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); font-size: 0.85rem; }
    input, select, textarea {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.12);
      color: #eee;
      padding: 0.5rem 0.6rem;
      border-radius: 10px;
      outline: none;
    }
    input[type="date"] { padding: 0.4rem 0.6rem; }
    textarea { min-height: 60px; width: 100%; }
    .btn {
      background: rgba(255,255,255,0.10);
      border: 1px solid rgba(255,255,255,0.16);
      color: #eee;
      border-radius: 10px;
      padding: 0.5rem 0.9rem;
      cursor: pointer;
    }
    .btn:hover { background: rgba(255,255,255,0.14); }
    .btn.danger { background: rgba(255,80,80,0.14); border-color: rgba(255,80,80,0.28); }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 0.75rem;
    }
    th, td {
      border-bottom: 1px solid rgba(255,255,255,0.10);
      padding: 0.55rem 0.5rem;
      text-align: left;
      vertical-align: top;
    }
    th { font-size: 0.9rem; color: rgba(255,255,255,0.8); }
    .error { color: #ffb6b6; margin-top: 0.5rem; }
    .alert {
      padding: 0.75rem 1rem;
      border-radius: 10px;
      margin: 0.75rem 0;
      border: 1px solid rgba(255,255,255,0.12);
    }
    .alert.ok { background: rgba(0,255,120,0.08); }
    .alert.bad { background: rgba(255,80,80,0.10); }
    pre { white-space: pre-wrap; word-break: break-word; max-height: 260px; overflow: auto; }
  </style>
</head>

<body>
<div class="wrap">
  <header class="row" style="justify-content: space-between;">
    <div>
      <h1>ICS LAB</h1>
      <div class="muted">
        Virtualni ICS simulator za testiranje multi-unit uvoza.<br>
        Trenutna virtualna enota: <strong><?= htmlspecialchars($unit, ENT_QUOTES) ?></strong>
      </div>
    </div>
    <form method="get">
      <label>
        Enota:
        <select name="unit" onchange="this.form.submit()">
          <option value="SIM1"<?= $unit === 'SIM1' ? ' selected' : '' ?>>SIM1 (Virtual 1)</option>
          <option value="SIM2"<?= $unit === 'SIM2' ? ' selected' : '' ?>>SIM2 (Virtual 2)</option>
        </select>
      </label>
    </form>
  </header>

  <section class="section">
    <h2>Obstoječi razponi</h2>
    <p class="muted">
      Razponi so shranjeni v <code>ics_lab/data/<?= htmlspecialchars($unit, ENT_QUOTES) ?>.json</code>.
      ICS feed bere isti JSON.
    </p>

    <?php if (!$segments): ?>
      <p class="muted">Ni vpisanih razponov.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Od</th>
            <th>Do</th>
            <th>Tip</th>
            <th>Opomba</th>
            <th>Akcija</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($segments as $i => $seg): ?>
          <tr>
            <td><?= $i ?></td>
            <td><?= htmlspecialchars($seg['from'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($seg['to'] ?? '', ENT_QUOTES) ?></td>
            <td><span class="pill"><?= htmlspecialchars($seg['type'] ?? '', ENT_QUOTES) ?></span></td>
            <td class="muted"><?= htmlspecialchars($seg['note'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <form method="post" style="margin:0; display:inline;">
                <input type="hidden" name="unit" value="<?= htmlspecialchars($unit, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="idx" value="<?= (int)$i ?>">
                <button type="submit" class="btn danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>Dodaj razpon</h2>
    <form method="post">
      <input type="hidden" name="unit" value="<?= htmlspecialchars($unit, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="add">

      <div class="row">
        <label>
          Od:
          <input type="date" name="from" value="">
        </label>
        <label>
          Do:
          <input type="date" name="to" value="">
        </label>
        <label>
          Tip:
          <select name="type">
            <option value="booking">booking</option>
            <option value="block">block</option>
          </select>
        </label>
      </div>

      <label style="display:block; margin-top: 0.75rem;">
        Opomba (optional):
        <textarea name="note" placeholder="npr. test prek NY, booking – SIM2, ..."></textarea>
      </label>

      <?php if ($errorMsg !== ''): ?>
        <div class="error"><?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></div>
      <?php else: ?>
        <div class="muted" style="margin-top:0.5rem;">
          Opomba je samo informativna. Tip <code>booking</code> se v ICS izvozi kot rezervacija, tip <code>block</code> pa kot blokada.
          ICS generator uporablja all-day evente (DATE) in zato DTEND predstavlja end-exclusive datum.
        </div>
      <?php endif; ?>

      <div style="margin-top: 0.9rem;">
        <button type="submit" class="btn">Dodaj</button>
      </div>
    </form>
  </section>

  <section class="section">
    <h2>ICS feed URL-ji</h2>
    <p class="muted">
      Te URL-je lahko vpišeš v <strong>integrations &raquo; inbound ICS</strong> kot simuliran vir
      (npr. za Booking / Airbnb) za poljubno enoto.
    </p>
    <ul class="muted">
      <li>
        <span class="pill">mode=booked</span>
        &nbsp;
        <code><?= htmlspecialchars($icsBooked, ENT_QUOTES) ?></code>
      </li>
      <li>
        <span class="pill">mode=all</span>
        &nbsp;
        <code><?= htmlspecialchars($icsAll, ENT_QUOTES) ?></code>
      </li>
    </ul>
  </section>

  <section class="section">
    <h2>Imported (CM feed pull)</h2>
    <p class="muted">
      Tukaj ICS LAB igra vlogo platforme: prek HTTP potegne ICS feed iz CM-ja, ga shrani v cache in prikaže v tabeli.
    </p>

    <?php
      $defaultUrl = '';
      if (is_array($importMeta) && isset($importMeta['url'])) {
        $defaultUrl = (string)$importMeta['url'];
      }
      $qsUrl = trim((string)($_GET['ics_url'] ?? ''));
      if ($qsUrl !== '' && cm_icslab_is_http_url($qsUrl)) {
        $defaultUrl = $qsUrl;
      }
    ?>

    <?php if ($pullErr !== ''): ?>
      <div class="alert bad"><?= htmlspecialchars($pullErr, ENT_QUOTES) ?></div>
    <?php elseif (isset($_GET['pulled']) && $_GET['pulled'] === '1'): ?>
      <div class="alert ok">Pull OK.</div>
    <?php endif; ?>

    <form method="post" class="card">
      <input type="hidden" name="unit" value="<?= htmlspecialchars($unit, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="pull">

      <label style="display:block; margin-bottom:0.5rem;">
        ICS URL (CM OUT):
        <input
          type="url"
          name="ics_url"
          placeholder="http://192.168.2.57/app/admin/api/integrations/ics.php?unit=S1&type=calendar&mode=blocked&key=..."
          value="<?= htmlspecialchars($defaultUrl, ENT_QUOTES) ?>"
          style="width:100%; margin-top:0.35rem;"
          required
        >
      </label>

      <div class="row" style="justify-content: space-between; align-items:center;">
        <button type="submit" class="btn">PULL</button>
        <div class="muted" style="text-align:right;">
          <?php if (!empty($importMeta)): ?>
            <div>Last pull: <code><?= htmlspecialchars((string)($importMeta['fetched_at'] ?? ''), ENT_QUOTES) ?></code></div>
            <div>HTTP: <code><?= htmlspecialchars((string)($importMeta['http_code'] ?? ''), ENT_QUOTES) ?></code> | events: <code><?= (int)($importMeta['events'] ?? count($importEvents)) ?></code></div>
          <?php else: ?>
            <div>No cache yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <?php if (!$importEvents): ?>
      <p class="muted" style="margin-top:0.75rem;">Ni uvoženih dogodkov (še nisi naredil PULL ali pa feed nima VEVENT-ov).</p>
<?php else: ?>

  <div class="card" style="margin-top:0.75rem;">
    <div class="row" style="justify-content: space-between; gap: 0.75rem;">
      <label style="flex: 1 1 380px;">
        Filter (SUMMARY / UID):
        <input id="cmImportFilter" type="text" placeholder="npr. Reserved, cm:S1:, ..." style="width:100%; margin-top:0.35rem;">
      </label>

      <label class="muted" style="display:flex; align-items:center; gap:0.5rem; margin-top: 1.35rem;">
        <input id="cmImportFutureOnly" type="checkbox">
        Samo prihodnje (DTSTART ≥ danes)
      </label>

      <div class="muted" style="margin-top: 1.35rem;">
        Prikazano: <code id="cmImportCountShown">0</code> / <code id="cmImportCountAll">0</code>
      </div>
    </div>
  </div>

  <table id="cmImportTable" style="margin-top:0.75rem;">
<thead>
  <tr>
    <th>#</th>
    <th>DTSTART</th>
    <th>DTEND</th>
    <th>Noči</th>
    <th>SUMMARY</th>
    <th>UID</th>
  </tr>
</thead>

        <tbody>
        <?php foreach ($importEvents as $i => $ev): ?>
          <?php
  $nights = cm_icslab_calc_nights($ev);
  $rowText = strtolower(trim((string)($ev['summary'] ?? '') . ' ' . (string)($ev['uid'] ?? '')));
  $rowStart = (string)($ev['dtstart'] ?? '');
?>
<tr
  data-start="<?= htmlspecialchars($rowStart, ENT_QUOTES) ?>"
  data-text="<?= htmlspecialchars($rowText, ENT_QUOTES) ?>"
>
  <td><?= (int)$i ?></td>
  <td>
    <code><?= htmlspecialchars((string)($ev['dtstart_raw'] ?? ''), ENT_QUOTES) ?></code>
    <?php if (!empty($ev['dtstart'])): ?><div class="muted"><?= htmlspecialchars((string)$ev['dtstart'], ENT_QUOTES) ?></div><?php endif; ?>
  </td>
  <td>
    <code><?= htmlspecialchars((string)($ev['dtend_raw'] ?? ''), ENT_QUOTES) ?></code>
    <?php if (!empty($ev['dtend'])): ?><div class="muted"><?= htmlspecialchars((string)$ev['dtend'], ENT_QUOTES) ?></div><?php endif; ?>
  </td>
  <td><span class="pill"><?= (int)$nights ?></span></td>
  <td><?= htmlspecialchars((string)($ev['summary'] ?? ''), ENT_QUOTES) ?></td>
  <td><code><?= htmlspecialchars((string)($ev['uid'] ?? ''), ENT_QUOTES) ?></code></td>
</tr>

        <?php endforeach; ?>
        </tbody>
      </table>

      <details style="margin-top:0.75rem;">
        <summary class="muted">Raw ICS (cache)</summary>
        <pre><?= htmlspecialchars((string)($importCache['raw'] ?? ''), ENT_QUOTES) ?></pre>
      </details>
    <?php endif; ?>

    <script>
      // Keep last URL in browser (nice for demos)
      (function () {
        const input = document.querySelector('input[name="ics_url"]');
        if (!input) return;
        const key = 'cmIcsLab.lastIcsUrl.' + <?= json_encode($unit) ?>;
        try {
          if (!input.value) {
            const saved = localStorage.getItem(key);
            if (saved) input.value = saved;
          }
          input.addEventListener('change', () => localStorage.setItem(key, input.value));
        } catch (e) {}
      })();
    </script>
  </section>

</div>
</body>
</html>
