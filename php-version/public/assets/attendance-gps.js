'use strict';
document.querySelectorAll('.attendance-punch-form').forEach(form => {
  const button = form.querySelector('button');
  const status = form.querySelector('.attendance-gps-status');
  const mode = form.elements.namedItem('punchMode');
  const reason = form.elements.namedItem('tripReason');
  const method = form.elements.namedItem('verificationMethod');
  // iPadOS can use a desktop user agent; keep touch-capable Macs on the GPS flow.
  if (method && navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) {
    method.value = 'gps';
    form.querySelector('.attendance-manual-fields').hidden = true;
    form.querySelector('.attendance-mobile-fields').hidden = false;
    form.elements.namedItem('entryTime').disabled = true;
    form.elements.namedItem('confirmSave').disabled = true;
  }
  if (method?.value === 'manual') {
    form.querySelector('.attendance-mobile-fields').querySelectorAll('input,select').forEach(input => { input.disabled = true; });
    let submitted = false;
    form.addEventListener('submit', event => {
      if (submitted) { event.preventDefault(); return; }
      submitted = true;
      button.disabled = true;
      button.textContent = '저장 중…';
    });
    return;
  }
  let busy = false;
  const updateMode = () => {
    reason.closest('label').hidden = mode.value !== 'business_trip';
    reason.required = mode.value === 'business_trip';
  };
  mode.addEventListener('change', updateMode);
  updateMode();
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    if (!navigator.onLine) {
      status.textContent = '인터넷 연결 후 다시 시도해주세요. 오프라인에서는 출퇴근을 저장하지 않습니다.';
      return;
    }
    busy = true;
    const label = button.textContent;
    button.disabled = true;
    button.textContent = '위치 확인 중…';
    mode.disabled = true;
    reason.readOnly = true;
    status.textContent = '현재 위치를 확인하고 있습니다.';
    const fail = message => {
      busy = false;
      button.disabled = false;
      button.textContent = label;
      mode.disabled = false;
      reason.readOnly = false;
      status.textContent = message;
    };
    const setMethod = value => {
      let input = form.elements.namedItem('verificationMethod');
      if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'verificationMethod'; form.append(input); }
      input.value = value;
    };
    setMethod('gps');
    if (!window.isSecureContext || !navigator.geolocation) {
      fail('위치를 확인할 수 없는 브라우저입니다. HTTPS 주소에서 다시 접속해주세요.');
      return;
    }
    if (!navigator.onLine) { fail('인터넷 연결 후 다시 시도해주세요.'); return; }
    button.textContent = '위치 확인 중…';
    status.textContent = '현재 위치를 확인합니다. 위치 권한을 허용해주세요.';
    let attempts = 0;
    const locate = () => {
      attempts += 1;
      navigator.geolocation.getCurrentPosition(position => {
      const { latitude, longitude, accuracy } = position.coords;
      const age = Date.now() - position.timestamp;
      if (![latitude, longitude, accuracy, position.timestamp].every(Number.isFinite) || accuracy <= 0) {
        fail('기기에서 정확한 위치 정보를 받지 못했습니다. 위치 설정을 확인해주세요.');
        return;
      }
      if (accuracy > 100) {
        const meters = Math.round(accuracy).toLocaleString('ko-KR');
        if (attempts < 2 && navigator.onLine) {
          status.textContent = `현재 위치 오차가 약 ${meters}m입니다. 한 번 더 확인하고 있어요.`;
          locate();
          return;
        }
        fail(`위치가 불분명해 출근 장소를 확인할 수 없어요. 현재 오차 약 ${meters}m · 허용 100m`);
        return;
      }
      if (age > 120000 || age < -30000) {
        fail('방금 측정한 위치가 아닙니다. 다시 위치를 확인해주세요.');
        return;
      }
      if (!navigator.onLine) { fail('연결이 끊겼습니다. 온라인 상태에서 다시 시도해주세요.'); return; }
      for (const [name, value] of Object.entries({latitude, longitude, accuracy, capturedAt: position.timestamp})) {
        let input = form.elements.namedItem(name);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.append(input); }
        input.value = String(value);
      }
      mode.disabled = false;
      status.textContent = '위치 확인 완료. 회사 반경과 출퇴근 상태를 확인하고 저장합니다.';
      button.textContent = '기록 저장 중…';
      // PHP independently validates the reported position; no offline queue or local GPS storage.
      HTMLFormElement.prototype.submit.call(form);
    }, error => {
      fail(error.code === 1 ? '위치 권한이 거부되었습니다. 브라우저의 사이트 설정에서 위치를 허용한 뒤 다시 눌러주세요.'
        : error.code === 3 ? '위치 확인 시간이 초과되었습니다. 위치 서비스를 켜고 다시 시도해주세요.'
          : '현재 위치를 확인하지 못했습니다. 기기의 위치 서비스를 켜고 다시 시도해주세요.');
      }, {enableHighAccuracy: true, maximumAge: 0, timeout: 15000});
    };
    locate();
  });
});
