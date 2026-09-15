// Run in the head so installed launches show branding before the page paints.
(() => {
  const installed = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  if (!installed) return;
  const key = 'mnm-launch-shown';
  try {
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');
  } catch {
    // Avoid repeating on internal navigation when session storage is unavailable.
    if (document.referrer && new URL(document.referrer).origin === location.origin) return;
  }
  const root = document.documentElement;
  root.classList.add('app-launching');
  const started = performance.now();
  const dismiss = () => root.classList.remove('app-launching');
  setTimeout(dismiss, 4000);
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(dismiss, Math.max(0, 2500 - (performance.now() - started)));
  }, { once: true });
  window.addEventListener('pageshow', event => { if (event.persisted) dismiss(); });
})();
