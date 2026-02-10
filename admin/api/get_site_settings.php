<?php
require_once __DIR__ . "/../_common.php";

$settings = cm_settings_load();

json_response([
    "ok" => true,
    "settings" => $settings
]);
 
