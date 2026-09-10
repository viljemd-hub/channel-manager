<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/cm_connector.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * "CM ecosystem / Connectivity" - opt-in activation of the local CM Connector.
 * Faza A: local preparation only (consent + business agreement + installation_uuid),
 * no live connection to CM Relay yet (Relay does not exist as a live service -
 * see docs/cm_relay/).
 *
 * UI language: English is the default for distribution, Slovenian is available
 * side by side via ?lang=sl - both read from the single $T dictionary below so
 * there is one source of truth per string, not two diverging templates.
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/api/_lib/json_io.php';
require_once APP_COMMON . '/lib/cm_connector.php';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/admin/.*$#', '', $scriptName);
if ($basePath === null || $basePath === '/') {
    $basePath = '';
}

$lang = ($_GET['lang'] ?? '') === 'sl' ? 'sl' : 'en';

/**
 * Single source of truth for every user-facing string on this page, keyed by
 * id, each with an 'en' and 'sl' variant. Add a new language by adding a key
 * to every entry - t() and the language switcher need no other changes.
 */
$T = [
    'back' => ['en' => '&larr; Integrations', 'sl' => '&larr; Integrations'],
    'title' => ['en' => 'CM ecosystem / Connectivity', 'sl' => 'CM ekosistem / Connectivity'],
    'status_connected' => ['en' => 'Connected', 'sl' => 'Povezano'],
    'status_pending' => ['en' => 'Local setup in progress', 'sl' => 'V teku lokalne priprave'],
    'status_off' => ['en' => 'Not connected', 'sl' => 'Ni povezano'],
    'edition' => ['en' => 'Edition', 'sl' => 'Izdaja'],
    'version' => ['en' => 'Version', 'sl' => 'Verzija'],
    'installation_id' => ['en' => 'Installation ID', 'sl' => 'Installation ID'],
    'saved' => ['en' => 'Saved.', 'sl' => 'Shranjeno.'],
    'err_consent' => ['en' => 'All three consents must be checked to connect.', 'sl' => 'Za povezavo je treba potrditi vsa tri soglasja.'],
    'err_agreement' => ['en' => 'Could not save the business agreement details.', 'sl' => 'Podatkov poslovnega dogovora ni bilo mogoče shraniti.'],
    'err_services' => ['en' => 'Could not save the service selections.', 'sl' => 'Izbire storitev ni bilo mogoče shraniti.'],
    'err_disconnect' => ['en' => 'Could not disconnect.', 'sl' => 'Prekinitev povezave ni uspela.'],
    'intro' => [
        'en' => '<strong>CM Free/Plus/PRO works fully on its own, even without this connection.</strong> '
              . 'CM Connector is an optional bridge to the central <strong>CM Relay</strong> service, '
              . 'which will enable real two-way OTA sync (Booking.com, Airbnb) via an external '
              . 'connectivity provider in a future phase. Relay does not exist as a live service '
              . 'yet - this page only prepares a local installation identity and consent record, '
              . 'without making any outbound network call.',
        'sl' => '<strong>CM Free/Plus/PRO deluje popolnoma samostojno tudi brez te povezave.</strong> '
              . 'CM Connector je opcijski most do centralnega <strong>CM Relay</strong> servisa, ki bo '
              . 'v prihodnji fazi omogočil pravi dvosmerni OTA sync (Booking.com, Airbnb) prek '
              . 'zunanjega connectivity providerja. Trenutno Relay še ne obstaja kot živ servis - '
              . 'ta stran pripravi samo lokalno identiteto namestitve in soglasja, brez kakršnegakoli '
              . 'odhodnega omrežnega klica.',
    ],
    'scope_legend' => ['en' => 'What is exchanged once the connection is active', 'sl' => 'Kaj se izmenjuje, ko je povezava aktivna'],
    'scope_allowed' => ['en' => 'Allowed (only after activation, registry/telemetry):', 'sl' => 'Dovoljeno (samo po aktivaciji, registry/telemetrija):'],
    'scope_allowed_1' => ['en' => 'installation_id, edition (Free/Plus/PRO), version', 'sl' => 'installation_id, izdaja (Free/Plus/PRO), verzija'],
    'scope_allowed_2' => ['en' => 'unit count, language/locale', 'sl' => 'število enot, jezik/locale'],
    'scope_allowed_3' => ['en' => 'last activity time (heartbeat)', 'sl' => 'čas zadnje aktivnosti (heartbeat)'],
    'scope_allowed_4' => ['en' => 'list of enabled services (e.g. connectivity)', 'sl' => 'seznam vključenih storitev (npr. connectivity)'],
    'scope_denied' => ['en' => 'Never sent:', 'sl' => 'Nikoli se ne pošilja:'],
    'scope_denied_1' => ['en' => 'guest names, emails, phone numbers', 'sl' => 'imena, e-maili, telefonske številke gostov'],
    'scope_denied_2' => ['en' => 'Guestbook / official ID documents', 'sl' => 'Guestbook / AJPES dokumenti'],
    'scope_denied_3' => ['en' => 'full reservations, revenue, business data', 'sl' => 'celotne rezervacije, prihodki, poslovni podatki'],
    'scope_note' => [
        'en' => 'OTA reservation payloads may operationally pass through Relay (required for '
              . 'connectivity), but kept separate from the statistics above, with their own limited retention.',
        'sl' => 'OTA rezervacijski payload lahko skozi Relay operativno prehaja (nujno za connectivity), '
              . 'vendar ločeno od zgornje statistike in z lastno, omejeno retencijo.',
    ],
    'consent_legend' => ['en' => '1. Consent', 'sl' => '1. Soglasja'],
    'consent_1' => ['en' => 'I have read what data the CM Connector will exchange.', 'sl' => 'Prebral sem, katere podatke bo CM Connector izmenjeval.'],
    'consent_2' => ['en' => 'I agree to include this installation in the CM ecosystem (registry).', 'sl' => 'Strinjam se z vključitvijo te namestitve v CM ekosistem (registry).'],
    'consent_3' => ['en' => 'I agree to the terms and price of the selected service (see business agreement below).', 'sl' => 'Strinjam se s pogoji in ceno izbrane storitve (glej poslovni dogovor spodaj).'],
    'btn_connect' => ['en' => 'Connect CM (local setup)', 'sl' => 'Poveži CM (lokalna priprava)'],
    'services_legend' => ['en' => '2. Ecosystem services', 'sl' => '2. Storitve ekosistema'],
    'services_intro' => [
        'en' => 'Each service below is a separate decision - enabling one does not enable the others. '
              . 'These are the categories a future CM Relay build may offer; none are live yet (see '
              . 'docs/cm_relay/community_and_shared_services.md).',
        'sl' => 'Vsaka spodnja storitev je ločena odločitev - vklop ene ne vklopi ostalih. To so '
              . 'kategorije, ki jih bo lahko ponudil prihodnji CM Relay; nobena še ni živa (glej '
              . 'docs/cm_relay/community_and_shared_services.md).',
    ],
    'service_ota_connectivity' => ['en' => 'CM Connectivity (OTA sync via Relay)', 'sl' => 'CM Connectivity (OTA sync prek Relayja)'],
    'service_community' => ['en' => 'CM Community (public discovery, shared availability)', 'sl' => 'CM Community (javni discovery, deljena razpoložljivost)'],
    'service_community_referrals' => ['en' => 'Sharing / referrals to other CM Community members', 'sl' => 'Sharing / referrali k drugim CM Community članom'],
    'service_ai_discovery' => ['en' => 'AI-assisted discovery (natural-language search over Community data)', 'sl' => 'AI-podprto iskanje (naravno-jezikovno iskanje po Community podatkih)'],
    'btn_save_services' => ['en' => 'Save services', 'sl' => 'Shrani storitve'],
    'agreement_legend' => ['en' => '3. Business agreement', 'sl' => '3. Poslovni dogovor'],
    'agreement_intro' => [
        'en' => 'Template for manually entering the commercial terms once agreed with the CM Relay '
              . 'operator (plan, price, contract). This page does not bill anything - the fields are '
              . 'stored locally only, until Faza B/C connects them to a real entitlement system on the Relay side.',
        'sl' => 'Predloga za ročni vnos podatkov, ko je z operaterjem CM Relay dogovorjen komercialni '
              . 'okvir (plan, cena, pogodba). Ta stran ne obračunava ničesar - polja se le hranijo '
              . 'lokalno, dokler jih Faza B/C ne poveže z dejanskim entitlement sistemom na Relayju.',
    ],
    'agreement_plan' => ['en' => 'Plan', 'sl' => 'Plan / paket'],
    'agreement_plan_ph' => ['en' => 'e.g. CM Free + Connectivity', 'sl' => 'npr. CM Free + Connectivity'],
    'agreement_price' => ['en' => 'Price (EUR / month)', 'sl' => 'Cena (EUR / mesec)'],
    'agreement_contract_ref' => ['en' => 'Contract / agreement reference', 'sl' => 'Referenca pogodbe / dogovora'],
    'agreement_contract_ref_ph' => ['en' => 'e.g. CM-2026-0001', 'sl' => 'npr. CM-2026-0001'],
    'agreement_email' => ['en' => 'Relay account email', 'sl' => 'E-mail Relay računa'],
    'agreement_notes' => ['en' => 'Notes', 'sl' => 'Opombe'],
    'btn_save_agreement' => ['en' => 'Save business agreement', 'sl' => 'Shrani poslovni dogovor'],
    'disconnect_legend' => ['en' => '4. Disconnect', 'sl' => '4. Prekini povezavo'],
    'disconnect_hint' => ['en' => 'Clears consent, business agreement and status; the installation ID is kept in case you reconnect later.', 'sl' => 'Počisti soglasja, poslovni dogovor in status; installation ID ostane, če se boš kdaj vrnil.'],
    'disconnect_confirm' => ['en' => 'Disconnect from the CM ecosystem?', 'sl' => 'Prekini povezavo s CM ekosistemom?'],
    'btn_disconnect' => ['en' => 'Disconnect', 'sl' => 'Prekini povezavo'],
];

