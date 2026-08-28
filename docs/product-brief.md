# LUMS product brief

## Working name

**LUMS — Laboratory Usage Monitoring System**  
ระบบตรวจสอบและบันทึกการเข้าใช้งานห้องเรียนปฏิบัติการ

## Research goal

Develop a digital form-integrated system that improves the accuracy, speed, storage, retrieval, and analysis of laboratory classroom usage data while reducing staff workload and recording errors.

## Primary users

| User | Primary needs |
|---|---|
| นักเรียน/นักศึกษา | เปิดหน้าสาธารณะจาก QR ของคลาส ลงชื่อได้รวดเร็ว และเห็นผลการบันทึกชัดเจน |
| อาจารย์ | เข้าเว็บหลังบ้าน สร้างคลาส/QR และตรวจรายชื่อการเข้าเรียน |
| ผู้ดูแลระบบ | จัดการห้อง ผู้ใช้ สิทธิ์ แบบฟอร์ม และตรวจสอบย้อนหลัง |

## MVP scope

1. Authentication and role-based access
2. Academic terms and recurring weekly laboratory timetables
3. Graphic timetable slot selection and room/lecturer conflict detection
4. Atomic full-semester CSV timetable import
5. Laboratory room directory and live room status
6. A unique QR code generated for each scheduled class session
7. Public mobile student attendance form with validation and duplicate protection
8. Central class and attendance database with search and audit information
9. Dashboard and daily/weekly/monthly reports
10. CSV/XLSX export, user/role settings, and satisfaction evaluation

## Success indicators from the research proposal

- At least 30 users participate in system use/evaluation.
- At least 100 laboratory usage records are captured during the evaluation period.
- Reports can be produced for daily, weekly, and monthly usage.
- Average user satisfaction is at least 4.00 out of 5.
- The system records data accurately and reliably without material recording errors.
- The interface is easy to use, has a user guide, and can be extended in the future.

## Initial navigation map

```text
LUMS
├── ภาพรวม
├── ตารางเรียน
│   ├── ปฏิทินรายสัปดาห์
│   ├── เพิ่มตารางจากช่องเวลา
│   ├── นำเข้า CSV ทั้งเทอม
│   └── จัดการภาคการศึกษา
├── คลาสเรียนและ QR
│   ├── สร้างคลาส
│   └── QR และรายชื่อนักศึกษา
├── ห้องปฏิบัติการ
├── ประวัติการเข้าเรียน
│   ├── รายการทั้งหมด
│   └── รายละเอียดรายการ
├── รายงาน
│   ├── รายวัน
│   ├── รายสัปดาห์
│   └── รายเดือน
├── แบบประเมินความพึงพอใจ
├── ผู้ใช้งานและสิทธิ์
└── ตั้งค่า

Student public web
└── /?page=student-checkin&token={class-token}
    ├── ข้อมูลคลาส
    ├── แบบฟอร์มลงชื่อ
    └── ผลการบันทึก/สถานะปิดรับ
```

## Important open decisions

- Exact institutional identity/login provider for lecturers and admins
- Whether Google Forms/Sheets remains the production data source or is used only during research prototyping
- Check-out rules and how incomplete sessions are handled
- Required approval flow for room usage
- University holiday, cancelled-class, make-up-class, and examination-week rules
- Data-retention, privacy, and audit requirements of the university
- Preferred implementation stack and deployment environment
