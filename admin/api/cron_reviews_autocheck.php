<?php
require_once __DIR__ . "/../_common.php";
require_once __DIR__ . "/../../common/lib/reviews_ai.php";

$now = time();
$threshold = 48 * 3600;  // 48 hours

$changed = 0;

// Load all review years
$reviewsRoot = CM_DATA_DIR . "/reviews";
$years = array_filter(scandir($reviewsRoot), fn($y) => preg_match('/^[0-9]{4}$/', $y));

foreach ($years as $year) {
    $path = "$reviewsRoot/$year.json";
    if (!file_exists($path)) continue;

    $reviews = json_decode(file_get_contents($path), true);
    if (!is_array($reviews)) continue;

    foreach ($reviews as $id => &$r) {

        // Only pending reviews
        if (($r["status"] ?? "") !== "pending") continue;

        // Missing timestamp → skip
        if (empty($r["timestamp"])) continue;

        $age = $now - $r["timestamp"];
        if ($age < $threshold) continue;

        // 48h expired → decide
        $tox = $r["toxicity"] ?? 0;
        $cat = $r["ai_category"] ?? "ok";
        $sent = $r["sentiment"] ?? 0;

        $danger =
            in_array($cat, ["insult", "hate", "spam"]) ||
            $tox >= 0.60 ||
            $sent <= -0.60;

        if ($danger) {
            $r["status"] = "quarantine";
            $r["auto_moderation"] = "quarantine_after_timeout";
        } else {
            $r["status"] = "approved";
            $r["auto_moderation"] = "approved_after_timeout";
        }

        $r["auto_moderation_ts"] = $now;
        $changed++;
    }

    // Save updated year file
    file_put_contents($path, json_encode($reviews, JSON_PRETTY_PRINT));
}

// Rebuild public JSON (only approved will appear)
cm_review_rebuild_public_files();

json_response([
    "ok" => true,
    "changed" => $changed,
    "message" => "$changed review entries auto-moderated"
]);
