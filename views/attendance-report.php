<?php
$attendanceFilters = attendance_report_filters($_GET);
$attendanceQuery = attendance_report_query($attendanceFilters);
$attendanceResult = $attendanceFilters['errors'] ? ['items'=>[], 'total'=>0] : list_attendance_records($attendanceFilters);
$attendanceRooms = list_rooms();
$attendanceRoomLabel = 'ทุกห้อง';
foreach ($attendanceRooms as $room) if ($room['id'] === $attendanceFilters['room_id']) $attendanceRoomLabel = $room['code'].' — '.$room['name'];
$attendancePrinting = isset($_GET['print']);
?>
<section class="usage-report <?= $attendancePrinting?'is-print-view':'' ?>">
<header class="section-heading report-section-heading"><div><h2>การลงชื่อเข้าเรียน</h2><p>ค้นหานักศึกษา รายวิชา ห้อง และเวลาที่ลงชื่อผ่าน QR Code</p></div><?php if (!$attendanceFilters['errors']): ?><div class="schedule-header-actions"><a class="button button--secondary" target="_blank" rel="noopener" href="?<?= e(http_build_query(['page'=>'reports','tab'=>'attendance','print'=>1]+$attendanceQuery)) ?>">ฉบับพิมพ์ / PDF</a><a class="button button--primary" href="?<?= e(http_build_query(['download'=>'attendance-csv']+$attendanceQuery)) ?>"><span data-icon="download"></span>ส่งออก CSV</a></div><?php endif; ?></header>
<form method="get" class="report-filters" data-report-filters>
    <input type="hidden" name="page" value="reports"><input type="hidden" name="tab" value="attendance">
    <label class="field"><span>ตั้งแต่วันที่</span><input type="date" name="date_from" value="<?= e($attendanceFilters['date_from']) ?>" required></label>
    <label class="field"><span>ถึงวันที่</span><input type="date" name="date_to" value="<?= e($attendanceFilters['date_to']) ?>" required></label>
    <label class="field"><span>ห้องปฏิบัติการ</span><select name="room_id"><option value="0">ทุกห้อง</option><?php foreach ($attendanceRooms as $room): ?><option value="<?= $room['id'] ?>" <?= $room['id']===$attendanceFilters['room_id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>ค้นหา</span><input name="q" value="<?= e($attendanceFilters['q']) ?>" maxlength="100" placeholder="ชื่อ รหัสนักศึกษา รายวิชา หรือห้อง"></label>
    <div class="report-filter-actions"><button class="button button--primary" type="submit">แสดงรายงาน</button><a class="button button--secondary" href="?page=reports&amp;tab=attendance">ล้างตัวกรอง</a></div>
</form>
<p class="inline-note" role="status" data-report-pending hidden>เปลี่ยนตัวกรองแล้ว กรุณากด “แสดงรายงาน” ก่อนส่งออก ข้อมูลด้านล่างยังเป็นผลลัพธ์ครั้งก่อน</p>
<?php if ($attendanceFilters['errors']): ?><div class="alert alert--error" role="alert"><?= e(implode(' ', $attendanceFilters['errors'])) ?></div><?php else: ?>
<div class="report-applied-filters"><h2><?= e(thai_date_label($attendanceFilters['date_from']).' – '.thai_date_label($attendanceFilters['date_to'])) ?></h2><p><?= e($attendanceRoomLabel) ?> · เวลาไทย (UTC+7)<?= $attendanceFilters['q']!==''?' · คำค้น: '.e($attendanceFilters['q']):'' ?></p></div>
<div class="report-summary"><span><strong><?= e($attendanceResult['total']) ?></strong>รายการลงชื่อ</span></div>
<?php if ($attendancePrinting): ?><div class="print-actions"><button class="button button--primary" type="button" data-print-report>พิมพ์ / บันทึกเป็น PDF</button><p>เลือก “บันทึกเป็น PDF” ในหน้าต่างพิมพ์ของเบราว์เซอร์</p></div><?php endif; ?>
<div class="section-heading"><div><h2>รายละเอียดการลงชื่อ</h2><p>เรียงจากเวลาล่าสุด · แสดงได้สูงสุด 200 รายการต่อรายงาน</p></div><span class="result-count">พบ <?= e($attendanceResult['total']) ?> รายการ</span></div>
<?php render_attendance_table($attendanceResult['items'], true, true); ?>
<?php endif; ?>
</section>
