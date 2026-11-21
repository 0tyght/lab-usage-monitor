(() => {
  'use strict';
  const root = document.querySelector('#room-smart');
  const panel = document.querySelector('.room-flow__content');
  const tabs = document.querySelector('.room-flow__tabs');
  if (!root || !panel || !tabs || root.dataset.static === 'yes') return;
  const input = root.querySelector('#room-smart-code');
  const status = root.querySelector('#room-smart-status');
  const retry = root.querySelector('#room-smart-retry');
  const exitForm = root.querySelector('#room-smart-exit');
  const entryCode = panel.querySelector('input[name="student_code"], input[name="person_code"]');
  const role = panel.querySelector('select[name="person_role"]');
  const csrf = exitForm.querySelector('input[name="csrf_token"]').value;
  const roomToken = root.dataset.roomToken;
  let timer;
  let sequence = 0;
  let controller;

  tabs.hidden = true;
  root.hidden = false;
  panel.hidden = true;
  if (entryCode) entryCode.closest('label').hidden = true;
  if (role) {
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'เลือกประเภทผู้เข้าใช้';
    role.prepend(empty);
    role.required = true;
  }
  const clear = () => {
    panel.hidden = true;
    exitForm.hidden = true;
    retry.hidden = true;
    if (entryCode) entryCode.value = '';
  };
  async function lookup() {
    const code = input.value.trim().toUpperCase();
    const request = ++sequence;
    if (controller) controller.abort();
    clear();
    if (!/^[A-Z0-9._-]{4,30}$/.test(code)) {
      status.textContent = code ? 'กรอกรหัสอย่างน้อย 4 ตัว โดยใช้ตัวอักษรอังกฤษหรือตัวเลข' : 'กรอกรหัสเพื่อเริ่มต้น';
      return;
    }
    controller = new AbortController();
    status.textContent = 'กำลังตรวจสอบสถานะ…';
    const data = new URLSearchParams({csrf_token:csrf, room_token:roomToken, person_code:code});
    try {
      const response = await fetch('?api=room-lookup', {method:'POST', body:data, credentials:'same-origin', cache:'no-store', signal:controller.signal});
      if (!response.ok) throw new Error('lookup');
      const result = await response.json();
      if (request !== sequence || input.value.trim().toUpperCase() !== code) return;
      if (!result.ok) throw new Error('lookup');
      if (result.active) {
        status.textContent = 'พบรายการที่กำลังใช้ห้อง กดบันทึกเวลาออกเมื่อออกจากห้อง';
        exitForm.querySelector('input[name="person_code"]').value = code;
        exitForm.hidden = false;
      } else {
        status.textContent = 'ไม่พบรายการที่กำลังใช้ห้อง กรอกข้อมูลเพื่อบันทึกเวลาเข้า';
        if (entryCode) entryCode.value = code;
        if (role) {
          role.value = result.role || '';
          role.closest('label').hidden = Boolean(result.role);
        }
        panel.hidden = false;
      }
    } catch (error) {
      if (error.name === 'AbortError' || request !== sequence) return;
      status.textContent = 'ยังตรวจสอบสถานะไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองอีกครั้ง';
      retry.hidden = false;
    }
  }
  input.addEventListener('input', () => {
    clearTimeout(timer);
    ++sequence;
    if (controller) controller.abort();
    clear();
    status.textContent = input.value ? 'รอตรวจสอบรหัส…' : 'กรอกรหัสเพื่อเริ่มต้น';
    timer = setTimeout(lookup, 600);
  });
  retry.addEventListener('click', lookup);
})();
