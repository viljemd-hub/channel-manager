/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/inquiries_admin.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /var/www/html/app/admin/ui/js/inquiries_admin.js

import { listInquiries, getInquiry } from "./admin_api.js";

/**
 * Simple admin inquiries board.
 *
 * Layout (see inquiries_admin.php + inquiries.css):
 *
 * <section id="tab-inquiries">
 *   <div class="inq-wrap">
 *     <div id="inqList"></div>   ← left list
 *     <div id="inqDetail"></div> ← right detail card + raw JSON
 *   </div>
 * </section>
 */

let CURRENT_UNIT = "";

// Global marked set (shared with other views if needed)
window.CM_MARKED_SET = window.CM_MARKED_SET || new Set();

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */

function qs(sel, root) {
  return (root || document).querySelector(sel);
}

function qsa(sel, root) {
  return Array.from((root || document).querySelectorAll(sel));
}

function isoNights(from, to) {
  if (!from || !to) return null;
  const a = new Date(from + "T00:00:00");
  const b = new Date(to + "T00:00:00");
  if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;
  const days = Math.round((b - a) / 86400000);
  return days > 0 ? days : null;
}

function formatMoney(v) {
  if (v == null) return "—";
  const num = Number(v);
  if (!Number.isFinite(num)) return "—";
  return `${num.toFixed(2)} €`;
}

function safe(v) {
  if (v == null || v === "") return "—";
  return String(v);
}

/* ------------------------------------------------------------------ */
/* Initial wiring                                                     */
/* ------------------------------------------------------------------ */

document.addEventListener("DOMContentLoaded", () => {
  wireUnitSelect();
  wireRefreshButton();
  initCurrentUnitFromSelect();

  // najprej naložimo marked set iz JSON-a, potem seznam
  (async () => {
    await loadMarkedSetForUnit(CURRENT_UNIT);
    await loadInquiries();
  })();
});

/**
 * Initialize CURRENT_UNIT from the <select> value if present.
 */
function initCurrentUnitFromSelect() {
  const sel = qs("#inq-unit-select");
  if (sel && sel.value) {
    CURRENT_UNIT = sel.value;
  } else {
    CURRENT_UNIT = "";
  }
}

// Napolni <select id="inq-unit-select"> glede na dejanske enote v seznamu
function updateUnitSelectFromItems(items) {
  const sel = qs("#inq-unit-select");
  if (!sel) return;
  if (!Array.isArray(items)) items = [];

  // prejšnja izbira + globalni CURRENT_UNIT
  const prevValue = sel.value || "";
  const current = CURRENT_UNIT || "";

  const unitsSet = new Set();
  for (const it of items) {
    if (it && it.unit) {
      unitsSet.add(String(it.unit));
    }
  }

  const units = Array.from(unitsSet).sort();

  // zgradimo nove option elemente
  const frag = document.createDocumentFragment();
  const mkOpt = (value, label) => {
    const o = document.createElement("option");
    o.value = value;
    o.textContent = label;
    return o;
  };

  frag.appendChild(mkOpt("", "All"));
  units.forEach((u) => {
    frag.appendChild(mkOpt(u, u));
  });

  // zamenjamo obstoječe opcije
  sel.innerHTML = "";
  sel.appendChild(frag);

  // poskusimo ohraniti trenutno izbrano enoto
  let desired = current || prevValue;
  if (desired && units.includes(desired)) {
    sel.value = desired;
    CURRENT_UNIT = desired;
  } else {
    sel.value = "";
    CURRENT_UNIT = "";
  }
}


/**
 * Wire unit select – for now it just sets CURRENT_UNIT and reloads list.
 * In a later session we can auto-populate options from manifest.json.
 */
function wireUnitSelect() {
  const sel = qs("#inq-unit-select");
  if (!sel) return;

  sel.addEventListener("change", () => {
    CURRENT_UNIT = sel.value || "";

    // ob menjavi enote najprej preberemo marked set za to enoto,
    // nato osvežimo seznam
    (async () => {
      await loadMarkedSetForUnit(CURRENT_UNIT);
      await loadInquiries();
    })();
  });
}


/**
 * Wire Refresh button.
 */
function wireRefreshButton() {
  const btn = qs("#inq-refresh-btn");
  if (!btn) return;

  btn.addEventListener("click", () => {
    void loadInquiries();
  });
}

