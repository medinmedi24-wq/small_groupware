'use strict';
(() => {
  const root = document.querySelector('[data-attendance-sync]');
  if (!root) return;
  const notice = root.querySelector('[role="status"]');
  const scrollKey = `attendance-sync-scroll:${location.pathname}${location.search}`;
  try {
    const saved = sessionStorage.getItem(scrollKey);
    sessionStorage.removeItem(scrollKey);
    if (saved !== null) window.scrollTo(0, Number(saved) || 0);
  } catch {}
  let dirty = false, submitting = false, busy = false, stopped = false;
  const editing = event => {
    if (event.target.closest('form')) dirty = true;
  };
  document.addEventListener('input', editing);
  document.addEventListener('change', editing);
  document.addEventListener('submit', event => {
    if (!event.defaultPrevented) submitting = true;
  });
  window.addEventListener('pageshow', () => { submitting = false; });
  const check = async () => {
    if (busy || stopped || submitting || document.hidden || !navigator.onLine) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(root.dataset.attendanceSync, {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal
      });
      if ([401, 403].includes(response.status)) { stopped = true; return; }
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) return;
      const data = await response.json();
      if (typeof data.revision !== 'string' || data.revision === root.dataset.revision) return;
      // Recheck after the request: the user may have started editing while it was in flight.
      if (submitting || document.hidden) return;
      if (dirty || document.querySelector('dialog[open], .attendance-punch-form button:disabled') ||
          document.activeElement?.matches('input, textarea, select, button, [contenteditable="true"]')) {
        notice.hidden = false;
        return;
      }
      try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch {}
      stopped = true;
      location.reload();
    } catch {
      // A transient network failure must not replace the page or imply a saved punch.
    } finally {
      clearTimeout(timeout);
      busy = false;
    }
  };
  setInterval(check, 10000);
  document.addEventListener('visibilitychange', check);
  window.addEventListener('focus', check);
  window.addEventListener('online', check);
})();
