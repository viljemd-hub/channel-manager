<?php
require_once __DIR__ . "/../_common.php";
require_once APP_COMMON . "/lib/reviews.php";

$year = $_GET['year'] ?? date('Y');

$data = cm_review_load_year($year);
$out = [];

foreach ($data as $rv) {
    if (!isset($rv['reservation_id'])) continue;
    $rv['id'] = $rv['reservation_id'];
    $out[] = $rv;
}

json_response([
    "ok" => true,
    "reviews" => $out
]);

