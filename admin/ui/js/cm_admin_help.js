/**
 * CM Free / CM Plus – Central Admin Help
 * JSON-driven contextual guide for admin pages.
 */

(function () {
  if (window.CMAdminHelp) return;

  const STORAGE_PREFIX = "cm_admin_help_seen_";
  const GUIDE_API = "/app/admin/api/admin_guides_get.php";

  let allGuides = {};
  let activeGuide = [];
  let activeIndex = 0;
  let bar = null;

  function qs(sel) {
    return document.querySelector(sel);
  }

  function pageKey() {
    const path = window.location.pathname || "";
    return path.replace(/^\/app(?:_pro)?/, "");
  }

  function storageKey() {
    return STORAGE_PREFIX + pageKey();
  }

  function currentLang() {
    const lang = (document.documentElement.lang || "sl").toLowerCase();
    return lang.startsWith("en") ? "en" : "sl";
  }

  async function loadGuides() {
    try {
      const res = await fetch(GUIDE_API, {
        credentials: "same-origin",
        cache: "no-store"
      });

      if (!res.ok) return {};

      const json = await res.json();
      if (!json || !json.ok || !json.data) return {};

      return json.data;
    } catch (e) {
      console.warn("[CMAdminHelp] loadGuides failed", e);
      return {};
    }
  }

  function getGuideForCurrentPage() {
    const page = pageKey();
    const lang = currentLang();

    const pageGuides = allGuides[page];
    if (!pageGuides) return [];

    return pageGuides[lang] || pageGuides.en || pageGuides.sl || [];
  }

  function typeClass(type) {
    switch (type) {
      case "warning": return "cm-help-warning";
      case "success": return "cm-help-success";
      case "pro": return "cm-help-pro";
      default: return "";
    }
  }
  
  function sizeClass(size) {
  switch (size) {
    case "large": return "cm-help-size-large";
    case "xl": return "cm-help-size-xl";
    default: return "";
  }
}
  
function normalizeSelector(sel) {
  sel = String(sel || "").trim();
  if (!sel) return "";

  // already complex selector
  if (
    sel.startsWith("#") ||
    sel.startsWith(".") ||
    sel.startsWith("[") ||
    sel.includes(" ") ||
    sel.includes(">") ||
    sel.includes(":")
  ) {
    return sel;
  }

  // try ID
  if (document.getElementById(sel)) {
    return "#" + sel;
  }

  // try class
  if (document.getElementsByClassName(sel).length > 0) {
    return "." + sel;
  }

  // fallback (tag or unknown)
  return sel;
}

  function ensureStyles() {
    if (document.getElementById("cm-admin-help-style")) return;

    const style = document.createElement("style");
    style.id = "cm-admin-help-style";
    style.textContent = `
.cm-help-bar{
  position:sticky;
  top:0;
  z-index:5000;
  margin:0;
  padding:10px 14px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  background:rgba(11,33,66,.96);
  border-bottom:1px solid rgba(255,255,255,.12);
  box-shadow:0 8px 24px rgba(0,0,0,.22);
}
.cm-help-warning{background:#5a3d00;}
.cm-help-success{background:#0b3d1f;}
.cm-help-pro{background:#3d0b5a;}

.cm-help-copy{
  display:flex;
  flex-direction:column;
  gap:2px;
}
.cm-help-title{
  font-weight:800;
  font-size:14px;
  color:#eaf2ff;
}
.cm-help-text{
  font-size:13px;
  line-height:1.35;
  color:rgba(234,242,255,.86);
}
.cm-help-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-shrink:0;
}
.cm-help-step{
  font-size:12px;
  opacity:.75;
  margin-right:4px;
}
.cm-help-target{
  position:relative;
  outline:2px solid rgba(87,166,255,.85);
  outline-offset:3px;
  border-radius:10px;
  box-shadow:0 0 0 9999px rgba(0,0,0,.16);
  transition:box-shadow .18s ease, outline .18s ease;
}
.cm-help-size-large .cm-help-title{
  font-size:16px;
}

.cm-help-size-large .cm-help-text{
  font-size:15px;
}

.cm-help-size-xl .cm-help-title{
  font-size:19px;
}

.cm-help-size-xl .cm-help-text{
  font-size:17px;
}
@media (max-width:720px){
  .cm-help-bar{
    align-items:flex-start;
    flex-direction:column;
  }
  .cm-help-actions{
    width:100%;
    justify-content:flex-end;
  }
}
`;
    document.head.appendChild(style);
  }

  function clearTarget() {
    document.querySelectorAll(".cm-help-target").forEach(function (el) {
      el.classList.remove("cm-help-target");
    });
  }

  function closeGuide(markSeen) {
    clearTarget();

    if (bar) {
      bar.remove();
      bar = null;
    }

    if (markSeen) {
      localStorage.setItem(storageKey(), "1");
    }
  }

  function findStep(startIndex) {
    for (let i = startIndex; i < activeGuide.length; i++) {
      const step = activeGuide[i];
      if (!step || step.enabled === false) continue;
      const sel = normalizeSelector(step.el);
      if (sel && qs(sel)) return i;
      console.log("checking:", step.el);
    }
    return -1;
  }

  function renderStep() {
    clearTarget();

    const found = findStep(activeIndex);
    if (found < 0) {
      closeGuide(true);
      return;
    }

    activeIndex = found;

    const step = activeGuide[activeIndex];
    const target = qs(normalizeSelector(step.el));

    if (!target) {
      activeIndex++;
      renderStep();
      return;
    }

    target.classList.add("cm-help-target");

    target.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest"
    });

    if (!bar) {
      bar = document.createElement("section");
      document.body.insertBefore(bar, document.body.firstChild);
    }

    const nextFound = findStep(activeIndex + 1);
    const isLast = nextFound < 0;

    bar.className = "cm-help-bar " + typeClass(step.type) + " " + sizeClass(step.size);

    bar.innerHTML =
      '<div class="cm-help-copy">' +
      '  <div class="cm-help-title">' + escapeHtml(step.title || "Help") + '</div>' +
      '  <div class="cm-help-text">' + escapeHtml(step.text || "") + '</div>' +
      '</div>' +
      '<div class="cm-help-actions">' +
      '  <span class="cm-help-step">' + String(activeIndex + 1) + " / " + String(activeGuide.length) + '</span>' +
      '  <button type="button" class="btn small ghost" data-cm-help="back">Back</button>' +
      '  <button type="button" class="btn small ghost" data-cm-help="close">Close</button>' +
      '  <button type="button" class="btn small primary" data-cm-help="next">' + (isLast ? "Finish" : "Next") + '</button>' +
      '</div>';

    const nextBtn = bar.querySelector('[data-cm-help="next"]');
    const closeBtn = bar.querySelector('[data-cm-help="close"]');
    const backBtn = bar.querySelector('[data-cm-help="back"]');

if (backBtn) {
  backBtn.addEventListener("click", function () {
    if (activeIndex === 0) return;
    activeIndex--;
    renderStep();
  });
}

    if (nextBtn) {
      nextBtn.addEventListener("click", function () {
        if (isLast) {
          closeGuide(true);
          return;
        }
        activeIndex = nextFound;
        renderStep();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener("click", function () {
        closeGuide(true);
      });
    }
  }

  function start(force) {
    activeGuide = getGuideForCurrentPage();

    if (!Array.isArray(activeGuide) || !activeGuide.length) {
      return;
    }

    if (!force && localStorage.getItem(storageKey())) {
      return;
    }

    ensureStyles();
    activeIndex = 0;
    renderStep();
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  window.CMAdminHelp = {
    start: function () {
      start(true);
    },
    close: function () {
      closeGuide(false);
    },
    resetCurrentPage: function () {
      localStorage.removeItem(storageKey());
    },
    reload: async function () {
      allGuides = await loadGuides();
      start(true);
    }
  };

  document.addEventListener("DOMContentLoaded", async function () {
    allGuides = await loadGuides();
    start(false);
  });
})();