/* ------------------------------------------------------------------ */
/* Loading & rendering list                                           */
/* ------------------------------------------------------------------ */

/**
 * Prebere označene pending-e z backend-a (pending_mark_toggle.php)
 * in napolni window.CM_MARKED_SET, brez da briše JSON.
 */
async function loadMarkedSetForUnit(unit) {
  const newSet = new Set();

  // če imaš izbrano enoto, pošlji unit=..., sicer vzemi vse
  const url = unit
    ? `/app/admin/api/pending_mark_toggle.php?unit=${encodeURIComponent(unit)}&v=${Date.now()}`
    : `/app/admin/api/pending_mark_toggle.php?v=${Date.now()}`;

  try {
    const res = await fetch(url, {
      credentials: "same-origin",
      cache: "no-store"
    });

    if (!res.ok) {
      console.warn("[inquiries_admin] loadMarkedSetForUnit HTTP", res.status);
    } else {
      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }

      let items = [];
      if (Array.isArray(data)) {
        items = data;
      } else if (data && Array.isArray(data.items)) {
        items = data.items;
      }

      for (const item of items) {
        if (!item || !item.id) continue;
        newSet.add(String(item.id));
      }
    }
  } catch (err) {
    console.warn("[inquiries_admin] loadMarkedSetForUnit failed:", err);
  }

  // zamenjaj globalni set in osveži pikice v levem seznamu
  window.CM_MARKED_SET = newSet;
  refreshMarkedDots();
}


async function loadInquiries() {
  const listEl = qs("#inqList");
  const detailEl = qs("#inqDetail");

  if (listEl) listEl.textContent = "Nalagam…";
  if (detailEl && !detailEl.dataset.id) {
    detailEl.innerHTML = `
      <div class="inq-card">
        <div class="inq-card-row">
          <span>Status:</span>
          <b>Izberi povpraševanje na levi.</b>
        </div>
      </div>
    `;
  }

  let items = [];
  try {
    // list_inquiries.php pozna ?status=...; če ne podamo nič, vrne
    // pending + accepted_soft_hold. Zaenkrat filtriramo samo po enoti.
    const params = {
      order: "desc",
      limit: 200
    };
    if (CURRENT_UNIT) params.unit = CURRENT_UNIT;

    items = await listInquiries(params);
    if (!Array.isArray(items)) items = [];
    // posodobi <select> z enotami glede na dejanski seznam
    updateUnitSelectFromItems(items);
  } catch (err) {
    console.error("[inquiries_admin] listInquiries failed:", err);
    if (listEl) listEl.textContent = "Napaka pri branju povpraševanj.";
    return;
  }

  if (!items.length) {
    if (listEl) listEl.textContent = "Ni povpraševanj (pending/soft-hold).";
    return;
  }

  if (!listEl) return;
  listEl.innerHTML = "";
  items.forEach((it) => renderInquiryRow(listEl, it));

  // after rendering, sync marked dots
  refreshMarkedDots();
}

/**
 * Render a single row in the left inquiry list.
 */
function renderInquiryRow(listEl, item) {
  const row = document.createElement("div");
  const nightsCalc = Number.isFinite(item.nights)
    ? item.nights
    : isoNights(item.from, item.to);

  row.className = "inq-row";
  row.dataset.id = item.id || "";

  row.innerHTML = `
    <div class="inq-col inq-id">
      <span class="inq-id-text">${safe(item.id)}</span>
      <span class="mark-dot"></span>
    </div>
    <div class="inq-col inq-dates">
      <span class="dates-text">${safe(item.from)} → ${safe(item.to)}</span>
      <span class="nights-badge" title="Št. noči">${nightsCalc ?? "—"}</span>noči
    </div>
    <div class="inq-col inq-guest">${safe(item.guest_name)}</div>
  `;

  // Show marked dot if ID is currently marked
  if (window.CM_MARKED_SET?.has(item.id)) {
    row.classList.add("is-marked");
  }

  row.addEventListener("click", async () => {
    // Visual active state
    qsa(".inq-row", listEl).forEach((el) => el.classList.remove("active"));
    row.classList.add("active");

    // Load full inquiry and render detail
    let full = null;
    try {
      if (item.id) {
        full = await getInquiry(item.id);
      }
    } catch (err) {
      console.warn("[inquiries_admin] getInquiry failed:", err);
    }

    renderInquiryDetailCard(qs("#inqDetail"), item, full);

    // Recalculate nights from full data if possible
    const badge = row.querySelector(".nights-badge");
    if (badge && full?.data) {
      const fd = full.data;
      const f = fd.from || item.from;
      const t = fd.to || item.to;
      const n = Number.isFinite(fd.nights) ? fd.nights : isoNights(f, t);
      if (n) badge.textContent = n;
    }
  });

  listEl.appendChild(row);
}

