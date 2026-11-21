<?php
// Used both by the authorized fragment endpoint and the class-list fallback.
$panelAttendance = class_attendance($panelClass['id']);
$panelStatus = class_display_status($panelClass);
$panelAction = '?'.http_build_query(['page'=>'classes','class_id'=>$panelClass['id']]);
$panelMode = $panelClass['checkin_mode'];
$panelPast = time() >= strtotime($panelClass['ends_at']);
?>
<div class="class-panel-content" data-panel-class="<?= $panelClass['id'] ?>" data-course-code="<?= e($panelClass['course_code']) ?>">
    <div class="class-panel-meta">
        <p class="inline-note">นักศึกษาสแกน QR ประจำห้องเพื่อเข้าและออก</p><p><a class="button button--primary" href="?page=rooms&amp;qr_room=<?= $panelClass['room_id'] ?>">เปิด QR ประจำห้อง / พิมพ์ป้าย</a></p>
        <h3><?= e($panelClass['course_code'].' · '.$panelClass['course_name']) ?></h3>
        <p><?= e($panelClass['room_code'].' — '.$panelClass['room_name']) ?></p>
        <p><?= e(thai_datetime($panelClass['starts_at']).' – '.thai_datetime($panelClass['ends_at'])) ?></p>
        <p><?= e($panelClass['lecturer_name']) ?><?= $panelClass['section']?' · กลุ่ม '.e($panelClass['section']):'' ?></p>
        <span class="status status--<?= e($panelStatus) ?>"><span></span><?= e(status_label($panelStatus)) ?></span>
    </div>
    <section class="class-admission-controls" aria-label="ควบคุมการรับลงชื่อ">
        <?php if (!$panelPast && (int)$panelClass['admission_lead_minutes']===10 && $panelMode==='scheduled' && $panelClass['status']==='open'): ?>
        <p class="inline-note">เปิดรับอัตโนมัติ <?= e(thai_datetime(gmdate('Y-m-d\TH:i:s\Z',strtotime($panelClass['starts_at'])-600))) ?> ถึง <?= e(thai_datetime($panelClass['ends_at'])) ?></p>
        <?php else: ?><p class="inline-note"><?= $panelClass['status']==='cancelled'?'คลาสนี้ยกเลิกแล้ว ไม่รับลงชื่อและไม่จองห้องในตาราง':($panelPast?'คลาสสิ้นสุดแล้ว ไม่สามารถเปิดรับหรือลงชื่อย้อนหลังได้':($panelClass['status']==='closed'?'เจ้าของคลาสปิดรับแล้ว เปิดรับใหม่ได้ก่อนเวลาเลิกคลาส':($panelMode==='manual'?'เปิดรับทันทีและหยุดรับเมื่อถึงเวลาเลิกคลาส':'รับตามกำหนด และหยุดรับเมื่อถึง '.e(thai_datetime($panelClass['ends_at']))))) ?></p><?php endif; ?>
        <?php if ($panelClass['status']!=='cancelled' && !$panelPast): ?>
        <div class="admission-actions">
            <form method="post" action="<?= e($panelAction) ?>" data-confirm="ยืนยันวิธีรับลงชื่อที่เลือก? ระบบจะหยุดรับเมื่อถึงเวลาเลิกคลาส" data-confirm-title="ยืนยันการเปิดรับลงชื่อ" data-confirm-label="ยืนยันเปิดรับ">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>">
                <label class="field"><span>วิธีเปิดรับลงชื่อ</span><select name="checkin_mode" aria-describedby="admission-mode-help"><option value="scheduled" <?= $panelMode==='scheduled'?'selected':'' ?>>รับตามเวลาที่กำหนด</option><option value="manual" <?= $panelMode==='manual'?'selected':'' ?>>เปิดรับทันทีจนถึงเวลาเลิกคลาส</option></select></label>
                <button class="button button--primary" type="submit">บันทึกวิธีเปิดรับ</button>
            </form>
            <?php if ($panelClass['status']==='open'): ?><form method="post" action="<?= e($panelAction) ?>" data-confirm="เมื่อระงับแล้ว คลาสนี้จะไม่รับลงชื่อจนกว่าผู้สอนจะเปิดรับใหม่ ยืนยันหรือไม่?" data-confirm-title="ระงับการลงชื่อ" data-confirm-label="ระงับการลงชื่อ"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><button class="button button--secondary" type="submit"><?= $panelStatus==='scheduled'?'ระงับการเปิดรับ':'ปิดรับการลงชื่อ' ?></button></form><?php endif; ?>
        </div>
        <p class="helper-text" id="admission-mode-help">ผู้สอนสามารถปิดรับก่อนเวลาเลิกคลาสได้</p>
        <?php endif; ?>
    </section>
    <p><a class="button button--secondary" href="?page=classes&edit_class=<?= $panelClass['id'] ?>">แก้ไข / ยกเลิกคลาสเรียน</a></p>
    <div class="class-panel-columns">
        <p class="class-print-help">หากต้องการพิมพ์ QR ให้เปิด QR ประจำห้องจากหน้ารายละเอียดคลาส</p>
        <section class="class-panel-attendance" aria-label="รายชื่อผู้ลงชื่อ">
            <div class="section-heading"><div><h3>ผู้ลงชื่อเข้าเรียน</h3><p><?= count(array_unique(array_column($panelAttendance,'student_code'))) ?> คน · <?= count($panelAttendance) ?> รอบเข้าใช้</p></div><button class="button button--secondary" type="button" data-refresh-class>รีเฟรชรายชื่อ</button></div>
            <?php render_attendance_table($panelAttendance,false); ?>
            <?php if ($panelClass['notes']): ?><p class="inline-note">หมายเหตุ: <?= e($panelClass['notes']) ?></p><?php endif; ?>
        </section>
    </div>
</div>
