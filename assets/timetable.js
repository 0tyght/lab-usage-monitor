(() => {
  'use strict';
  function init() {
    const page = document.querySelector('.schedule-page');
    if (!page) return;
    const q = (selector, root = page) => root.querySelector(selector);
    const qa = (selector, root = page) => [...root.querySelectorAll(selector)];
    const editor = q('#schedule-editor');
    const form = q('[data-schedule-form]');
    const filter = q('[data-timetable-filter]');
    let filtersChanged = false;
    const announce = (text) => window.LUMS?.announce(text);

    const openers = new Map();
    function openDialog(dialog, opener) {
      if (!dialog || dialog.open || !dialog.showModal) return;
      openers.set(dialog, opener);
      dialog.showModal();
      document.body.classList.add('term-dialog-open');
      (q('[data-schedule-errors]', dialog) || q('select,input:not([type="hidden"])', dialog) || q('button,a',dialog))?.focus();
    }
    qa('[data-schedule-overlay]').forEach((dialog) => {
      qa('[data-close-schedule]', dialog).forEach((close) => close.addEventListener('click', (event) => {
        if (!dialog.showModal) return;
        event.preventDefault();
        if (!q('form[data-submitting="true"]', dialog)) dialog.close();
      }));
      dialog.addEventListener('cancel', (event) => {
        if (q('form[data-submitting="true"]', dialog)) event.preventDefault();
      });
      dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          if (!q('form[data-submitting="true"]',dialog)) dialog.close();
        }
        if (event.key === 'Tab') {
          const controls = qa('a[href],button:not(:disabled),input:not([type="hidden"]):not(:disabled),select:not(:disabled),textarea:not(:disabled)',dialog).filter((control) => control.getClientRects().length);
          const first = controls[0], last = controls[controls.length-1];
          if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last?.focus(); }
          else if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first?.focus(); }
        }
      });
      dialog.addEventListener('close', () => {
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('term-dialog-open');
        const url = new URL(location.href);
        ['selected','new_schedule','import'].forEach((name) => url.searchParams.delete(name));
        history.replaceState(null, '', url);
        openers.get(dialog)?.focus({preventScroll:true});
      });
      if (dialog.open && dialog.showModal) {
        dialog.removeAttribute('open');
        openDialog(dialog, q('.schedule-block.is-selected') || q(`[data-open-schedule="${dialog.id}"]`));
      }
    });
    qa('[data-open-schedule]').forEach((link) => link.addEventListener('click', (event) => {
      const dialog = document.getElementById(link.dataset.openSchedule);
      if (!dialog?.showModal) return;
      event.preventDefault();
      if (filtersChanged && dialog === editor) { q('button[type="submit"]',filter)?.focus(); announce('กดแสดงตารางก่อนเลือกเวลา'); return; }
      openDialog(dialog, link);
    }));

    if (!form) return;
    const field = (name) => form.elements.namedItem(name);
    const slots = qa('[data-slot-start]');
    const bar = q('[data-schedule-selection-bar]');
    let anchor = null;
    let range = null;
    const toMinutes = (time) => { const [h,m]=time.split(':').map(Number); return h*60+m; };
    const clock = (minutes) => `${String(Math.floor(minutes/60)).padStart(2,'0')}:${String(minutes%60).padStart(2,'0')}`;
    const clearRange = () => {
      anchor = null; range = null; bar.hidden = true;
      slots.forEach((slot) => { slot.classList.remove('is-selected'); slot.setAttribute('aria-pressed','false'); });
    };
    slots.forEach((slot) => slot.addEventListener('click', () => {
      if (filtersChanged) return;
      const day = slot.dataset.slotDay;
      const minute = toMinutes(slot.dataset.slotStart);
      if (!anchor || anchor.day !== day) {
        anchor = {day, minute};
        range = {day, start:minute, end:Math.min(1439,minute+60)};
      } else {
        range = {day, start:Math.min(minute,anchor.minute), end:Math.min(1439,Math.max(minute,anchor.minute)+60)};
        anchor = null;
      }
      slots.forEach((item) => {
        const selected = item.dataset.slotDay === day && toMinutes(item.dataset.slotStart) >= range.start && toMinutes(item.dataset.slotStart) < range.end;
        item.classList.toggle('is-selected',selected); item.setAttribute('aria-pressed',String(selected));
      });
      const dayLabel = slot.closest('.schedule-day-row').querySelector('.schedule-day-heading strong').textContent;
      q('[data-selection-summary]').textContent = `${dayLabel} · ${clock(range.start)}–${clock(range.end)} · ${(range.end-range.start)/60} ชั่วโมง`;
      q('[data-selection-hint]').textContent = anchor ? 'เลือกช่องสุดท้ายเพื่อขยายเวลา หรือกดใช้ช่วงเวลานี้สำหรับ 1 ชั่วโมง' : 'พร้อมเพิ่มตารางที่ทำซ้ำทุกสัปดาห์ในช่วงนี้';
      bar.hidden = false;
      announce(q('[data-selection-summary]').textContent);
    }));
    q('[data-clear-schedule-range]').addEventListener('click', clearRange);
    q('[data-use-schedule-range]').addEventListener('click', (event) => {
      if (!range || filtersChanged) return;
      field('day_of_week').value = range.day;
      field('starts_time').value = clock(range.start);
      field('ends_time').value = clock(range.end);
      field('room_id').value = filter.elements.namedItem('room_id').value;
      openDialog(editor, event.currentTarget);
      field('course_code').focus();
      queuePreview();
    });
    filter.addEventListener('input', () => {
      filtersChanged = true; clearRange();
      q('[data-timetable-stale]').hidden = false;
      slots.forEach((slot) => slot.disabled = true);
    });
    field('term_id').addEventListener('change', () => {
      const option = field('term_id').selectedOptions[0];
      if (option?.dataset.start) field('active_from').value = option.dataset.start;
      if (option?.dataset.end) field('active_until').value = option.dataset.end;
    });

    const preview = q('[data-schedule-preview]');
    const previewText = q('[data-schedule-preview-text]');
    const retry = q('[data-retry-schedule-preview]');
    const submit = q('button[type="submit"]',form);
    let sequence = 0, controller, timer, validated = '';
    const payload = () => new URLSearchParams(new FormData(form));
    const complete = () => qa('[required]',form).every((input) => input.value.trim() && input.checkValidity());
    function showPreview(text, state = '') {
      previewText.textContent = text;
      preview.classList.toggle('is-error',state === 'error');
      preview.classList.toggle('is-valid',state === 'valid');
    }
    async function checkPreview() {
      clearTimeout(timer);
      controller?.abort();
      const serial = ++sequence;
      validated = ''; submit.disabled = true; retry.hidden = true;
      preview.setAttribute('aria-busy','false');
      if (!complete()) { showPreview('กรอกข้อมูลให้ครบ ระบบจะตรวจเวลาซ้ำก่อนบันทึก'); return false; }
      const body = payload();
      controller = new AbortController();
      showPreview('กำลังตรวจห้องและผู้สอนตลอดช่วงที่ใช้ตาราง…');
      preview.setAttribute('aria-busy','true');
      try {
        const response = await fetch('?api=schedule-preview',{method:'POST',body,credentials:'same-origin',signal:controller.signal});
        const result = await response.json();
        if (serial !== sequence || body.toString() !== payload().toString()) return false;
        if (!response.ok) throw new Error(result.message || 'ตรวจตารางไม่สำเร็จ กรุณาลองอีกครั้ง');
        showPreview(result.message,result.ok ? 'valid' : 'error');
        if (result.ok) { validated = body.toString(); submit.disabled = false; }
        return result.ok;
      } catch (error) {
        if (error.name === 'AbortError' || serial !== sequence) return false;
        showPreview(error.message || 'เชื่อมต่อไม่สำเร็จ กรุณาลองอีกครั้ง','error');
        retry.hidden = false;
        return false;
      } finally {
        if (serial === sequence) preview.setAttribute('aria-busy','false');
      }
    }
    function queuePreview() {
      controller?.abort(); sequence++; validated = ''; submit.disabled = true;
      preview.setAttribute('aria-busy','false');
      clearTimeout(timer);
      showPreview(complete() ? 'รอตรวจสอบช่วงเวลาที่เปลี่ยน…' : 'กรอกข้อมูลให้ครบ ระบบจะตรวจเวลาซ้ำก่อนบันทึก');
      timer = setTimeout(checkPreview,350);
    }
    form.addEventListener('input', queuePreview);
    form.addEventListener('change', queuePreview);
    retry.addEventListener('click',checkPreview);
    form.addEventListener('submit', async (event) => {
      if (validated && validated === payload().toString()) return;
      event.preventDefault();
      if (!form.reportValidity()) return;
      if (await checkPreview()) form.requestSubmit(submit);
    });
    // The server repeats validation on save; this preview is advisory, not a lock.
    checkPreview();
  }
  if (document.readyState === 'complete') init();
  else document.addEventListener('DOMContentLoaded',init,{once:true});
})();
