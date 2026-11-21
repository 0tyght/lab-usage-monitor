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
  document.querySelector('#help-detail').textContent = 'แจ้งผู้ดูแลห้องให้ตรวจสอบเซิร์ฟเวอร์ แล้วลองอีกครั้งด้วย QR เดิม ไม่ต้องกรอกข้อมูลใหม่ที่หน้านี้';
}

const states = {
  offline: ['ระบบปิดให้บริการอยู่', 'ผู้ดูแลปิดระบบไว้ กรุณาติดต่อผู้ดูแลห้อง'],
  unreachable: ['ยังตรวจสอบระบบไม่ได้', 'การเชื่อมต่ออาจขัดข้องชั่วคราว ระบบอาจยังเปิดอยู่ กรุณาลองอีกครั้ง'],
  unconfigured: ['ยังไม่ได้เปิดระบบออนไลน์', 'ลิงก์เข้าระบบพร้อมแล้ว รอผู้ดูแลเปิดเซิร์ฟเวอร์ครั้งแรก'],
  network: ['ตรวจสอบการเชื่อมต่อไม่ได้', 'กรุณาตรวจสอบอินเทอร์เน็ตของคุณ แล้วกดตรวจสอบอีกครั้ง'],
  config: ['ข้อมูลการเชื่อมต่อไม่ถูกต้อง', 'ระบบหยุดการเชื่อมต่อเพื่อความปลอดภัย กรุณาแจ้งผู้ดูแลให้เปิดระบบใหม่'],
  link: ['ลิงก์ลงชื่อไม่ถูกต้อง', 'กรุณาสแกน QR หน้าห้องอีกครั้ง หรือขอลิงก์จากผู้ดูแลห้อง'],
};
const normalHelp = document.querySelector('#help-detail').textContent;

async function connect() {
  document.querySelector('.actions').hidden = true;
  document.querySelector('#help').hidden = true;
  document.querySelector('#operator-help').hidden = true;
  document.querySelector('#help-detail').textContent = normalHelp;
  retry.disabled = true;
  next.hidden = true;
  section.setAttribute('aria-busy', 'true');
  status.dataset.state = 'loading';
  title.textContent = 'กำลังตรวจสอบการเชื่อมต่อ';
  detail.textContent = 'กำลังค้นหาเซิร์ฟเวอร์ล่าสุด กรุณารอสักครู่';
  const attempt = async () => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try { return await checkConnection({ search: location.search, signal: controller.signal }); }
    finally { clearTimeout(timeout); }
  };
  try {
    let result;
    try { result = await attempt(); }
    catch (error) { if (error.message !== 'network') throw error; result = {status:'unreachable'}; }
    if (result.status === 'unreachable') {
      detail.textContent = 'การเชื่อมต่อช้ากว่าปกติ กำลังลองอีกครั้ง';
      result = await attempt();
    }
    if (result.status === 'ready') {
      status.dataset.state = 'ready';
      title.textContent = 'เชื่อมต่อสำเร็จ';
      detail.textContent = isStudent ? 'กำลังเปิดหน้าลงชื่อเข้าใช้ห้อง' : 'กำลังเปิดหน้าเข้าสู่ระบบ LUMS';
      next.textContent = isStudent ? 'เปิดหน้าลงชื่อ' : 'เข้าสู่ระบบ';
      next.href = result.destination;
      next.hidden = false;
      document.querySelector('.actions').hidden = false;
      location.replace(result.destination);
      return;
    }
    status.dataset.state = result.status === 'offline' ? 'offline' : 'error';
    [title.textContent, detail.textContent] = states[result.status];
  } catch (error) {
    status.dataset.state = 'error';
    [title.textContent, detail.textContent] = states[error.message] || states.network;
    if (error.message === 'link') {
      status.dataset.state = 'bad-link';
      document.querySelector('#help-detail').textContent = 'ลิงก์นี้มีรหัสห้องหรือคลาสไม่ครบ หรือรูปแบบไม่ถูกต้อง กรุณาขอ QR ที่ถูกต้องจากผู้ดูแล';
    }
  } finally {
    if (status.dataset.state !== 'ready') {
      document.querySelector('.actions').hidden = status.dataset.state === 'bad-link';
      document.querySelector('#help').hidden = false;
      if (!isStudent) document.querySelector('#operator-help').hidden = false;
    }
    retry.disabled = false;
    section.setAttribute('aria-busy', 'false');
    document.querySelector('#checked').textContent = 'ตรวจสอบล่าสุด ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) + ' น.';
  }
}
retry.addEventListener('click', connect);
connect();
