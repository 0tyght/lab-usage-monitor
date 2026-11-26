<?php
// Shared by the authorized fragment endpoint and full-page class details.
$panelAttendance = class_attendance($panelClass['id']);
$panelStatus = class_display_status($panelClass);
$panelAction = '?'.http_build_query(($panelReturnQuery ?? ['page'=>'classes'])+['class_id'=>$panelClass['id']]);
$start = strtotime($panelClass['starts_at']);
$end = strtotime($panelClass['ends_at']);
$now = time();
$opens = $start - 60 * (int)$panelClass['admission_lead_minutes'];
$canManage = $end > $now && $panelStatus !== 'cancelled';
$editClassId = $start > $now && !$panelClass['attendance_count'] && $panelStatus !== 'cancelled' ? $panelClass['id'] : null;
if (!$editClassId && $panelClass['series_key']) {
    $editable = db()->prepare("SELECT cs.id FROM class_sessions cs WHERE cs.series_key=? AND cs.lecturer_user_id=? AND cs.starts_at>? AND cs.status<>'cancelled' AND NOT EXISTS (SELECT 1 FROM attendance_records ar WHERE ar.class_session_id=cs.id) ORDER BY cs.starts_at,cs.id LIMIT 1");
    $editable->execute([$panelClass['series_key'],$panelClass['lecturer_user_id'],utc_now()]);
    $editClassId = (int)$editable->fetchColumn();
}
$endLabel = date('Y-m-d',$start) === date('Y-m-d',$end) ? date('H:i',$end) : thai_date_label(date('Y-m-d',$end)).' '.date('H:i',$end);
$openLabel = date('Y-m-d',$opens) === date('Y-m-d',$now) ? date('H:i',$opens) : thai_date_label(date('Y-m-d',$opens)).' '.date('H:i',$opens);
?>
<div class="class-panel-content" data-panel-class="<?= $panelClass['id'] ?>" data-course-code="<?= e($panelClass['course_code']) ?>">
    <div class="class-panel-meta">
        <h3><?= e($panelClass['course_name']) ?></h3>
        <p><?= e($panelClass['course_code']) ?><?= $panelClass['section']?' · กลุ่ม '.e($panelClass['section']):'' ?> · <?= e($panelClass['lecturer_name']) ?></p>
        <p><strong><?= e(thai_date_label(date('Y-m-d',$start)).' · '.date('H:i',$start).'–'.$endLabel.' น.') ?></strong> · <?= e($panelClass['room_code'].' '.$panelClass['room_name']) ?></p>
        <div class="class-context-actions">
            <a class="text-link" href="?page=rooms&amp;qr_room=<?= $panelClass['room_id'] ?>">QR ประจำห้อง / พิมพ์ป้าย</a>
            <?php if ($editClassId): ?><a class="text-link" href="?page=classes&amp;edit_class=<?= $editClassId ?><?= $panelClass['series_key']?'&amp;scope=all':'' ?>"><?= $panelClass['series_key']?'แก้ไขชุดคลาส':'แก้ไขคลาสนี้' ?></a><?php endif; ?>
        </div>
    </div>
    <section class="class-admission-controls" aria-label="การรับลงชื่อ">
        <div class="admission-state">
            <span class="status status--<?= e($panelStatus) ?>"><span></span><?= e(status_label($panelStatus)) ?></span>
            <?php if ($panelStatus==='scheduled'): ?><span>เปิดอัตโนมัติ <?= e($openLabel) ?> น.</span>
            <?php elseif ($panelStatus==='open'): ?><span>รับถึง <?= e(thai_datetime($panelClass['ends_at'],date('Y-m-d',$end)!==date('Y-m-d',$now))) ?></span>
            <?php elseif ($end <= $now && $panelStatus !== 'cancelled'): ?><span>คลาสสิ้นสุดแล้ว ไม่สามารถเปิดรับหรือลงชื่อย้อนหลังได้</span><?php endif; ?>
        </div>
        <?php if ($canManage): ?>
        <div class="admission-actions">
            <?php if ($panelStatus!=='open'): ?>
            <form method="post" action="<?= e($panelAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><input type="hidden" name="checkin_mode" value="<?= $now < $opens ? 'manual':'scheduled' ?>">
                <button class="button button--secondary" type="submit"><?= $panelStatus==='scheduled'?'เปิดรับก่อนเวลา':'เปิดรับลงชื่อตอนนี้' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($now < $opens && $panelStatus!=='scheduled'): ?>
            <form method="post" action="<?= e($panelAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><input type="hidden" name="checkin_mode" value="scheduled">
                <button class="button button--secondary" type="submit">ตั้งให้เปิด <?= e($openLabel) ?> น.</button>
            </form>
            <?php endif; ?>
            <?php if ($panelClass['status']==='open'): ?>
            <form method="post" action="<?= e($panelAction) ?>" data-confirm="นิสิตจะลงชื่อเข้าคลาสนี้ไม่ได้จนกว่าจะเปิดรับอีกครั้ง รายชื่อที่บันทึกแล้วจะยังอยู่" data-confirm-title="ปิดรับการลงชื่อคลาสนี้?" data-confirm-label="ยืนยันปิดรับ">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>">
                <button class="button button--secondary" type="submit"><?= $panelStatus==='scheduled'?'ระงับการเปิดรับ':'ปิดรับการลงชื่อ' ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <div class="class-panel-columns">
        <p class="class-print-help">พิมพ์ป้ายได้จาก “QR ประจำห้อง / พิมพ์ป้าย”</p>
        <section class="class-panel-attendance" aria-labelledby="panel-roster-title">
            <div class="section-heading"><div><h3 id="panel-roster-title">รายชื่อผู้เข้าเรียน</h3><?php if ($panelAttendance): ?><p><?= count(array_unique(array_column($panelAttendance,'student_code'))) ?> คน · <?= count($panelAttendance) ?> รอบเข้าใช้</p><?php endif; ?></div><button class="button button--secondary" type="button" data-refresh-class>รีเฟรชรายชื่อ</button></div>
            <p class="class-panel-feedback" data-roster-feedback role="status"></p>
            <?php if ($panelAttendance): render_attendance_table($panelAttendance,false); else: ?>
                <p class="roster-empty"><?= $end <= $now || $panelStatus==='cancelled'?'ไม่มีผู้ลงชื่อในคลาสนี้':'ยังไม่มีผู้ลงชื่อเข้าเรียน' ?></p>
            <?php endif; ?>
            <?php if ($panelClass['notes']): ?><p class="inline-note">หมายเหตุ <?= e($panelClass['notes']) ?></p><?php endif; ?>
        </section>
    </div>
</div>
