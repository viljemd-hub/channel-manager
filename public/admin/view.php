<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: public/admin/view.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_key();
$id = $_GET['id'] ?? '';
$p = find_inquiry_path($id);
if (!$p) { http_response_code(404); echo "Ni zapisa."; exit; }
header('Content-Type: application/json; charset=utf-8');
readfile($p);
