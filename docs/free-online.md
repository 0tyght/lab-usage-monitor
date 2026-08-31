# เปิด LUMS ออนไลน์ฟรีผ่านลิงก์ GitHub

ลิงก์ถาวร: **https://0tyght.github.io/lab-usage-monitor/**

GitHub Pages แสดงสถานะและพาไปยัง LUMS ที่รันใน Docker บนเครื่องคุณ ผ่าน Cloudflare Quick Tunnel เมื่อเชื่อมต่อแล้วแถบที่อยู่จะเปลี่ยนเป็น HTTPS ของ Tunnel ไม่ได้ซ่อนหรือฝังเว็บไซต์ใน iframe

## เปิดใช้งาน

1. เปิด Docker Desktop รอให้ engine พร้อม และเปิดอินเทอร์เน็ตไว้
2. เปิด PowerShell แล้วใช้โฟลเดอร์งานปัจจุบัน (ไม่ใช่สำเนาเก่าใน XAMPP):

```powershell
cd C:\Users\Admin\OneDrive\Documents\ChatGPT\lab-usage-monitor
.\start-online.ps1
```

3. รอ `LUMS is ready` แล้วเปิดลิงก์ GitHub ด้านบน การเปิดครั้งแรกต้องดาวน์โหลด/สร้าง Docker images จึงนานกว่าครั้งถัดไป
4. ลงชื่อเข้าใช้ด้วย `admin@lums.local` และรหัสที่สร้างในไฟล์ **`.env.online`** ไม่ใช่ `admin123` เปิดไฟล์นี้อ่านเองในเครื่องและเก็บเป็นความลับ ไม่ต้องส่งในแชต

ถ้า PowerShell บล็อกสคริปต์ ใช้คำสั่งนี้ในโฟลเดอร์เดิมแทน โดยไม่ต้องลดนโยบายความปลอดภัยของเครื่อง:

```powershell
node scripts/online.mjs start
```

ต้องมี Node.js 22 ขึ้นไป, Git ที่ล็อกอินแล้วและ push repository นี้ได้ และ Docker Desktop แบบ Linux containers

## ปิดใช้งาน

```powershell
.\stop-online.ps1
```

หรือ `node scripts/online.mjs stop` ระบบหยุดเฉพาะ `lums-online` และประกาศสถานะออฟไลน์ ไม่ลบฐานข้อมูล ไม่หยุด Docker ของโปรเจ็กต์อื่น หาก GitHub ติดต่อไม่ได้ หน้าเข้าระบบจะตรวจพบว่า Tunnel ใช้งานไม่ได้อยู่ดี

ดูสถานะ: `node scripts/online.mjs status`

หลังรีสตาร์ตเครื่อง ต้องรันสคริปต์เปิดใหม่ ระบบไม่เปิด Tunnel เองโดยอัตโนมัติ อย่าให้เครื่อง sleep ระหว่างเปิดรับลงชื่อ

## QR และข้อมูล

- QR ใหม่ที่สร้างในโหมดนี้ชี้ผ่าน GitHub Pages พร้อมรหัสคลาส จึงยังใช้ได้เมื่อ Tunnel เปลี่ยนชื่อหลังเปิดใหม่
- QR เก่าที่ชี้ localhost หรือ Tunnel โดยตรงต้องสร้าง/พิมพ์ใหม่
- เมื่อนักศึกษาเปิด QR ขณะออฟไลน์ จะแสดงคำแนะนำให้แจ้งอาจารย์และลองอีกครั้ง ไม่มีการแสดงว่าบันทึกสำเร็จทั้งที่ไม่ได้บันทึก
- ข้อมูลเก็บใน Docker volume `lums-online_online_storage` แยกจาก local/demo และ production เดิม ไม่ย้ายข้อมูลเก่าอัตโนมัติ
- ห้ามลบ volume หรือใช้ `down -v` และต้องสำรองข้อมูลก่อนเปลี่ยนเครื่องหรือติดตั้ง Docker ใหม่
- `.env.online` เป็นไฟล์ลับในเครื่อง (หากโฟลเดอร์อยู่บน OneDrive อาจถูกซิงก์ด้วย) ไม่ขึ้น GitHub หรือ Docker image อย่าลบหรือสร้างใหม่เพื่อเปลี่ยนรหัสผ่านบัญชีที่มีอยู่แล้ว

