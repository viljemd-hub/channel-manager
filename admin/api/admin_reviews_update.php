<?php
// admin/api/admin_reviews_update.php
require_once __DIR__ . "/../_common.php";
require_once APP_COMMON . "/lib/reviews.php";

$in = json_decode(file_get_contents("php://input"), true);

$id     = $in["id"] ?? null;
$action = $in["action"] ?? null;

if (!$id || !$action) {
    json_response(["ok" => false, "error" => "missing_parameters"]);
}

$review = cm_review_get_for_reservation($id);
if (!$review) {
    json_response(["ok" => false, "error" => "not_found"]);
}

switch ($action) {
    case "approve":
        $review["status"] = "approved";
        $review["visible"] = true;
        $review["approved"] = true;
        break;

    case "pending":
        $review["status"] = "pending";
        $review["visible"] = false;
        $review["approved"] = false;
        break;

    case "quarantine":
        $review["status"] = "quarantine";
        $review["visible"] = false;
        $review["is_flagged"] = true;
        break;

    case "reject":
        $review["status"] = "rejected";
        $review["visible"] = false;
        break;

    default:
        json_response(["ok" => false, "error" => "invalid_action"]);
}

cm_review_save_for_reservation($id, $review);
cm_review_rebuild_public_files();

json_response(["ok" => true]);
