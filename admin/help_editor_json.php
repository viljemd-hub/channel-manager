<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Guide Editor</title>
  <style>
    textarea{
      width:100%;
      height:500px;
      font-family:monospace;
      font-size:13px;
    }
  </style>
</head>
<body>

<h1>Admin Guide Editor</h1>

<textarea id="json"></textarea>
<br><br>
<button onclick="save()">Save</button>

<script>
async function load(){
  const res = await fetch('/app/admin/api/admin_guides_get.php');
  const json = await res.json();
  document.getElementById('json').value =
    JSON.stringify(json.data, null, 2);
}

async function save(){
  const val = document.getElementById('json').value;

  try{
    const parsed = JSON.parse(val);

    await fetch('/app/admin/api/admin_guides_save.php', {
      method: 'POST',
      body: JSON.stringify(parsed),
      headers: { 'Content-Type': 'application/json' }
    });

    alert('Saved');
  }catch(e){
    alert('Invalid JSON');
  }
}

load();
</script>

</body>
</html> 
