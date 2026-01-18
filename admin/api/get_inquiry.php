<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/api/get_inquiry.php
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Get full details for a single inquiry by ID.
 *
 * Responsibilities:
 * - Locate the JSON file for a given inquiry ID in the inquiries tree.
 * - Return the complete JSON payload (guest details, range, pricing info).
 *
 * Used by:
 * - admin/ui/js/admin_info_panel.js (detail view).
 * - admin/ui/js/manage_reservations.js (when inspecting a pending item).
 *
 * Notes:
 * - This endpoint is read-only.
 */

// /var/www/html/app/admin/api/get_inquiry.php
//
// GET/POST:
//  - inquiry_id (required)
//  - stage (optional): accepted|pending|cancelled|rejected
//
// Če stage NI podan, endpoint sam poišče v vrstnem redu:
// accepted -> pending -> cancelled -> rejected
//
// Odgovor:
// { ok:true, stage:"accepted", path:"/abs/file.json", data:{...} }

header('Content-Type: application/json; charset=utf-8');

function fail($code,$msg,$extra=[]){
  http_response_code($code);
  echo json_encode(array_merge(["ok"=>false,"err"=>$msg],$extra), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
  exit;
}
function json_load_lenient($p,$fb=[]){
  if(!file_exists($p)) return $fb;
  $r=file_get_contents($p); if($r===false) return $fb;
  $d=json_decode($r,true); return is_array($d)?$d:$fb;
}

$in = $_GET;
if(empty($in)){
  $raw=file_get_contents('php://input');
  $tmp=json_decode($raw,true);
  if(is_array($tmp)) $in=$tmp;
}

$id   = $in['inquiry_id'] ?? null;
$stage= $in['stage'] ?? null; // optional

if(!$id) fail(400,"Missing 'inquiry_id'");
if(!preg_match('/^(\d{14})-[A-Za-z0-9]+-[A-Za-z0-9]+$/',$id,$m)) fail(400,"Bad inquiry_id format",["inquiry_id"=>$id]);
$stamp=$m[1]; $year=substr($stamp,0,4); $mon=substr($stamp,4,2);

$BASE="/var/www/html/app/common/data/json/inquiries/$year/$mon";
$paths=[
  "accepted"=>"$BASE/accepted/$id.json",
  "pending"=>"$BASE/pending/$id.json",
  "cancelled"=>"$BASE/cancelled/$id.json",
  "rejected"=>"$BASE/rejected/$id.json",
];

$checkOrder = $stage ? [$stage] : ["accepted","pending","cancelled","rejected"];

$foundStage=null; $foundPath=null;
foreach($checkOrder as $s){
  if(isset($paths[$s]) && file_exists($paths[$s])){
    $foundStage=$s; $foundPath=$paths[$s]; break;
  }
}
if(!$foundPath){
  fail(404,"Inquiry not found in expected stages",[
    "checked_stages"=>$checkOrder,
    "base_dir"=>$BASE
  ]);
}

$data=json_load_lenient($foundPath,[]);
http_response_code(200);
echo json_encode([
  "ok"=>true,
  "stage"=>$foundStage,
  "path"=>$foundPath,
  "data"=>$data
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
