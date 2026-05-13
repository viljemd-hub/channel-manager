<?php
// admin/api/admin_ai_settings_get.php
require_once __DIR__ . '/../_common.php';

// Kje hranimo AI nastavitve?
$path = CM_DATA_DIR . '/site_settings/ai_settings.json';

if (!file_exists($path)) {
    // Default vrednosti, če datoteka še ne obstaja
    $defaults = [
        "enabled"     => false,
        "provider"    => "none",
        "openai_key"  => "",
        "groq_key"    => "",
        "ollama_url"  => "http://localhost:11434"
    ];

    echo json_encode([
        "ok" => true,
        "settings" => $defaults
    ]);
    exit;
}

$raw = file_get_contents($path);
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode([
        "ok" => false,
        "error" => "settings_invalid"
    ]);
    exit;
}

// Vrni JSON nazaj admin_reviews.js
echo json_encode([
    "ok" => true,
    "settings" => $data
]);
