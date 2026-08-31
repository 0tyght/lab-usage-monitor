(() => {
  "use strict";

  const q = (selector, scope = document) => scope.querySelector(selector);
  const qa = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  const iconPaths = {
    "book-open": '<path d="M12 7v14M3 3h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5v16h-5a4 4 0 0 0-4 2 4 4 0 0 0-4-2H3Z"/>',
    "layout-dashboard": '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    "scan-line": '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8h10M7 12h10M7 16h10"/>',
    "door-open": '<path d="M13 4h6a2 2 0 0 1 2 2v14M2 20h3M13 20h9M10 12v.01"/><path d="M13 4.56v15.88a1 1 0 0 1-1.24.97l-6-1.5A1 1 0 0 1 5 18.94V6.06a1 1 0 0 1 .76-.97l6-1.5A1 1 0 0 1 13 4.56Z"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    "chart-no-axes-combined": '<path d="M4 20V10M10 20V4M16 20v-6M22 20H2M4 10l6-6 6 10 6-6"/>',
    "log-out": '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    x: '<path d="m18 6-12 12M6 6l12 12"/>',
    "chevron-right": '<path d="m9 18 6-6-6-6"/>',
    "chevron-left": '<path d="m15 18-6-6 6-6"/>',
    "chevron-down": '<path d="m6 9 6 6 6-6"/>',
    "calendar-days": '<path d="M8 2v4M16 2v4M3 10h18"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
    "calendar-x": '<path d="M8 2v4M16 2v4M3 10h18"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="m9.5 14.5 5 5M14.5 14.5l-5 5"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    "mouse-pointer-click": '<path d="m9 9 5 12 2-5 5-2Z"/><path d="M7.2 2.2 8 5.1M3.4 5.4 6 6.8M2 10l3 .2M12.6 2.5 11.2 5"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    camera: '<path d="M14.5 4 16 6h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3l1.5-2z"/><circle cx="12" cy="13" r="3"/>',
    "circle-check-big": '<path d="M21.8 10A10 10 0 1 1 17 3.3"/><path d="m9 11 3 3L22 4"/>',
    "circle-alert": '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    "qr-code": '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3M21 21v.01M12 7v3a2 2 0 0 1-2 2H7M3 12h.01M12 3h.01M12 16v.01M16 12h1M21 12v.01M12 21v-1"/>',
    plus: '<path d="M5 12h14M12 5v14"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    inbox: '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="m5.45 5.11-3.45 6.9V19a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6.99l-3.45-6.9A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    eye: '<path d="M2.1 12a10.5 10.5 0 0 1 19.8 0 10.5 10.5 0 0 1-19.8 0"/><circle cx="12" cy="12" r="3"/>',
    "eye-off": '<path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10.7 10.7 0 0 1 12 4c5 0 8.5 4 9.9 8a12.7 12.7 0 0 1-2 3.5M6.6 6.6A13.5 13.5 0 0 0 2.1 12c1.4 4 4.9 8 9.9 8a10.4 10.4 0 0 0 5.4-1.5"/>'
  };

  function renderIcons(scope = document) {
    qa("[data-icon]", scope).forEach((node) => {
      const name = node.dataset.icon;
      const paths = iconPaths[name];
      if (!paths || node.querySelector("svg")) return;
      node.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">${paths}</svg>`;
    });
  }

  function ensureLiveRegion() {
    let region = q("#lums-live-region");
    if (!region) {
      region = document.createElement("div");
      region.id = "lums-live-region";
      region.className = "sr-only";
      region.setAttribute("aria-live", "polite");
      region.setAttribute("aria-atomic", "true");
      document.body.append(region);
    }
    return region;
  }

  function announce(message) {
    const region = ensureLiveRegion();
    region.textContent = "";
    window.setTimeout(() => {
      region.textContent = message;
    }, 30);
  }

  function initMobileNavigation() {
    const toggle = q("[data-mobile-nav]");
    const sidebar = q("#sidebar");
    const scrim = q("[data-nav-scrim]");
    if (!toggle || !sidebar || !scrim) return;

    const desktop = window.matchMedia("(min-width: 64rem)");
    let returnFocus = null;

    const syncClosedState = () => {
      if (desktop.matches) {
        sidebar.removeAttribute("aria-hidden");
        sidebar.inert = false;
        scrim.hidden = true;
        document.body.classList.remove("nav-open");
        toggle.setAttribute("aria-expanded", "false");
      } else if (!document.body.classList.contains("nav-open")) {
        sidebar.setAttribute("aria-hidden", "true");
        sidebar.inert = true;
      }
    };

    const open = () => {
      returnFocus = document.activeElement;
      sidebar.inert = false;
      sidebar.removeAttribute("aria-hidden");
      scrim.hidden = false;
      document.body.classList.add("nav-open");
      toggle.setAttribute("aria-expanded", "true");
      toggle.setAttribute("aria-label", "ปิดเมนู");
      window.requestAnimationFrame(() => q("a, button", sidebar)?.focus());
    };

    const close = ({ restoreFocus = true } = {}) => {
      document.body.classList.remove("nav-open");
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-label", "เปิดเมนู");
      scrim.hidden = true;
      if (!desktop.matches) {
        sidebar.setAttribute("aria-hidden", "true");
        sidebar.inert = true;
      }
      if (restoreFocus && returnFocus instanceof HTMLElement) returnFocus.focus();
    };

    toggle.addEventListener("click", () => {
      document.body.classList.contains("nav-open") ? close() : open();
    });
    scrim.addEventListener("click", () => close());
    sidebar.addEventListener("click", (event) => {
      if (!desktop.matches && event.target.closest("a")) close({ restoreFocus: false });
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && document.body.classList.contains("nav-open")) close();
    });
    desktop.addEventListener?.("change", syncClosedState);
    syncClosedState();
  }

  function initAlerts() {
    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-dismiss-alert]");
      if (!button) return;
      const alert = button.closest(".alert");
      if (!alert) return;
      alert.classList.add("is-dismissing");
      const remove = () => alert.remove();
      reduceMotion.matches ? remove() : window.setTimeout(remove, 180);
    });
  }

  function initPasswordToggles() {
    qa("[data-toggle-password]").forEach((button) => {
      const input = document.getElementById(button.dataset.togglePassword);
      if (!(input instanceof HTMLInputElement)) return;
      button.setAttribute("aria-controls", input.id);
      button.setAttribute("aria-pressed", "false");
      button.addEventListener("click", () => {
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        button.textContent = show ? "ซ่อน" : "แสดง";
        button.setAttribute("aria-pressed", String(show));
        button.setAttribute("aria-label", show ? "ซ่อนรหัสผ่าน" : "แสดงรหัสผ่าน");
        input.focus({ preventScroll: true });
        try {
          input.setSelectionRange(input.value.length, input.value.length);
        } catch (_) {
          /* Some input implementations do not expose selection. */
        }
      });
    });
  }

  function createConfirmDialog() {
    const dialog = document.createElement("dialog");
    dialog.className = "confirm-dialog";
    dialog.setAttribute("aria-labelledby", "confirm-dialog-title");
    dialog.setAttribute("aria-describedby", "confirm-dialog-message");
    dialog.innerHTML = `
      <form method="dialog">
        <div class="confirm-dialog__header">
          <h2 id="confirm-dialog-title">ยืนยันรายการ</h2>
        </div>
        <div class="confirm-dialog__body">
          <p id="confirm-dialog-message"></p>
        </div>
        <div class="confirm-dialog__actions">
          <button class="button button--ghost" value="cancel">ยกเลิก</button>
          <button class="button button--primary" value="confirm">ยืนยัน</button>
        </div>
      </form>`;
    document.body.append(dialog);
    return dialog;
  }

  function confirmAction(message, options = {}) {
    if (typeof HTMLDialogElement === "undefined" || typeof HTMLDialogElement.prototype.showModal !== "function") {
      return Promise.resolve(window.confirm(message));
    }

    const dialog = q(".confirm-dialog") || createConfirmDialog();
    q("#confirm-dialog-title", dialog).textContent = options.title || "ยืนยันรายการ";
    q("#confirm-dialog-message", dialog).textContent = message;
    const confirmButton = q('[value="confirm"]', dialog);
    confirmButton.textContent = options.confirmLabel || "ยืนยัน";
    confirmButton.classList.toggle("button--danger", options.danger === true);
    confirmButton.classList.toggle("button--primary", options.danger !== true);

    return new Promise((resolve) => {
      const handleClose = () => {
        dialog.removeEventListener("close", handleClose);
        resolve(dialog.returnValue === "confirm");
      };
      dialog.addEventListener("close", handleClose);
      dialog.returnValue = "cancel";
      dialog.showModal();
    });
  }

  function setFormBusy(form, busy) {
    const loadingLabel = form.method.toLowerCase() === "get" ? "กำลังค้นหา…" : "กำลังบันทึก…";
    form.dataset.submitting = busy ? "true" : "false";
    form.setAttribute("aria-busy", String(busy));
    qa('button[type="submit"], input[type="submit"]', form).forEach((button) => {
      if (busy) {
        button.dataset.originalLabel = button.value || button.textContent;
        button.disabled = true;
        if (button instanceof HTMLInputElement) {
          button.value = button.dataset.loadingLabel || loadingLabel;
        } else {
          button.textContent = button.dataset.loadingLabel || loadingLabel;
        }
      } else {
        button.disabled = false;
        if (button.dataset.originalLabel) {
          if (button instanceof HTMLInputElement) button.value = button.dataset.originalLabel;
          else button.textContent = button.dataset.originalLabel;
        }
      }
    });
  }

  function initForms() {
    document.addEventListener("invalid", (event) => {
      if (event.target.matches("input, select, textarea")) event.target.setAttribute("aria-invalid", "true");
    }, true);

    document.addEventListener("input", (event) => {
      if (event.target.matches('[aria-invalid="true"]') && event.target.checkValidity()) {
        event.target.removeAttribute("aria-invalid");
      }
    });

    document.addEventListener("submit", async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        q(":invalid", form)?.focus();
        return;
      }

      if (form.dataset.submitting === "true") {
        event.preventDefault();
        announce("กำลังบันทึกรายการ กรุณารอสักครู่");
        return;
      }

      if (form.dataset.confirm && form.dataset.confirmApproved !== "true") {
        event.preventDefault();
        const approved = await confirmAction(form.dataset.confirm, {
          title: form.dataset.confirmTitle,
          confirmLabel: form.dataset.confirmLabel,
          danger: form.dataset.confirmDanger === "true"
        });
        if (approved) {
          form.dataset.confirmApproved = "true";
          form.requestSubmit(event.submitter || undefined);
        }
        return;
      }

      setFormBusy(form, true);
    });

    window.addEventListener("pageshow", (event) => {
      if (!event.persisted) return;
      qa('form[data-submitting="true"]').forEach((form) => {
        delete form.dataset.confirmApproved;
        setFormBusy(form, false);
      });
    });
  }

  function enhanceTables() {
    qa(".table-wrap").forEach((wrapper, tableIndex) => {
      const table = q("table", wrapper);
      if (!table) return;
      const headings = qa("thead th", table).map((cell) => cell.textContent.trim());
      qa("tbody tr", table).forEach((row) => {
        qa("td", row).forEach((cell, index) => {
          if (!cell.dataset.label && headings[index]) cell.dataset.label = headings[index];
        });
      });
      if (!wrapper.hasAttribute("tabindex")) wrapper.tabIndex = 0;
      wrapper.setAttribute("role", "region");
      if (!wrapper.hasAttribute("aria-label")) {
        wrapper.setAttribute("aria-label", table.getAttribute("aria-label") || `ตารางข้อมูล ${tableIndex + 1}`);
      }
    });
  }

  class QrScanner {
    constructor(root) {
      this.root = root;
      this.video = q("#qr-video", root) || q("#qr-video");
      this.placeholder = q("#scanner-placeholder", root) || q("#scanner-placeholder");
      this.status = q("#scanner-status");
      this.button = q("#start-scanner");
      this.roomSelect = q("#room-select");
      this.methodInput = q("#checkin-method");
      this.stream = null;
      this.detector = null;
      this.frame = 0;
      this.lastScanAt = 0;
      this.running = false;
      this.settingFromQr = false;
    }

    init() {
      if (!this.video || !this.button || !this.roomSelect) return;
      this.button.addEventListener("click", () => this.running ? this.stop("ปิดกล้องแล้ว เลือกห้องจากแบบฟอร์มได้") : this.start());
      this.roomSelect.addEventListener("change", () => {
        if (!this.settingFromQr && this.methodInput) this.methodInput.value = "manual";
      });
      window.addEventListener("pagehide", () => this.stop(null, false));
      document.addEventListener("visibilitychange", () => {
        if (document.hidden && this.running) this.stop("ปิดกล้องชั่วคราวเมื่อออกจากหน้านี้", false);
      });

      const roomFromUrl = new URL(window.location.href).searchParams.get("room");
      if (roomFromUrl) this.applyCode(roomFromUrl, false);
    }

    setStatus(message, state = "idle") {
      if (!this.status) return;
      this.status.textContent = message;
      this.status.dataset.state = state;
    }

    setButton(scanning) {
      this.button.innerHTML = `<span data-icon="${scanning ? "x" : "camera"}"></span>${scanning ? "ปิดกล้อง" : "เปิดกล้องเพื่อสแกน"}`;
      this.button.setAttribute("aria-pressed", String(scanning));
      renderIcons(this.button);
    }

    async start() {
      if (this.running) return;
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        this.fallback("เบราว์เซอร์ไม่อนุญาตให้ใช้กล้องในหน้านี้ กรุณาเลือกห้องจากแบบฟอร์ม");
        return;
      }
      if (!("BarcodeDetector" in window)) {
        this.fallback("อุปกรณ์นี้ยังไม่รองรับการสแกน QR Code กรุณาเลือกห้องจากแบบฟอร์ม");
        return;
      }

      try {
        const supported = typeof BarcodeDetector.getSupportedFormats === "function"
          ? await BarcodeDetector.getSupportedFormats()
          : ["qr_code"];
        if (!supported.includes("qr_code")) {
          this.fallback("เบราว์เซอร์นี้อ่าน QR Code ไม่ได้ กรุณาเลือกห้องจากแบบฟอร์ม");
          return;
        }

        this.button.disabled = true;
        this.setStatus("กำลังขออนุญาตใช้กล้อง…");
        this.detector = new BarcodeDetector({ formats: ["qr_code"] });
        this.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: false
        });
        this.video.srcObject = this.stream;
        await this.video.play();
        this.running = true;
        this.placeholder?.setAttribute("hidden", "");
        this.root.classList.add("is-scanning");
        this.setButton(true);
        this.setStatus("กำลังสแกน วาง QR Code ให้อยู่ภายในกรอบ", "scanning");
        this.tick(performance.now());
      } catch (error) {
        const message = error?.name === "NotAllowedError"
          ? "ไม่ได้รับอนุญาตให้ใช้กล้อง กรุณาอนุญาตในตั้งค่าเบราว์เซอร์หรือเลือกห้องด้วยตนเอง"
          : error?.name === "NotFoundError"
            ? "ไม่พบกล้องบนอุปกรณ์นี้ กรุณาเลือกห้องด้วยตนเอง"
            : "เปิดกล้องไม่สำเร็จ กรุณาลองอีกครั้งหรือเลือกห้องด้วยตนเอง";
        this.fallback(message);
      } finally {
        this.button.disabled = false;
      }
    }

    async tick(timestamp) {
      if (!this.running) return;
      if (timestamp - this.lastScanAt > 180 && this.video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
        this.lastScanAt = timestamp;
        try {
          const codes = await this.detector.detect(this.video);
          if (codes.length && this.applyCode(codes[0].rawValue, true)) return;
        } catch (_) {
          /* A transient unreadable frame is normal; keep scanning. */
        }
      }
      this.frame = window.requestAnimationFrame((time) => this.tick(time));
    }

    extractCandidates(rawValue) {
      const value = String(rawValue || "").trim();
      const candidates = [value];
      try {
        const url = new URL(value);
        ["room", "code", "qr", "room_code"].forEach((key) => {
          const candidate = url.searchParams.get(key);
          if (candidate) candidates.push(candidate);
        });
        const lastPath = url.pathname.split("/").filter(Boolean).pop();
        if (lastPath) candidates.push(decodeURIComponent(lastPath));
      } catch (_) {
        /* Plain room codes are the expected default. */
      }
      return [...new Set(candidates.map((item) => item.trim()).filter(Boolean))];
    }

    applyCode(rawValue, fromCamera) {
      const candidates = this.extractCandidates(rawValue);
      const options = qa("option", this.roomSelect);
      const match = options.find((option) => candidates.some((candidate) => {
        const normalized = candidate.toLocaleUpperCase("en-US");
        return option.value.toLocaleUpperCase("en-US") === normalized
          || (option.dataset.code || "").toLocaleUpperCase("en-US") === normalized;
      }));

      if (!match || !match.value) {
        if (fromCamera) {
          this.setStatus("อ่าน QR Code ได้ แต่ไม่พบห้องนี้ในระบบ กรุณาเลือกห้องด้วยตนเอง", "error");
          announce("ไม่พบรหัสห้องในระบบ");
        }
        return false;
      }

      this.settingFromQr = true;
      this.roomSelect.value = match.value;
      this.roomSelect.dispatchEvent(new Event("change", { bubbles: true }));
      this.settingFromQr = false;
      if (this.methodInput) this.methodInput.value = fromCamera ? "qr" : "manual";
      if (fromCamera) {
        this.stop(null, false);
        this.setStatus(`พบห้อง ${match.dataset.code || match.textContent.trim()} แล้ว กรุณาตรวจสอบข้อมูลก่อนบันทึก`, "success");
        announce("สแกนสำเร็จ กรุณาตรวจสอบข้อมูลก่อนบันทึก");
        this.roomSelect.focus();
      }
      return true;
    }

    fallback(message) {
      this.stop(null, false);
      this.setStatus(message, "error");
      this.roomSelect.focus();
      announce(message);
    }

    stop(message = "ปิดกล้องแล้ว", updateButton = true) {
      this.running = false;
      if (this.frame) window.cancelAnimationFrame(this.frame);
      this.frame = 0;
      this.stream?.getTracks().forEach((track) => track.stop());
      this.stream = null;
      if (this.video) {
        this.video.pause();
        this.video.srcObject = null;
      }
      this.root.classList.remove("is-scanning");
      this.placeholder?.removeAttribute("hidden");
      if (updateButton) this.setButton(false);
      if (message) this.setStatus(message);
    }
  }

  function initQrScanner() {
    const root = q("[data-scanner]");
    if (root) new QrScanner(root).init();
  }

  function initGeneratedQrCodes() {
    qa("[data-qr-value]").forEach((root) => {
      const value = root.dataset.qrValue;
      if (!value) return;
      root.textContent = "";
      if (typeof window.QRCode !== "function") {
        root.innerHTML = '<span class="qr-error">สร้าง QR Code ไม่สำเร็จ กรุณาใช้ลิงก์ด้านล่าง</span>';
        return;
      }
      new window.QRCode(root, {
        text: value,
        width: 220,
        height: 220,
        colorDark: "#0B2545",
        colorLight: "#FFFFFF",
        correctLevel: window.QRCode.CorrectLevel.M
      });
    });
  }

  function initCopyButtons() {
    qa("[data-copy-target]").forEach((button) => {
      button.addEventListener("click", async () => {
        const input = document.getElementById(button.dataset.copyTarget);
        if (!(input instanceof HTMLInputElement)) return;
        try {
          await navigator.clipboard.writeText(input.value);
        } catch (_) {
          input.select();
          document.execCommand("copy");
        }
        const original = button.textContent;
        button.textContent = "คัดลอกแล้ว";
        announce("คัดลอกลิงก์สำหรับนักศึกษาแล้ว");
        window.setTimeout(() => { button.textContent = original; }, 1600);
      });
    });
  }

  function initTermDialog() {
    const dialog = q("#term-settings");
    if (!dialog || typeof dialog.showModal !== "function") return;
    const form = q("[data-term-form]", dialog);
    const triggers = qa("[data-open-term]");
    let opener = triggers[0];
    const fields = qa('input[required], select[required], input[name="dates_confirmed"]', form);
    const startsOn = q('[name="term_starts_on"]', form);
    const endsOn = q('[name="term_ends_on"]', form);

    const open = () => {
      if (dialog.open) return;
      dialog.showModal();
      document.body.classList.add("term-dialog-open");
      (q("[data-term-error-summary]", dialog) || fields[0]).focus();
    };
    triggers.forEach((trigger) => {
      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        opener = trigger;
        open();
      });
    });
    qa("[data-close-term]", dialog).forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        if (form.dataset.submitting !== "true") dialog.close();
      });
    });
    dialog.addEventListener("cancel", (event) => {
      if (form.dataset.submitting === "true") event.preventDefault();
    });
    dialog.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        if (form.dataset.submitting !== "true") dialog.close();
      }
      if (event.key === "Tab") {
        const controls = qa('a[href], button:not(:disabled), input:not([type="hidden"]):not(:disabled), select:not(:disabled)', dialog);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
    dialog.addEventListener("close", () => {
      document.body.classList.remove("term-dialog-open");
      opener?.focus({ preventScroll: true });
      // Remove the server's reopen marker without losing timetable filters.
      const url = new URL(window.location.href);
      if (url.searchParams.has("new_term") || url.hash === "#term-settings") {
        url.searchParams.delete("new_term");
        if (url.hash === "#term-settings") url.hash = "";
        window.history.replaceState(null, "", url);
      }
    });

    const validate = (field) => {
      field.setCustomValidity("");
      let message = "";
      if (field.type === "checkbox" && field.required && !field.checked) message = "กรุณายืนยันว่าตรวจสอบวันที่กับประกาศแล้ว";
      else if (!field.value.trim()) message = "กรุณากรอกข้อมูลช่องนี้";
      else if (field.name === "term_name" && (Array.from(field.value.trim()).length < 3 || Array.from(field.value.trim()).length > 100)) message = "ชื่อภาคการศึกษาต้องมีความยาว 3–100 ตัวอักษร";
      else if (field.name === "academic_year" && !field.checkValidity()) message = "ปีการศึกษาต้องเป็นจำนวนเต็มระหว่าง 2500–2700 พ.ศ.";
      else if (field === endsOn && startsOn.value && endsOn.value < startsOn.value) message = "วันปิดภาคต้องไม่อยู่ก่อนวันเปิดภาค";
      field.setCustomValidity(message);
      const error = document.getElementById(`error-${field.name}`);
      error.textContent = message;
      error.hidden = !message;
      field.setAttribute("aria-invalid", String(Boolean(message)));
      return !message;
    };
    fields.forEach((field) => {
      field.addEventListener("input", () => {
        validate(field);
        if (field === startsOn && endsOn.value) validate(endsOn);
      });
    });
    // Runs before the shared submit handler, which handles busy/duplicate submits.
    form.addEventListener("submit", (event) => {
      const valid = fields.map(validate).every(Boolean);
      if (!valid) {
        event.preventDefault();
        q('[aria-invalid="true"]', form)?.focus();
      }
    });
    // Server validation and the no-JavaScript link render an open nonmodal dialog.
    if (dialog.open || window.location.hash === "#term-settings") {
      dialog.removeAttribute("open");
      open();
    }
  }

  function initSchedulePicker() {
    const form = q("[data-schedule-form]");
    if (!form) return;
    const dayInput = q('[name="day_of_week"]', form);
    const startsInput = q('[name="starts_time"]', form);
    const endsInput = q('[name="ends_time"]', form);
    const termInput = q('[name="term_id"]', form);
    const activeFrom = q('[name="active_from"]', form);
    const activeUntil = q('[name="active_until"]', form);
    const message = q("[data-slot-selection]", form);
    let rangeAnchor = null;

    const addMinutes = (time, minutes) => {
      const [hour, minute] = time.split(":").map(Number);
      const total = Math.min(23 * 60 + 59, hour * 60 + minute + minutes);
      return `${String(Math.floor(total / 60)).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`;
    };

    qa("[data-slot-start]").forEach((slot) => {
      slot.addEventListener("click", () => {
        const day = slot.dataset.slotDay;
        const start = slot.dataset.slotStart;
        if (!rangeAnchor || rangeAnchor.day!==day) {
          rangeAnchor={day,start}; startsInput.value=start; endsInput.value=addMinutes(start,60);
        } else {
          startsInput.value=start<rangeAnchor.start?start:rangeAnchor.start;
          endsInput.value=addMinutes(start>rangeAnchor.start?start:rangeAnchor.start,60);
          rangeAnchor=null;
        }
        dayInput.value = day;
        qa("[data-slot-start]").forEach((item) => {
          const selected=item.dataset.slotDay===day && item.dataset.slotStart>=startsInput.value && item.dataset.slotStart<endsInput.value;
          item.classList.toggle('is-selected',selected);item.setAttribute('aria-pressed',String(selected));
        });
        const label = slot.getAttribute("aria-label") || "ช่องเวลาที่เลือก";
        message.textContent = `เลือก ${startsInput.value}–${endsInput.value} แล้ว${rangeAnchor?' · คลิกช่องสุดท้ายเพื่อเลือกหลายชั่วโมง':' · ตรวจห้องและผู้สอนในแบบฟอร์มก่อนบันทึก'}`;
        message.classList.add("is-selected");
        announce(`เลือก ${label} แล้ว`);
        if (window.matchMedia("(max-width: 640px)").matches) {
          form.scrollIntoView({ behavior: reduceMotion.matches ? "auto" : "smooth", block: "start" });
        } else if (!rangeAnchor) {
          q('[name="course_code"]', form)?.focus();
        }
      });
    });
    [dayInput,startsInput,endsInput].forEach((input)=>input.addEventListener('input',()=>{
      rangeAnchor=null;
      qa('[data-slot-start]').forEach((item)=>{
        const selected=item.dataset.slotDay===dayInput.value && item.dataset.slotStart>=startsInput.value && item.dataset.slotStart<endsInput.value;
        item.classList.toggle('is-selected',selected);item.setAttribute('aria-pressed',String(selected));
      });
      message.textContent=`ช่วงเวลา ${startsInput.value}–${endsInput.value} · ระบบจะตรวจทั้งภาคก่อนบันทึก`;
    }));

    termInput?.addEventListener("change", () => {
      const option = termInput.selectedOptions[0];
      if (!option) return;
      if (option.dataset.start) activeFrom.value = option.dataset.start;
      if (option.dataset.end) activeUntil.value = option.dataset.end;
    });
    form.addEventListener("reset", () => {
      rangeAnchor=null;
      window.setTimeout(() => {
        qa(".schedule-empty-slot.is-selected").forEach((item) => { item.classList.remove("is-selected"); item.setAttribute('aria-pressed','false'); });
        message.textContent = "ยังไม่ได้เลือกช่องเวลา สามารถกรอกเองได้";
        message.classList.remove("is-selected");
      }, 0);
    });
  }

  function init() {
    renderIcons();
    initMobileNavigation();
    initAlerts();
    initPasswordToggles();
    initForms();
    enhanceTables();
    initQrScanner();
    initGeneratedQrCodes();
    initCopyButtons();
    initTermDialog();
    initSchedulePicker();
  }

  window.LUMS = Object.freeze({ announce, confirm: confirmAction, renderIcons });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
