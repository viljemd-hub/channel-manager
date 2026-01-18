<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/offer_day.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/public/offer_day.php
// Simple Day-use offer page (public, V1.5)
// - Called from pubcal.js when DAY_USE_MODE is enabled
// - Input: GET ?unit=ID&date=YYYY-MM-DD (prvi prikaz)
//          POST unit/date/persons (potrditev št. oseb + generiranje ID)
// - Pricing model:
//   * day_use.day_price_person  → osnovna cena na osebo za celoten day-use
//   * cleaning_fee_eur (global) → enkratni strošek čiščenja
//   * TT = 0
//   * brez popustov, brez kuponov, brez special offers
// - Ob potrditvi se v /common/data/json/units/<UNIT>/day_use.json doda zapis z ID-jem
//   (status "token") in prikazuje se navodilo, da gost pošlje ID na telefonsko številko.

declare(strict_types=1);

$appRoot = realpath(__DIR__ . '/..');
if ($appRoot === false) {
    $appRoot = __DIR__ . '/..';
}

const DAYUSE_STORE_FILENAME = 'day_use.json';
// TODO: prilagodi telefonsko številko po potrebi:
const DAYUSE_CONTACT_PHONE = '+386 41 758 937';

function read_json(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_json(string $path, array $data): bool {
    @mkdir(dirname($path), 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ustvari unikaten ID za day-use token.
 * Primer: DU-20251202-143512-A2-7F39B2
 */
function generate_dayuse_id(string $unit, string $date): string {
    $unitSafe = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper($unit));
    $dateFlat = preg_replace('/[^0-9]/', '', $date); // YYYYMMDD
    if (strlen($dateFlat) !== 8) {
        $dateFlat = date('Ymd');
    }
    $rand = strtoupper(bin2hex(random_bytes(3))); // 6 hex znakov
    return 'DU-' . $dateFlat . '-' . date('His') . '-' . $unitSafe . '-' . $rand;
}

// ---------- INPUT ----------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$unit = '';
$date = '';

if ($method === 'POST') {
    $unit = isset($_POST['unit']) ? trim((string)$_POST['unit']) : '';
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
} else {
    $unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
    $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
}

$errors = [];

if ($unit === '') {
    $errors[] = 'Enota ni podana.';
}
if ($date === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
    $errors[] = 'Datum ni podan ali ni v veljavnem formatu.';
}

// ---------- LOAD SETTINGS ----------

$unitSettingsPath   = $appRoot . '/common/data/json/units/' . $unit . '/site_settings.json';
$globalSettingsPath = $appRoot . '/common/data/json/units/site_settings.json';

$unitSettings   = read_json($unitSettingsPath);
$globalSettings = read_json($globalSettingsPath);

$dayUse = isset($unitSettings['day_use']) && is_array($unitSettings['day_use'])
    ? $unitSettings['day_use']
    : [];

// day-use enabled?
if (!($dayUse['enabled'] ?? false)) {
    $errors[] = 'Dnevni počitek (day-use) ni omogočen za to enoto.';
}

// price per person
$dayPricePerson = 0.0;
if (isset($dayUse['day_price_person']) && is_numeric($dayUse['day_price_person'])) {
    $dayPricePerson = (float)$dayUse['day_price_person'];
}
if ($dayPricePerson <= 0) {
    $errors[] = 'Day-use cena na osebo ni nastavljena ali je neveljavna.';
}

// cleaning
$cleaningFee = 0.0;
if (isset($globalSettings['cleaning_fee_eur']) && is_numeric($globalSettings['cleaning_fee_eur'])) {
    $cleaningFee = (float)$globalSettings['cleaning_fee_eur'];
}

// dodatne info
$dayFrom = isset($dayUse['from']) ? (string)$dayUse['from'] : '';
$dayTo   = isset($dayUse['to'])   ? (string)$dayUse['to']   : '';
$maxP    = isset($dayUse['max_persons']) ? (int)$dayUse['max_persons'] : 0;
if ($maxP <= 0) {
    $maxP = 1; // minimalno 1 oseba, da ne "pade" UI
}

// TT = 0 (zaenkrat)
$totalTT = 0.0;

// ---------- PERSONS + ID GENERATION (POST) ----------

$selectedPersons = null;
$generatedId     = null;
$totalOverall    = null;

if ($method === 'POST' && !$errors) {
    $personsRaw = isset($_POST['persons']) ? trim((string)$_POST['persons']) : '';
    if ($personsRaw === '' || !preg_match('/^\d+$/', $personsRaw)) {
        $errors[] = 'Prosimo, izberite število oseb.';
    } else {
        $p = (int)$personsRaw;
        if ($p < 1 || $p > $maxP) {
            $errors[] = 'Število oseb mora biti med 1 in ' . $maxP . '.';
        } else {
            $selectedPersons = $p;
        }
    }

    if (!$errors && $selectedPersons !== null) {
        // izračun končnega zneska
        $totalOverall = $selectedPersons * $dayPricePerson + $cleaningFee + $totalTT;

        // generiranje ID-ja
        try {
            $generatedId = generate_dayuse_id($unit, $date);
        } catch (Throwable $e) {
            $errors[] = 'Napaka pri generiranju kode. Poskusite znova.';
        }

        // zapis v day_use.json
        if (!$errors && $generatedId !== null) {
            $storePath = $appRoot . '/common/data/json/units/' . $unit . '/' . DAYUSE_STORE_FILENAME;
            $list = read_json($storePath);
            if (!is_array($list)) {
                $list = [];
            }

            $list[] = [
                'id'               => $generatedId,
                'unit'             => $unit,
                'date'             => $date,
                'persons'          => $selectedPersons,
                'day_price_person' => $dayPricePerson,
                'cleaning_fee'     => $cleaningFee,
                'tt'               => $totalTT,
                'total'            => $totalOverall,
                'created_at'       => date('c'),
                'status'           => 'token',
                'source'           => 'offer_day_v1'
            ];

            if (!write_json($storePath, $list)) {
                $errors[]   = 'Napaka pri shranjevanju kode. Poskusite znova ali nas kontaktirajte.';
                $generatedId = null;
                $totalOverall = null;
            }
        }
    }
}

// ---------- STATIC PARTS FOR VIEW ----------

$totalBasePerPerson = $dayPricePerson;
$totalClean         = $cleaningFee;

// ---------- HTML OUTPUT ----------

?>
<!DOCTYPE html>
<html lang="sl">
<head>
  <meta charset="UTF-8">
  <title>Day-use ponudba – enota <?php echo h($unit); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/app/public/css/pubcal.css">
  <style>
    /* Minimalen layout za day-use ponudbo */
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      padding: 0;
      background: #111;
      color: #eee;
    }
    .day-offer-wrap {
      max-width: 960px;
      margin: 0 auto;
      padding: 1.5rem 1rem 3rem;
    }
    .day-offer-card {
      background: #1b1b1b;
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: 0 10px 30px rgba(0,0,0,0.6);
      border: 1px solid #333;
    }
    .day-offer-header {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: center;
      margin-bottom: 1rem;
    }
    .day-offer-title {
      font-size: 1.2rem;
      font-weight: 600;
    }
    .day-offer-date {
      font-size: 0.95rem;
      opacity: 0.8;
    }
    .badge {
      display: inline-block;
      padding: 0.2rem 0.6rem;
      border-radius: 999px;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      background: #1e88e5;
      color: #fff;
    }
    .badge-dayuse {
      background: linear-gradient(135deg, #00c6ff, #0072ff);
    }
    .line-items {
      border-top: 1px solid #333;
      border-bottom: 1px solid #333;
      padding: 1rem 0;
      margin: 1rem 0;
    }
    .line-item {
      display: flex;
      justify-content: space-between;
      font-size: 0.95rem;
      padding: 0.2rem 0;
    }
    .total-row {
      display: flex;
      justify-content: space-between;
      font-weight: 600;
      margin-top: 0.5rem;
      border-top: 1px dashed #444;
      padding-top: 0.4rem;
    }
    .meta {
      font-size: 0.9rem;
      opacity: 0.9;
      line-height: 1.4;
      margin-bottom: 1rem;
    }
    .meta div {
      margin-bottom: 0.25rem;
    }
    .hint {
      margin-top: 0.5rem;
      font-size: 0.85rem;
      opacity: 0.8;
    }
    .errors {
      background: #4a1212;
      border-radius: 8px;
      padding: 0.75rem 1rem;
      border: 1px solid #993333;
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }
    .errors ul {
      margin: 0.25rem 0 0;
      padding-left: 1.2rem;
    }
    .actions {
      margin-top: 1.5rem;
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.45rem 0.9rem;
      border-radius: 999px;
      border: 1px solid #666;
      background: #222;
      color: #eee;
      font-size: 0.9rem;
      text-decoration: none;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s, transform 0.05s;
    }
    .btn:hover {
      background: #333;
      border-color: #aaa;
    }
    .btn-primary {
      background: linear-gradient(135deg, #00c6ff, #0072ff);
      border-color: #0072ff;
      color: #fff;
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #00d4ff, #0084ff);
      border-color: #00a0ff;
    }
    .persons-form {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem 1rem;
      align-items: center;
      font-size: 0.9rem;
    }
    .persons-form label {
      margin-right: 0.25rem;
    }
    .persons-form select {
      background: #111;
      color: #eee;
      border-radius: 999px;
      border: 1px solid #555;
      padding: 0.25rem 0.6rem;
      font-size: 0.9rem;
    }
    .id-box {
      margin-top: 1rem;
      padding: 0.8rem 1rem;
      border-radius: 10px;
      background: #13222f;
      border: 1px solid #265a88;
      font-size: 0.9rem;
    }
    .id-box strong.code {
      font-family: "Fira Mono", "SF Mono", ui-monospace, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size: 1rem;
    }
    .id-box small {
      display: block;
      margin-top: 0.4rem;
      opacity: 0.85;
    }

    @media (max-width: 600px) {
      .day-offer-card { padding: 1rem; }
      .day-offer-header { flex-direction: column; align-items: flex-start; }
      .actions { flex-direction: column; align-items: stretch; }
      .btn { width: 100%; justify-content: center; }
      .persons-form { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>
<div class="day-offer-wrap">
  <div class="day-offer-card">
    <div class="day-offer-header">
      <div>
        <div class="day-offer-title">
          Day-use ponudba – enota <?php echo h($unit); ?>
        </div>
        <div class="day-offer-date">
          Datum: <strong><?php echo h(implode('.', array_reverse(explode('-', $date)))); ?></strong>
        </div>
      </div>
      <div>
        <span class="badge badge-dayuse">Dnevni počitek</span>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="errors">
        <strong>Trenutno ne moremo pripraviti ponudbe:</strong>
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?php echo h($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="line-items">
        <div class="line-item">
          <span>Osnovna day-use cena (na osebo)</span>
          <span><?php echo number_format($totalBasePerPerson, 2, ',', ' '); ?> €</span>
        </div>
        <div class="line-item">
          <span>Čiščenje (enkratno)</span>
          <span><?php echo number_format($totalClean, 2, ',', ' '); ?> €</span>
        </div>
        <div class="line-item">
          <span>Turistična taksa</span>
          <span><?php echo number_format($totalTT, 2, ',', ' '); ?> €</span>
        </div>
        <?php if ($selectedPersons !== null && $totalOverall !== null && $generatedId !== null): ?>
          <div class="total-row">
            <span>Skupaj za <?php echo (int)$selectedPersons; ?> oseb</span>
            <span><?php echo number_format($totalOverall, 2, ',', ' '); ?> €</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="meta">
        <?php if ($dayFrom || $dayTo): ?>
          <div>Okno dnevnega počitka: <strong><?php echo h(trim($dayFrom . '–' . $dayTo, '–')); ?></strong></div>
        <?php endif; ?>
        <?php if ($maxP > 0): ?>
          <div>Največje število oseb za day-use: <strong><?php echo (int)$maxP; ?></strong></div>
        <?php endif; ?>
        <div class="hint">
          Osnovna cena velja na osebo za izbrani datum. Končna cena je odvisna od dejanskega števila oseb.
          Popusti, promocijske kode in posebne akcije se na day-use ne uporabljajo. Turistična taksa se pri day-use ne obračuna.
        </div>
      </div>
    <?php endif; ?>

    <div class="actions">
      <a class="btn" href="/app/public/pubcal.php?unit=<?php echo urlencode($unit); ?>">
        ← Nazaj na koledar
      </a>

      <?php if (!$errors && $generatedId === null): ?>
        <form class="persons-form" method="post" action="">
          <input type="hidden" name="unit" value="<?php echo h($unit); ?>">
          <input type="hidden" name="date" value="<?php echo h($date); ?>">
          <label for="persons">Število oseb:</label>
          <select name="persons" id="persons">
            <?php
            $defaultPersons = $selectedPersons ?? 1;
            if ($defaultPersons < 1 || $defaultPersons > $maxP) {
                $defaultPersons = 1;
            }
            for ($i = 1; $i <= $maxP; $i++): ?>
              <option value="<?php echo $i; ?>" <?php if ($i === $defaultPersons) echo 'selected'; ?>>
                <?php echo $i; ?>
              </option>
            <?php endfor; ?>
          </select>
          <button class="btn btn-primary" type="submit">
            Potrdi število oseb in ustvari kodo
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$errors && $generatedId !== null): ?>
      <div class="id-box">
        <div>
          Vaša koda za day-use termin je:
          <strong class="code"><?php echo h($generatedId); ?></strong>
        </div>
        <small>
          Kodo skrbno shranite. Za navodila in dokončno dogovorjene podrobnosti
          nam pošljite to kodo na telefonsko številko
          <strong><?php echo h(DAYUSE_CONTACT_PHONE); ?></strong>.
          V primeru izgube kode jo lahko obnovimo le, če ste nam jo poslali preko
          SMS ali drugega dogovorjenega kanala.
        </small>
      </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