/* ------------------------------------------------------------------ */
/* Detail card + actions                                              */
/* ------------------------------------------------------------------ */

function renderInquiryDetailCard(targetEl, basic, fullResp) {
  if (!targetEl) return;

  const full = fullResp?.data || {};
  const d = full || {};

  const id = safe(fullResp?.id || basic?.id || "");
  const unit = safe(full.unit || basic?.unit || CURRENT_UNIT || "—");

  const rawFrom = full.from || basic?.from;
  const rawTo   = full.to   || basic?.to;
  const from = safe(rawFrom);
  const to   = safe(rawTo);

  const nights =
    full.nights ??
    basic?.nights ??
    isoNights(rawFrom, rawTo);

  const guestName  = safe(d?.guest?.name);
  const guestEmail = safe(d?.guest?.email);
  const guestPhone = safe(d?.guest?.phone);

  // NOVO: sporočilo gosta – zapisano je v guest.note
  const guestNote = safe(d?.guest?.note || d?.guest?.message);

  // "Ustvarjeno" – poskusi created_fmt, potem created ...
  const createdRaw =
    full.created_fmt ||
    full.created ||
    fullResp?.data?.created_at ||
    basic?.created_fmt ||
    basic?.created_at ||
    basic?.created ||
    "";
  const created = safe(createdRaw);

  const stage = safe(fullResp?.stage || full.stage || basic?.stage || "pending");

  // Znesek – podpiramo pricing.final_total, calc.final, pricing.total, total
  let total = null;
  if (d?.pricing && d.pricing.final_total != null) {
    total = d.pricing.final_total;
  } else if (d?.calc && d.calc.final != null) {
    total = d.calc.final;
  } else if (d?.pricing && d.pricing.total != null) {
    total = d.pricing.total;
  } else if (d?.total != null) {
    total = d.total;
  }
  const priceTotal = total;

  const isPending = !stage || stage === "pending";
  const rawJson = fullResp ? JSON.stringify(fullResp.data || fullResp, null, 2) : "";

  targetEl.dataset.id = id;

  targetEl.innerHTML = `
    <div class="inq-card">
      <div class="inq-card-row">
        <span>ID:</span><b>${id}</b>
      </div>
      <div class="inq-card-row"><span>Enota:</span><b>${unit}</b></div>
      <div class="inq-card-row">
        <span>Termin:</span>
        <b>${from} → ${to} (${nights || "?"} noči)</b>
      </div>
      <div class="inq-card-row"><span>Gost:</span><b>${guestName}</b></div>
      <div class="inq-card-row"><span>Email:</span><b>${guestEmail}</b></div>
      <div class="inq-card-row"><span>Telefon:</span><b>${guestPhone}</b></div>

      <!-- NOVO: sporočilo gosta -->
      <div class="inq-card-row">
        <span>Sporočilo:</span>
        <div style="white-space:pre-wrap; max-width:260px;">
          ${guestNote}
        </div>
      </div>

      <div class="inq-card-row"><span>Stage:</span><b>${stage}</b></div>
      <div class="inq-card-row"><span>Ustvarjeno:</span><b>${created}</b></div>
      <div class="inq-card-row"><span>Znesek:</span><b>${formatMoney(priceTotal)}</b></div>

      <div class="inq-card-actions">
        <button type="button" id="btnMark" class="btn-sm">
          Mark
        </button>
        <button type="button" id="btnConfirm" class="btn-sm btn-primary" ${isPending ? "" : "disabled"}>
          Confirm
        </button>
        <button type="button" id="btnReject" class="btn-sm btn-danger" ${isPending ? "" : "disabled"}>
          Reject
        </button>
        <button type="button" id="btnToggleJson" class="btn-sm">
          Surovi JSON
        </button>
      </div>

      <pre id="inqRawJson" class="inq-json" style="display:none;"></pre>
    </div>
  `;

  const root = targetEl.querySelector(".inq-card");
  if (!root) return;

  // Fill raw JSON
  const jsonEl = root.querySelector("#inqRawJson");
  if (jsonEl) {
    jsonEl.textContent = rawJson || "// Ni podatkov.";
  }

  // Wire "Surovi JSON" toggle
  const btnToggleJson = root.querySelector("#btnToggleJson");
  if (btnToggleJson && jsonEl) {
    btnToggleJson.addEventListener("click", () => {
      const isHidden = jsonEl.style.display === "none";
      jsonEl.style.display = isHidden ? "block" : "none";
    });
  }

  // Wire Mark / Unmark + obveščanje koledarja + persist v pending_mark_toggle.php
  const btnMark = root.querySelector("#btnMark");
  if (btnMark && id) {
    // inicialno stanje gumba glede na CM_MARKED_SET
    {
      const set = window.CM_MARKED_SET || new Set();
      btnMark.textContent = set.has(id) ? "Unmark" : "Mark";
    }

    btnMark.addEventListener("click", async () => {
      const set = window.CM_MARKED_SET || new Set();
      const wasMarked = set.has(id);
      let nowMarked;

      if (wasMarked) {
        set.delete(id);
        nowMarked = false;
      } else {
        set.add(id);
        nowMarked = true;
      }

      window.CM_MARKED_SET = set;
      refreshMarkedDots();
      btnMark.textContent = nowMarked ? "Unmark" : "Mark";

      const payload = {
        id,
        unit: unit === "—" ? (basic?.unit || "") : unit,
        from: rawFrom,
        to: rawTo,
        marked: nowMarked
      };

      // 1) obvesti koledar za golden frame (lokalno, brez reload)
      if (nowMarked) {
        document.dispatchEvent(
          new CustomEvent("pending:mark_add", { detail: payload })
        );
      } else {
        document.dispatchEvent(
          new CustomEvent("pending:mark_remove", { detail: payload })
        );
      }

      // 2) pošlji na server, da se shrani v marked_pending.json
      try {
        await fetch("/app/admin/api/pending_mark_toggle.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json"
          },
          credentials: "same-origin",
          body: JSON.stringify(payload)
        });
      } catch (err) {
        console.warn("[inquiries_admin] pending_mark_toggle failed", err);
      }
    });
  }

  // Wire Confirm
  const btnConfirm = root.querySelector("#btnConfirm");
  if (btnConfirm && id && isPending) {
    btnConfirm.addEventListener("click", () => {
      void confirmInquiry(id);
    });
  }

  // Wire Reject
  const btnReject = root.querySelector("#btnReject");
  if (btnReject && id && isPending) {
    btnReject.addEventListener("click", () => {
      void rejectInquiry(id);
    });
  }
}

