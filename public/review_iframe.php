<?php
/**
 * CM Pro – Reviews iframe widget
 *
 * This file implements the embeddable guest reviews widget that can be used
 * inside an <iframe> on an external website.
 *
 * In CM Free / CM Plus public repositories this file SHOULD ONLY be shipped
 * as a stub/wrapper that explains this is a CM Pro feature and points to
 * the documentation on how to enable it.
 *
 * This implementation:
 *  - reads summary.json and public.json from /app/public/data/reviews/
 *  - renders a compact "Guest feedback" section with 3 featured reviews
 *  - provides a modal with full review list and sorting
 *
 * Typical embed code for property owners (CM Pro):
 *
 *   <iframe
 *     src="https://YOUR-DOMAIN/app/review__iframe.php"
 *     style="border:0;width:100%;min-height:420px;overflow:hidden;"
 *     loading="lazy">
 *   </iframe>
 *
 * NOTE: This file is intended to be iframe-friendly. Do NOT add headers like
 * X-Frame-Options: SAMEORIGIN here – that should be configured at the web
 * server level if needed.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Guest reviews</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --cm-font: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      --cm-bg: #f5f5f7;
      --cm-panel-bg: #ffffff;
      --cm-border-subtle: rgba(15, 23, 42, 0.08);
      --cm-text-main: #111827;
      --cm-text-muted: #6b7280;
      --cm-accent: #2563eb;
      --cm-accent-soft: rgba(37, 99, 235, 0.08);
      --cm-radius-lg: 18px;
      --cm-radius-md: 12px;
      --cm-shadow-soft: 0 18px 40px rgba(15, 23, 42, 0.12);
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      margin: 0;
      padding: 0;
      font-family: var(--cm-font);
      background: var(--cm-bg);
      color: var(--cm-text-main);
    }

    body.has-modal {
      overflow: hidden;
    }

    .page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .reviews-section {
      width: 100%;
      max-width: 960px;
    }

    .wrap {
      padding: 0;
    }

    .panel {
      background: radial-gradient(circle at top left, #e0f2fe 0, #f5f5f7 32%, #ffffff 100%);
      border-radius: 24px;
      padding: 24px 20px 22px;
      box-shadow: var(--cm-shadow-soft);
      border: 1px solid rgba(15, 23, 42, 0.05);
    }

    @media (min-width: 720px) {
      .panel {
        padding: 26px 28px 24px;
      }
    }

    .panel-header {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 14px;
    }

    @media (min-width: 640px) {
      .panel-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
      }
    }

    .panel-title {
      margin: 0;
      font-size: 1.2rem;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      font-weight: 650;
      color: #0f172a;
    }

    .panel-subtitle {
      margin: 0;
      font-size: 0.9rem;
      color: var(--cm-text-muted);
      max-width: 36rem;
    }

    .rating-summary {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 8px;
      margin-top: 8px;
    }

    @media (min-width: 640px) {
      .rating-summary {
        justify-content: flex-end;
        margin-top: 0;
      }
    }

    .rating-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(15, 23, 42, 0.08);
      background: rgba(255, 255, 255, 0.85);
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
      font-size: 0.85rem;
      color: #020617;
      white-space: nowrap;
    }

    .rating-badge[data-level="good"] {
      border-color: rgba(16, 185, 129, 0.5);
      background: linear-gradient(120deg, rgba(16, 185, 129, 0.18), rgba(21, 128, 61, 0.08));
    }

    .rating-badge[data-level="ok"] {
      border-color: rgba(37, 99, 235, 0.45);
      background: linear-gradient(120deg, rgba(37, 99, 235, 0.18), rgba(37, 99, 235, 0.06));
    }

    .rating-badge[data-level="bad"] {
      border-color: rgba(239, 68, 68, 0.5);
      background: linear-gradient(120deg, rgba(248, 113, 113, 0.22), rgba(185, 28, 28, 0.08));
    }

    .rating-score {
      font-weight: 700;
      font-variant-numeric: tabular-nums lining-nums;
    }

    .rating-count {
      font-size: 0.78rem;
      color: rgba(15, 23, 42, 0.8);
    }

    .rating-stars {
      font-size: 0.85rem;
      color: #facc15;
    }

    .reviews-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 10px;
      margin-top: 14px;
    }

    @media (min-width: 720px) {
      .reviews-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    .review-card {
      border: 1px solid var(--cm-border-subtle);
      background: rgba(255, 255, 255, 0.98);
      border-radius: var(--cm-radius-md);
      padding: 10px 11px 20px;
      text-align: left;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      gap: 8px;
      transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease, background 120ms ease;
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08);
      position: relative;
    }

    .review-card:hover,
    .review-card:focus-visible {
      outline: none;
      transform: translateY(-1px);
      box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22);
      border-color: rgba(37, 99, 235, 0.4);
      background: #ffffff;
    }

    .review-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      font-size: 0.75rem;
    }

    .review-stars {
      font-size: 0.85rem;
      color: #facc15;
      letter-spacing: 0.03em;
    }

    .review-verified {
      padding: 2px 6px;
      border-radius: 999px;
      background: rgba(22, 163, 74, 0.08);
      color: #15803d;
      font-size: 0.7rem;
      border: 1px solid rgba(21, 128, 61, 0.18);
      white-space: nowrap;
    }

    .review-text {
      font-size: 0.86rem;
      line-height: 1.5;
      color: #111827;
      max-height: 4.2em;
      overflow: hidden;
      text-overflow: ellipsis;
    }

.review-meta {
  margin-top: 4px;
  font-size: 0.78rem;
  color: var(--cm-text-muted);
  text-align: left;
  padding-right: 22px; /* prostor na desni za "More…" */
}
.review-name {
  display: block;
  font-weight: 600;
  color: #0f172a;
  text-align: left;      /* ime levo */
}
.review-when {
  display: block;
  font-size: 0.76rem;
  text-align: center;    /* mesec na sredini kartice */
  opacity: 0.9;
}

    .review-card__more {
      position: absolute;
      right: 9px;
      bottom: 7px;
      font-size: 0.76rem;
      color: #2563eb;
      text-decoration: underline;
    }

    .muted {
      color: var(--cm-text-muted);
      font-size: 0.85rem;
    }

    #reviews-empty {
      margin-top: 10px;
    }

    /* Modal overlay */

    .reviews-modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .reviews-modal[hidden] {
      display: none !important;
    }

    .reviews-modal__backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.52);
      backdrop-filter: blur(3px);
    }

    .reviews-modal__dialog {
      position: relative;
      z-index: 1;
      max-width: min(720px, 100% - 32px);
      max-height: min(80vh, 520px);
      background: #ffffff;
      border-radius: 18px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.45);
      border: 1px solid rgba(15, 23, 42, 0.12);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .reviews-modal__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 16px 10px;
      border-bottom: 1px solid rgba(15, 23, 42, 0.08);
      background: linear-gradient(120deg, #eff6ff, #ffffff);
    }

    .reviews-modal__title {
      margin: 0;
      font-size: 1rem;
      font-weight: 650;
      color: #111827;
    }

    .reviews-modal__close {
      border: none;
      background: transparent;
      font-size: 1.3rem;
      line-height: 1;
      cursor: pointer;
      color: #4b5563;
      border-radius: 999px;
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .reviews-modal__close:hover {
      background: rgba(15, 23, 42, 0.06);
      color: #111827;
    }

    .reviews-modal__toolbar {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px 6px;
      border-bottom: 1px solid rgba(15, 23, 42, 0.05);
      background: #f9fafb;
      font-size: 0.78rem;
    }

    .reviews-modal__label {
      font-weight: 500;
      color: #4b5563;
    }

    .reviews-sort-button {
      border-radius: 999px;
      border: 1px solid rgba(15, 23, 42, 0.16);
      background: #ffffff;
      font-size: 0.78rem;
      padding: 3px 10px;
      cursor: pointer;
      color: #374151;
      transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
    }

    .reviews-sort-button:hover {
      background: rgba(37, 99, 235, 0.08);
      border-color: rgba(37, 99, 235, 0.5);
      color: #1d4ed8;
    }

    .reviews-sort-button--active {
      background: #2563eb;
      color: #ffffff;
      border-color: #1d4ed8;
    }

    .reviews-modal__body {
      padding: 10px 14px 14px;
      overflow: auto;
    }

    .reviews-modal__list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .reviews-modal-review {
      border-radius: 14px;
      border: 1px solid rgba(15, 23, 42, 0.08);
      padding: 10px 11px;
      background: #f9fafb;
    }

    .reviews-modal-review .review-text {
      max-height: none;
    }

    .reviews-modal-review .review-meta {
      margin-top: 4px;
    }

    .reviews-modal-review + .reviews-modal-review {
      border-top-width: 1px;
    }
  </style>
</head>
<body>
  <div class="page">
    <section class="reviews-section" aria-labelledby="reviews-title">
      <div class="wrap">
        <div class="panel">
          <div class="panel-header">
            <div>
              <h1 id="reviews-title" class="panel-title">Guest feedback</h1>
              <p class="panel-subtitle">
                Selected reviews from verified stays. After each stay, guests receive a short feedback form.
              </p>
            </div>

            <div class="rating-summary">
              <div id="rating-badge"
                   class="rating-badge"
                   aria-label="Guest ratings (in preparation)">
                <span id="rating-score" class="rating-score">5.0</span>
                <span class="rating-stars">★★★★★</span>
                <span id="rating-count" class="rating-count">(future reviews)</span>
              </div>
            </div>
          </div>

          <div class="reviews-grid" id="reviews-list" aria-live="polite"></div>

          <p class="muted" id="reviews-empty" style="margin-top:.7rem; display:none">
            Reviews will appear here soon.
          </p>
        </div>
      </div>
    </section>
  </div>

  <div id="reviews-modal" class="reviews-modal" hidden>
    <div class="reviews-modal__backdrop" data-reviews-close></div>
    <div class="reviews-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reviews-modal-title">
      <div class="reviews-modal__header">
        <h2 id="reviews-modal-title" class="reviews-modal__title">Guest reviews</h2>
        <button type="button"
                class="reviews-modal__close"
                aria-label="Close reviews"
                data-reviews-close>×</button>
      </div>

      <div class="reviews-modal__toolbar">
        <span class="reviews-modal__label">Sort:</span>
        <button type="button"
                class="reviews-sort-button reviews-sort-button--active"
                data-reviews-sort="newest">
          Newest
        </button>
        <button type="button"
                class="reviews-sort-button"
                data-reviews-sort="oldest">
          Oldest
        </button>
        <button type="button"
                class="reviews-sort-button"
                data-reviews-sort="rating">
          Best rated
        </button>
      </div>

      <div class="reviews-modal__body">
        <div id="reviews-modal-list" class="reviews-modal__list" aria-live="polite"></div>
      </div>
    </div>
  </div>

  <script id="REVIEWS_LOADER">
  (function(){
    const SUMMARY_URL = "/app/public/data/reviews/summary.json";
    const PUBLIC_URL  = "/app/public/data/reviews/public.json";
    const MAX_FEATURED = 3;  // only 3 cards in the section

    let allReviews = [];     // approved reviews in “natural” order (newest → oldest)
    let currentSort = "newest";

    function num(v){
      const n = Number(v);
      return Number.isFinite(n) ? n : null;
    }

    function fmt1(n){
      return (Math.round(n * 10) / 10).toFixed(1);
    }

    function level(avg){
      if (avg >= 4.8) return "good";
      if (avg >= 4.5) return "ok";
      return "bad";
    }

    async function fetchJson(url){
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) throw new Error("HTTP_" + res.status);
      return await res.json();
    }

    function setBadge(summary){
      const badge  = document.getElementById("rating-badge");
      const scoreEl = document.getElementById("rating-score");
      const countEl = document.getElementById("rating-count");
      if (!badge || !scoreEl || !countEl) return;

      const avg = num(summary && summary.avg);
      const count = num(summary && summary.count);

      if (avg === null || count === null || count <= 0){
        // keep default fallback "(future reviews)"
        badge.removeAttribute("data-level");
        badge.setAttribute("aria-label", "Guest ratings (in preparation)");
        return;
      }

      scoreEl.textContent = fmt1(avg);
      countEl.textContent = "(" + count + " review" + (count === 1 ? "" : "s") + ")";
      badge.setAttribute("aria-label", "Guest rating " + fmt1(avg) + " out of 5 from " + count + " reviews");
      badge.setAttribute("data-level", level(avg));
    }

    function clampRating(v){
      const n = Math.round(Number(v) || 0);
      return Math.max(1, Math.min(5, n));
    }

    function stars(r){
      const n = clampRating(r);
      return "★★★★★".slice(0, n) + "☆☆☆☆☆".slice(0, 5 - n);
    }

    function normalizeReviews(payload){
      const raw = Array.isArray(payload)
        ? payload
        : (payload && Array.isArray(payload.reviews) ? payload.reviews : []);

      const approved = raw.filter(r =>
        r && (r.approved === true || r.approved === 1 || r.approved === "true")
      );

      return approved.map((r, idx) => Object.assign({ __index: idx }, r));
    }

    function renderFeatured(){
      const listEl  = document.getElementById("reviews-list");
      const emptyEl = document.getElementById("reviews-empty");
      if (!listEl) return;

      listEl.innerHTML = "";

      if (!allReviews.length){
        if (emptyEl) emptyEl.style.display = "";
        return;
      }
      if (emptyEl) emptyEl.style.display = "none";

      const items = allReviews.slice(0, MAX_FEATURED);

      items.forEach((r) => {
        const card = document.createElement("button");
        card.type = "button";
        card.className = "review-card";
        card.setAttribute("data-review-index", String(r.__index));
        card.setAttribute("aria-label", "Read full review from " + ((r.name || "Guest") + ""));

        const top = document.createElement("div");
        top.className = "review-top";

        const st = document.createElement("div");
        st.className = "review-stars";
        st.textContent = stars(r.rating);

        const ver = document.createElement("div");
        ver.className = "review-verified";
        ver.textContent = "Verified stay";

        top.appendChild(st);
        top.appendChild(ver);

        const txt = document.createElement("div");
        txt.className = "review-text";
        const t = (r.text || "").toString().trim();
        txt.textContent = t ? ("“" + t + "”") : "";

        const meta = document.createElement("div");
        meta.className = "review-meta";

        const who = document.createElement("span");
        who.className = "review-name";
        who.textContent = (r.name || "").toString().trim() || "Guest";

        const when = document.createElement("span");
        when.className = "review-when";
        when.textContent = (r.stay_month || r.date || "").toString().trim();

        meta.appendChild(who);
        meta.appendChild(when);

        const more = document.createElement("span");
        more.className = "review-card__more";
        more.textContent = "More…";

        card.appendChild(top);
        if (txt.textContent) card.appendChild(txt);
        card.appendChild(meta);
        card.appendChild(more);

        card.addEventListener("click", () => {
          openModal(); // open popup when clicking any card
        });

        listEl.appendChild(card);
      });
    }

    function getSorted(mode){
      const base = allReviews.slice(); // copy

      if (mode === "oldest"){
        return base.reverse();
      }
      if (mode === "rating"){
        return base.sort((a, b) => (Number(b.rating) || 0) - (Number(a.rating) || 0));
      }

      // "newest" = natural JSON order (you maintain it)
      return base;
    }

    function setActiveSortButton(mode){
      const buttons = document.querySelectorAll("[data-reviews-sort]");
      buttons.forEach(btn => {
        const m = btn.getAttribute("data-reviews-sort") || "";
        if (m === mode){
          btn.classList.add("reviews-sort-button--active");
        }else{
          btn.classList.remove("reviews-sort-button--active");
        }
      });
    }

    function renderModalList(mode){
      const listEl = document.getElementById("reviews-modal-list");
      if (!listEl) return;

      const items = getSorted(mode);
      listEl.innerHTML = "";

      if (!items.length){
        const p = document.createElement("p");
        p.className = "muted";
        p.textContent = "No reviews yet.";
        listEl.appendChild(p);
        return;
      }

      items.forEach(r => {
        const wrap = document.createElement("article");
        wrap.className = "reviews-modal-review";

        const top = document.createElement("div");
        top.className = "review-top";

        const st = document.createElement("div");
        st.className = "review-stars";
        st.textContent = stars(r.rating);

        const ver = document.createElement("div");
        ver.className = "review-verified";
        ver.textContent = "Verified stay";

        top.appendChild(st);
        top.appendChild(ver);

        const txt = document.createElement("div");
        txt.className = "review-text";
        const t = (r.text || "").toString().trim();
        txt.textContent = t ? ("“" + t + "”") : "";

        const meta = document.createElement("div");
        meta.className = "review-meta";

        const who = document.createElement("span");
        who.className = "review-name";
        who.textContent = (r.name || "").toString().trim() || "Guest";

        const when = document.createElement("span");
        when.className = "review-when";
        when.textContent = (r.stay_month || r.date || "").toString().trim();

        meta.appendChild(who);
        meta.appendChild(when);

        wrap.appendChild(top);
        if (txt.textContent) wrap.appendChild(txt);
        wrap.appendChild(meta);

        listEl.appendChild(wrap);
      });
    }

    function openModal(){
      const modal = document.getElementById("reviews-modal");
      if (!modal) return;
      modal.hidden = false;
      document.body.classList.add("has-modal");

      setActiveSortButton(currentSort);
      renderModalList(currentSort);
    }

    function closeModal(){
      const modal = document.getElementById("reviews-modal");
      if (!modal) return;
      modal.hidden = true;
      document.body.classList.remove("has-modal");
    }

    document.addEventListener("DOMContentLoaded", async () => {
      // close buttons + click on backdrop
      document.querySelectorAll("[data-reviews-close]").forEach(el => {
        el.addEventListener("click", closeModal);
      });

      const modal = document.getElementById("reviews-modal");
      if (modal){
        modal.addEventListener("click", (ev) => {
          if (ev.target === modal){
            closeModal();
          }
        });
      }

      document.addEventListener("keydown", (ev) => {
        if (ev.key === "Escape"){
          closeModal();
        }
      });

      // sort buttons
      document.querySelectorAll("[data-reviews-sort]").forEach(btn => {
        btn.addEventListener("click", () => {
          const mode = btn.getAttribute("data-reviews-sort") || "newest";
          currentSort = mode;
          setActiveSortButton(mode);
          renderModalList(mode);
        });
      });

      // load summary + reviews
      try{
        const summary = await fetchJson(SUMMARY_URL);
        setBadge(summary);
      }catch(e){
        // silent fail – keep default badge
      }

      try{
        const payload = await fetchJson(PUBLIC_URL);
        allReviews = normalizeReviews(payload);
        renderFeatured();
      }catch(e){
        // if fetch fails, keep fallback "Reviews will appear here soon."
        allReviews = [];
        renderFeatured();
      }
    });
  })();
  </script>
</body>
</html>
