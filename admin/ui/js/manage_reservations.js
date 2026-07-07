/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/manage_reservations.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/manage_reservations.js
/* eslint-disable no-alert */
/**
 * "Manage reservations" tab / page.
 *
 * - Seznam rezervacij po enoti / letu / statusu / izvoru.
 * - Badge-i za confirmed / cancelled / soft-hold / ics / direct.
 * - Akcije: cancel + re-send accept.
 */

(function () {
  // Determine base path (/app vs /app_pro vs root) from current admin URL
  const CM_BASE_PATH = (() => {
    try {
      const path = window.location?.pathname || "";
      // npr. "/app_pro/admin/manage_reservations.php" → [1] "/app_pro"
      const m = path.match(/^(.*)\/admin\/[^/]*$/);
      if (m && typeof m[1] === "string") {
        return m[1] === "/" ? "" : m[1];
      }
    } catch (_) {
      // ignore, fallback spodaj
    }
    return "";
  })();

  const API_BASE = CM_BASE_PATH + "/admin/api";
  const LAST_ITEMS_BY_ID = {};

  // --------------------------
  // Basic helpers
  // --------------------------

  function qs(sel, root = document) {
    return root.querySelector(sel);
  }

  function qsa(sel, root = document) {
    return Array.from(root.querySelectorAll(sel));
  }

  async function getJSON(url) {
    const res = await fetch(url, { credentials: "same-origin" });
    if (!res.ok) throw new Error("HTTP " + res.status + " → " + url);
    return res.json();
  }

  async function postJSON(url, body) {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(body)
    });
    if (!res.ok) throw new Error("HTTP " + res.status + " → " + url);
    return res.json();
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  /**
   * Extract SMS code (HHMMSS) from reservation ID.
   *
   * ID format is typically: YYYYMMDDHHMMSS-xxxx-UNIT
   * We take the first segment before "-" and return the last 6 digits.
   * Fallback: if format is weird, use last 6 digits from the whole ID.
   */
  function getSmsCodeFromId(id) {
    if (!id) return "";

    const str = String(id);

    // First segment is usually the timestamp part: YYYYMMDDHHMMSS
    const firstPart = (str.split("-")[0] || "").replace(/\D/g, "");

    if (firstPart.length >= 6) {
      return firstPart.slice(-6); // HHMMSS
    }

    // Fallback – use all digits from whole ID
    const allDigits = str.replace(/\D/g, "");
    if (allDigits.length >= 6) {
      return allDigits.slice(-6);
    }

    return "";
  }

  // --------------------------
  // UI helpers
  // --------------------------

  // --------------------------
  // Modal helpers (self-contained, no external deps)
  // --------------------------

  let MR_MODAL_CSS_READY = false;
  function ensureMrModalCss() {
    if (MR_MODAL_CSS_READY) return;
    MR_MODAL_CSS_READY = true;

    const css = `
.mr-modal-overlay{
  position:fixed; inset:0;
 background:rgba(0,0,0,.62);
  display:flex; align-items:center; justify-content:center;
  padding:18px;
  z-index:9999;
}
.mr-modal{
  width:min(720px, 96vw);
  border-radius:16px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(10,26,51,.98);
  box-shadow:0 18px 60px rgba(0,0,0,.55);
  overflow:hidden;
}
.mr-modal-header{
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:12px;
  padding:14px 16px;
  border-bottom:1px solid rgba(255,255,255,.10);
}
.mr-modal-title{
  font-weight:800;
  font-size:16px;
}
.mr-modal-sub{
  margin-top:3px;
  font-size:12px;
  opacity:.85;
}
.mr-modal-close{
  border:1px solid rgba(255,255,255,.12);
  background:rgba(11,33,66,.55);
  color:#eaf2ff;
  width:34px; height:34px;
  border-radius:10px;
  cursor:pointer;
}
.mr-modal-close:hover{ background:rgba(11,33,66,.85); }
.mr-modal-body{
  padding:14px 16px 6px;
}
.mr-modal-grid{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:12px;
}
@media (max-width:720px){
  .mr-modal-grid{ grid-template-columns:1fr; }
}
.mr-field label{
  display:block;
  font-size:12px;
  opacity:.85;
  margin:0 0 6px;
}
.mr-field input, .mr-field select{
  width:100%;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(7,18,37,.55);
  color:#eaf2ff;
  padding:10px 10px;
  outline:none;
  font-size:14px;
}
.mr-field input:focus, .mr-field select:focus{
  border-color:rgba(87,166,255,.35);
}
.mr-modal-note{
  margin-top:10px;
  font-size:12px;
  opacity:.82;
  line-height:1.35;
}
.mr-modal-error{
  margin-top:10px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(255,90,90,.25);
  background:rgba(255,90,90,.10);
  font-size:13px;
  display:none;
}
.mr-modal-actions{
  display:flex;
  gap:10px;
  justify-content:flex-end;
  padding:12px 16px 16px;
}
.mr-btn-muted{
  border:1px solid rgba(255,255,255,.12);
  background:rgba(11,33,66,.35);
  color:#eaf2ff;
  padding:9px 12px;
  border-radius:999px;
  cursor:pointer;
  font-weight:700;
}
.mr-btn-muted:hover{ background:rgba(11,33,66,.60); }
`;

    const style = document.createElement("style");
    style.setAttribute("data-mr-modal", "1");
    style.textContent = css;
    document.head.appendChild(style);
  }

  function isIsoDate(d) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(d || "").trim());
  }

  function setModalError(el, msg) {
    if (!el) return;
    if (!msg) {
      el.style.display = "none";
      el.textContent = "";
      return;
    }
    el.style.display = "block";
    el.textContent = msg;
  }

  function getUnitOptionsFromFilter(root) {
    const unitSel = qs("#mr-filter-unit", root || document);
    if (!unitSel) return [];
    return Array.from(unitSel.querySelectorAll("option"))
      .map((o) => ({ value: String(o.value || ""), label: String(o.textContent || "") }))
      .filter((o) => o.value.trim() !== ""); // exclude "All units"
  }

  function buildSelect(options, value) {
    const sel = document.createElement("select");
    options.forEach((opt) => {
      const o = document.createElement("option");
      o.value = opt.value;
      o.textContent = opt.label || opt.value;
      sel.appendChild(o);
    });
    if (value) sel.value = value;
    return sel;
  }


  async function loadMrUnits() {
    try {
      const manifest = await getJSON(
        CM_BASE_PATH + "/common/data/json/units/manifest.json"
      );
      const units = manifest.units || [];
      const select = qs("#mr-filter-unit");
      if (!select) return units;

      // Najprej "All units", potem posamezne enote
      const options = ['<option value="">All units</option>'].concat(
        units.map(function (u) {
          var label = u.id;
          if (u.label) {
            label += " – " + u.label;
          }
          return (
            '<option value="' +
            escapeHtml(u.id) +
            '">' +
            escapeHtml(label) +
            "</option>"
          );
        })
      );

      select.innerHTML = options.join("");
      // privzeto: All units (prazna vrednost)
      select.value = "";

      return units;
    } catch (e) {
      console.error("[manage_reservations] loadMrUnits failed:", e);
      return [];
    }
  }

  function buildStatusChip(r) {
    const s = r.status || "unknown";
    const src = r.source || "";
    const hard = r.lock === "hard";
    const soft = r.lock === "soft" || r.soft === true;

    let cls = "mr-badge";
    let label = s;

    if (hard && s === "confirmed") {
      cls += " hard";
      label = "Confirmed";
    } else if (s.indexOf("cancelled") === 0) {
      // Covers every cancellation variant (cancelled, cancelled_unpaid,
      // cancelled_admin, cancelled_guest, ...) - previously only the exact
      // string "cancelled" got styled, everything else silently fell
      // through to the unstyled base badge (white-on-white in dark mode).
      cls += " cancelled";
      const reason = s === "cancelled" ? "" : s.slice("cancelled".length).replace(/^_/, "");
      label = reason ? "Cancelled (" + reason + ")" : "Cancelled";
    } else if (soft) {
      cls += " soft";
      label = "Soft-hold";
    } else if (src === "ics") {
      cls += " ics";
      label = "ICS";
    } else if (src === "direct") {
      cls += " direct";
      label = "Direct";
    }

    return '<span class="' + cls + '">' + escapeHtml(label) + "</span>";
  }

  function buildRow(r) {
    const id = r.id || r.res_id || "";
    const unit = r.unit || "";
    const src = r.source || "";
    const from = r.from || r.date_from || "";
    const to = r.to || r.date_to || "";
    const nights =
      r.nights != null
        ? r.nights
        : (r.meta && r.meta.nights) != null
        ? r.meta.nights
        : "";

    // guest je lahko string ali objekt
    let guest = "";
    if (typeof r.guest === "string") {
      guest = r.guest;
    } else if (r.guest && typeof r.guest === "object") {
      guest = r.guest.name || r.guest.full_name || "";
    } else {
      guest = r.guest_name || "";
    }

    const email =
      (r.guest && typeof r.guest === "object" && r.guest.email) ||
      r.email ||
      r.guest_email ||
      "";

    const badge = buildStatusChip(r);
    const externalBadge =
      src === "external"
        ? ' <span class="mr-badge external">External</span>'
        : "";

    // --- NEW: decide when to show "Re-send accept" ---
    const status = (r.status || "").toLowerCase();
    const lock = (r.lock || "").toLowerCase();
    const soft = lock === "soft" || r.soft === true;

    // Show re-send only for accepted soft-hold inquiries that still have an email
    const canResendAccept = soft && status === "accepted" && !!email;

    return (
      '<article class="mr-card" data-id="' + escapeHtml(id) + '">' +
      '  <header class="mr-header">' +
      '    <div class="mr-title">' +
      '      <span class="mr-id">' +
      escapeHtml(id) +
      "</span>" +
      "      " +
      badge +
      externalBadge +
      "    </div>" +
      '    <div class="mr-unit">' +
      escapeHtml(unit) +
      "</div>" +
      "  </header>" +
      '  <div class="mr-body">' +
      '    <div class="mr-dates">' +
      '      <span class="mr-from">' +
      escapeHtml(from) +
      "</span>" +
      "      &rarr; " +
      '      <span class="mr-to">' +
      escapeHtml(to) +
      "</span>" +
      (nights !== ""
        ? '      <span class="mr-nights">(' +
          escapeHtml(String(nights)) +
          " nights)</span>"
        : "") +
      "    </div>" +
      '    <div class="mr-guest">' +
      '      <span class="mr-guest-name">' +
      escapeHtml(guest) +
      "</span>" +
      (email
        ? '      <span class="mr-guest-email">&lt;' +
          escapeHtml(email) +
          "&gt;</span>"
        : "") +
      "    </div>" +
      "  </div>" +
      '  <footer class="mr-actions">' +
      '    <button class="mr-btn danger mr-btn-cancel" data-id="' +
      escapeHtml(id) +
      '">Cancel</button>' +
      (canResendAccept
        ? '    <button class="mr-btn mr-btn-resend" data-id="' +
          escapeHtml(id) +
          '">Re-send accept</button>'
        : "") +
      "  </footer>" +
      "</article>"
    );
  }

  function renderEmptyDetail(root) {
    const panel = qs("#mr-detail", root || document);
    if (!panel) return;
    panel.innerHTML =
      '<div class="mr-detail-card">' +
      '  <div class="mr-detail-row">' +
      '    <span class="mr-detail-label">Reservation:</span>' +
      "    <b>Izberi rezervacijo na levi.</b>" +
      "  </div>" +
      "</div>";
  }

  function renderDetail(item, root) {
    const panel = qs("#mr-detail", root || document);
    if (!panel) return;

    if (!item || typeof item !== "object") {
      renderEmptyDetail(root);
      return;
    }

    const id = item.id || item.res_id || "";
    const unit = item.unit || "";
    const src = item.source || "";
    const status = item.status || "";
    const lock = item.lock || (item.soft ? "soft" : "");
    const from = item.from || item.date_from || "";
    const to = item.to || item.date_to || "";

    // Normalize status/lock for SMS code logic
    const statusNorm = String(status).toLowerCase();
    const lockNorm = String(lock).toLowerCase();

    // Soft-hold = soft lock OR explicit soft_hold status
    const isSoftHold =
      lockNorm === "soft" ||
      item.soft === true ||
      statusNorm === "soft_hold";

    // Final statuses where we do NOT want to show SMS code
    const isFinalStatus =
      statusNorm === "confirmed" || statusNorm === "cancelled";

    // Show SMS code only for soft-hold reservations until guest confirms
    const smsCode =
      isSoftHold && !isFinalStatus ? getSmsCodeFromId(id) : "";

    const nights =
      item.nights != null
        ? item.nights
        : (item.meta && item.meta.nights != null)
        ? item.meta.nights
        : "";

    let guest = "";
    if (typeof item.guest === "string") {
      guest = item.guest;
    } else if (item.guest && typeof item.guest === "object") {
      guest = item.guest.name || item.guest.full_name || "";
    } else {
      guest = item.guest_name || "";
    }

    const email =
      (item.guest && typeof item.guest === "object" && item.guest.email) ||
      item.email ||
      item.guest_email ||
      "";

    const phone =
      (item.guest && typeof item.guest === "object" && item.guest.phone) ||
      item.phone ||
      item.guest_phone ||
      "";

    // Review link only makes sense for confirmed reservations with an e-mail
    const canSendReview = statusNorm === "confirmed" && !!email;

    const message =
      // 1) najprej note na guest objektu – TO imaš v JSON
      (item.guest &&
        typeof item.guest === "object" &&
        (item.guest.note || item.guest.message)) ||
      // 2) top-level message, če kdaj obstaja
      (typeof item.message === "string" && item.message) ||
      // 3) različne stare oblike
      item.guest_message ||
      (item.meta && (item.meta.guest_message || item.meta.note)) ||
      "";

    const created =
      item.created_at ||
      (item.meta && item.meta.created_at) ||
      item.created ||
      "";
    const editUrl =
      id
        ? (CM_BASE_PATH + "/admin/edit_reservation.php?id=" + encodeURIComponent(id))
        : "";

    let total = null;
    if (item.total_eur != null) {
      total = item.total_eur;
    } else if (item.meta && item.meta.total_eur != null) {
      total = item.meta.total_eur;
    }
    const currency =
      item.currency || (item.meta && item.meta.currency) || "€";

    const badgeHtml = buildStatusChip(item);
    const lockLabel =
      lock === "hard"
        ? "hard"
        : lock === "soft"
        ? "soft-hold"
        : "-";

    let amountHtml = "";
    if (total != null && total !== "") {
      amountHtml =
        '  <div class="mr-detail-row">' +
        '    <span class="mr-detail-label">Znesek:</span>' +
        "    <span>" +
        escapeHtml(String(total)) +
        " " +
        escapeHtml(currency) +
        "</span>" +
        "  </div>";
    }

    panel.innerHTML =
      '<div class="mr-detail-card">' +
      '  <header class="mr-detail-header">' +
      '    <div class="mr-detail-main">' +
      '      <div class="mr-detail-id">' +
      escapeHtml(id) +
      "</div>" +
      '      <div class="mr-detail-badge">' +
      badgeHtml +
      "</div>" +
      "    </div>" +
      '    <div class="mr-detail-unit">' +
      escapeHtml(unit) +
      "</div>" +
      "  </header>" +
      '  <div class="mr-detail-body">' +
      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Termin:</span>' +
      "      <span>" +
      escapeHtml(from) +
      " → " +
      escapeHtml(to) +
      (nights !== ""
        ? " (" + escapeHtml(String(nights)) + " nights)"
        : "") +
      "      </span>" +
      "    </div>" +
      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Gost:</span>' +
      "      <span>" +
      (guest ? escapeHtml(guest) : "—") +
      "</span>" +
      "    </div>" +
      (email
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">E-mail:</span>' +
          "    <span>" +
          escapeHtml(email) +
          "</span>" +
          "  </div>"
        : "") +
      (phone
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">Telefon:</span>' +
          "    <span>" +
          escapeHtml(phone) +
          "</span>" +
          "  </div>"
        : "") +
      (smsCode
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">SMS koda:</span>' +
          "    <span><code>" +
          escapeHtml(smsCode) +
          "</code></span>" +
          "  </div>"
        : "") +
      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Vir:</span>' +
      "      <span>" +
      (src ? escapeHtml(src) : "—") +
      "</span>" +
      "    </div>" +
      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Status:</span>' +
      "      <span>" +
      (status ? escapeHtml(status) : "—") +
      (lockLabel !== "-"
        ? " · lock: " + escapeHtml(lockLabel)
        : "") +
      "</span>" +
      "    </div>" +
      (created
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">Ustvarjeno:</span>' +
          "    <span>" +
          escapeHtml(created) +
          "</span>" +
          "  </div>"
        : "") +
      (message
        ? '  <div class="mr-detail-row mr-detail-row-message">' +
          '    <span class="mr-detail-label">Sporočilo:</span>' +
          "    <span>" +
          escapeHtml(message) +
          "</span>" +
          "  </div>"
        : "") +
      amountHtml +
      "  </div>" +
      // Actions row – TT & KEYCARD check-in + review link
      '  <div class="mr-detail-actions" style="margin-top:5px;">' +
      '    <button type="button" class="mr-btn" id="mr-btn-checkin-tt">' +
      "      TT &amp; račun (check-in)" +
      "    </button>" +
      '    <button type="button" class="mr-btn" id="mr-btn-edit-reservation">' +
      "      Edit reservation" +
      "    </button>" +
      (canSendReview
        ? '    <button type="button" class="mr-btn" id="mr-btn-review-link">' +
          "      Povezava za oceno" +
          "    </button>"
        : "") +
      "  </div>" +
      '  <div class="mr-detail-raw">' +
      '    <button type="button" class="mr-btn mr-btn-raw" id="mr-toggle-raw">Pokaži surovi JSON</button>' +
      '    <pre id="mr-raw" class="mr-raw" hidden>' +
      escapeHtml(JSON.stringify(item, null, 2)) +
      "    </pre>" +
      "  </div>" +
      "</div>";

    const toggle = panel.querySelector("#mr-toggle-raw");
    const pre = panel.querySelector("#mr-raw");
    if (toggle && pre) {
      toggle.addEventListener("click", function () {
        const isHidden = pre.hasAttribute("hidden");
        if (isHidden) {
          pre.removeAttribute("hidden");
          toggle.textContent = "Skrij surovi JSON";
        } else {
          pre.setAttribute("hidden", "hidden");
          toggle.textContent = "Pokaži surovi JSON";
        }
      });
    }

    // Check-in TT & KEYCARD slip – open in new tab
    const checkinBtn = panel.querySelector("#mr-btn-checkin-tt");
    if (checkinBtn && id) {
      checkinBtn.addEventListener("click", function () {
        const url =
          CM_BASE_PATH +
          "/admin/checkin_tt.php?id=" +
          encodeURIComponent(id);
        window.open(url, "_blank");
      });
    }
    // Edit reservations button
    const editBtn = panel.querySelector("#mr-btn-edit-reservation");
    if (editBtn && editUrl) {
      editBtn.addEventListener("click", function () {
        window.open(editUrl, "_blank");
      });
    }
    // Send review link e-mail (only if button exists)
    const reviewBtn = panel.querySelector("#mr-btn-review-link");
    if (reviewBtn && id) {
      reviewBtn.addEventListener("click", function () {
        handleSendReview(item);
      });
    }
  } // ← renderDetail

  // Visually select a reservation card by ID and render its details in the
  // right-hand panel - shared by manual clicks and the ?id= calendar deep-link.
  function selectCardById(id, root) {
    if (!id) return;

    const list = qs("#mr-list", root);
    if (!list) return;

    const card = list.querySelector(
      '.mr-card[data-id="' + String(id).replace(/"/g, "") + '"]'
    );
    if (!card) return;

    qsa(".mr-card.selected", list).forEach(function (c) {
      c.classList.remove("selected");
    });
    card.classList.add("selected");
    card.scrollIntoView({ block: "nearest" });

    const item = LAST_ITEMS_BY_ID[id] || null;
    renderDetail(item, root);
  }

  async function loadReservations(root) {
    const unitSel = qs("#mr-filter-unit", root);
    const ym = qs("#mr-filter-ym", root);
    const yearSel = qs("#mr-filter-year", root);
    const statusSel = qs("#mr-filter-status", root);
    const sourceSel = qs("#mr-filter-source", root);
    const softCheckbox = qs("#mr-filter-soft", root);
    const blocksCheckbox = qs("#mr-filter-blocks", root);
    const qInput = qs("#mr-filter-q", root);
    const info = qs("#mr-info", root);
    const list = qs("#mr-list", root);

    const params = {};
    if (unitSel && unitSel.value) params.unit = unitSel.value;
    if (ym && ym.value) params.ym = ym.value;
    if (yearSel && yearSel.value) params.year = yearSel.value;
    if (statusSel && statusSel.value) params.status = statusSel.value;
    if (sourceSel && sourceSel.value) params.source = sourceSel.value;
    if (softCheckbox && softCheckbox.checked)
      params.include_soft_hold = "1";
    if (blocksCheckbox && blocksCheckbox.checked)
      params.include_blocks = "1";
    if (qInput && qInput.value) params.q = qInput.value.trim();

    // Info vrstica – kaj je trenutno prikazano
    if (info) {
      const hasYm = !!(ym && ym.value);
      const hasYear = !!(yearSel && yearSel.value);
      let text = "";

      if (!hasYm && !hasYear) {
        text =
          "Prikazane so rezervacije v teku in prihodnje (od danes naprej).";
      } else if (hasYear && !hasYm) {
        text =
          "Prikazane so rezervacije, ki prečkajo leto " +
          yearSel.value +
          ".";
      } else if (hasYm) {
        text =
          "Prikazane so rezervacije, ki prečkajo mesec " +
          ym.value +
          ".";
      }

      info.textContent = text;
    }

    list.innerHTML = '<div class="mr-loading">Loading…</div>';

    // vedno resetiraj detail panel ob reloadu
    renderEmptyDetail(root);

    try {
      const query = new URLSearchParams(params).toString();
      const data = await getJSON(
        API_BASE + "/list_reservations.php?" + query
      );
      const rows = Array.isArray(data) ? data : data.items || [];

      if (!rows.length) {
        list.innerHTML =
          '<p class="mr-empty">No reservations found.</p>';
        return;
      }

      // resetiraj mapo in jo ponovno napolni
      Object.keys(LAST_ITEMS_BY_ID).forEach(function (k) {
        delete LAST_ITEMS_BY_ID[k];
      });

      const html = rows
        .map(function (r) {
          const id = r.id || r.res_id || "";
          if (id) {
            LAST_ITEMS_BY_ID[id] = r;
          }
          return buildRow(r);
        })
        .join("");

      list.innerHTML = html;

      // akcije na gumbih
      qsa(".mr-btn-cancel", list).forEach(function (btn) {
        btn.addEventListener("click", function () {
          handleCancel(btn.getAttribute("data-id"), root);
        });
      });

      qsa(".mr-btn-resend", list).forEach(function (btn) {
        btn.addEventListener("click", function () {
          handleResend(btn.getAttribute("data-id"));
        });
      });

      // klik na kartico → označi in prikaži detajle desno
      qsa(".mr-card", list).forEach(function (card) {
        card.addEventListener("click", function (ev) {
          // ignoriraj klike na gumbe znotraj kartice
          if (ev.target.closest("button")) return;
          selectCardById(card.getAttribute("data-id"), root);
        });
      });

      // Deep-link iz koledarja (?id=<reservationId>) → samodejno označi in
      // prikaži isto kartico, kot bi jo admin izbral ročno. Tiho ne naredi
      // nič, če ID ni v trenutno prikazanem (filtriranem) seznamu.
      const deepLinkId = new URLSearchParams(window.location.search).get(
        "id"
      );
      if (deepLinkId) {
        selectCardById(deepLinkId, root);
      }
    } catch (e) {
      console.error(
        "[manage_reservations] loadReservations failed:",
        e
      );
      list.innerHTML =
        '<p class="mr-error">Failed to load reservations.</p>';
    }
  }

  async function handleCancel(id, root) {
    if (!id) return;

    const item = LAST_ITEMS_BY_ID[id] || null;
    const src = item && item.source ? String(item.source) : "";
    const lock = item && item.lock ? String(item.lock) : "";
    const unit = (item && item.unit) || "";
    const from = (item && item.from) || "";
    const to = (item && item.to) || "";

    // 1) ICS rezervacije → samo info
    if (src === "ics") {
      alert(
        "Rezervacije iz kanalov (ICS – npr. Booking/Airbnb) lahko urejaš samo na izvoru."
      );
      return;
    }

    // 2) Lokalni admin blok (local_block) → brez posebnih trikov
    if (src === "local_block") {
      const ok = confirm(
        `Odstranim lokalni admin blok za ${unit} (${from} → ${to})?`
      );
      if (!ok) return;

      try {
        await postJSON(API_BASE + "/local_block_remove.php", {
          unit: unit,
          from: from,
          to: to
        });
        await loadReservations(root);
      } catch (e) {
        console.error(
          "[manage_reservations] local_block_remove failed:",
          e
        );
        alert("Failed to remove local block.");
      }
      return;
    }

    // 3) Soft-hold / soft lock → navaden confirm
    if (lock !== "hard") {
      const ok = confirm(
        `Prekličem soft-hold / pending rezervacijo ${id}?`
      );
      if (!ok) return;

      try {
        await postJSON(API_BASE + "/cancel_reservation.php", { id: id });
        await loadReservations(root);
      } catch (e) {
        console.error(
          "[manage_reservations] cancel_reservation failed:",
          e
        );
        alert("Failed to cancel reservation.");
      }
      return;
    }

    // 4) Hard rezervacija → zahteva vnos ID-ja
    const entered = prompt(
      "Za potrditev brisanja vpiši ID rezervacije:\n\n" + id
    );
    if (entered !== id) {
      alert("ID se ne ujema. Brisanje je preklicano.");
      return;
    }

    try {
      await postJSON(API_BASE + "/cancel_reservation.php", { id: id });
      await loadReservations(root);
    } catch (e) {
      console.error(
        "[manage_reservations] cancel_reservation failed:",
        e
      );
      alert("Failed to cancel reservation.");
    }
  }

  async function handleSendReview(item) {
    if (!item || typeof item !== "object") return;

    const id = item.id || item.res_id || "";
    if (!id) return;

    const guest =
      item.guest && typeof item.guest === "object" ? item.guest : {};

    const rawEmail =
      (guest && guest.email) ||
      item.email ||
      item.guest_email ||
      "";

    const labelEmail = rawEmail || "(neznan naslov)";

    const ok = confirm(
      "Pošljem gostu povezavo za oceno bivanja" +
        (rawEmail ? " na " + labelEmail : "") +
        "?"
    );
    if (!ok) return;

    try {
      const reply = await postJSON(
        API_BASE + "/send_review_request.php",
        { id: id }
      );
      if (!reply || reply.ok === false) {
        const msg =
          (reply && (reply.error || reply.message)) || "send_failed";
        console.error(
          "[manage_reservations] send_review_request failed:",
          reply
        );
        alert(
          "❌ Napaka pri pošiljanju povezave za oceno: " + msg
        );
        return;
      }

      const finalEmail = reply.email || labelEmail;
      alert(
        "✅ Povezava za oceno je bila poslana na: " + finalEmail
      );
    } catch (e) {
      console.error(
        "[manage_reservations] handleSendReview error:",
        e
      );

      const emsg = String(e && e.message ? e.message : e);

      if (emsg.includes("HTTP 403")) {
        alert(
          "ℹ️  This feature is available only in PRO version"
        );
        return;
      }

      alert("❌ Unexpected error sending review link.");
    }
  }

  // --------------------------
  // Create external reservation (manual guest)
  // --------------------------
  async function openExternalReservationDialog(root) {
    root = root || document;

    const unitSel = qs("#mr-filter-unit", root);
    const defaultUnit = unitSel && unitSel.value ? unitSel.value : "";
    ensureMrModalCss();

    // Build overlay
    const overlay = document.createElement("div");
    overlay.className = "mr-modal-overlay";

    const modal = document.createElement("div");
    modal.className = "mr-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");

    const header = document.createElement("div");
    header.className = "mr-modal-header";
    const hLeft = document.createElement("div");
    const title = document.createElement("div");
    title.className = "mr-modal-title";
    title.textContent = "Add external reservation";
    const sub = document.createElement("div");
    sub.className = "mr-modal-sub";
    sub.textContent = "Modal ostane odprt tudi, če preklopiš v drug zavihek (kopiranje podatkov).";
    hLeft.appendChild(title);
    hLeft.appendChild(sub);

    const btnClose = document.createElement("button");
    btnClose.className = "mr-modal-close";
    btnClose.type = "button";
    btnClose.textContent = "✕";

    header.appendChild(hLeft);
    header.appendChild(btnClose);

    const body = document.createElement("div");
    body.className = "mr-modal-body";

    const grid = document.createElement("div");
    grid.className = "mr-modal-grid";

    // Fields
    const fUnit = document.createElement("div");
    fUnit.className = "mr-field";
    const lUnit = document.createElement("label");
    lUnit.textContent = "Unit";
    fUnit.appendChild(lUnit);

    const unitOptions = getUnitOptionsFromFilter(root);
    let unitInput;
    if (unitOptions.length) {
      unitInput = buildSelect(unitOptions, defaultUnit || (unitOptions[0] && unitOptions[0].value) || "");
    } else {
      unitInput = document.createElement("input");
      unitInput.type = "text";
      unitInput.placeholder = "npr. A1 / T1";
      unitInput.value = defaultUnit || "";
    }
    fUnit.appendChild(unitInput);

    const fName = document.createElement("div");
    fName.className = "mr-field";
    const lName = document.createElement("label");
    lName.textContent = "Guest name";
    const iName = document.createElement("input");
    iName.type = "text";
    iName.autocomplete = "off";
    iName.placeholder = "Ime in priimek";
    fName.appendChild(lName);
    fName.appendChild(iName);

    const fEmail = document.createElement("div");
    fEmail.className = "mr-field";
    const lEmail = document.createElement("label");
    lEmail.textContent = "Guest e-mail";
    const iEmail = document.createElement("input");
    iEmail.type = "email";
    iEmail.autocomplete = "off";
    iEmail.placeholder = "email@domain.com";
    fEmail.appendChild(lEmail);
    fEmail.appendChild(iEmail);

    const fPhone = document.createElement("div");
    fPhone.className = "mr-field";
    const lPhone = document.createElement("label");
    lPhone.textContent = "Phone (optional)";
    const iPhone = document.createElement("input");
    iPhone.type = "tel";
    iPhone.autocomplete = "off";
    iPhone.placeholder = "+38640123456";
    fPhone.appendChild(lPhone);
    fPhone.appendChild(iPhone);

    const fGuests = document.createElement("div");
    fGuests.className = "mr-field";
    const lGuests = document.createElement("label");
    lGuests.textContent = "Guests";
    const iGuests = document.createElement("input");
    iGuests.type = "number";
    iGuests.min = "1";
    iGuests.step = "1";
    iGuests.value = "2";
    iGuests.placeholder = "Number of guests";
    fGuests.appendChild(lGuests);
    fGuests.appendChild(iGuests);

    const fFrom = document.createElement("div");
    fFrom.className = "mr-field";
    const lFrom = document.createElement("label");
    lFrom.textContent = "Arrival date";
    const iFrom = document.createElement("input");
    iFrom.type = "date";
    fFrom.appendChild(lFrom);
    fFrom.appendChild(iFrom);

    const fTo = document.createElement("div");
    fTo.className = "mr-field";
    const lTo = document.createElement("label");
    lTo.textContent = "Departure date";
    const iTo = document.createElement("input");
    iTo.type = "date";
    fTo.appendChild(lTo);
    fTo.appendChild(iTo);

    const fChannel = document.createElement("div");
    fChannel.className = "mr-field";
    const lChannel = document.createElement("label");
    lChannel.textContent = "Channel";
    const iChannel = document.createElement("input");
    iChannel.type = "text";
    iChannel.placeholder = "booking.com / airbnb / phone / walk_in / other";
    iChannel.value = "booking.com";
    fChannel.appendChild(lChannel);
    fChannel.appendChild(iChannel);

    const fTotal = document.createElement("div");
    fTotal.className = "mr-field";
    const lTotal = document.createElement("label");
    lTotal.textContent = "Total amount (EUR) – optional";
    const iTotal = document.createElement("input");
    iTotal.type = "number";
    iTotal.step = "0.01";
    iTotal.min = "0";
    iTotal.placeholder = "npr. 450.00";
    fTotal.appendChild(lTotal);
    fTotal.appendChild(iTotal);

    const fLang = document.createElement("div");
    fLang.className = "mr-field";
    const lLang = document.createElement("label");
    lLang.textContent = "Language";
    const iLang = buildSelect(
      [
        { value: "en", label: "en" },
        { value: "sl", label: "sl" }
      ],
      "en"
    );
    fLang.appendChild(lLang);
    fLang.appendChild(iLang);

    // Layout in grid
    // Layout in grid (2 columns)
    // Left column: Unit → Name → Arrival → Departure → Total
    // Right column: Language → Email → Phone → Channel
    grid.appendChild(fUnit);
    grid.appendChild(fLang);

    grid.appendChild(fName);
    grid.appendChild(fEmail);

    grid.appendChild(fFrom);
    grid.appendChild(fPhone);

    grid.appendChild(fTo);
    grid.appendChild(fGuests);

    grid.appendChild(fChannel);
    grid.appendChild(fTotal);

    const note = document.createElement("div");
    note.className = "mr-modal-note";
    note.textContent = "Namig: polja lahko prosto kopiraš/lepiš. Če želiš prekiniti, klikni ✕ ali ESC.";

    const errBox = document.createElement("div");
    errBox.className = "mr-modal-error";

    body.appendChild(grid);
    body.appendChild(note);
    body.appendChild(errBox);

    const actions = document.createElement("div");
    actions.className = "mr-modal-actions";

    const btnCancel = document.createElement("button");
    btnCancel.type = "button";
    btnCancel.className = "mr-btn-muted";
    btnCancel.textContent = "Cancel";

    const btnCreate = document.createElement("button");
    btnCreate.type = "button";
    // reuse existing styling class if present
    btnCreate.className = "mr-btn";
    btnCreate.textContent = "Create";

    actions.appendChild(btnCancel);
    actions.appendChild(btnCreate);

    modal.appendChild(header);
    modal.appendChild(body);
    modal.appendChild(actions);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    let inFlight = false;
    let isClosed = false;
    function close() {
      if (isClosed) return;
      isClosed = true;
      document.removeEventListener("keydown", onKeyDown);
      overlay.remove();
    }

    function onKeyDown(e) {
      if (e.key === "Escape") close();
    }

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.addEventListener("keydown", onKeyDown);
    btnClose.addEventListener("click", close);
    btnCancel.addEventListener("click", close);

    function readTrim(el) {
      return String((el && el.value) || "").trim();
    }

    async function doCreate() {
      if (inFlight) return;
      setModalError(errBox, "");

      const unit = readTrim(unitInput);
      const guestName = readTrim(iName);
      const guestEmail = readTrim(iEmail);
      const guestPhone = readTrim(iPhone).replace(/\s+/g, "");
      const from = readTrim(iFrom);
      const to = readTrim(iTo);
      const guestsStr = readTrim(iGuests);
      const channel = readTrim(iChannel);
      const lang = readTrim(iLang) || "en";
      const totalStr = readTrim(iTotal);

      if (!unit) return setModalError(errBox, "Unit is required.");
      if (!guestName) return setModalError(errBox, "Guest name is required.");
      if (!guestEmail) return setModalError(errBox, "Guest e-mail is required.");
      if (!isIsoDate(from)) return setModalError(errBox, "Arrival date must be set (YYYY-MM-DD).");
      if (!isIsoDate(to)) return setModalError(errBox, "Departure date must be set (YYYY-MM-DD).");
      if (from >= to) return setModalError(errBox, "Departure date must be after arrival date.");
      const guests = Number(guestsStr);
      if (!Number.isInteger(guests) || guests < 1) {
        return setModalError(errBox, "Guests must be a whole number starting from 1.");
      }


      let total = null;
      if (totalStr !== "") {
        const n = Number(totalStr);
        if (!Number.isFinite(n) || n < 0) {
          return setModalError(errBox, "Total amount must be a non-negative number (or empty).");
        }
        total = n;
      }
 
      const payload = {
        unit: unit,
        guest_name: guestName,
        guest_email: guestEmail,
        from: from,
        to: to,
        adults: guests,
        channel: channel,
        lang: lang
      };
      if (guestPhone !== "") {
        payload.guest_phone = guestPhone;

      }
      if (total !== null) payload.total = total;

      inFlight = true;
      btnCreate.disabled = true;
      btnCreate.textContent = "Saving…";

      try {
        // Safety: never get stuck in "Saving…" forever
        const timeoutMs = 15000;
        const reply = await Promise.race([
          postJSON(API_BASE + "/create_external_reservation.php", payload),
          new Promise((_, rej) => setTimeout(() => rej(new Error("timeout")), timeoutMs))
        ]);
        if (!reply || reply.ok === false) {
          const msg = (reply && (reply.error || reply.message)) || "create_external_failed";
          throw new Error(msg);
        }
        const id = reply.id || "(unknown ID)";
        inFlight = false;
        btnCreate.disabled = false;
        btnCreate.textContent = "Create";

        // Close first (so modal can never remain stuck), then notify
        if (!isClosed) close();
        alert("✅ External reservation created: " + id);
        loadReservations(root);
      } catch (e) {
        console.error("[manage_reservations] create_external error:", e);
        const msg = (e && e.message) ? e.message : String(e);
        if (msg === "timeout") {
          setModalError(errBox, "⏳ Shranjevanje traja predolgo. Rezervacija je morda vseeno nastala – preveri listo (refresh).");
        } else {
          setModalError(errBox, "❌ Napaka pri ustvarjanju external rezervacije: " + msg);
        }
        inFlight = false;
        btnCreate.disabled = false;
        btnCancel.disabled = false;
        btnClose.disabled = false;
        btnCreate.textContent = "Create";
      }
    }

    btnCreate.addEventListener("click", doCreate);

    // UX: Enter submits (except inside date pickers it still works fine)
    modal.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        // prevent accidental submit from select changes
        if (e.target && (e.target.tagName === "INPUT" || e.target.tagName === "SELECT")) {
          e.preventDefault();
          doCreate();
        }
      }
    });

    // Focus first field
    setTimeout(() => {
      try { iName.focus(); } catch(_) {}
    }, 0);
   }
