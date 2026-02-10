// admin/reviews/admin_reviews.js

// ===============================
// Helpers & constants
// ===============================

const REVIEWS_FILTERS_KEY = 'cm_admin_reviews_filters_v1';

// Save current filter state to localStorage
function saveFilterState() {
  try {
    const fsEl = document.getElementById("f_status");
    const frEl = document.getElementById("f_rating");
    const faEl = document.getElementById("f_ai");
    if (!fsEl || !frEl || !faEl) return;

    const payload = {
      status: fsEl.value || "",
      rating: frEl.value || "",
      ai:     faEl.value || ""
    };

    localStorage.setItem(REVIEWS_FILTERS_KEY, JSON.stringify(payload));
  } catch (err) {
    console.warn("[reviews] saveFilterState failed", err);
  }
}

// Restore filter state from localStorage (if any)
function restoreFilterState() {
  try {
    const raw = localStorage.getItem(REVIEWS_FILTERS_KEY);
    if (!raw) return;

    const payload = JSON.parse(raw);
    const fsEl = document.getElementById("f_status");
    const frEl = document.getElementById("f_rating");
    const faEl = document.getElementById("f_ai");
    if (!fsEl || !frEl || !faEl) return;

    if (typeof payload.status === "string") fsEl.value = payload.status;
    if (typeof payload.rating === "string") frEl.value = payload.rating;
    if (typeof payload.ai     === "string") faEl.value = payload.ai;
  } catch (err) {
    console.warn("[reviews] restoreFilterState failed", err);
  }
}

// ===============================
// Backend calls
// ===============================

async function fetchReviews() {
  const year = new Date().getFullYear();
  const res = await fetch(`../api/admin_reviews_list.php?year=${year}`);
  const data = await res.json();
  return data.reviews || [];
}

