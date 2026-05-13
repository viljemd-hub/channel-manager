<?php
require_once __DIR__ . "/../_common.php";
  
/**
 * Small local helper: read JSON from php://input.
 * We guard it with function_exists in case a global helper is added later.
 */
if (!function_exists('json_input')) {
    function json_input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

$input = json_input();

$settings = cm_settings_load();

$settings["ai"] = [
    "enabled"     => !empty($input["enabled"]),
    "provider"    => $input["provider"] ?? "none",
    "openai_key"  => trim($input["openai_key"] ?? ""),
    "groq_key"    => trim($input["groq_key"] ?? ""),
    "ollama_url"  => trim($input["ollama_url"] ?? "http://localhost:11434"),
];

// Save back to settings storage
cm_settings_save($settings);

// Respond to JS
json_response(["ok" => true]);
