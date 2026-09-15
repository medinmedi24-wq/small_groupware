'use strict';
// Native details remain usable without JavaScript; phones start with compact lists.
const leaveListViewport = window.matchMedia('(max-width: 700px)');
function setLeaveListDisclosure() {
  document.querySelectorAll('[data-mobile-filter]').forEach(panel => {
    panel.open = !leaveListViewport.matches || !!document.querySelector('.error');
  });
}
setLeaveListDisclosure();
leaveListViewport.addEventListener('change', setLeaveListDisclosure);
// Carry the exact list position with the detail link, independently in each tab.
document.addEventListener('click', event => {
  const link = event.target.closest('a[data-preserve-list-scroll]');
  if (!link) return;
  const target = new URL(link.href, window.location.href);
  target.searchParams.set('return_scroll', String(Math.max(0, Math.round(window.scrollY))));
  link.href = target.href;
});
window.addEventListener('pageshow', event => {
  if (event.persisted) return;
  const query = new URLSearchParams(window.location.search);
  const scroll = query.get('scroll');
  if (query.get('page') === 'admin-leaves' && /^\d{1,7}$/.test(scroll || '')) {
    window.scrollTo({ top: Number(scroll), behavior: 'instant' });
  }
});
// Keep desktop entry forms expanded; mobile starts with the saved records in view.
const cardEntryViewport = window.matchMedia('(min-width: 701px)');
function expandDesktopCardEntry() {
  if (cardEntryViewport.matches) document.querySelectorAll('details.card-entry').forEach(panel => { panel.open = true; });
}
expandDesktopCardEntry();
cardEntryViewport.addEventListener('change', expandDesktopCardEntry);
// Dismiss brief authentication confirmations; leave errors and business notices visible.
document.querySelectorAll('[data-auth-notice]').forEach(notice => {
  window.setTimeout(() => notice.remove(), 3000);
});
function updateManualLeave(form) {
  const mode = form?.querySelector('select[name="leaveAccrual"]');
  const input = form?.querySelector('input[name="annualLeave"]');
  if (!mode || !input) return;
  const manual = mode.value === 'MANUAL';
  input.closest('label').hidden = !manual;
  input.disabled = !manual;
  input.required = manual;
}
document.querySelectorAll('select[name="leaveAccrual"]').forEach(select => updateManualLeave(select.form));
const leavePreviewRequests = new WeakMap();
function updateLeavePreview(form) {
  const preview = form?.querySelector('.leave-preview');
  if (!preview) return;
  const value = name => form.elements.namedItem(name)?.value;
  const type = value('leaveType');
  const endInput = form.elements.namedItem('endDate');
  const halfDay = ['AM_HALF', 'PM_HALF'].includes(type);
  endInput.readOnly = halfDay;
  if (halfDay) endInput.value = value('startDate');
  const startText = value('startDate'), endText = value('endDate');
  const key = [type, startText, endText].join('|');
  const previous = leavePreviewRequests.get(preview);
  if (previous?.key === key) return;
  clearTimeout(previous?.timer);
  previous?.controller.abort();
  const request = { key, controller: new AbortController() };
  leavePreviewRequests.set(preview, request);
  preview.classList.remove('is-warning');
  preview.removeAttribute('aria-busy');
  if (!startText || !endText || endText < startText || startText.slice(0, 4) !== endText.slice(0, 4)) {
    preview.textContent = '같은 연도 안에서 시작일과 종료일을 올바르게 선택해주세요.';
    return;
  }
  preview.textContent = '신청일 기준 연차를 계산하고 있습니다…';
  preview.setAttribute('aria-busy', 'true');
  request.timer = setTimeout(async () => {
    try {
      const url = new URL(preview.dataset.previewUrl, window.location.href);
      url.searchParams.set('leaveType', type);
      url.searchParams.set('startDate', startText);
      url.searchParams.set('endDate', endText);
      const response = await fetch(url, { credentials: 'same-origin', signal: request.controller.signal, cache: 'no-store' });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Preview unavailable');
      const result = await response.json();
      if (leavePreviewRequests.get(preview) !== request || !preview.isConnected) return;
      if (!response.ok) {
        preview.textContent = result.error || '예상 일수를 확인하지 못했습니다. 날짜를 다시 선택해주세요.';
        preview.classList.add('is-warning');
        return;
      }
      const lines = [
        `이번 신청 ${result.days}일 · 연차 ${result.deduction}일 차감 예정`,
        `${result.startDate} 기준 신청 가능 ${result.available}일 · ${result.editing ? '변경' : '신청'} 후 추가 신청 가능 ${result.after}일`
      ];
      if (result.shortage > 0) lines.push(`연차가 ${result.shortage}일 부족합니다. 날짜 또는 휴가 종류를 확인해주세요.`);
      else if (!result.deduction) lines.push('선택한 휴가는 연차를 차감하지 않습니다.');
      if (result.editing) lines.push('수정 중인 신청의 기존 차감·대기 일수를 제외한 예상입니다.');
      lines.push('시작일까지 발생할 연차를 반영한 예상이며, 최종 가능 여부는 저장 시 확인합니다.');
      preview.textContent = lines.join('\n');
      preview.classList.toggle('is-warning', result.shortage > 0);
    } catch (error) {
      if (error.name !== 'AbortError' && leavePreviewRequests.get(preview) === request && preview.isConnected) {
        preview.textContent = '예상 일수를 불러오지 못했습니다. 날짜를 다시 선택하거나 새로고침해주세요. 최종 가능 여부는 저장 시 확인합니다.';
        preview.classList.add('is-warning');
      }
    } finally {
      if (leavePreviewRequests.get(preview) === request) preview.removeAttribute('aria-busy');
    }
  }, 180);
}

