/**
 * CM Free / CM Plus – Channel Manager
 * File: lib/i18n.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

(function(){
  async function detectLang(){
    const url = new URL(window.location.href);
    const qs = url.searchParams.get("lang");
    if(qs) { localStorage.setItem("lang", qs); return qs; }
    const saved = localStorage.getItem("lang");
    if(saved) return saved;
    const nav = (navigator.languages && navigator.languages[0]) || navigator.language || "en";
    const short = String(nav).toLowerCase().split('-')[0];
    const supported = ["sl","en","de","it","fr","nl"];
    const pick = supported.includes(short) ? short : "en";
    localStorage.setItem("lang", pick);
    return pick;
  }

  async function loadStrings(lang){
    try{
      const res = await fetch(`/app/common/data/i18n/strings_${lang}.json`, {cache:"no-store"});
      if(!res.ok) throw new Error("i18n fetch failed: "+res.status);
      const data = await res.json();
      window.I18N = data;
    }catch(e){
      console.warn(e);
      window.I18N = {};
    }
  }

  function t(key){
    const en = window.I18N && window.I18N[key];
    if(en) return en;
    console.warn("i18n missing key:", key);
    return key;
  }

  window.i18nReady = (async ()=>{
    const lang = await detectLang();
    await loadStrings(lang);
    window.__LANG__ = lang;
    return {lang, t};
  })();

  window.t = t;
})();
