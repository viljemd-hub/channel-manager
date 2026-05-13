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
body{font-family:sans-serif; padding:20px;}
.row{display:flex; gap:10px; margin-bottom:10px;}
input,select{padding:6px;}
.step{border:1px solid #ccc; padding:10px; margin-bottom:10px;}
.btn{padding:6px 10px; cursor:pointer;}
</style>
</head>
<body>

<h1>Guide Editor</h1>

<div class="row">
  <select id="page">
    <option value="/admin/admin_calendar.php">Calendar</option>
    <option value="/admin/manage_reservations.php">Reservations</option>
    <option value="/admin/inquiries_admin.php">Inquiries</option>
    <option value="/admin/integrations.php">Integrations</option>
  </select>

  <select id="lang">
    <option value="sl">SL</option>
    <option value="en">EN</option>
  </select>

  <button class="btn" onclick="addStep()">+ Add step</button>
  <button class="btn" onclick="save()">Save</button>
  <button class="btn" onclick="preview()">Preview</button>
  <button class="btn" onclick="resetSeen()">Reset seen</button>
</div>

<div id="steps"></div>

<script>
const PRESETS = {
  "/admin/admin_calendar.php": [
    "#admin-unit-select",
    "#cal-layer-toggles",
    "#calendar",
    "#cal-command-bar"
  ],
  "/admin/manage_reservations.php": [
    "#manage-reservations",
    "#mr-filter-unit",
    "#mr-detail"
  ],
  "/admin/inquiries_admin.php": [
    "#inq-unit-select",
    "#inqList",
    "#inqDetail"
  ],
  "/admin/integrations.php": [
    "#unitSelect",
    "#card-units",
    "#card-ics",
    "#card-channels",
    "#card-autopilot"
  ]
};
let DATA = {};

async function load(){
  const res = await fetch('/app/admin/api/admin_guides_get.php');
  const json = await res.json();
  DATA = json.data || {};
  render();
}

function getCurrent(){
  const p = page.value;
  const l = lang.value;
  if(!DATA[p]) DATA[p] = {};
  if(!DATA[p][l]) DATA[p][l] = [];
  return DATA[p][l];
}

function preset(i, val){
  if(!val) return;
  getCurrent()[i].el = val;
  render();
}

function render(){
  const list = getCurrent();
  steps.innerHTML = '';

  list.forEach((s,i)=>{
    const div = document.createElement('div');
    div.className='step';

    div.innerHTML = `
      <div class="row">
<div class="row">
  <select onchange="preset(${i}, this.value)">
    <option value="">-- element --</option>
    ${(PRESETS[page.value] || []).map(p => 
      `<option value="${p}">${p}</option>`
    ).join('')}
  </select>

  <input placeholder="selector" value="${s.el||''}"
    onchange="upd(${i},'el',this.value)">
  
   <input placeholder="title" value="${s.title||''}" onchange="upd(${i},'title',this.value)">
      </div>
      <div class="row">
        <input style="flex:1" placeholder="text" value="${s.text||''}" onchange="upd(${i},'text',this.value)">
      </div>
      <div class="row">
        <select onchange="upd(${i},'type',this.value)">
          <option ${s.type==='info'?'selected':''}>info</option>
          <option ${s.type==='warning'?'selected':''}>warning</option>
          <option ${s.type==='success'?'selected':''}>success</option>
          <option ${s.type==='pro'?'selected':''}>pro</option>
        </select>
       <select onchange="upd(${i},'size',this.value)">
       <option value="normal" ${s.size==='normal' || !s.size ? 'selected' : ''}>normal</option>
       <option value="large" ${s.size==='large' ? 'selected' : ''}>large</option>
       <option value="xl" ${s.size==='xl' ? 'selected' : ''}>xl</option>
  </select>
        <button onclick="up(${i})">↑</button>
        <button onclick="down(${i})">↓</button>
        <button onclick="del(${i})">✕</button>
      </div>
    `;
    steps.appendChild(div);
  });
}

function upd(i,k,v){
  getCurrent()[i][k]=v;
}

function addStep(){
  getCurrent().push({el:'',title:'',text:'',type:'info'});
  render();
}

function del(i){
  getCurrent().splice(i,1);
  render();
}

function up(i){
  if(i===0) return;
  const a=getCurrent();
  [a[i-1],a[i]]=[a[i],a[i-1]];
  render();
}

function down(i){
  const a=getCurrent();
  if(i===a.length-1) return;
  [a[i+1],a[i]]=[a[i],a[i+1]];
  render();
}

async function save(){
  await fetch('/app/admin/api/admin_guides_save.php',{
    method:'POST',
    body:JSON.stringify(DATA),
    headers:{'Content-Type':'application/json'}
  });
  alert('Saved');
}

function preview(){
  const path = page.value.replace('/admin','/app/admin');
  window.open(path,'_blank');
}

function seenKeyForCurrentPage(){
  return "cm_admin_help_seen_" + page.value;
}

function resetSeen(){
  const key = seenKeyForCurrentPage();
  localStorage.removeItem(key);
  alert("Guide reset for: " + page.value);
}

page.onchange=render;
lang.onchange=render;

load();
</script>

</body>
</html>