/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/helpers.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * Shared helper functions for admin-side JavaScript.
 *
 * Responsibilities:
 * - Provide small, reusable utilities (DOM helpers, date helpers,
 *   formatting functions, type checks, etc.).
 * - Centralise "tiny" functions so that other modules don't need to
 *   reimplement them (keep code DRY).
 *
 * Depends on:
 * - (none) – this module should stay dependency-free where possible.
 *
 * Used by:
 * - admin_shell.js
 * - admin_api.js
 * - admin_calendar.js
 * - range_select_admin.js
 * - admin_info_panel.js
 * - locks_loader.js
 * - manage_reservations.js
 * - integrations.js
 *
 * Notes:
 * - Avoid making this file a dumping ground for unrelated logic.
 *   Keep helpers small, pure and well named.
 */


export const pad2 = (n) => String(n).padStart(2, "0");
export const ymd = (d) => `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
export const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate()+n); return x; };
export const parseISO = (s) => { const [Y,M,D] = s.split("-").map(Number); return new Date(Y, M-1, D); };
export const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
export const el  = (sel, root=document) => root.querySelector(sel);
export const els = (sel, root=document) => root.querySelectorAll(sel);
export const sleep = (ms) => new Promise(r => setTimeout(r, ms));
