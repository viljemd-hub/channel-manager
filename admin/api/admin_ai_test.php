<?php
require_once __DIR__ . "/../_common.php";

$settings = cm_settings_load();
$ai = $settings["ai"] ?? [];

$enabled = $ai["enabled"] ?? false;
$provider = $ai["provider"] ?? "none";

if (!$enabled) {
    json_response(["ok" => false, "error" => "AI moderation disabled"]);
}

// --------------------------
// PROVIDER: OPENAI / GROQ / OLLAMA
// --------------------------

if ($provider === "groq") {
    $key = trim($ai["groq_key"] ?? "");
    if (!$key) {
        json_response(["ok" => false, "error" => "Groq key missing"]);
    }

    $url = "https://api.groq.com/openai/v1/chat/completions";

    $payload = [
        "model" => "llama-3.1-8b-instant",
        "messages" => [
            ["role" => "user", "content" => "ping"]
        ]
    ];

    $response = ai_http_post($url, $payload, [
        "Authorization: Bearer " . $key,
        "Content-Type: application/json"
    ]);

    if (!$response["ok"]) {
        json_response([
            "ok" => false,
            "error" => $response["error"],
            "body" => $response["body"]
        ]);
    }

    json_response([
        "ok" => true,
        "info" => "Groq connection OK"
    ]);
}

if ($provider === "openai") {
    $key = trim($ai["openai_key"] ?? "");
    if (!$key) {
        json_response(["ok" => false, "error" => "OpenAI key missing"]);
    }

    $url = "https://api.openai.com/v1/chat/completions";

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "user", "content" => "ping"]
        ]
    ];

    $response = ai_http_post($url, $payload, [
        "Authorization: Bearer " . $key,
        "Content-Type: application/json"
    ]);

    if (!$response["ok"]) {
        json_response([
            "ok" => false,
            "error" => $response["error"],
            "body" => $response["body"]
        ]);
    }

    json_response([
        "ok" => true,
        "info" => "OpenAI connection OK"
    ]);
}

if ($provider === "ollama") {
    $url = rtrim($ai["ollama_url"] ?? "http://localhost:11434", "/") . "/api/chat";

    $payload = [
        "model" => "llama3.2",
        "messages" => [
            ["role" => "user", "content" => "ping"]
        ]
    ];

    $response = ai_http_post($url, $payload, [
        "Content-Type: application/json"
    ]);

    if (!$response["ok"]) {
        json_response([
            "ok" => false,
            "error" => $response["error"],
            "body" => $response["body"]
        ]);
    }

    json_response([
        "ok" => true,
        "info" => "Ollama connection OK"
    ]);
}

json_response(["ok" => false, "error" => "Unknown provider"]);



// ===================================================
// UNIVERSAL HTTP POST HELPER (cURL required)
// ===================================================
function ai_http_post($url, $payload, $headers = []) {

    if (!function_exists('curl_init')) {
        return [
            "ok" => false,
            "error" => "PHP cURL module missing",
            "body" => ""
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_POSTFIELDS      => json_encode($payload),
        CURLOPT_TIMEOUT         => 10
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($err || $code >= 400) {
        return [
            "ok" => false,
            "error" => $err ?: "HTTP error $code",
            "body" => $body
        ];
    }

    return ["ok" => true, "body" => $body];
}