## โครงสร้างและความปลอดภัย

- Pages เผยแพร่เฉพาะ `pages/` ไม่มี PHP ฐานข้อมูล ไฟล์ session หรือ secrets
- สคริปต์อัปเดตเฉพาะ `runtime.json` ใน branch `codex/online-runtime` ด้วย Git Credential Manager เดิม ไม่ stage/commit โค้ดที่คุณกำลังแก้ และไม่ force-push
- JSON นี้เป็นข้อมูลสาธารณะ มีเฉพาะสถานะ, HTTPS Tunnel URL, public gateway ID และเวลาอัปเดต ไม่ใช่รหัสผ่านหรือ token สำหรับล็อกอิน
- หน้า gateway ยอมรับเฉพาะ HTTPS ของ `*.trycloudflare.com` ตรวจ health/service/gateway ID ก่อนพาไป และไม่รับ URL ปลายทางจาก query string
- เปิด CORS เฉพาะ health endpoint ให้ `https://0tyght.github.io` โดยไม่ส่ง credentials; หน้าเข้าสู่ระบบและข้อมูลต่าง ๆ ไม่เปิด CORS
- Docker เสิร์ฟเฉพาะ `public/`, bind พอร์ตเครื่องเฉพาะ `127.0.0.1:8088` และไม่เปิดฐานข้อมูลออกอินเทอร์เน็ต
- สคริปต์ตรวจ production mode, secure cookies และรหัส demo ที่รู้จักก่อนเปิด Tunnel แต่ไม่ใช่การตรวจความปลอดภัยเต็มระบบ

## ข้อจำกัดที่ต้องทราบ

Quick Tunnel ฟรีนี้เหมาะกับการทดลองและสาธิต ไม่รับประกัน uptime ต้องพึ่งเครื่องและเน็ตของคุณ มีข้อจำกัดคำขอพร้อมกันและไม่รองรับ SSE ตาม [Cloudflare Quick Tunnels](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/)

หากใช้งานประจำกับข้อมูลนักศึกษาจริง ควรผ่านการตรวจความปลอดภัย จัดการสำรองข้อมูล และวางบริการที่มีความพร้อมใช้งานตามข้อกำหนดมหาวิทยาลัยก่อน ไม่ได้หมายความว่าจำเป็นต้องซื้อ Render

## เมื่อเปิดไม่ขึ้น

- **หน้า GitHub เปิดแต่แจ้งออฟไลน์:** เปิดเครื่อง/Docker และรัน `start-online.ps1` ใหม่ จากนั้นกดตรวจสอบอีกครั้ง
- **Docker engine ไม่พร้อม:** แก้ Docker Desktop ให้เริ่มได้ก่อน สคริปต์ไม่ทำ factory reset หรือลบข้อมูล Docker
- **Git push ปฏิเสธสิทธิ์:** ลงชื่อเข้าใช้ GitHub ผ่าน Git Credential Manager และตรวจสิทธิ์ repository นี้
- **Tunnel ไม่พร้อม:** ดู `docker compose -p lums-online -f compose.online.yaml logs --tail 80 tunnel` และตรวจอินเทอร์เน็ต ไม่ต้องเปิด inbound port บน router
- **GitHub Pages ยังไม่ถูกตั้งค่า:** ผู้ดูแลรัน `node scripts/configure-pages.mjs --apply` ครั้งเดียว แล้วให้ Pages workflow ของ `main` ทำงาน สคริปต์ใช้สิทธิ์เดิมเฉพาะ repo นี้และไม่เปิดเผย token

แหล่งอ้างอิง: [GitHub Pages workflows](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages), [Cloudflare Quick Tunnels](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/)
