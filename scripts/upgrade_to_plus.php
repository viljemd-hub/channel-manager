<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: scripts/upgrade_to_plus.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * has to be in 
 * scripts/upgrade_to_plus.php
 */
// Determine app root dynamically (this script lives in APP/scripts)
$APP_ROOT = realpath(__DIR__ . '/..');
if ($APP_ROOT === false) {
    $APP_ROOT = __DIR__ . '/..';
}

$SETTINGS_PATH = $APP_ROOT . '/common/data/json/units/site_settings.json';

// BASIC PROTECTION (admin key)
$ADMIN_KEY_PATH = $APP_ROOT . '/common/data/admin_key.txt';
$validKey = trim((string)@file_get_contents($ADMIN_KEY_PATH));
$givenKey = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));

if ($validKey === '' || !hash_equals($validKey, $givenKey)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function read_settings(string $path): array {
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

function write_settings(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return (bool)@file_put_contents($path, $json);
}

function first_manifest_unit_id(string $appRoot): ?string {
    $manifestPath = $appRoot . '/common/data/json/units/manifest.json';
    if (!is_file($manifestPath)) {
        return null;
    }

    $raw = @file_get_contents($manifestPath);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $manifest = json_decode($raw, true);
    if (!is_array($manifest) || !isset($manifest['units']) || !is_array($manifest['units'])) {
        return null;
    }

    // IMPORTANT:
    // First unit = first inserted unit in manifest.json.
    // Do NOT sort alphabetically.
    foreach ($manifest['units'] as $unit) {
        if (is_string($unit) && trim($unit) !== '') {
            return trim($unit);
        }

        if (is_array($unit)) {
            $id = trim((string)($unit['id'] ?? $unit['unit'] ?? ''));
            $active = ($unit['active'] ?? true) !== false;

            if ($id !== '' && $active) {
                return $id;
            }
        }
    }

    return null;
}

function read_unit_settings(string $appRoot, string $unitId): array {
    $path = $appRoot . '/common/data/json/units/' . $unitId . '/site_settings.json';
    return read_settings($path);
}

function write_unit_settings(string $appRoot, string $unitId, array $data): bool {
    $path = $appRoot . '/common/data/json/units/' . $unitId . '/site_settings.json';
    return write_settings($path, $data);
}


$settings = read_settings($SETTINGS_PATH);

// pripravi obstoječe vrednosti (če so)
$licenseTier  = (string)($settings['license']['tier'] ?? 'free');
$sepaName     = (string)($settings['payment']['sepa']['name'] ?? 'Apartma Matevž');
$sepaIban     = (string)($settings['payment']['sepa']['iban'] ?? '');
$sepaBic      = (string)($settings['payment']['sepa']['bic']  ?? '');
$bonusDays    = (int)($settings['payment']['booking']['bonus_deadline_days_before_arrival']    ?? 7);
$payDays      = (int)($settings['payment']['booking']['payment_deadline_days_before_arrival']  ?? 3);
$reminderDays = (int)($settings['payment']['booking']['reminder_days_before_payment_deadline'] ?? 2);
$firstUnitId = first_manifest_unit_id($APP_ROOT);
$firstUnitSettings = $firstUnitId ? read_unit_settings($APP_ROOT, $firstUnitId) : [];

if ($firstUnitId) {
    $sepaName = (string)($firstUnitSettings['payment']['sepa']['name'] ?? $sepaName);
    $sepaIban = (string)($firstUnitSettings['payment']['sepa']['iban'] ?? $sepaIban);
    $sepaBic  = (string)($firstUnitSettings['payment']['sepa']['bic']  ?? $sepaBic);
}

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sepaName     = trim($_POST['sepa_name'] ?? '');
    $sepaIban     = trim($_POST['sepa_iban'] ?? '');
    $sepaBic      = trim($_POST['sepa_bic']  ?? '');
    $bonusDays    = (int)($_POST['bonus_days']    ?? 7);
    $payDays      = (int)($_POST['payment_days']  ?? 3);
    $reminderDays = (int)($_POST['reminder_days'] ?? 2);

    if ($sepaName === '' || $sepaIban === '' || $sepaBic === '') {
        $errorMsg = 'Prosimo izpolnite polja: Naziv, IBAN in BIC.';
    } else {
         // zagotovimo strukturo
        if (!isset($settings['license']) || !is_array($settings['license'])) {
            $settings['license'] = [];
        }
        if (!isset($settings['license']['features']) || !is_array($settings['license']['features'])) {
            $settings['license']['features'] = [];
        }
        if (!isset($settings['payment']) || !is_array($settings['payment'])) {
            $settings['payment'] = [];
        }
        if (!isset($settings['payment']['booking']) || !is_array($settings['payment']['booking'])) {
            $settings['payment']['booking'] = [];
        }
        if (!isset($settings['payment']['sepa']) || !is_array($settings['payment']['sepa'])) {
            $settings['payment']['sepa'] = [];
        }
        if (!isset($settings['product']) || !is_array($settings['product'])) {
            $settings['product'] = [];
        }


        // license: preklop na Plus
        $settings['license']['tier'] = 'plus';
        $settings['license']['features']['advanced_payments'] = true;
        // PRO functions stay disabled in Plus tier
        $settings['license']['features']['invoicing']        = $settings['license']['features']['invoicing']        ?? false;
        $settings['license']['features']['basic_accounting'] = $settings['license']['features']['basic_accounting'] ?? false;

        // product meta: reflect Plus tier globally
        $settings['product']['tier'] = 'plus';
        if (!isset($settings['product']['version'])) {
            $settings['product']['version'] = '1.0.0';
        }

// Global Plus payment capability.
// Real SEPA recipient data is now stored per unit.
$settings['payment']['methods'] = ['at_desk', 'sepa'];

$settings['payment']['booking']['bonus_deadline_days_before_arrival']    = max(0, $bonusDays);
$settings['payment']['booking']['payment_deadline_days_before_arrival']  = max(0, $payDays);
$settings['payment']['booking']['reminder_days_before_payment_deadline'] = max(0, $reminderDays);

$firstUnitId = first_manifest_unit_id($APP_ROOT);

if ($firstUnitId === null) {
    $errorMsg = 'CM Plus je pripravljen, vendar ni mogoče najti prve enote v manifest.json.';
} else {
    $unitSettings = read_unit_settings($APP_ROOT, $firstUnitId);

    if (!isset($unitSettings['payment']) || !is_array($unitSettings['payment'])) {
        $unitSettings['payment'] = [];
    }
    if (!isset($unitSettings['payment']['booking']) || !is_array($unitSettings['payment']['booking'])) {
        $unitSettings['payment']['booking'] = [];
    }
    if (!isset($unitSettings['payment']['sepa']) || !is_array($unitSettings['payment']['sepa'])) {
        $unitSettings['payment']['sepa'] = [];
    }

    $unitSettings['payment']['methods'] = ['at_desk', 'sepa'];

    $unitSettings['payment']['booking']['bonus_deadline_days_before_arrival']    = max(0, $bonusDays);
    $unitSettings['payment']['booking']['payment_deadline_days_before_arrival']  = max(0, $payDays);
    $unitSettings['payment']['booking']['reminder_days_before_payment_deadline'] = max(0, $reminderDays);

    $unitSettings['payment']['sepa']['enabled'] = true;
    $unitSettings['payment']['sepa']['name'] = $sepaName;
    $unitSettings['payment']['sepa']['iban'] = $sepaIban;
    $unitSettings['payment']['sepa']['bic']  = $sepaBic;

    if (!write_unit_settings($APP_ROOT, $firstUnitId, $unitSettings)) {
        $errorMsg = 'Napaka pri pisanju SEPA nastavitev za prvo enoto: ' . $firstUnitId;
    }
}

if ($errorMsg === '') {
    if (write_settings($SETTINGS_PATH, $settings)) {
        $licenseTier = 'plus';
        $successMsg = 'Evreka! CM Plus je vklopljen. SEPA podatki so shranjeni za prvo ustvarjeno enoto'
            . ($firstUnitId ? ' (' . $firstUnitId . ')' : '')
            . '. Pri več enotah vnesite SEPA podatke za vsako enoto posebej.';

        $renameOk = @rename(__FILE__, __FILE__ . '.used');
        if (!$renameOk) {
            error_log('[upgrade_to_plus] auto-disable failed for ' . __FILE__);
        }
    } else {
        $errorMsg = 'Napaka pri pisanju globalne datoteke units/site_settings.json.';
    }
}


?>
<!doctype html>
<html lang="sl">
<head>
    <meta charset="utf-8">
    <title>CM Plus – Upgrade čarovnik</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f5f7;
            margin: 0;
            padding: 0;
        }
        .shell {
            max-width: 720px;
            margin: 0 auto;
            padding: 16px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            padding: 20px 20px 18px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 1.6rem;
        }
        .subtitle {
            margin: 0 0 16px;
            color: #555;
            font-size: 0.95rem;
        }
        label {
            display: block;
            margin-top: 10px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 7px 10px;
            margin-top: 4px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 0.95rem;
        }
        .row {
            display: flex;
            gap: 12px;
        }
        .row .col {
            flex: 1 1 0;
        }
        .muted {
            color: #777;
            font-size: 0.85rem;
        }
        .status {
            margin: 10px 0 0;
            font-size: 0.95rem;
        }
        .status.ok {
            color: #0a7a0a;
        }
        .status.err {
            color: #b00020;
        }
        button {
            margin-top: 16px;
            padding: 8px 16px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            background: #007bff;
            color: #fff;
        }
        .tier-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            background: #eee;
            margin-left: 6px;
        }
        .tier-badge.plus {
            background: #d1f1ff;
            color: #00518a;
        }
        .tier-badge.free {
            background: #f0f0f0;
            color: #555;
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <h1>CM Plus – Upgrade čarovnik
            <span class="tier-badge <?php echo $licenseTier === 'plus' ? 'plus' : 'free'; ?>">
                Trenutno: <?php echo h(strtoupper($licenseTier)); ?>
            </span>
        </h1>
        <p class="subtitle">
            Ta čarovnik preklopi Channel Manager v <b>Plus način</b> (SEPA plačila + časovnice) in
            vklopi Plus globalno, SEPA podatke pa zapiše v <b>prvo ustvarjeno enoto</b>.
Pri več enotah je treba SEPA podatke vnesti za vsako enoto posebej.        </p>

        <?php if ($successMsg): ?>
            <div class="status ok"><?php echo h($successMsg); ?></div>
        <?php elseif ($errorMsg): ?>
            <div class="status err"><?php echo h($errorMsg); ?></div>
        <?php endif; ?>

        <form method="post">
            <h2>SEPA nastavitve</h2>

            <label for="sepa_name">Naziv prejemnika</label>
            <input type="text" id="sepa_name" name="sepa_name" value="<?php echo h($sepaName); ?>" required>

            <label for="sepa_iban">IBAN</label>
            <input type="text" id="sepa_iban" name="sepa_iban" value="<?php echo h($sepaIban); ?>" required>

            <label for="sepa_bic">BIC</label>
            <input type="text" id="sepa_bic" name="sepa_bic" value="<?php echo h($sepaBic); ?>" required>

            <h2>Časovnice</h2>
            <div class="row">
                <div class="col">
                    <label for="bonus_days">Bonus rok (dni pred prihodom)</label>
                    <input type="number" id="bonus_days" name="bonus_days" min="0" value="<?php echo (int)$bonusDays; ?>">
                    <div class="muted">Do tega dne velja bonus za zgodnje plačilo.</div>
                </div>
                <div class="col">
                    <label for="payment_days">Končni rok plačila (dni pred prihodom)</label>
                    <input type="number" id="payment_days" name="payment_days" min="0" value="<?php echo (int)$payDays; ?>">
                    <div class="muted">Po tem datumu se lahko rezervacija samodejno odpove.</div>
                </div>
                <div class="col">
                    <label for="reminder_days">Opomnik (dni pred končnim rokom)</label>
                    <input type="number" id="reminder_days" name="reminder_days" min="0" value="<?php echo (int)$reminderDays; ?>">
                    <div class="muted">Kdaj pošljemo opomnik za plačilo.</div>
                </div>
            </div>

            <button type="submit">💥 Evreka – vklopi CM Plus</button>
            <p class="muted" style="margin-top:8px;">
                Po uspešnem vklopu je priporočljivo, da skripto <code>upgrade_to_plus.php</code> umaknete ali zaklenete (npr. z geslom).
            </p>
        </form>
    </div>
</div>
</body>
</html>