async function updateReview(id, action) {
  try {
    await fetch(`../api/admin_reviews_update.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, action })
    });
  } catch (err) {
    console.error("[reviews] updateReview failed", err);
  }
  loadUI();
}

// ===============================
// UI rendering
// ===============================

async function loadUI() {
  const wrap = document.getElementById("reviews");
  if (!wrap) return;

  wrap.innerHTML = "Loading…";

  const list = await fetchReviews();

  const fsEl = document.getElementById("f_status");
  const frEl = document.getElementById("f_rating");
  const faEl = document.getElementById("f_ai");

  const fs = fsEl ? fsEl.value : "";
  const fr = frEl ? frEl.value : "";
  const fa = faEl ? faEl.value : "";

  wrap.innerHTML = "";

  list.forEach(r => {
    // Filters
    if (fs && r.status !== fs) return;
    if (fr && String(r.rating) !== fr) return;
    if (fa && r.ai_category !== fa) return;

    const div = document.createElement("div");
    div.className = `review-item status-${r.status}`;

    // Status badge
    let statusLabel = r.status || "pending";
    let statusClass = "";
    switch (r.status) {
      case "approved":
        statusClass = "status-badge approved";
        break;
      case "quarantine":
        statusClass = "status-badge quarantine";
        break;
      case "rejected":
        statusClass = "status-badge rejected";
        break;
      case "pending":
      default:
        statusClass = "status-badge pending";
        statusLabel = "pending";
    }

    // Simple AI label
    const aiLabel = r.ai_category ? `AI: ${r.ai_category}` : "";

    div.innerHTML = `
      <div class="top-line">
        <div class="rating">
          ${"★".repeat(r.rating) + "☆".repeat(5 - r.rating)}
        </div>
        <div class="meta">
          <span class="id">ID: ${r.id}</span>
          <span class="${statusClass}">${statusLabel}</span>
          ${aiLabel ? `<span class="ai-label">${aiLabel}</span>` : ""}
        </div>
      </div>

      <div class="text">${r.text}</div>

      ${
        r.is_flagged
          ? `<div class="flag">⚠ ${r.flag_reason || "flagged"}</div>`
          : ""
      }

      <div class="toxicity">Toxicity: ${r.toxicity}</div>

      <div class="actions">
        <button class="btn-approve"
                onclick="updateReview('${r.id}','approve')">Approve</button>
        <button class="btn-quarantine"
                onclick="updateReview('${r.id}','quarantine')">Quarantine</button>
        <!-- Pending je zdaj samo status badge, brez gumba -->

      </div>
    `;

    wrap.appendChild(div);
  });

  if (!wrap.innerHTML) {
    wrap.innerHTML = "<p>No reviews found for current filters.</p>";
  }
}

// ===============================
// Filter events + Refresh
// ===============================

(function initFilters() {
  const fsEl = document.getElementById("f_status");
  const frEl = document.getElementById("f_rating");
  const faEl = document.getElementById("f_ai");
  const btnRefresh = document.getElementById("btnReviewsRefresh");

  if (fsEl) {
    fsEl.addEventListener("change", () => {
      saveFilterState();
      loadUI();
    });
  }
  if (frEl) {
    frEl.addEventListener("change", () => {
      saveFilterState();
      loadUI();
    });
  }
  if (faEl) {
    faEl.addEventListener("change", () => {
      saveFilterState();
      loadUI();
    });
  }

  if (btnRefresh) {
    btnRefresh.addEventListener("click", () => {
      // osveži seznam s trenutnimi filtri
      loadUI();
    });
  }

  // ob prvem loadu obnovimo zadnji state filtrov
  restoreFilterState();
})();

// Initial load
loadUI();


// ===============================
// AI SETTINGS PANEL
// ===============================

async function loadAiSettings() {
  try {
    const res = await fetch("../api/get_site_settings.php");
    const js = await res.json();

    const ai = js.settings && js.settings.ai ? js.settings.ai : {};

    document.getElementById("ai_enabled").checked = !!ai.enabled;
    document.getElementById("ai_provider").value = ai.provider || "none";
    document.getElementById("ai_openai_key").value = ai.openai_key || "";
    document.getElementById("ai_groq_key").value = ai.groq_key || "";
    document.getElementById("ai_ollama_url").value = ai.ollama_url || "";
  } catch (err) {
    console.error("[reviews] loadAiSettings failed", err);
    alert("Failed to load AI settings.");
  }
}

async function saveAiSettings() {
  const payload = {
    enabled: document.getElementById("ai_enabled").checked,
    provider: document.getElementById("ai_provider").value,
    openai_key: document.getElementById("ai_openai_key").value,
    groq_key: document.getElementById("ai_groq_key").value,
    ollama_url: document.getElementById("ai_ollama_url").value
  };

  try {
    const res = await fetch("../api/admin_ai_settings_save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const js = await res.json().catch(() => ({}));

    if (!js.ok) {
      console.warn("[reviews] saveAiSettings not ok", js);
      alert("Error saving AI settings.");
      return;
    }

    alert("Settings saved.");
  } catch (err) {
    console.error("[reviews] saveAiSettings failed", err);
    alert("Error saving AI settings (network or server).");
  }
}

async function testAiConnection() {
  try {
    const res = await fetch("../api/admin_ai_test.php");
    const js = await res.json();

    if (js.ok) {
      alert("AI OK: " + (js.info || "success"));
    } else {
      alert("AI ERROR: " + (js.error || "unknown") + "\n" + (js.body || ""));
    }
  } catch (err) {
    console.error("[reviews] testAiConnection failed", err);
    alert("AI ERROR: request failed.");
  }
}

// Modal show/hide wiring
(function initAiModal() {
  const btnSettings = document.getElementById("btnAiSettings");
  const modal       = document.getElementById("aiModal");
  const btnClose    = document.getElementById("aiCloseBtn");
  const btnSave     = document.getElementById("aiSaveBtn");
  const btnTest     = document.getElementById("aiTestBtn");

  if (btnSettings && modal) {
    btnSettings.addEventListener("click", () => {
      modal.style.display = "flex";
      loadAiSettings();
    });
  }

  if (btnClose && modal) {
    btnClose.addEventListener("click", () => {
      modal.style.display = "none";
    });
  }

  if (btnSave) {
    btnSave.addEventListener("click", () => {
      saveAiSettings();
    });
  }

  if (btnTest) {
    btnTest.addEventListener("click", () => {
      testAiConnection();
    });
  }
})();
