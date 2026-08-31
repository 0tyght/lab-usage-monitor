import { checkConnection } from './gateway-core.mjs';

const title = document.querySelector('#status-title');
const detail = document.querySelector('#status-detail');
const retry = document.querySelector('#retry');
const next = document.querySelector('#continue');
const status = document.querySelector('.status');
const section = document.querySelector('#connection');
const isStudent = new URLSearchParams(location.search).has('token');
if (isStudent) {
  document.querySelector('#context').textContent = 'ลงชื่อเข้าใช้ห้องปฏิบัติการ';
  document.querySelector('#operator-help').hidden = true;
  document.querySelector('#help-detail').textContent = 'แจ้งอาจารย์ประจำคลาสให้ตรวจสอบเซิร์ฟเวอร์ แล้วลองอีกครั้งด้วย QR เดิม ไม่ต้องกรอกข้อมูลใหม่ที่หน้านี้';
}

const states = {
  offline: ['เซิร์ฟเวอร์ยังไม่พร้อมใช้งาน', 'เครื่องเซิร์ฟเวอร์อาจปิดอยู่ หรือการเชื่อมต่อขัดข้อง กรุณารอผู้ดูแลเปิดระบบแล้วลองอีกครั้ง'],
  unconfigured: ['ยังไม่ได้เปิดระบบออนไลน์', 'ลิงก์เข้าระบบพร้อมแล้ว รอผู้ดูแลเปิดเซิร์ฟเวอร์ครั้งแรก'],
  network: ['ตรวจสอบการเชื่อมต่อไม่ได้', 'กรุณาตรวจสอบอินเทอร์เน็ตของคุณ แล้วกดตรวจสอบอีกครั้ง'],
  config: ['ข้อมูลการเชื่อมต่อไม่ถูกต้อง', 'ระบบหยุดการเชื่อมต่อเพื่อความปลอดภัย กรุณาแจ้งผู้ดูแลให้เปิดระบบใหม่'],
  link: ['ลิงก์คลาสเรียนไม่ถูกต้อง', 'กรุณาสแกน QR ของคลาสอีกครั้ง หรือขอลิงก์ใหม่จากอาจารย์'],
};
const normalHelp = document.querySelector('#help-detail').textContent;

async function connect() {
  document.querySelector('.actions').hidden = false;
  document.querySelector('#help-detail').textContent = normalHelp;
  retry.disabled = true;
  next.hidden = true;
  section.setAttribute('aria-busy', 'true');
  status.dataset.state = 'loading';
  title.textContent = 'กำลังตรวจสอบการเชื่อมต่อ';
  detail.textContent = 'กำลังค้นหาเซิร์ฟเวอร์ล่าสุด กรุณารอสักครู่';
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const result = await checkConnection({ search: location.search, signal: controller.signal });
    if (result.status === 'ready') {
      status.dataset.state = 'ready';
      title.textContent = 'เชื่อมต่อสำเร็จ';
      detail.textContent = isStudent ? 'กำลังเปิดหน้าลงชื่อของคลาสเรียน' : 'กำลังเปิดหน้าเข้าสู่ระบบ LUMS';
      next.textContent = isStudent ? 'เปิดหน้าลงชื่อ' : 'เข้าสู่ระบบ';
      next.href = result.destination;
      next.hidden = false;
      location.replace(result.destination);
      return;
    }
    status.dataset.state = 'offline';
    [title.textContent, detail.textContent] = states[result.status];
  } catch (error) {
    status.dataset.state = 'error';
    [title.textContent, detail.textContent] = states[error.message] || states.network;
    if (error.message === 'link') {
      document.querySelector('.actions').hidden = true;
      document.querySelector('#help-detail').textContent = 'ลิงก์นี้มีรหัสคลาสไม่ครบหรือรูปแบบไม่ถูกต้อง การโหลดซ้ำจะไม่แก้ปัญหา กรุณาขอ QR ที่ถูกต้องจากอาจารย์';
    }
  } finally {
    clearTimeout(timeout);
    retry.disabled = false;
    section.setAttribute('aria-busy', 'false');
    document.querySelector('#checked').textContent = 'ตรวจสอบล่าสุด ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) + ' น.';
  }
}
retry.addEventListener('click', connect);
connect();
