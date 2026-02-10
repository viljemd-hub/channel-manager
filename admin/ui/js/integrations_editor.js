/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_editor.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_editor.js
 * Shared dialog used by Promo + Offers.
 * Uses existing DOM:
 *  #jsonEditDialog, #jsonEditForm, #jsonEditTitle, #jsonEditMeta
 *  #editName, #editCode, #editPercent, #editFrom, #editTo, #editActive
 *  #jsonEditTextarea, #jsonEditDelete, #jsonEditCancel
 */
(() => {
  'use strict';
  const ctx = window.CM_INTEGRATIONS;
  if (!ctx) return;

  const $ = (id) => document.getElementById(id);

  const dlg = $('jsonEditDialog');
  const form = $('jsonEditForm');
  const titleEl = $('jsonEditTitle');
  const metaEl = $('jsonEditMeta');

  const editName = $('editName');
  const editCode = $('editCode');
  const editPercent = $('editPercent');
  const editFrom = $('editFrom');
  const editTo = $('editTo');
  const editActive = $('editActive');
  const editMinNights = $('editMinNights');
  const fldMinNights  = $('fldMinNights');

  const ta = $('jsonEditTextarea');
  const btnDelete = $('jsonEditDelete');
  const btnCancel = $('jsonEditCancel');

  let current = null; // { type, onSave, onDelete }

  function applyUserFieldsToObject(type, obj) {
    if (!obj || typeof obj !== 'object') return obj;

    const name = (editName?.value || '').trim();
    const code = (editCode?.value || '').trim();
    const percentStr = (editPercent?.value || '').trim();
    const fromStr = (editFrom?.value || '').trim();
    const toStr = (editTo?.value || '').trim();
    const isActive = !!(editActive && editActive.checked);

    if (name) obj.name = name;

    if (type === 'promo') {
      if (code) {
        obj.code = code;
        if (!obj.id) obj.id = code;
      }
      if (percentStr !== '' && !Number.isNaN(+percentStr)) obj.discount_percent = +percentStr;
      if (fromStr) obj.valid_from = fromStr;
      if (toStr) obj.valid_to = toStr;
      obj.active = isActive;
      obj.enabled = isActive;
    }

    if (type === 'offer') {
      // percent -> offer.discount
      if (percentStr !== '' && !Number.isNaN(+percentStr)) {
        const val = +percentStr;
        obj.discount_percent = val;
        obj.discount = obj.discount || {};
        obj.discount.type = obj.discount.type || 'percent';
        obj.discount.value = val;
      }
      if (fromStr) { obj.active_from = fromStr; if ('from' in obj) delete obj.from; }
      if (toStr) { obj.active_to = toStr; if ('to' in obj) delete obj.to; }
      obj.active = isActive;
      obj.enabled = isActive;

      // 🔹 periods sync (0 or 1 period)
      const baseFrom = obj.active_from;
      const baseTo = obj.active_to;
      if (baseFrom && baseTo) {
        if (!Array.isArray(obj.periods) || obj.periods.length === 0) {
          obj.periods = [{ start: baseFrom, end: baseTo }];
        } else if (obj.periods.length === 1) {
          obj.periods[0].start = baseFrom;
          obj.periods[0].end = baseTo;
        }
      }

      // 🔹 MIN NIGHTS – tukaj dejansko zapišemo vrednost iz polja
      if (editMinNights) {
        let minN = parseInt(editMinNights.value, 10);
        if (!Number.isFinite(minN) || minN < 1) minN = 1;

        obj.conditions = (obj.conditions && typeof obj.conditions === 'object')
          ? obj.conditions
          : {};

        obj.conditions.min_nights = minN;
      }
    }

    return obj;
  }

  function open({ type, title, meta, obj, fields, onSave, onDelete }) {
    if (!dlg || !form || !ta) return;

    current = { type, onSave, onDelete };

    if (titleEl) titleEl.textContent = title || 'Uredi zapis';
    if (metaEl) metaEl.textContent = meta || '';

    // fill “pretty” fields (optional)
    if (editName) editName.value = fields?.name || '';
    if (editCode) editCode.value = fields?.code || '';
    if (editPercent) editPercent.value = fields?.percent != null ? String(fields.percent) : '';
    if (editFrom) editFrom.value = fields?.from || '';
    if (editTo) editTo.value = fields?.to || '';
    if (editActive) editActive.checked = !!fields?.active;
    // 🔹 Min. noči – samo za offers
    if (current.type === 'offer') {
      if (fldMinNights) fldMinNights.style.display = '';
      if (editMinNights) {
        const fn = fields?.min_nights;
        editMinNights.value =
          fn != null && fn !== ''
            ? String(fn)
            : '1';
      }
    } else {
      // za promo / druge tipe skrij polje
      if (fldMinNights) fldMinNights.style.display = 'none';
    }



    ta.value = JSON.stringify(obj || {}, null, 2);

    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
  }

  function close() {
    current = null;
    if (!dlg) return;
    if (typeof dlg.close === 'function') dlg.close();
    else dlg.removeAttribute('open');
  }

  function onSubmit(ev) {
    ev.preventDefault();
    if (!current || !ta) return close();

    let parsed;
    try {
      parsed = JSON.parse(ta.value || '{}');
    } catch (e) {
      alert('Napaka pri branju JSON-a: ' + e.message);
      return;
    }

    parsed = applyUserFieldsToObject(current.type, parsed);
    current.onSave?.(parsed);
    close();
  }

  function onDeleteClick() {
    if (!current) return close();
    if (!confirm('Želite res izbrisati ta zapis?')) return;
    current.onDelete?.();
    close();
  }

  function onCancelClick() { close(); }

  form?.addEventListener('submit', onSubmit);
  btnDelete?.addEventListener('click', onDeleteClick);
  btnCancel?.addEventListener('click', onCancelClick);

  ctx.Editor = { init() {}, open };
})();
