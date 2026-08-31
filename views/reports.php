<?php
$filters = planning_filters($_GET, true);
$report = usage_report($filters);
$reportTable = usage_report_table($report);
$reportRooms = list_rooms();
$reportTerms = list_academic_terms();
$reportQuery = usage_report_query($filters);
$roomLabel = 'ทุกห้อง';
foreach ($reportRooms as $r) if ($r['id'] === $filters['room_id']) $roomLabel = $r['code'].' — '.$r['name'];
$termLabel = $filters['term_id'] ? (get_academic_term($filters['term_id'])['name'] ?? 'ไม่พบภาค') : 'ทุกภาค';
$sourceLabels = ['classes'=>'คลาสที่สร้างแล้ว', 'schedule'=>'แผนที่ยังไม่สร้างคลาส', 'all'=>'แผนและคลาสที่สร้าง'];
$pageNumber = max(1, (int)($_GET['page_number'] ?? 1));
$pageCount = max(1, (int)ceil(count($reportTable['rows'])/50));
$pageNumber = min($pageNumber, $pageCount);
$visibleRows = isset($_GET['print']) ? $reportTable['rows'] : array_slice($reportTable['rows'], ($pageNumber-1)*50, 50);
?>
<section class="usage-report">
<header class="page-header"><div><p class="eyebrow">ตรวจสอบและส่งออกข้อมูลชุดเดียวกัน</p><h1>รายงานการใช้ห้อง</h1><p>เลือกวันที่ ห้อง และช่วงเวลา แล้วสรุปเป็นชั่วโมงหรือคาบเทียบเท่า</p></div><?php if (!$report['errors']): ?><div class="schedule-header-actions"><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'reports', 'print'=>1]+$reportQuery)) ?>" target="_blank" rel="noopener">ฉบับพิมพ์ / PDF</a><a class="button button--primary" href="?<?= e(http_build_query(['download'=>'report-csv']+$reportQuery)) ?>"><span data-icon="download"></span>ส่งออก CSV</a></div><?php endif; ?></header>
<form method="get" class="report-filters" data-report-filters>
    <input type="hidden" name="page" value="reports">
    <label class="field"><span>ตั้งแต่วันที่</span><input type="date" name="date_from" value="<?= e($filters['date_from']) ?>" required></label>
    <label class="field"><span>ถึงวันที่</span><input type="date" name="date_to" value="<?= e($filters['date_to']) ?>" required></label>
    <label class="field"><span>ห้องปฏิบัติการ</span><select name="room_id"><option value="0">ทุกห้อง</option><?php foreach ($reportRooms as $r): ?><option value="<?= $r['id'] ?>" <?= $r['id']===$filters['room_id']?'selected':'' ?>><?= e($r['code'].' — '.$r['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>ปี / ภาค</span><select name="term_id"><option value="0">ทุกภาคการศึกษา</option><?php foreach ($reportTerms as $t): ?><option value="<?= $t['id'] ?>" <?= $t['id']===$filters['term_id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <details class="report-options" open><summary>ตัวกรองเพิ่มเติม · เวลา หน่วย และรูปแบบรายงาน</summary><div class="report-advanced-grid">
    <label class="field"><span>เวลาเริ่มในแต่ละวัน</span><input type="time" name="time_from" value="<?= e($filters['time_from']) ?>" required></label>
    <label class="field"><span>เวลาสิ้นสุดในแต่ละวัน</span><input name="time_to" value="<?= e($filters['time_to']) ?>" placeholder="24:00 = สิ้นวัน" pattern="([01][0-9]|2[0-3]):[0-5][0-9]|24:00" inputmode="text" required><small class="muted">ใช้ 24:00 เมื่อต้องการถึงสิ้นวัน</small></label>
    <label class="field"><span>ข้อมูลที่นำมารายงาน</span><select name="source"><?php foreach ($sourceLabels as $v=>$label): ?><option value="<?= $v ?>" <?= $v===$filters['source']?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>รูปแบบสรุป</span><select name="group"><?php foreach (['detail'=>'แยกรายการ', 'day'=>'รวมตามวัน', 'room'=>'รวมตามห้อง'] as $v=>$label): ?><option value="<?= $v ?>" <?= $v===$filters['group']?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>หน่วยการใช้งาน</span><select name="unit"><option value="hours" <?= $filters['unit']==='hours'?'selected':'' ?>>ชั่วโมง</option><option value="periods" <?= $filters['unit']==='periods'?'selected':'' ?>>คาบเทียบเท่า</option></select></label>
    <label class="field"><span>ความยาว 1 คาบ</span><select name="period_minutes" data-period-length><?php foreach ([50, 60, 90] as $n): ?><option value="<?= $n ?>" <?= $n===$filters['period_minutes']?'selected':'' ?>><?= $n ?> นาที</option><?php endforeach; ?></select><small class="muted">ใช้เมื่อเลือกคาบ ไม่ใช่มาตรฐานที่กำหนดโดยมหาวิทยาลัย</small></label>
    <label class="field"><span>ค้นหาวิชา / ผู้สอน</span><input name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="รหัสวิชา ชื่อวิชา ผู้สอน หรือกลุ่ม"></label>
    <label class="field"><span>เรียงวันที่ / ห้อง</span><select name="sort"><option value="asc" <?= $filters['sort']==='asc'?'selected':'' ?>>น้อยไปมาก</option><option value="desc" <?= $filters['sort']==='desc'?'selected':'' ?>>มากไปน้อย</option></select></label>
    </div></details>
    <div class="report-filter-actions"><button class="button button--primary" type="submit">แสดงรายงาน</button><a class="button button--secondary" href="?page=reports">ล้างตัวกรอง</a></div>
</form>
<p class="inline-note" role="status" data-report-pending hidden>เปลี่ยนตัวกรองแล้ว กรุณากด “แสดงรายงาน” ก่อนส่งออก ข้อมูลด้านล่างยังเป็นผลลัพธ์ครั้งก่อน</p>
<?php if ($report['errors']): ?><div class="alert alert--error" role="alert"><?= e(implode(' ', $report['errors'])) ?></div><?php else: ?>
<div class="report-applied-filters"><h2><?= e(thai_date_label($filters['date_from']).' – '.thai_date_label($filters['date_to'])) ?></h2><p><?= e($roomLabel.' · '.$termLabel.' · '.$sourceLabels[$filters['source']]) ?></p><p>ช่วงเวลาในแต่ละวัน <?= e($filters['time_from'].'–'.$filters['time_to']) ?> น. · เวลาไทย (UTC+7) · <?= $filters['unit']==='periods'?'1 คาบ = '.e($filters['period_minutes']).' นาที':'หน่วยชั่วโมง' ?></p></div>
<div class="report-summary"><span><strong><?= number_format($report['quantity'], 2) ?></strong><?= e($report['unit_label']) ?></span><span><strong><?= $report['events'] ?></strong>รายการใช้ห้องที่ไม่ซ้ำ</span><span><strong><?= $report['attendees'] ?></strong>การลงชื่อในคลาสที่เลือก</span></div>
<?php if ($filters['q'] !== ''): ?><p>คำค้น: <?= e($filters['q']) ?></p><?php endif; ?>
<p class="inline-note">เวลาคำนวณจากกำหนดการและเฉพาะส่วนที่ทับกับตัวกรอง ไม่ใช่เวลาการใช้งานที่ตรวจวัดจริง คาบเทียบเท่า = นาที ÷ ความยาวคาบ (ไม่ปัดขึ้น) จำนวนการลงชื่อเป็นยอดทั้งคลาส นับครั้งเดียวแม้คลาสข้ามวัน</p>
<?php if (isset($_GET['print'])): ?><div class="print-actions"><button class="button button--primary" type="button" data-print-report><span data-icon="download"></span>พิมพ์ / บันทึกเป็น PDF</button><p>เลือก “บันทึกเป็น PDF” ในหน้าต่างพิมพ์ของเบราว์เซอร์</p></div><?php endif; ?>
<div class="section-heading"><h2>รายละเอียดรายงาน</h2><span class="result-count">แสดง <?= count($visibleRows) ?> จาก <?= count($reportTable['rows']) ?> แถว<?= !isset($_GET['print'])?' · หน้า '.$pageNumber.'/'.$pageCount:'' ?></span></div>
<?php if (!$reportTable['rows']): ?><div class="empty-state"><span data-icon="inbox"></span><strong>ไม่พบข้อมูลตามตัวกรอง</strong><span>ลองขยายช่วงวันที่ เลือกทุกห้อง หรือเลือกแผนและคลาสที่สร้าง</span></div><?php else: ?>
<div class="table-wrap report-table-wrap"><table class="data-table report-data-table"><caption class="sr-only">รายงาน <?= e($roomLabel) ?> ตามวันที่และเวลาที่เลือก</caption><thead><tr><?php foreach ($reportTable['headers'] as $heading): ?><th><?= e($heading) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($visibleRows as $row): ?><tr><?php foreach ($row as $i=>$value): ?><td data-label="<?= e($reportTable['headers'][$i]) ?>"><?= e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
<?php if ($pageCount > 1 && !isset($_GET['print'])): ?><nav class="report-pagination" aria-label="หน้ารายงาน"><?php if ($pageNumber>1): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'reports', 'page_number'=>$pageNumber-1]+$reportQuery)) ?>">หน้าก่อน</a><?php endif; ?><?php if ($pageNumber<$pageCount): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'reports', 'page_number'=>$pageNumber+1]+$reportQuery)) ?>">หน้าถัดไป</a><?php endif; ?><small>CSV และฉบับพิมพ์รวมทุกหน้าที่ตรงตัวกรอง</small></nav><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</section>
