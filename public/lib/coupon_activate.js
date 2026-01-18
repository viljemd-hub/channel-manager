/**
 * CM Free / CM Plus – Channel Manager
 * File: public/lib/coupon_activate.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

(async function(){
  // Wait for i18n
  if(window.i18nReady){ await window.i18nReady; }

  const t = (window.t || (k=>k));

  const title = document.getElementById('title');
  const hint  = document.getElementById('hint');
  const input = document.getElementById('codeInput');
  const btn   = document.getElementById('activateBtn');
  const status= document.getElementById('status');
  const details=document.getElementById('details');
  const cCode = document.getElementById('cCode');
  const cValid= document.getElementById('cValid');
  const cValue= document.getElementById('cValue');

  // i18n labels
  title.textContent = "Coupon Activation";
  hint.textContent  = t("cta.activate_coupon");

  function qsCode(){
    const u = new URL(window.location.href);
    return u.searchParams.get('promo') || u.searchParams.get('code');
  }

  async function activate(code){
    if(!code){ status.innerHTML = `<span class="err">${t("coupon.invalid")}</span>`; return; }
    try{
      const res = await fetch('/app/public/api/activate_coupon.php?code='+encodeURIComponent(code), {method:'GET', cache:'no-store'});
      const j = await res.json();
      if(!j.ok){
        status.innerHTML = `<span class="err">${t("coupon.invalid")}</span>`;
        details.style.display = 'none';
        return;
      }
      const s = j.status; // activated | already_active
      status.innerHTML = `<span class="ok">${s==="activated" ? t("coupon.activated") : t("coupon.thanks")}</span>`;
      cCode.textContent = j.coupon.code;
      cValid.textContent = `${j.coupon.valid_from} → ${j.coupon.valid_to}`;
      cValue.textContent = `${j.coupon.type} ${j.coupon.value}`;
      details.style.display = '';
    }catch(e){
      console.warn(e);
      status.innerHTML = `<span class="err">${t("coupon.invalid")}</span>`;
      details.style.display = 'none';
    }
  }

  btn.addEventListener('click', ()=> activate(input.value.trim()));
  // Auto-activation on link open (?promo=CODE)
  const auto = qsCode();
  if(auto){
    input.value = auto;
    activate(auto);
  }
})();
