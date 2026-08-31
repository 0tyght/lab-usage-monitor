(() => {
  'use strict';
  function init() {
    const page = document.querySelector('.schedule-page');
    if (!page) return;
    const q = (selector, root = page) => root.querySelector(selector);
    const qa = (selector, root = page) => [...root.querySelectorAll(selector)];
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
      openDialog(dialog, link);
    }));

    if (!filter) return;
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
      range.date = slot.closest('.schedule-day-row').dataset.scheduleDate;
      const dayLabel = slot.closest('.schedule-day-row').querySelector('.schedule-day-heading strong').textContent;
      q('[data-selection-summary]').textContent = `${dayLabel} · ${clock(range.start)}–${clock(range.end)} · ${(range.end-range.start)/60} ชั่วโมง`;
      q('[data-selection-hint]').textContent = anchor ? 'เลือกช่องสุดท้ายเพื่อขยายเวลา หรือกดใช้ช่วงเวลานี้สำหรับ 1 ชั่วโมง' : 'เลือกสร้างแบบครั้งเดียวหรือทั้งภาคเรียนในขั้นตอนถัดไป';
      bar.hidden = false;
      announce(q('[data-selection-summary]').textContent);
    }));
    q('[data-clear-schedule-range]').addEventListener('click', clearRange);
    q('[data-use-schedule-range]').addEventListener('click', (event) => {
      if (!range || filtersChanged) return;
      document.dispatchEvent(new CustomEvent('lums:open-class', {detail:{
        opener:event.currentTarget, day_of_week:range.day, class_date:range.date,
        starts_time:clock(range.start), ends_time:clock(range.end),
        room_id:filter.elements.namedItem('room_id').value,
        term_id:filter.elements.namedItem('term_id').value,
      }}));
    });
    filter.addEventListener('input', () => {
      filtersChanged = true; clearRange();
      q('[data-timetable-stale]').hidden = false;
      slots.forEach((slot) => slot.disabled = true);
    });

  }
  if (document.readyState === 'complete') init();
  else document.addEventListener('DOMContentLoaded',init,{once:true});
})();