document.querySelectorAll('.leave-preview').forEach(preview => updateLeavePreview(preview.closest('form')));
document.addEventListener('input', event => updateLeavePreview(event.target.form));
document.getElementById('menu-toggle')?.addEventListener('click', function () {
  const open = document.getElementById('sidebar').classList.toggle('open');
  this.setAttribute('aria-expanded', String(open));
});
document.addEventListener('submit', event => {
  const form = event.target;
  if (form.matches('form[data-confirm]') && !confirm(form.dataset.confirm)) event.preventDefault();
});
document.addEventListener('change', event => {
  if (event.target.matches('select[name="leaveAccrual"]')) updateManualLeave(event.target.form);
  updateLeavePreview(event.target.form);
  if (event.target.matches('input[name="receipt"]') && event.target.files.length) {
    const flag = event.target.form?.querySelector('select[name="hasReceipt"]');
    if (flag) flag.value = '1';
  }
});
// Render existing PHP forms in an accessible dialog; native page links remain the fallback.
document.addEventListener('click', async event => {
  const link = event.target.closest('a[data-dialog]');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  try {
    const response = await fetch(link.href, {credentials: 'same-origin'});
    if (!response.ok) throw new Error('Page unavailable');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const selector = link.dataset.dialog === 'summary' ? '.employee-layout > section:last-child' : link.dataset.dialog === 'edit' ? '.employee-layout > section:first-child' : '.content .formpanel';
    const panel = page.querySelector(selector);
    if (!panel) throw new Error('Session or page changed');
    const dialog = document.createElement('dialog');
    dialog.className = 'php-dialog ' + (link.dataset.dialog === 'summary' ? 'summary-dialog' : '');
    const heading = document.createElement('div'); heading.className = 'dialog-heading';
    const title = document.createElement('h2');
    title.textContent = link.dataset.dialog === 'summary' ? '직원 연차 상세' : page.querySelector('.title h1')?.textContent || '정보 수정';
    const employee = page.querySelector('.employee-identity');
    if (employee) title.textContent = `${employee.dataset.employeeName}(${employee.dataset.loginId})`;
    title.id = 'php-dialog-title';
    dialog.setAttribute('aria-labelledby', title.id);
    const close = document.createElement('button');close.type = 'button';close.className = 'link';close.textContent = '닫기';close.addEventListener('click', () => dialog.close());
    heading.append(title, close);dialog.append(heading, panel);
    panel.querySelectorAll('form').forEach(form => form.action = link.href);
    document.body.append(dialog);
    dialog.addEventListener('close', () => {dialog.remove();link.focus();}, {once:true});
    dialog.querySelectorAll('select[name="leaveAccrual"]').forEach(select => updateManualLeave(select.form));
    dialog.showModal();
    dialog.querySelectorAll('.leave-preview').forEach(preview => updateLeavePreview(preview.closest('form')));
  } catch {
    window.location.assign(link.href);
  }
});
// PHP renders every page. Cache only public app assets, never authenticated HTML or receipts.
if ('serviceWorker' in navigator && window.isSecureContext) navigator.serviceWorker.register('./sw.js').catch(() => {});
