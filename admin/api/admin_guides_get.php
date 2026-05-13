<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_key();

$path = __DIR__ . '/../../common/data/json/admin_guides.json';

if (!file_exists($path)) {
    echo json_encode(['ok' => true, 'data' => new stdClass()]);
    exit;
}

$data = json_decode(file_get_contents($path), true);
if (!is_array($data)) {
    $data = [];
}

echo json_encode(['ok' => true, 'data' => $data]); 