/* ------------------------------------------------------------------ */
/* Actions: Confirm / Reject                                          */
/* ------------------------------------------------------------------ */

async function confirmInquiry(id) {
  if (!id) return;

  const ok = window.confirm(
    `Potrdi povpraševanje ${id} in pošlji gostu link za prevzem rezervacije?`
  );
  if (!ok) return;

  try {
    const res = await fetch("/app/admin/api/accept_inquiry.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id })
    });

    let data = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }

    if (!res.ok || !data || !data.ok) {
      const msg =
        (data && (data.error || data.note)) ||
        `HTTP ${res.status}`;
      alert("Napaka pri potrditvi: " + msg);
      console.error("[inquiries_admin] confirmInquiry failed:", res.status, data);
      return;
    }

    // ─────────────────────────────────────────────
    // dodatno: če je backend auto-rejectal konflikte
    // ─────────────────────────────────────────────
    let msg = "Povpraševanje potrjeno (soft-hold + e-mail za gost).";
    const auto = data.auto_reject;
    if (auto && typeof auto === "object") {
      const n = Number(auto.rejected_count ?? 0);
      if (Number.isFinite(n) && n > 0) {
        msg += `\n\nAvto-zavrnjenih konfliktnih povpraševanj: ${n}.`;
      }
    }

    // ─────────────────────────────────────────────
    // Autopilot – info za admina
    // ─────────────────────────────────────────────
    const ap = data.autopilot;
    if (ap && typeof ap === "object" && ap.attempted) {
      if (ap.success) {
        msg +=
          "\n\nAutopilot: termin je bil prost in rezervacija je " +
          "že dokončno potrjena (hard-lock).";
      } else {
        const reason = ap.reason || "neznan razlog";
        msg +=
          "\n\nAutopilot: poskus, vendar se je ustavil (" +
          reason +
          "). Povpraševanje ostane v stanju 'accepted (soft-hold)'.";
      }
    }

    alert(msg);


    // Remove from mark set + osveži pikice & golden frame
    if (window.CM_MARKED_SET && window.CM_MARKED_SET.delete) {
      window.CM_MARKED_SET.delete(id);
    }
    refreshMarkedDots();
    document.dispatchEvent(
      new CustomEvent("pending:mark_remove", { detail: { id } })
    );

    // (opcijsko) posodobi tudi JSON na serverju (marked:false)
    try {
      await fetch("/app/admin/api/pending_mark_toggle.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ id, marked: false })
      });
    } catch (err2) {
      console.warn("[inquiries_admin] pending_mark_toggle (confirm) failed", err2);
    }

    // Reload list & clear detail card
    await loadInquiries();
    const detailEl = qs("#inqDetail");
    if (detailEl) {
      detailEl.dataset.id = "";
      detailEl.innerHTML = `
        <div class="inq-card">
          <div class="inq-card-row">
            <span>Status:</span>
            <b>Povpraševanje je bilo potrjeno. Osveži seznam za nove podatke.</b>
          </div>
        </div>
      `;
    }
  } catch (err) {
    console.error("[inquiries_admin] confirmInquiry network error:", err);
    alert("Napaka pri potrditvi (network).");
  }
}

