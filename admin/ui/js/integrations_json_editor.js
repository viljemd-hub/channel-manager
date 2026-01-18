/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/ui/js/integrations_json_editor.js
 * Author: Viljem Dvojmoč
 * Assistant: GPT
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 */

/**
 * integrations_json_editor.js
 * Shared JSON editor dialog
 */
(() => {
  'use strict';
  const ctx = window.CM_INTEGRATIONS;
  if (!ctx) return;

  const { helpers } = ctx;

  const dlg = document.getElementById('jsonEditDialog');
  const ta  = document.getElementById('jsonEditTextarea');
  const btnOk = document.getElementById('jsonEditOk');
  const btnCancel = document.getElementById('jsonEditCancel');
  const btnDelete = document.getElementById('jsonEditDelete');

  let current = null;

  function open(obj, onSave, onDelete) {
    current = { obj, onSave, onDelete };
    ta.value = JSON.stringify(obj, null, 2);
    dlg.showModal();
  }

  function close() {
    current = null;
    dlg.close();
  }

  function save() {
    try {
      const parsed = JSON.parse(ta.value);
      current?.onSave?.(parsed);
      close();
    } catch (e) {
      alert('JSON error: ' + e.message);
    }
  }

  function del() {
    if (confirm('Delete item?')) {
      current?.onDelete?.();
      close();
    }
  }

  btnOk?.addEventListener('click', save);
  btnCancel?.addEventListener('click', close);
  btnDelete?.addEventListener('click', del);

  ctx.JsonEditor = { open };
})();
