/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/manage_reservations.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

// /app/admin/ui/js/manage_reservations.js

/**
 * "Manage reservations" tab / page.
 *
 * - Seznam rezervacij po enoti / letu / statusu / izvoru.
 * - Badge-i za confirmed / cancelled / soft-hold / ics / direct.
 * - Akcije: cancel + re-send accept.
 */

(function () {
  const API_BASE = "/app/admin/api";
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

  // Zadnje naloženi itemi, indeksirani po ID → za Cancel logiko


  // --------------------------
  // UI helpers
  // --------------------------

   async function loadMrUnits() {
    try {
      const manifest = await getJSON("/app/common/data/json/units/manifest.json");
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
          return '<option value="' + escapeHtml(u.id) + '">' + escapeHtml(label) + "</option>";
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
    } else if (s === "cancelled") {
      cls += " cancelled";
      label = "Cancelled";
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
  const lock   = (r.lock   || "").toLowerCase();
  const soft   = lock === "soft" || r.soft === true;

  // Show re-send only for accepted soft-hold inquiries that still have an email
  const canResendAccept = soft && status === "accepted" && !!email;

  return (
    '<article class="mr-card" data-id="' + escapeHtml(id) + '">' +

    '  <header class="mr-header">' +
    '    <div class="mr-title">' +
    '      <span class="mr-id">' + escapeHtml(id) + "</span>" +
    "      " + badge + externalBadge +
    "    </div>" +
    '    <div class="mr-unit">' + escapeHtml(unit) + "</div>" +
    "  </header>" +

    '  <div class="mr-body">' +
    '    <div class="mr-dates">' +
    '      <span class="mr-from">' + escapeHtml(from) + "</span>" +
    "      &rarr; " +
    '      <span class="mr-to">' + escapeHtml(to) + "</span>" +
    (nights !== ""
      ? '      <span class="mr-nights">(' + escapeHtml(String(nights)) + " nights)</span>"
      : "") +
    "    </div>" +
    '    <div class="mr-guest">' +
    '      <span class="mr-guest-name">' + escapeHtml(guest) + "</span>" +
    (email
      ? '      <span class="mr-guest-email">&lt;' + escapeHtml(email) + "&gt;</span>"
      : "") +
    "    </div>" +
    "  </div>" +

    '  <footer class="mr-actions">' +
    '    <button class="mr-btn danger mr-btn-cancel" data-id="' + escapeHtml(id) + '">Cancel</button>' +
    (canResendAccept
      ? '    <button class="mr-btn mr-btn-resend" data-id="' + escapeHtml(id) + '">Re-send accept</button>'
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
      '    <b>Izberi rezervacijo na levi.</b>' +
      '  </div>' +
      '</div>';
  }

  function renderDetail(item, root) {
    const panel = qs("#mr-detail", root || document);
    if (!panel) return;

    if (!item || typeof item !== "object") {
      renderEmptyDetail(root);
      return;
    }

    const id     = item.id || item.res_id || "";
    const unit   = item.unit || "";
    const src    = item.source || "";
    const status = item.status || "";
    const lock   = item.lock || (item.soft ? "soft" : "");
    const from   = item.from || item.date_from || "";
    const to     = item.to   || item.date_to   || "";

    // Normalize status/lock for SMS code logic
    const statusNorm = String(status).toLowerCase();
    const lockNorm   = String(lock).toLowerCase();

    // Soft-hold = soft lock OR explicit soft_hold status
    const isSoftHold =
      lockNorm === "soft" ||
      item.soft === true ||
      statusNorm === "soft_hold";

    // Final statuses where we do NOT want to show SMS code
    const isFinalStatus =
      statusNorm === "confirmed" ||
      statusNorm === "cancelled";

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

    let total = null;
    if (item.total_eur != null) {
      total = item.total_eur;
    } else if (item.meta && item.meta.total_eur != null) {
      total = item.meta.total_eur;
    }
    const currency =
      item.currency ||
      (item.meta && item.meta.currency) ||
      "€";

    const badgeHtml = buildStatusChip(item);
    const lockLabel =
      lock === "hard" ? "hard" :
      lock === "soft" ? "soft-hold" :
      "-";

    let amountHtml = "";
    if (total != null && total !== "") {
      amountHtml =
        '  <div class="mr-detail-row">' +
        '    <span class="mr-detail-label">Znesek:</span>' +
        '    <span>' + escapeHtml(String(total)) + " " + escapeHtml(currency) + '</span>' +
        '  </div>';
    }

    panel.innerHTML =
      '<div class="mr-detail-card">' +
      '  <header class="mr-detail-header">' +
      '    <div class="mr-detail-main">' +
      '      <div class="mr-detail-id">' + escapeHtml(id) + '</div>' +
      '      <div class="mr-detail-badge">' + badgeHtml + '</div>' +
      '    </div>' +
      '    <div class="mr-detail-unit">' + escapeHtml(unit) + '</div>' +
      '  </header>' +

      '  <div class="mr-detail-body">' +
      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Termin:</span>' +
      '      <span>' +
               escapeHtml(from) + ' → ' + escapeHtml(to) +
               (nights !== ""
                 ? " (" + escapeHtml(String(nights)) + " nights)"
                 : "") +
      '      </span>' +
      '    </div>' +

      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Gost:</span>' +
      '      <span>' + (guest ? escapeHtml(guest) : "—") + '</span>' +
      '    </div>' +

      (email
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">E-mail:</span>' +
          '    <span>' + escapeHtml(email) + '</span>' +
          '  </div>'
        : "") +

      (phone
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">Telefon:</span>' +
          '    <span>' + escapeHtml(phone) + '</span>' +
          '  </div>'
        : "") +
      (smsCode
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">SMS koda:</span>' +
          '    <span><code>' + escapeHtml(smsCode) + '</code></span>' +
          '  </div>'
        : "") +

      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Vir:</span>' +
      '      <span>' + (src ? escapeHtml(src) : "—") + '</span>' +
      '    </div>' +

      '    <div class="mr-detail-row">' +
      '      <span class="mr-detail-label">Status:</span>' +
      '      <span>' +
               (status ? escapeHtml(status) : "—") +
               (lockLabel !== "-"
                 ? " · lock: " + escapeHtml(lockLabel)
                 : "") +
      '      </span>' +
      '    </div>' +

      (created
        ? '  <div class="mr-detail-row">' +
          '    <span class="mr-detail-label">Ustvarjeno:</span>' +
          '    <span>' + escapeHtml(created) + '</span>' +
          '  </div>'
        : "") +

      (message
        ? '  <div class="mr-detail-row mr-detail-row-message">' +
          '    <span class="mr-detail-label">Sporočilo:</span>' +
          '    <span>' + escapeHtml(message) + '</span>' +
          '  </div>'
        : "") +


      amountHtml +
      '  </div>' +

      // Actions row – TT & KEYCARD check-in + review link
      '  <div class="mr-detail-actions" style="margin-top:5px;">' +
      '    <button type="button" class="mr-btn" id="mr-btn-checkin-tt">' +
      '      TT &amp; račun (check-in)' +
      '    </button>' +
      (canSendReview
        ? '    <button type="button" class="mr-btn" id="mr-btn-review-link">' +
          '      Povezava za oceno' +
          '    </button>'
        : '') +
      '  </div>' +


      '  <div class="mr-detail-raw">' +
      '    <button type="button" class="mr-btn mr-btn-raw" id="mr-toggle-raw">Pokaži surovi JSON</button>' +

      '    <pre id="mr-raw" class="mr-raw" hidden>' +
             escapeHtml(JSON.stringify(item, null, 2)) +
      '    </pre>' +
      '  </div>' +
      '</div>';

    const toggle = panel.querySelector("#mr-toggle-raw");
    const pre    = panel.querySelector("#mr-raw");
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
        const url = "/app/admin/checkin_tt.php?id=" + encodeURIComponent(id);
        window.open(url, "_blank");
      });
    }

    // Send review link e-mail (only if button exists)
    const reviewBtn = panel.querySelector("#mr-btn-review-link");
    if (reviewBtn && id) {
      reviewBtn.addEventListener("click", function () {
        handleSendReview(item);
      });
    }
  } // ← to je zaključek renderDetail



  async function loadReservations(root) {
    const unitSel = qs("#mr-filter-unit", root);
    const ym = qs("#mr-filter-ym", root);
    const yearSel = qs("#mr-filter-year", root);
    const statusSel = qs("#mr-filter-status", root);
    const sourceSel = qs("#mr-filter-source", root);
    const softCheckbox = qs("#mr-filter-soft", root);
    const qInput = qs("#mr-filter-q", root);
    const info = qs("#mr-info", root);
    const list = qs("#mr-list", root);

    const params = {};
    if (unitSel && unitSel.value) params.unit = unitSel.value;
    if (ym && ym.value) params.ym = ym.value;
    if (yearSel && yearSel.value) params.year = yearSel.value;
    if (statusSel && statusSel.value) params.status = statusSel.value;
    if (sourceSel && sourceSel.value) params.source = sourceSel.value;
    if (softCheckbox && softCheckbox.checked) params.include_soft_hold = "1";
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
        text = "Prikazane so rezervacije, ki prečkajo leto " + yearSel.value + ".";
      } else if (hasYm) {
        text = "Prikazane so rezervacije, ki prečkajo mesec " + ym.value + ".";
      }

      info.textContent = text;
    }

    list.innerHTML = '<div class="mr-loading">Loading…</div>';

  // vedno resetiraj detail panel ob reloadu
  renderEmptyDetail(root);


    try {
      const query = new URLSearchParams(params).toString();
      const data = await getJSON(API_BASE + "/list_reservations.php?" + query);
      const rows = Array.isArray(data) ? data : data.items || [];

      if (!rows.length) {
        list.innerHTML = '<p class="mr-empty">No reservations found.</p>';
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

          const id = card.getAttribute("data-id");
          if (!id) return;

          // vizualno označi izbrano kartico
          qsa(".mr-card.selected", list).forEach(function (c) {
            c.classList.remove("selected");
          });
          card.classList.add("selected");

          const item = LAST_ITEMS_BY_ID[id] || null;
          renderDetail(item, root);
        });
      });

    } catch (e) {
      console.error("[manage_reservations] loadReservations failed:", e);
      list.innerHTML = '<p class="mr-error">Failed to load reservations.</p>';
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
          to: to,
        });
        await loadReservations(root);
      } catch (e) {
        console.error("[manage_reservations] local_block_remove failed:", e);
        alert("Failed to remove local block.");
      }
      return;
    }

    // 3) Soft-hold / soft lock → navaden confirm
    if (lock !== "hard") {
      const ok = confirm(`Prekličem soft-hold / pending rezervacijo ${id}?`);
      if (!ok) return;

      try {
        await postJSON(API_BASE + "/cancel_reservation.php", { id: id });
        await loadReservations(root);
      } catch (e) {
        console.error("[manage_reservations] cancel_reservation failed:", e);
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
      console.error("[manage_reservations] cancel_reservation failed:", e);
      alert("Failed to cancel reservation.");
    }
  }
 
   async function handleSendReview(item) {
    if (!item || typeof item !== "object") return;

    const id =
      item.id ||
      item.res_id ||
      "";

    if (!id) return;

    const guest =
      item.guest && typeof item.guest === "object"
        ? item.guest
        : {};

    const rawEmail =
      (guest && guest.email) ||
      item.email ||
      item.guest_email ||
      "";

    const labelEmail = rawEmail || "(neznan naslov)";

    const ok = confirm(
      "Pošljem gostu povezavo za oceno bivanja"
        + (rawEmail ? " na " + labelEmail : "")
        + "?"
    );
    if (!ok) return;

    try {
      const reply = await postJSON(API_BASE + "/send_review_request.php", { id: id });
      if (!reply || reply.ok === false) {
        const msg = (reply && (reply.error || reply.message)) || "send_failed";
        console.error("[manage_reservations] send_review_request failed:", reply);
        alert("❌ Napaka pri pošiljanju povezave za oceno: " + msg);
        return;
      }

      const finalEmail = reply.email || labelEmail;
      alert("✅ Povezava za oceno je bila poslana na: " + finalEmail);
} catch (e) {
  console.error("[manage_reservations] handleSendReview error:", e);

  const emsg = String(e && e.message ? e.message : e);

  if (emsg.includes("HTTP 403")) {
    alert("ℹ️  This future is oavailable only in PRO version");
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

    // Simple prompt-based UI for now – later can be upgraded to a proper modal
    const unit = prompt("Unit (e.g. T1)", defaultUnit || "");
    if (!unit) return;

    const guestName = prompt("Guest name", "");
    if (!guestName) return;

    const guestEmail = prompt("Guest e-mail", "");
    if (!guestEmail) return;

    const from = prompt("Arrival date (YYYY-MM-DD)", "");
    if (!from) return;

    const to = prompt("Departure date (YYYY-MM-DD)", "");
    if (!to) return;

    const channel = prompt(
      "Channel (booking.com / airbnb / phone / walk_in / other)",
      "booking.com"
    ) || "";

    const totalStr = prompt(
      "Total amount in EUR (optional – for invoice; leave empty if unknown)",
      ""
    );
    let total = null;
    if (totalStr && !isNaN(totalStr)) {
      total = parseFloat(totalStr);
    }

    // For now we assume language EN for external guests
    const lang = "en";

    const payload = {
      unit: unit.trim(),
      guest_name: guestName.trim(),
      guest_email: guestEmail.trim(),
      from: from.trim(),
      to: to.trim(),
      channel: channel.trim(),
      lang: lang
    };
    if (total !== null) {
      payload.total = total;
    }

    const confirmMsg =
      "Create external reservation for " +
      guestName +
      " in " +
      unit +
      " (" +
      from +
      " → " +
      to +
      ")" +
      (channel ? " via " + channel : "") +
      (total !== null ? " with total " + total + " EUR" : "") +
      "?";

    if (!window.confirm(confirmMsg)) {
      return;
    }

    try {
      const reply = await postJSON(API_BASE + "/create_external_reservation.php", payload);
      if (!reply || reply.ok === false) {
        const msg =
          (reply && (reply.error || reply.message)) ||
          "create_external_failed";
        console.error("[manage_reservations] create_external failed:", reply);
        alert("❌ Napaka pri ustvarjanju external rezervacije: " + msg);
        return;
      }

      const id = reply.id || "(unknown ID)";
      alert("✅ External reservation created: " + id);

      // Reload list to show the new reservation
      loadReservations(root);
    } catch (e) {
      console.error("[manage_reservations] openExternalReservationDialog error:", e);
      alert("❌ Unexpected error creating external reservation.");
    }
  }



  async function handleResend(id) {
    if (!id) return;
    try {
      await postJSON(API_BASE + "/resend_accept_email.php", { id: id });
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
      '    </select>' +
      '    <select id="mr-filter-status">' +
      '      <option value="confirmed">Confirmed</option>' +
      '      <option value="cancelled">Cancelled</option>' +
      '    </select>' +
      '    <select id="mr-filter-source">' +
      '      <option value="">All sources</option>' +
      '      <option value="direct">Direct</option>' +
      '      <option value="ics">ICS</option>' +
      '      <option value="local_block">Local block</option>' +
      '    </select>' +
      '    <label class="mr-check">' +
      '      <input id="mr-filter-soft" type="checkbox" />' +
      '      Vključi Soft-hold' +
      '    </label>' +
      '    <input id="mr-filter-q" type="search" placeholder="ID / e-mail / ime" />' +
      '    <button class="mr-btn" id="mr-btn-load">Osveži</button>' +
      '    <button class="mr-btn" id="mr-btn-add-external">Add external</button>' +
      '  </div>' +
      '  <div class="mr-layout">' +
      '    <div class="mr-left">' +
      '      <div id="mr-info" class="mr-info"></div>' +
      '      <div id="mr-list" class="mr-list"></div>' +
      '    </div>' +
      '    <aside id="mr-detail" class="mr-detail">' +
      '      <div class="mr-detail-card">' +
      '        <div class="mr-detail-row">' +
      '          <span class="mr-detail-label">Reservation:</span>' +
      '          <b>Izberi rezervacijo na levi.</b>' +
      '        </div>' +
      '      </div>' +
      '    </aside>' +
      '  </div>' +
      '</section>';



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
      "#mr-filter-soft"
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

  // ➕ NOVO: auto-boot na standalone strani
  document.addEventListener("DOMContentLoaded", function () {
    if (qs("#manage-reservations")) {
      init(document);
    }
  });
})();
