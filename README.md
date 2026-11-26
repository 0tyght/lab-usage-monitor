# LUMS

ระบบติดตามการใช้ห้องปฏิบัติการ อาจารย์และผู้ดูแลใช้เว็บจัดตารางและดูรายงาน ผู้ใช้ห้องสแกน QR ประจำห้องเพื่อลงชื่อเข้าและออก

## ติดตั้งและใช้งาน

อ่าน [คู่มือส่งมอบและย้ายเครื่อง](คู่มือส่งมอบ.md) สำหรับการติดตั้งเครื่องใหม่ การย้ายข้อมูล การเปิดและปิดระบบ และการตั้งค่าที่จำเป็น

หลังติดตั้ง ใช้ไอคอน **LUMS - เปิดระบบ** บน Desktop เพื่อเริ่ม Docker และเซิร์ฟเวอร์พร้อมเปิดเว็บไซต์ให้อัตโนมัติ ใช้ **ปิดระบบออนไลน์.cmd** เมื่อต้องการปิดระบบ ลิงก์เข้าสู่ระบบคือ https://0tyght.github.io/lab-usage-monitor/

**สำรองข้อมูลออนไลน์.cmd** สร้างสำเนา SQLite ที่ตรวจความสมบูรณ์แล้ว ใน `storage/handover-backups` เก็บสำเนาและไฟล์ `.env.online` เป็นความลับ การย้ายเครื่องต้องคัดลอกทั้งสองส่วนตามคู่มือ

ระบบใช้ Docker และ SQLite ซึ่งเก็บข้อมูลใน volume แยกจาก source code GitHub Pages เป็นทางเข้าที่เชื่อมต่อกับ Docker ผ่าน Cloudflare Quick Tunnel

## พัฒนาและทดสอบ

- `docker compose up --build -d` เปิดสภาพแวดล้อม local ที่ `http://localhost:8086` พร้อมข้อมูลตัวอย่าง โหมดนี้ใช้สำหรับพัฒนา
- `docker compose --env-file .env.production -f compose.production.yaml up --build -d` เปิด production หลัง HTTPS reverse proxy ตามการตั้งค่าใน `.env.production.example`
- `docs/schedule-import.md` อธิบายไฟล์นำเข้าตาราง
- `docs/design-system.md` และ `AGENTS.md` ระบุแนวทางพัฒนา
- GitHub Actions ตรวจ PHP, JavaScript, การเข้าใช้งาน, ตารางเรียน, QR และรายงาน

อย่า commit ไฟล์ `.env` ฐานข้อมูล สำเนาข้อมูล หรือ session และอย่าใช้ `docker compose down -v` กับข้อมูลที่ต้องการเก็บ
