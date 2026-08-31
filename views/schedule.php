<?php
$terms = list_academic_terms();
$termErrors = $_SESSION['term_form_errors'] ?? [];
$termInput = $_SESSION['term_old_input'] ?? [];
if (!$termInput) $termInput = array_intersect_key($_GET, array_flip(['academic_year','semester']));
unset($_SESSION['term_form_errors'], $_SESSION['term_old_input']);
$defaultTerm = current_academic_term();
$term = isset($_GET['term_id']) && (int)$_GET['term_id']===0 ? null : (get_academic_term((int)($_GET['term_id'] ?? $defaultTerm['id'] ?? 0)) ?? $defaultTerm);
$termId = (int)($term['id'] ?? 0);
$rooms = list_rooms();
$lecturers = list_lecturers();
$roomFilter = (string)($_GET['room_id'] ?? '');
$selectedRoom = null;
foreach ($rooms as $room) if ((string)$room['id'] === $roomFilter) $selectedRoom = $room;
if ($roomFilter !== '' && !$selectedRoom) $roomFilter = '';
$search = trim((string)($_GET['q'] ?? ''));
$weekDate = (string)($_GET['week'] ?? '');
if (!valid_iso_date($weekDate) && $term && (date('Y-m-d') < $term['starts_on'] || date('Y-m-d') > $term['ends_on'])) $weekDate = $term['starts_on'];
$weekStart = week_start_date($weekDate);
$showWeekend = (string)($_GET['weekend'] ?? '') === '1';
$dayCount = $showWeekend ? 7 : 5;
$weekFrom = $weekStart->format('Y-m-d');
$weekTo = $weekStart->modify('+'.($dayCount-1).' days')->format('Y-m-d');
$scheduleQueryBase = ['page'=>'schedule','term_id'=>$termId,'room_id'=>$roomFilter,'q'=>$search,'weekend'=>$showWeekend ? 1 : 0,'week'=>$weekFrom];
$scheduleReturnUrl = '?'.http_build_query($scheduleQueryBase);
$termReturnUrl = $scheduleReturnUrl;
$termOpenUrl = $termReturnUrl.'&new_term=1';
$selectedSchedule = get_course_schedule((int)($_GET['selected'] ?? 0));
if ($selectedSchedule && ($selectedSchedule['term_id'] !== $termId || $selectedSchedule['status'] !== 'active')) $selectedSchedule = null;
$selectedDate = $selectedSchedule ? $weekStart->modify('+'.($selectedSchedule['day_of_week']-1).' days')->format('Y-m-d') : '';
$loadError = false;
$events = [];
try {
    // Reconcile linked classes/cancellations first; standalone classes do not belong to a term.
    $events = array_values(array_filter(room_usage_events($weekFrom, $weekTo, ['room_id'=>$roomFilter,'q'=>$search]), static fn(array $event): bool => !$termId || !$event['term_id'] || $event['term_id'] === $termId));
} catch (Throwable) { $loadError = true; }
$timeline = timetable_layout(room_usage_slices($events, $weekFrom, $weekTo));
$outsideTerm = $term && ($weekTo < $term['starts_on'] || $weekFrom > $term['ends_on']);
?>
<section class="schedule-page" aria-labelledby="schedule-page-title">
    <header class="page-header schedule-page-header">
        <div><h1 id="schedule-page-title">ตารางเรียนห้องปฏิบัติการ</h1><p>เลือกช่วงเวลาเพื่อสร้างคลาสเรียนแบบครั้งเดียวหรือทั้งภาคเรียน</p></div>
        <div class="schedule-header-actions">
            <a class="button button--primary" href="<?= e($oneOffOpenUrl) ?>" data-open-once aria-haspopup="dialog" aria-controls="one-off-dialog"><span data-icon="plus"></span>สร้างคลาสเรียน</a>
        </div>
    </header>
        <form method="get" class="schedule-filter" data-timetable-filter>
            <input type="hidden" name="page" value="schedule"><input type="hidden" name="week" value="<?= e($weekFrom) ?>"><input type="hidden" name="weekend" value="<?= $showWeekend ? '1' : '0' ?>">
            <label class="field"><span>ภาคการศึกษา</span><select name="term_id"><option value="0">ทุกภาคการศึกษา</option><?php foreach ($terms as $item): ?><option value="<?= $item['id'] ?>" data-academic-year="<?= $item['academic_year'] ?>" data-semester="<?= e($item['semester']) ?>" <?= $item['id']===$termId ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>ห้องปฏิบัติการ</span><select name="room_id"><option value="">ทุกห้อง</option><?php foreach ($rooms as $room): ?><option value="<?= $room['id'] ?>" <?= $roomFilter===(string)$room['id'] ? 'selected' : '' ?>><?= e($room['code'].' · '.$room['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>ค้นหาตาราง</span><input name="q" value="<?= e($search) ?>" placeholder="รหัสวิชา ชื่อวิชา หรือผู้สอน"></label>
            <button type="submit" class="button button--secondary">แสดงตาราง</button>
        </form>
        <div class="schedule-term-line"><p><?php if ($term): ?><strong><?= e($term['name']) ?></strong> · <?= e(thai_date_label($term['starts_on']).' – '.thai_date_label($term['ends_on'])) ?><?php else: ?>เลือกภาคเรียนได้ขณะสร้างคลาส ไม่ต้องเพิ่มภาคการศึกษาแยก<?php endif; ?></p>
            <?php if ($user['role']==='admin'): ?><a href="<?= e($scheduleReturnUrl.'&import=1') ?>" data-open-schedule="schedule-import" aria-haspopup="dialog">นำเข้าตารางจาก CSV</a><?php endif; ?>
        </div>
        <div class="schedule-toolbar" aria-label="เปลี่ยนสัปดาห์">
            <div class="schedule-week-nav"><div class="button-group"><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($scheduleQueryBase,['week'=>$weekStart->modify('-7 days')->format('Y-m-d')]))) ?>" aria-label="สัปดาห์ก่อน"><span data-icon="chevron-left"></span></a><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($scheduleQueryBase,['week'=>week_start_date()->format('Y-m-d')]))) ?>">สัปดาห์นี้</a><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($scheduleQueryBase,['week'=>$weekStart->modify('+7 days')->format('Y-m-d')]))) ?>" aria-label="สัปดาห์ถัดไป"><span data-icon="chevron-right"></span></a></div><h2><?= e(thai_date_label($weekFrom).' – '.thai_date_label($weekTo)) ?></h2></div>
            <div class="segmented" aria-label="จำนวนวันที่แสดง"><?php foreach ([0=>'จ.–ศ.',1=>'7 วัน'] as $weekend=>$label): ?><a href="?<?= e(http_build_query(array_replace($scheduleQueryBase,['weekend'=>$weekend]))) ?>" class="<?= (int)$showWeekend===$weekend ? 'is-active' : '' ?>" <?= (int)$showWeekend===$weekend ? 'aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></div>
        </div>
        <?php if ($outsideTerm): ?><div class="schedule-notice">สัปดาห์นี้อยู่นอกภาค <?= e($term['name']) ?> จะแสดงเฉพาะคลาสเรียนแบบครั้งเดียว <a href="?<?= e(http_build_query(array_replace($scheduleQueryBase,['week'=>$term['starts_on']]))) ?>">ไปสัปดาห์แรกของภาค</a></div><?php endif; ?>
        <section class="schedule-calendar-panel" aria-labelledby="weekly-calendar-title">
            <div class="schedule-table-heading"><h2 id="weekly-calendar-title"><?= $selectedRoom ? e($selectedRoom['code'].' · '.$selectedRoom['name']) : 'ตารางประจำสัปดาห์ · ทุกห้อง' ?></h2><span class="result-count"><?= count($events) ?> รายการ</span></div>
            <div class="schedule-instructions"><p id="timetable-instructions">เลือกเวลา: คลิกช่องเริ่ม → ช่องสุดท้ายในวันเดียวกัน · คลิกรายวิชาเพื่อดูรายละเอียด</p><div class="schedule-legend"><span><i class="legend-swatch"></i>ตารางรายสัปดาห์</span><span><i class="legend-swatch legend-swatch--once"></i>คลาสครั้งเดียว</span></div></div>
            <p class="schedule-notice" data-timetable-stale hidden>ตัวเลือกเปลี่ยนแล้ว กด “แสดงตาราง” เพื่ออัปเดตก่อนเลือกเวลา</p>
            <?php if ($loadError): ?><div class="alert alert--error" role="alert">โหลดตารางไม่สำเร็จ <a href="<?= e($scheduleReturnUrl) ?>">ลองอีกครั้ง</a></div>
            <?php else: ?>
                <?php if (!$events): ?><p class="schedule-empty-note"><?= $search !== '' ? 'ไม่พบรายการที่ค้นหา ลองเปลี่ยนคำค้นหรือห้องที่เลือก' : 'ยังไม่มีรายการในสัปดาห์นี้ เลือกช่วงเวลาในตารางเพื่อเริ่มเพิ่มได้เลย' ?></p><?php endif; ?>
                <div class="schedule-scroll" role="region" aria-label="ตารางเรียนรายสัปดาห์ เวลาแนวนอน วันแนวตั้ง" aria-describedby="timetable-instructions" tabindex="0">
                    <div class="schedule-week" data-horizontal-timetable style="--hour-count:<?= $timeline['hours'] ?>">
                        <div class="schedule-time-header"><div class="schedule-corner">วัน / เวลา</div><div class="schedule-hours"><?php for ($minute=$timeline['start']; $minute<$timeline['end']; $minute+=60): ?><div class="schedule-hour"><span><?= sprintf('%02d:00',$minute/60) ?></span></div><?php endfor; ?><span class="schedule-last-hour"><?= sprintf('%02d:00',$timeline['end']/60) ?></span></div></div>
                        <?php for ($day=1; $day<=$dayCount; $day++): $date=$weekStart->modify('+'.($day-1).' days'); $dateValue=$date->format('Y-m-d'); $dayLayout=$timeline['days'][$dateValue] ?? ['events'=>[],'lanes'=>1]; $isToday=$dateValue===date('Y-m-d'); ?>
                            <div class="schedule-day-row <?= $isToday ? 'is-today' : '' ?>" style="--lane-count:<?= $dayLayout['lanes'] ?>" data-schedule-date="<?= e($dateValue) ?>">
                                <div class="schedule-day-heading"><strong><?= e(thai_day_label($day)) ?></strong><span><?= e($date->format('d/m')) ?><?= $isToday ? ' · วันนี้' : '' ?></span></div>
                                <div class="schedule-day-track" data-schedule-day="<?= $day ?>">
                                    <div class="schedule-slot-row"><?php for ($minute=$timeline['start']; $minute<$timeline['end']; $minute+=60): $time=sprintf('%02d:00',$minute/60); ?><button type="button" class="schedule-empty-slot" data-slot-day="<?= $day ?>" data-slot-start="<?= $time ?>" aria-pressed="false" aria-label="เลือก<?= e(thai_day_label($day)) ?> เวลา <?= $time ?>"></button><?php endfor; ?></div>
                                    <?php foreach ($dayLayout['events'] as $event): $once=!$event['term_id']; $eventUrl=$event['class_id'] ? '?page=classes&class_id='.$event['class_id'] : '?'.http_build_query($scheduleQueryBase+['selected'=>$event['schedule_id']]); $eventLabel=$event['course_code'].' · '.$event['course_name'].' · '.$event['room_code'].' · '.$event['start_time'].'–'.$event['end_time'].' · '.$event['lecturer_name'].' · '.($once ? 'คลาสเรียนแบบครั้งเดียว' : 'ตารางรายสัปดาห์'); ?>
                                        <a class="schedule-block <?= $once ? 'schedule-block--once' : '' ?> <?= $selectedSchedule && $event['schedule_id']===$selectedSchedule['id'] ? 'is-selected' : '' ?>" style="--event-left:<?= e($event['left']) ?>%;--event-width:<?= e($event['width']) ?>%;--event-lane:<?= $event['lane'] ?>" href="<?= e($eventUrl) ?>" <?= $event['class_id'] ? 'data-class-id="'.(int)$event['class_id'].'"' : '' ?> aria-label="<?= e($eventLabel) ?>" title="<?= e($eventLabel) ?>"><strong><?= e($event['course_code']) ?> <span><?= e($event['room_code']) ?></span></strong><span><?= e($event['course_name']) ?></span><small><?= e($event['start_time'].'–'.$event['end_time']) ?><?= $once ? ' · ครั้งเดียว' : '' ?></small></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="schedule-selection-bar" data-schedule-selection-bar hidden><div><strong data-selection-summary></strong><span data-selection-hint></span></div><button type="button" class="button button--ghost" data-clear-schedule-range>ล้างช่วงเวลา</button><button type="button" class="button button--primary" data-use-schedule-range>ใช้ช่วงเวลานี้</button></div>
            <p class="schedule-footer-note">ช่องว่างยังไม่ยืนยันว่าจองได้ ระบบตรวจห้องและผู้สอนตลอดช่วงที่ใช้ตารางก่อนบันทึก<?= $user['role']==='lecturer' ? ' · แสดงเฉพาะรายการของคุณ' : '' ?></p>
        </section>
        <?php require __DIR__.'/schedule-dialogs.php'; ?>
    <details class="schedule-once-details"><summary>คลาสเรียนแบบครั้งเดียวในสัปดาห์นี้ <span>ดูรายการและ QR</span></summary><?php require __DIR__.'/one-off-week.php'; ?></details>
</section>
<script src="<?= e(asset_url('timetable.js')) ?>" defer></script>
