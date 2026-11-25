/* Email Marketing System - admin UI helpers (vanilla JS + Bootstrap 5) */
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

  window.EMS = {
    csrf,
    async postJson(url, data = {}) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data),
      });
      let body = {};
      try { body = await res.json(); } catch (e) { /* non-JSON */ }
      if (!res.ok && !body.message) body.message = body.error || `Request failed (${res.status})`;
      return { ok: res.ok, status: res.status, body };
    },
    async getJson(url) {
      const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      return res.ok ? res.json() : null;
    },
    toast(message, type = 'success') {
      const host = document.getElementById('toasts');
      if (!host) return alert(message);
      const el = document.createElement('div');
      el.className = `toast align-items-center text-bg-${type === 'success' ? 'success' : 'danger'} border-0`;
      el.setAttribute('role', 'alert');
      el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
      el.querySelector('.toast-body').textContent = message; // textContent: server messages are never parsed as HTML
      host.appendChild(el);
      const t = new bootstrap.Toast(el, { delay: 6000 });
      t.show();
      el.addEventListener('hidden.bs.toast', () => el.remove());
    },
    debounce(fn, ms = 400) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; },
  };

  /* Sidebar (mobile) */
  document.querySelector('.sidebar-toggle')?.addEventListener('click', () => document.querySelector('.sidebar').classList.toggle('open'));

  /* Confirmation dialogs: <form data-confirm="Are you sure?" data-confirm-button="Delete"> */
  const confirmEl = document.getElementById('confirmModal');
  if (confirmEl) {
    const modal = new bootstrap.Modal(confirmEl);
    let pending = null;
    let pendingSubmitter = null;
    document.addEventListener('submit', (e) => {
      const form = e.target;
      // A confirmation can live on the <form> or on the specific submit button that was pressed.
      const source = e.submitter?.dataset.confirm ? e.submitter : form;
      if (!form.matches('form') || !source.dataset.confirm || form.dataset.confirmed === '1') return;
      e.preventDefault();
      pending = form;
      pendingSubmitter = e.submitter || null;
      confirmEl.querySelector('[data-confirm-message]').textContent = source.dataset.confirm;
      const btn = confirmEl.querySelector('[data-confirm-ok]');
      btn.textContent = source.dataset.confirmButton || 'Confirm';
      btn.className = 'btn ' + (source.dataset.confirmClass || 'btn-danger');
      modal.show();
    }, true);
    confirmEl.querySelector('[data-confirm-ok]').addEventListener('click', () => {
      if (!pending) return;
      pending.dataset.confirmed = '1';
      modal.hide();
      pending.requestSubmit ? pending.requestSubmit(pendingSubmitter) : pending.submit();
    });
  }

  /* Loading indicator + double-submit guard on every form */
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || form.hasAttribute('data-no-loading')) return;
    const btn = e.submitter || form.querySelector('button[type=submit]');
    if (!btn) return;
    setTimeout(() => {
      if (form.dataset.submitting === '1') return;
      form.dataset.submitting = '1';
      btn.dataset.label = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.loadingText || 'Working…');
      btn.disabled = true;
      // The submitter's name/value must still be posted: add it as a hidden input.
      if (btn.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
    }, 0);
  });
  window.addEventListener('pageshow', () => document.querySelectorAll('form[data-submitting]').forEach((f) => {
    delete f.dataset.submitting; f.dataset.confirmed = '0';
    f.querySelectorAll('button[disabled][data-label]').forEach((b) => { b.disabled = false; b.innerHTML = b.dataset.label; });
  }));

  /* Bulk selection: header checkbox [data-select-all], row checkboxes .row-check, bar #bulkBar, forms [data-bulk-form] */
  const checks = () => [...document.querySelectorAll('.row-check')];
  const bar = document.getElementById('bulkBar');
  const refreshBulk = () => {
    const n = checks().filter((c) => c.checked).length;
    if (bar) { bar.classList.toggle('show', n > 0); bar.querySelector('[data-bulk-count]').textContent = n; }
  };
  document.querySelector('[data-select-all]')?.addEventListener('change', (e) => { checks().forEach((c) => (c.checked = e.target.checked)); refreshBulk(); });
  document.addEventListener('change', (e) => { if (e.target.classList?.contains('row-check')) refreshBulk(); });
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form.matches('[data-bulk-form]')) return;
    form.querySelectorAll('input[name="ids[]"]').forEach((i) => i.remove());
    checks().filter((c) => c.checked).forEach((c) => {
      const h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = c.value; form.appendChild(h);
    });
  }, true);

  /* Edit modals: <button data-edit-modal="#modalId" data-action="/url" data-values='{"name":"x","group_ids":[1,2]}'> */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-edit-modal]');
    if (!btn) return;
    const el = document.querySelector(btn.dataset.editModal);
    const form = el.querySelector('form');
    const values = JSON.parse(btn.dataset.values || '{}');
    form.action = btn.dataset.action;
    const ea = form.querySelector('input[name=_edit_action]'); if (ea) ea.value = btn.dataset.action; // lets the form reopen after a validation error
    let method = form.querySelector('input[name=_method]');
    if (!method) { method = document.createElement('input'); method.type = 'hidden'; method.name = '_method'; form.appendChild(method); }
    method.value = 'PUT';
    form.querySelectorAll('[name]').forEach((f) => {
      const name = f.name.replace('[]', '');
      if (!(name in values) || name === '_method' || name === '_token') return;
      if (f.type === 'hidden' && form.querySelector('input[type=checkbox][name="' + f.name + '"]')) return; // keep the 0-fallback of a checkbox pair
      if (f.type === 'checkbox' && f.name.endsWith('[]')) f.checked = (values[name] || []).map(String).includes(f.value);
      else if (f.type === 'checkbox') f.checked = !!values[name];
      else if (f.tagName === 'SELECT' && f.multiple) [...f.options].forEach((o) => (o.selected = (values[name] || []).map(String).includes(o.value)));
      else if (f.type !== 'password' && f.type !== 'file') f.value = values[name] ?? '';
    });
    el.querySelector('[data-modal-title]') && (el.querySelector('[data-modal-title]').textContent = btn.dataset.title || 'Edit');
    bootstrap.Modal.getOrCreateInstance(el).show();
  });
  document.querySelectorAll('[data-create-modal]').forEach((btn) => btn.addEventListener('click', () => {
    const el = document.querySelector(btn.dataset.createModal);
    const form = el.querySelector('form');
    form.reset();
    form.action = btn.dataset.action;
    const ea2 = form.querySelector('input[name=_edit_action]'); if (ea2) ea2.value = '';
    form.querySelector('input[name=_method]')?.remove();
    el.querySelector('[data-modal-title]') && (el.querySelector('[data-modal-title]').textContent = btn.dataset.title || 'Add');
  }));
  document.querySelectorAll('[data-open-modal]').forEach((el) => bootstrap.Modal.getOrCreateInstance(document.querySelector(el.dataset.openModal)).show());

  /* Generic AJAX POST button: <button data-ajax-post="/url" data-email-input="#to"> shows the JSON message as a toast */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ajax-post]');
    if (!btn) return;
    e.preventDefault();
    const payload = {};
    if (btn.dataset.emailInput) {
      const input = document.querySelector(btn.dataset.emailInput);
      if (!input.value || !input.checkValidity()) { input.reportValidity(); return; }
      payload.to_email = input.value;
    }
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Working…';
    try {
      const r = await EMS.postJson(btn.dataset.ajaxPost, payload);
      EMS.toast(r.body.message || (r.ok ? 'Done' : 'Failed'), r.ok ? 'success' : 'danger');
    } catch (err) {
      EMS.toast('Network error. Please try again.', 'danger');
    } finally {
      btn.disabled = false; btn.innerHTML = original;
    }
  });

  /* Live progress polling: <div data-progress-url="/..."> with [data-field="progress|status|..."] children */
  document.querySelectorAll('[data-progress-url]').forEach((box) => {
    const tick = async () => {
      const d = await EMS.getJson(box.dataset.progressUrl);
      if (!d) return setTimeout(tick, 6000);
      const counts = d.counts || d;
      // Status pills elsewhere on the page (e.g. the header) are updated too.
      document.querySelectorAll('[data-live-status]').forEach((el) => { el.textContent = d.status_label ?? d.status; if (d.badge) el.className = 'pill pill-' + d.badge; });
      // The campaign changed state (started, paused by the engine, completed...): reload to show the right actions.
      if (box.dataset.reloadOnChange && box.dataset.initialStatus && d.status !== box.dataset.initialStatus) { location.reload(); return; }
      box.querySelectorAll('[data-field]').forEach((el) => {
        const key = el.dataset.field;
        if (key === 'progress') { el.style.width = d.progress + '%'; el.setAttribute('aria-valuenow', d.progress); }
        else if (key === 'progress_text') el.textContent = d.progress + '%';
        else if (key === 'status_label') { el.textContent = d.status_label ?? d.status; if (d.badge) el.className = 'pill pill-' + d.badge; }
        else if (key === 'note') el.textContent = d.note || '';
        else if (counts[key] !== undefined) el.textContent = Number(counts[key]).toLocaleString();
        else if (d[key] !== undefined) el.textContent = d[key];
      });
      if (d.finished) {
        if (box.dataset.reloadOnFinish) setTimeout(() => location.reload(), 800);
        return;
      }
      setTimeout(tick, 3000);
    };
    tick();
  });
})();
