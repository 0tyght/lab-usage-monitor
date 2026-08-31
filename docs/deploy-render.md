# เปิด LUMS ออนไลน์ด้วย Render

GitHub เก็บโค้ดและทดสอบ ส่วน Render เป็นเครื่องเซิร์ฟเวอร์ที่รัน Docker เมื่อเปิดออนไลน์แล้ว ผู้ใช้เปิด URL HTTPS ได้โดยไม่ต้องเปิด XAMPP หรือ Docker Desktop ที่เครื่องตัวเอง

## ก่อนเริ่ม

- ลงชื่อเข้าใช้ [Render Dashboard](https://dashboard.render.com/) ด้วยบัญชีของผู้ดูแลโครงการ
- เชื่อม GitHub และให้สิทธิ์เฉพาะ repository `0tyght/lab-usage-monitor` เท่าที่จำเป็น
- ตรวจ [ผลทดสอบ Container CI](https://github.com/0tyght/lab-usage-monitor/actions/workflows/container-ci.yml) ของ `main` ล่าสุดว่าผ่านก่อนสร้างบริการ
- เตรียมอีเมลแอดมินและรหัสผ่านใหม่อย่างน้อย 12 ตัวอักษร กรอกใน Render โดยตรง ไม่ส่งในแชตหรือบันทึกลง Git
- เตรียมอนุมัติค่าใช้จ่าย: Blueprint ใช้ compute `0.5c-512mb`, region Singapore และ persistent disk 1 GB ตรวจราคาที่ Render แสดงก่อนกดยืนยัน ยังไม่มีการสร้างบริการจากไฟล์ YAML เพียงอย่างเดียว

ต้องมี persistent disk เพราะ SQLite และ sessions ต้องอยู่ต่อหลัง restart / deploy ใหม่ ไม่ควรเปลี่ยนเป็น Free แล้วตัด disk ออกเพื่อใช้งานจริง

## สร้างบริการครั้งแรก

1. ใน Render เลือก **New → Blueprint** แล้วเชื่อม repository `0tyght/lab-usage-monitor`
2. เลือก branch `main`, ไฟล์ `render.yaml` และตั้งชื่อ Blueprint เช่น `LUMS`
3. กรอก `LUMS_ADMIN_EMAIL` และ `LUMS_ADMIN_PASSWORD` ซึ่งเป็นบัญชีล็อกอินใน LUMS ไม่ใช่รหัสผ่าน Render หรือ GitHub
4. ตรวจว่าเป็น Docker, Singapore, disk 1 GB ที่ `/var/www/html/storage`, production mode และรอ CI ผ่านก่อน auto-deploy
5. ตรวจค่าบริการและสิทธิ์ที่ขอให้ครบ แล้วจึงยืนยัน **Deploy Blueprint** หากยอมรับ
6. รอ service เป็น **Live** แล้วใช้ HTTPS URL ที่ Render แสดง อย่าเดาชื่อ URL จากชื่อ repository

ระบบใช้ `RENDER_EXTERNAL_URL` เพื่อสร้างลิงก์ QR อัตโนมัติ ถ้าภายหลังใช้โดเมนมหาวิทยาลัย ให้ตั้ง `APP_URL` เป็น HTTPS โดเมนจริงและสร้าง/พิมพ์ QR ใหม่ตามต้องการ

## ตรวจหลังเปิดออนไลน์

- เปิดหน้าเข้าสู่ระบบผ่าน HTTPS และล็อกอินด้วยบัญชีแอดมินที่กำหนด; ไม่ใช้ `admin@lums.local / admin123`
- เปิด `/?health=1` ต่อท้าย URL ของบริการ ต้องได้ `status: ok`
- เปิด `/storage/lums.sqlite`, `/config.php`, `/.env.production`, `/src/database.php` ต้องถูกปฏิเสธหรือไม่พบ (403/404) ห้ามดาวน์โหลดได้
- สร้างภาคเรียน/คลาสทดสอบด้วยข้อมูลสมมติ แล้วสแกน QR ด้วยมือถือที่ใช้เน็ตมือถือ เพื่อตรวจว่าลิงก์ไม่ได้ชี้ไป localhost
- ทดสอบลงชื่อสำเร็จ ลงชื่อซ้ำ และคลาสปิดรับแล้ว ก่อนใช้กับนักศึกษาจริง
- Restart service แล้วตรวจว่าคลาสทดสอบยังอยู่ เพื่อยืนยันว่าฐานข้อมูลเก็บใน disk ถูกตำแหน่ง

ข้อมูลสาธิตและฐานข้อมูลในเครื่องไม่ถูกอัปโหลดไป Render โดยอัตโนมัติ Production ใหม่มีแอดมินและห้องตั้งต้น ให้ตรวจชื่อห้อง/ข้อมูลก่อนใช้งานจริง

## การอัปเดตและการดูแล

- เมื่อ push ไป `main` และ CI ผ่าน Render ที่เชื่อม GitHub ถูกต้องจะ deploy อัตโนมัติ
- บริการที่ใช้ disk อาจหยุดชั่วครู่ระหว่าง deploy จัดเวลาอัปเดตนอกคาบเรียน
- สำรอง SQLite เป็นไฟล์ที่สอดคล้องกันด้วย SQLite backup API หรือ `VACUUM INTO` และเก็บสำเนานอกเครื่องพร้อมทดสอบกู้คืนก่อนใช้ข้อมูลจริง; disk ถาวรไม่ใช่แผนสำรองข้อมูล
- อย่าลบ service/disk/volume เพื่อแก้ปัญหา และอย่าใช้ `docker compose down -v` กับข้อมูลจริง
- การเปลี่ยน `LUMS_ADMIN_PASSWORD` ใน environment ไม่ได้เปลี่ยนรหัสผ่านผู้ใช้ที่สร้างไว้แล้วโดยอัตโนมัติ อย่าลบฐานข้อมูลเพื่อรีเซ็ตรหัสผ่าน
- หาก build/start ล้มเหลว ดู Events/Logs และตรวจ secrets ที่จำเป็น; อย่าโพสต์รหัสผ่านลง issue หรือภาพหน้าจอ

## ขอบเขตที่เตรียมแล้ว

- แยก HTTP document root เป็น `public/` ไม่เสิร์ฟฐานข้อมูล sessions หรือ source ทั้งโครงการ
- กัน `.env*`, SQLite และ sessions ออกจาก Docker image และกัน secrets ออกจาก Git
- ทดสอบ PHP/JavaScript, production bootstrap, UX regression และ HTTP/private-file isolation ใน CI
- ยังต้องยืนยันบัญชี Render, สิทธิ์ GitHub, ค่าใช้จ่าย และบัญชีแอดมินก่อนสร้างบริการจริง
- ยังต้องทดสอบ URL จริงและสำรอง/กู้คืนข้อมูลก่อนรับข้อมูลนักศึกษาจริง; การผ่าน CI ไม่ใช่การตรวจความปลอดภัยเต็มระบบ

เอกสารอ้างอิง: [Blueprint specification](https://render.com/docs/blueprint-spec), [Persistent disks](https://render.com/docs/disks), [Infrastructure as code](https://render.com/docs/infrastructure-as-code), [Apache filesystem access rules](https://httpd.apache.org/docs/2.4/sections.html)