function t(string $key): string
{
    global $T, $lang;
    return $T[$key][$lang] ?? $T[$key]['en'] ?? $key;
}

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_consent') {
        $consent = [
            'data_scope' => !empty($_POST['consent_data_scope']),
            'ecosystem_inclusion' => !empty($_POST['consent_ecosystem_inclusion']),
            'terms_price' => !empty($_POST['consent_terms_price']),
        ];
        if (cm_connector_save_consent($consent)) {
            $saved = true;
        } else {
            $errors[] = t('err_consent');
        }
    } elseif ($action === 'save_services') {
        $services = [
            'ota_connectivity' => !empty($_POST['service_ota_connectivity']),
            'community' => !empty($_POST['service_community']),
            'community_referrals' => !empty($_POST['service_community_referrals']),
            'ai_discovery' => !empty($_POST['service_ai_discovery']),
        ];
        if (cm_connector_save_services($services)) {
            $saved = true;
        } else {
            $errors[] = t('err_services');
        }
    } elseif ($action === 'save_agreement') {
        $agreement = [
            'plan' => (string)($_POST['agreement_plan'] ?? ''),
            'price_eur_month' => (string)($_POST['agreement_price_eur_month'] ?? ''),
            'contract_ref' => (string)($_POST['agreement_contract_ref'] ?? ''),
            'relay_account_email' => (string)($_POST['agreement_relay_account_email'] ?? ''),
            'notes' => (string)($_POST['agreement_notes'] ?? ''),
        ];
        if (cm_connector_save_agreement($agreement)) {
            $saved = true;
        } else {
            $errors[] = t('err_agreement');
        }
    } elseif ($action === 'disconnect') {
        if (cm_connector_disconnect()) {
            $saved = true;
        } else {
            $errors[] = t('err_disconnect');
        }
    }
}

