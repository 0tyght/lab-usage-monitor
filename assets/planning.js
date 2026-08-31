(() => {
  "use strict";
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const thaiDate = (date) => new Intl.DateTimeFormat("th-TH", {day:"numeric", month:"long", year:"numeric", timeZone:"Asia/Bangkok"}).format(new Date(`${date}T12:00:00+07:00`));

  function initTermPresets() {
    const form = q("[data-term-form]");
    if (!form) return;
    const presets = JSON.parse(q("[data-term-presets]").textContent);
    const existing = JSON.parse(q("[data-existing-terms]").textContent);
    const year = q('[name="academic_year"]', form);
    const semester = q('[name="semester"]', form);
    const start = q('[name="term_starts_on"]', form);
    const end = q('[name="term_ends_on"]', form);
    const confirm = q('[name="dates_confirmed"]', form);
    const source = q("[data-term-source]", form);
    const submit = q('[type="submit"]', form);
    const exists = () => existing.some((t) => String(t.year) === year.value && t.semester === semester.value);
    const describe = () => {
      const preset = presets[year.value];
      const dates = preset?.terms[semester.value];
      const matches = dates && dates.start === start.value && dates.end === end.value;
      confirm.required = !matches;
      if (matches) {
        confirm.setCustomValidity("");
        confirm.removeAttribute("aria-invalid");
        const error = document.getElementById("error-dates_confirmed");
        if (error) { error.hidden = true; error.textContent = ""; }
      }
      source.replaceChildren();
      if (preset) {
        const link = node("a", preset.source_label);
        link.href = preset.source;
        link.target = "_blank";
        link.rel = "noopener";
        source.append(link, node("span", matches ? " · เติมช่วงวันที่ตามประกาศแล้ว" : " · วันที่ปรับเอง ต้องยืนยันก่อนบันทึก"));
      } else source.textContent = "ยังไม่มีวันที่อ้างอิงที่ยืนยันแล้วสำหรับปีนี้ กรุณาตรวจประกาศของมหาวิทยาลัยและกรอกวันที่เอง";
    };
    const update = (changed = false) => {
      qa("option", semester).forEach((option) => {
        option.disabled = existing.some((t) => String(t.year) === year.value && t.semester === option.value);
      });
      if (semester.selectedOptions[0]?.disabled && !q("[data-term-error-summary]")) {
        const available = qa("option", semester).find((option) => !option.disabled);
        if (available) { semester.value = available.value; changed = true; }
      }
      if (changed) {
        const dates = presets[year.value]?.terms[semester.value];
        start.value = dates?.start || "";
        end.value = dates?.end || "";
        confirm.checked = false;
        [start, end, confirm].forEach((field) => {
          field.setCustomValidity("");
          field.removeAttribute("aria-invalid");
          const error = document.getElementById(`error-${field.name}`);
          error.hidden = true;
          error.textContent = "";
        });
      }
      q("[data-term-code]", form).textContent = `${year.value}/${semester.value === "summer" ? "3" : semester.value}`;
      q("[data-term-exists]", form).textContent = exists() ? "บันทึกภาคนี้แล้ว กรุณาเลือกปีหรือภาคอื่น" : "ระบบบันทึกได้ปีละ 3 ภาค ไม่ซ้ำกัน";
      submit.disabled = exists();
      describe();
    };
    year.addEventListener("change", () => update(true));
    semester.addEventListener("change", () => update(true));
    [start, end].forEach((field) => field.addEventListener("input", describe));
    form.addEventListener("submit", (event) => {
      if (exists()) { event.preventDefault(); event.stopPropagation(); year.focus(); }
    });
    update();
  }

  function initCalendar() {
    const dialog = q("#calendar-day-dialog");
    if (!dialog || !dialog.showModal) return;
    const events = JSON.parse(q("[data-calendar-events]").textContent);
    const context = JSON.parse(q("[data-calendar-context]").textContent);
    const room = q('[name="day_room_id"]', dialog);
    const form = q("[data-day-filter]", dialog);
    const content = q("[data-day-content]", dialog);
    let date = context.date;
    let opener;
    const detailUrl = (event) => event.class_id ? `?page=class-detail&id=${event.class_id}` : `?page=schedule&term_id=${event.term_id}&room_id=${event.room_id}&week=${event.date}&selected=${event.schedule_id}`;
    const minutes = (time) => { const [h, m] = time.split(":").map(Number); return h*60+m; };

    const render = () => {
      const rows = events.filter((event) => event.date === date && (!Number(room.value) || event.room_id === Number(room.value)));
      q("#calendar-day-title").textContent = `การใช้ห้อง · ${thaiDate(date)}`;
      q('[name="date"]', form).value = date;
      q("[data-day-count]", dialog).textContent = `${rows.length} รายการ · เวลาไทย · แผนและคลาสแสดงแยกประเภท`;
      const reportQuery = new URLSearchParams({page:"reports", date_from:date, date_to:date, room_id:room.value, term_id:context.term_id, source:context.source});
      q("[data-day-report]", dialog).href = `?${reportQuery}`;
      q('[data-day-once]', dialog).href = `?${new URLSearchParams({page:'calendar',month:date.slice(0,7),new_once:'1',once_date:date,room_id:room.value})}`;
      content.replaceChildren();
      if (!rows.length) {
        const empty = node("div", undefined, "empty-state");
        empty.append(node("strong", "ไม่พบรายการในห้องและวันที่เลือก"), node("span", "ลองเลือกทุกห้อง หรือเลือกวันอื่น"));
        content.append(empty);
        return;
      }
      const rangeStart = Math.min(8*60, ...rows.map((r) => Math.floor(minutes(r.start_time)/60)*60));
      const rangeEnd = Math.max(20*60, ...rows.map((r) => Math.ceil(minutes(r.end_time)/60)*60));
      const span = rangeEnd-rangeStart;
      const timeline = node("div", undefined, "day-timeline-scroll");
      timeline.tabIndex = 0;
      timeline.setAttribute("role", "region");
      timeline.setAttribute("aria-label", "ตารางเวลาใช้ห้อง เลื่อนแนวนอนเพื่อดูครบ");
      const grid = node("div", undefined, "day-timeline");
      const scale = node("div", undefined, "day-time-scale");
      for (let minute=rangeStart; minute<=rangeEnd; minute+=60) {
        const tick = node("span", `${String(minute/60).padStart(2,"0")}:00`);
        tick.style.left = `${(minute-rangeStart)/span*100}%`;
        scale.append(tick);
      }
      const heading = node("div", undefined, "day-timeline-row");
      heading.append(node("strong", "ห้อง / เวลา"), scale);
      grid.append(heading);
      for (const code of [...new Set(rows.map((r) => r.room_code))]) {
        const line = node("div", undefined, "day-timeline-row");
        const track = node("div", undefined, "day-time-track");
        const lanes = [];
        rows.filter((r) => r.room_code === code).forEach((event) => {
          const begin = minutes(event.start_time);
          const finish = minutes(event.end_time);
          let lane = lanes.findIndex((until) => until <= begin);
          if (lane < 0) lane = lanes.length;
          lanes[lane] = finish;
          const block = node("a", `${event.start_time} ${event.course_code}`, `day-time-block ${event.source === "schedule" ? "is-planned" : ""}`);
          block.href = detailUrl(event);
          block.title = `${event.start_time}–${event.end_time} ${event.course_name} · ${event.source_label}`;
          block.setAttribute("aria-label", block.title);
          block.style.left = `${(begin-rangeStart)/span*100}%`;
          block.style.width = `${(finish-begin)/span*100}%`;
          block.style.top = `${8+lane*48}px`;
          track.append(block);
        });
        track.style.minHeight = `${16+lanes.length*48}px`;
        line.append(node("strong", code), track);
        grid.append(line);
      }
      timeline.append(grid);
      content.append(timeline);
      const list = node("div", undefined, "day-event-list");
      rows.forEach((event) => {
        const row = node("article", undefined, "day-event");
        const detail = node("div");
        detail.append(node("strong", `${event.course_code} · ${event.course_name}`), node("p", `${event.room_code} · ${event.lecturer_name}${event.section ? ` · กลุ่ม ${event.section}` : ""}`), node("span", event.source_label, `event-type ${event.source === "schedule" ? "is-planned" : ""}`));
        const link = node("a", event.class_id ? "เปิดคลาส" : "ดูตาราง", "button button--secondary");
        link.href = detailUrl(event);
        row.append(node("strong", `${event.start_time}–${event.end_time}`), detail, link);
        list.append(row);
      });
      content.append(list);
    };
    const open = (day, trigger) => {
      date = day;
      opener = trigger || q(`[data-calendar-day="${day}"]`);
      render();
      dialog.showModal();
      document.body.classList.add("term-dialog-open");
      room.focus();
    };
    qa("[data-calendar-day]").forEach((link) => link.addEventListener("click", (event) => {
      event.preventDefault();
      room.value = String(context.room_id);
      open(link.dataset.calendarDay, link);
    }));
    qa("[data-close-day]", dialog).forEach((link) => link.addEventListener("click", (event) => { event.preventDefault(); dialog.close(); }));
    dialog.addEventListener("keydown", (event) => {
      if (event.key === "Escape") { event.preventDefault(); dialog.close(); }
      if (event.key === "Tab") {
        const controls = qa('a[href],select,button,[tabindex="0"]', dialog);
        if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus(); }
        if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus(); }
      }
    });
    dialog.addEventListener("close", () => {
      document.body.classList.remove("term-dialog-open");
      opener?.focus({preventScroll:true});
      const url = new URL(location.href);
      url.searchParams.delete("date"); url.searchParams.delete("day_room_id");
      history.replaceState(null,"",url);
    });
    room.addEventListener("change", render);
    form.addEventListener("submit", (event) => { event.preventDefault(); event.stopPropagation(); render(); });
    if (dialog.open && date) { dialog.removeAttribute("open"); open(date); }

    const tooltip = node("div", undefined, "calendar-tooltip");
    tooltip.id = "calendar-day-tooltip";
    tooltip.setAttribute("role", "tooltip");
    tooltip.hidden = true;
    document.body.append(tooltip);
    let hideTimer;
    let tipTarget;
    const hideTip = () => { tooltip.hidden = true; tipTarget?.removeAttribute("aria-describedby"); };
    qa("[data-day-tooltip]").forEach((link) => {
      const show = () => {
        clearTimeout(hideTimer);
        tipTarget?.removeAttribute("aria-describedby");
        tipTarget = link;
        tooltip.textContent = link.dataset.dayTooltip;
        tooltip.hidden = false;
        link.setAttribute("aria-describedby", tooltip.id);
        const rect = link.getBoundingClientRect();
        tooltip.style.left = `${Math.max(8, Math.min(rect.left, innerWidth-tooltip.offsetWidth-8))}px`;
        tooltip.style.top = `${Math.max(8, Math.min(rect.bottom+8, innerHeight-tooltip.offsetHeight-8))}px`;
      };
      link.addEventListener("mouseenter", show);
      link.addEventListener("focus", show);
      link.addEventListener("mouseleave", () => { hideTimer = setTimeout(hideTip, 150); });
      link.addEventListener("blur", hideTip);
      link.addEventListener("click", hideTip);
      link.addEventListener("keydown", (event) => { if (event.key === "Escape") hideTip(); });
    });
    tooltip.addEventListener("mouseenter", () => clearTimeout(hideTimer));
    tooltip.addEventListener("mouseleave", hideTip);
    window.addEventListener("scroll", hideTip, true);
  }

  function init() {
    initTermPresets();
    initCalendar();
    const filters = q('[data-report-filters]');
    if (filters) {
      const advanced = q('.report-options', filters);
      if (matchMedia('(max-width: 640px)').matches) advanced.open = false;
      // Reveal invalid controls before native browser validation focuses them.
      filters.addEventListener('invalid', () => { advanced.open = true; }, true);
      const exports = qa('.usage-report .schedule-header-actions a');
      const markPending = () => {
        q('[data-report-pending]').hidden = false;
        exports.forEach((link) => link.setAttribute('aria-disabled', 'true'));
      };
      filters.addEventListener('input', markPending);
      filters.addEventListener('change', markPending);
      exports.forEach((link) => link.addEventListener('click', (event) => {
        if (link.getAttribute('aria-disabled') === 'true') { event.preventDefault(); q('[type="submit"]', filters).focus(); }
      }));
    }
    q("[data-print-report]")?.addEventListener("click", () => window.print());
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, {once:true});
  else init();
})();