async function handleResend(id) {
  if (!id) return;

  const item = LAST_ITEMS_BY_ID[id] || null;
  const unit = (item && item.unit) ? String(item.unit) : "";

  try {
    await postJSON(API_BASE + "/resend_accept_email.php", { id, unit });
    alert("E-mail sent again.");
  } catch (e) {
    console.error("[manage_reservations] resend_accept failed:", e);
    alert("Failed to send e-mail.");
  }
}

  // --------------------------
  // INIT
  // --------------------------

  function init(root) {
    if (!root) root = document;
    const host = qs("#manage-reservations", root);
    if (!host) return;

    host.innerHTML =
      '<section class="mr-panel">' +
      '  <div class="mr-toolbar">' +
      '    <select id="mr-filter-unit"></select>' +
      '    <input id="mr-filter-ym" type="month" />' +
      '    <select id="mr-filter-year">' +
      '      <option value="">All years</option>' +
      '      <option value="2025">2025</option>' +
      '      <option value="2026">2026</option>' +
      "    </select>" +
      '    <select id="mr-filter-status">' +
      '      <option value="confirmed">Confirmed</option>' +
      '      <option value="cancelled">Cancelled</option>' +
      "    </select>" +
      '    <select id="mr-filter-source">' +
      '      <option value="">All sources</option>' +
      '      <option value="direct">Direct</option>' +
      '      <option value="ics">ICS</option>' +
      '      <option value="local_block">Local block</option>' +
      "    </select>" +
      '    <label class="mr-check">' +
      '      <input id="mr-filter-soft" type="checkbox" />' +
      "      Vključi Soft-hold" +
      "    </label>" +
      '    <label class="mr-check">' +
      '      <input id="mr-filter-blocks" type="checkbox" />' +
      "      Vključi blokade" +
      "    </label>" +
      '    <input id="mr-filter-q" type="search" placeholder="ID / e-mail / ime" />' +
      '    <button class="mr-btn" id="mr-btn-load">Osveži</button>' +
      '    <button class="mr-btn" id="mr-btn-add-external">Add external</button>' +
      "  </div>" +
      '  <div class="mr-layout">' +
      '    <div class="mr-left">' +
      '      <div id="mr-info" class="mr-info"></div>' +
      '      <div id="mr-list" class="mr-list"></div>' +
      "    </div>" +
      '    <aside id="mr-detail" class="mr-detail">' +
      '      <div class="mr-detail-card">' +
      '        <div class="mr-detail-row">' +
      '          <span class="mr-detail-label">Reservation:</span>' +
      "          <b>Izberi rezervacijo na levi.</b>" +
      "        </div>" +
      "      </div>" +
      "    </aside>" +
      "  </div>" +
      "</section>";

    function reload() {
      loadReservations(host);
    }

    const btnLoad = qs("#mr-btn-load", host);
    if (btnLoad) btnLoad.addEventListener("click", reload);
    const btnExternal = qs("#mr-btn-add-external", host);
    if (btnExternal) {
      btnExternal.addEventListener("click", function () {
        openExternalReservationDialog(host);
      });
    }

    [
      "#mr-filter-unit",
      "#mr-filter-ym",
      "#mr-filter-year",
      "#mr-filter-status",
      "#mr-filter-source",
      "#mr-filter-soft",
      "#mr-filter-blocks"
    ].forEach(function (sel) {
      const el = qs(sel, host);
      if (el) el.addEventListener("change", reload);
    });

    const qInput = qs("#mr-filter-q", host);
    if (qInput) {
      qInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") reload();
      });
    }

    // units → load list
    loadMrUnits().then(reload);
  }

  // za stari admin_shell tab (če bi ga še kdaj rabil)
  window.ManageReservations = { init: init };

  // ➕ auto-boot na standalone strani
  document.addEventListener("DOMContentLoaded", function () {
    if (qs("#manage-reservations")) {
      init(document);
    }
  });
})();