$status = cm_connector_status();
$consent = $status['consent'];
$agreement = $status['agreement'];
$services = $status['services'];
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<title>CM – <?= h(t('title')) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #0f1115; color: #e6e8eb; margin: 0; padding: 24px; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin-bottom: 4px; }
  h2 { font-size: 1.05rem; }
  .topbar { display: flex; justify-content: space-between; align-items: center; }
  .sub { color: #9aa1ab; margin-bottom: 24px; font-size: .9rem; }
  fieldset { border: 1px solid #2a2f3a; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; }
  legend { padding: 0 8px; font-weight: 600; color: #cdd3db; }
  label { display: block; font-size: .82rem; color: #9aa1ab; margin: 10px 0 4px; }
  label.chk { display: flex; align-items: flex-start; gap: 8px; color: #cdd3db; font-size: .88rem; margin: 10px 0; }
  label.chk input { margin-top: 3px; }
  input[type=text], input[type=email] { width: 100%; box-sizing: border-box; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; color: #e6e8eb; padding: 8px 10px; font-size: .92rem; }
  textarea { width: 100%; box-sizing: border-box; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; color: #e6e8eb; padding: 8px 10px; font-size: .92rem; min-height: 60px; }
  .btn { background: #2f6fed; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: .92rem; cursor: pointer; }
  .btn:hover { background: #2a63d4; }
  .btn.danger { background: #6e2a2a; }
  .btn.danger:hover { background: #7f3232; }
  .notice { background: #1a2e1a; border: 1px solid #2c5c2c; color: #b7e6b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .warn { background: #2e1a1a; border: 1px solid #5c2c2c; color: #e6b7b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .info { background: #1a2233; border: 1px solid #2c3f5c; color: #b7cfe6; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: .85rem; }
  .mono { font-family: ui-monospace, monospace; font-size: .85rem; }
  .status-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: .78rem; margin-left: 8px; }
  .status-pill.on { background: #1a2e1a; color: #9ee39e; }
  .status-pill.pending { background: #332b1a; color: #e6cf9e; }
  .status-pill.off { background: #2a2f3a; color: #9aa1ab; }
  .hint { color: #6f7784; font-size: .78rem; margin-top: 4px; }
  ul.scope { font-size: .85rem; color: #cdd3db; margin: 6px 0 0 18px; padding: 0; }
  ul.scope.deny li { color: #e6b7b7; }
  a.back { color: #7aa2f7; text-decoration: none; font-size: .85rem; }
  .lang-switch a { color: #7aa2f7; text-decoration: none; font-size: .8rem; margin-left: 8px; }
  .lang-switch a.active { color: #e6e8eb; font-weight: 600; text-decoration: underline; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <p><a class="back" href="<?= h($basePath) ?>/admin/integrations.php"><?= t('back') ?></a></p>
    <p class="lang-switch">
      <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
      <a href="?lang=sl" class="<?= $lang === 'sl' ? 'active' : '' ?>">SL</a>
    </p>
  </div>
  <h1><?= h(t('title')) ?>
    <?php if ($status['activation_status'] === 'connected'): ?>
      <span class="status-pill on"><?= h(t('status_connected')) ?></span>
    <?php elseif ($status['activation_status'] === 'pending_local'): ?>
      <span class="status-pill pending"><?= h(t('status_pending')) ?></span>
    <?php else: ?>
      <span class="status-pill off"><?= h(t('status_off')) ?></span>
    <?php endif; ?>
  </h1>
  <p class="sub">
    <?= h(t('edition')) ?>: <strong><?= h(strtoupper($status['edition'])) ?></strong> ·
    <?= h(t('version')) ?>: <strong><?= h($status['version']) ?></strong>
    <?php if (!empty($status['installation_uuid'])): ?>
      · <?= h(t('installation_id')) ?>: <span class="mono"><?= h((string)$status['installation_uuid']) ?></span>
    <?php endif; ?>
  </p>

  <?php if ($saved): ?>
    <div class="notice"><?= h(t('saved')) ?></div>
  <?php endif; ?>
  <?php foreach ($errors as $err): ?>
    <div class="warn"><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="info"><?= t('intro') ?></div>

  <fieldset>
    <legend><?= h(t('scope_legend')) ?></legend>
    <p class="hint"><?= h(t('scope_allowed')) ?></p>
    <ul class="scope">
      <li><?= h(t('scope_allowed_1')) ?></li>
      <li><?= h(t('scope_allowed_2')) ?></li>
      <li><?= h(t('scope_allowed_3')) ?></li>
      <li><?= h(t('scope_allowed_4')) ?></li>
    </ul>
    <p class="hint" style="margin-top:10px;"><?= h(t('scope_denied')) ?></p>
    <ul class="scope deny">
      <li><?= h(t('scope_denied_1')) ?></li>
      <li><?= h(t('scope_denied_2')) ?></li>
      <li><?= h(t('scope_denied_3')) ?></li>
    </ul>
    <p class="hint" style="margin-top:10px;"><?= h(t('scope_note')) ?></p>
  </fieldset>

  <fieldset>
    <legend><?= h(t('consent_legend')) ?></legend>
    <form method="post">
      <input type="hidden" name="action" value="save_consent">
      <label class="chk">
        <input type="checkbox" name="consent_data_scope" <?= !empty($consent['data_scope']) ? 'checked' : '' ?>>
        <span><?= h(t('consent_1')) ?></span>
      </label>
      <label class="chk">
        <input type="checkbox" name="consent_ecosystem_inclusion" <?= !empty($consent['ecosystem_inclusion']) ? 'checked' : '' ?>>
        <span><?= h(t('consent_2')) ?></span>
      </label>
      <label class="chk">
        <input type="checkbox" name="consent_terms_price" <?= !empty($consent['terms_price']) ? 'checked' : '' ?>>
        <span><?= h(t('consent_3')) ?></span>
      </label>
      <button class="btn" type="submit" style="margin-top:10px;"><?= h(t('btn_connect')) ?></button>
    </form>
  </fieldset>

  <fieldset>
    <legend><?= h(t('services_legend')) ?></legend>
    <p class="hint"><?= h(t('services_intro')) ?></p>
    <form method="post">
      <input type="hidden" name="action" value="save_services">
      <label class="chk">
        <input type="checkbox" name="service_ota_connectivity" <?= !empty($services['ota_connectivity']) ? 'checked' : '' ?>>
        <span><?= h(t('service_ota_connectivity')) ?></span>
      </label>
      <label class="chk">
        <input type="checkbox" name="service_community" <?= !empty($services['community']) ? 'checked' : '' ?>>
        <span><?= h(t('service_community')) ?></span>
      </label>
      <label class="chk">
        <input type="checkbox" name="service_community_referrals" <?= !empty($services['community_referrals']) ? 'checked' : '' ?>>
        <span><?= h(t('service_community_referrals')) ?></span>
      </label>
      <label class="chk">
        <input type="checkbox" name="service_ai_discovery" <?= !empty($services['ai_discovery']) ? 'checked' : '' ?>>
        <span><?= h(t('service_ai_discovery')) ?></span>
      </label>
      <button class="btn" type="submit" style="margin-top:10px;"><?= h(t('btn_save_services')) ?></button>
    </form>
  </fieldset>

  <fieldset>
    <legend><?= h(t('agreement_legend')) ?></legend>
    <p class="hint"><?= h(t('agreement_intro')) ?></p>
    <form method="post">
      <input type="hidden" name="action" value="save_agreement">
      <div class="grid2">
        <div>
          <label for="agreement_plan"><?= h(t('agreement_plan')) ?></label>
          <input type="text" id="agreement_plan" name="agreement_plan" class="mono"
                 value="<?= h((string)($agreement['plan'] ?? '')) ?>" placeholder="<?= h(t('agreement_plan_ph')) ?>">
        </div>
        <div>
          <label for="agreement_price_eur_month"><?= h(t('agreement_price')) ?></label>
          <input type="text" id="agreement_price_eur_month" name="agreement_price_eur_month" class="mono"
                 value="<?= h((string)($agreement['price_eur_month'] ?? '')) ?>" placeholder="9.90">
        </div>
        <div>
          <label for="agreement_contract_ref"><?= h(t('agreement_contract_ref')) ?></label>
          <input type="text" id="agreement_contract_ref" name="agreement_contract_ref" class="mono"
                 value="<?= h((string)($agreement['contract_ref'] ?? '')) ?>" placeholder="<?= h(t('agreement_contract_ref_ph')) ?>">
        </div>
        <div>
          <label for="agreement_relay_account_email"><?= h(t('agreement_email')) ?></label>
          <input type="email" id="agreement_relay_account_email" name="agreement_relay_account_email"
                 value="<?= h((string)($agreement['relay_account_email'] ?? '')) ?>" placeholder="info@example.com">
        </div>
      </div>
      <label for="agreement_notes"><?= h(t('agreement_notes')) ?></label>
      <textarea id="agreement_notes" name="agreement_notes"><?= h((string)($agreement['notes'] ?? '')) ?></textarea>
      <button class="btn" type="submit" style="margin-top:10px;"><?= h(t('btn_save_agreement')) ?></button>
    </form>
  </fieldset>

  <?php if ($status['activation_status'] !== 'not_connected'): ?>
    <fieldset>
      <legend><?= h(t('disconnect_legend')) ?></legend>
      <p class="hint"><?= h(t('disconnect_hint')) ?></p>
      <form method="post" onsubmit="return confirm('<?= h(t('disconnect_confirm')) ?>');">
        <input type="hidden" name="action" value="disconnect">
        <button class="btn danger" type="submit"><?= h(t('btn_disconnect')) ?></button>
      </form>
    </fieldset>
  <?php endif; ?>
</div>
</body>
</html>