async function rejectInquiry(id) {
  if (!id) return;

  const reason = window.prompt(
    "Razlog za zavrnitev (za interno uporabo / kupon):",
    ""
  );
  if (reason === null) {
    // user cancelled
    return;
  }

  const ok = window.confirm(
    `Zavrni povpraševanje ${id} in pošlji gostu e-mail (s kuponom, če je omogočen)?`
  );
  if (!ok) return;

  try {
    const res = await fetch("/app/admin/api/reject_inquiry.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, reason })
    });

    let data = null;
    try {
      data = await res.json();
    } catch {
      data = null;
    }

    if (!res.ok || !data || !data.ok) {
      const msg =
        (data && (data.error || data.note)) ||
        `HTTP ${res.status}`;
      alert("Napaka pri zavrnitvi: " + msg);
      console.error("[inquiries_admin] rejectInquiry failed:", res.status, data);
      return;
    }

    alert("Povpraševanje je zavrnjeno. Gost je prejel sporočilo (in kupon, če je omogočen).");

    // Remove from mark set + osveži pikice & golden frame
    if (window.CM_MARKED_SET && window.CM_MARKED_SET.delete) {
      window.CM_MARKED_SET.delete(id);
    }
    refreshMarkedDots();
    document.dispatchEvent(
      new CustomEvent("pending:mark_remove", { detail: { id } })
    );

    // (opcijsko) posodobi tudi JSON na serverju (marked:false)
    try {
      await fetch("/app/admin/api/pending_mark_toggle.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ id, marked: false })
      });
    } catch (err2) {
      console.warn("[inquiries_admin] pending_mark_toggle (reject) failed", err2);
    }

    // Reload list & clear detail card
    await loadInquiries();
    const detailEl = qs("#inqDetail");
    if (detailEl) {
      detailEl.dataset.id = "";
      detailEl.innerHTML = `
        <div class="inq-card">
          <div class="inq-card-row">
            <span>Status:</span>
            <b>Povpraševanje je bilo zavrnjeno. Osveži seznam za nove podatke.</b>
          </div>
        </div>
      `;
    }
  } catch (err) {
    console.error("[inquiries_admin] rejectInquiry network error:", err);
    alert("Napaka pri zavrnitvi (network).");
  }
}


/* ------------------------------------------------------------------ */
/* Marked dots helper                                                 */
/* ------------------------------------------------------------------ */

/**
 * Update "marked" dots in the left list based on CM_MARKED_SET.
 */
function refreshMarkedDots() {
  const set = window.CM_MARKED_SET || new Set();
  qsa("#inqList .inq-row").forEach((row) => {
    const id = row.dataset.id;
    const isMarked = id && set.has(id);
    row.classList.toggle("is-marked", isMarked);
   window.dispatchEvent(new Event("cm-marked-changed"));
  });
}
