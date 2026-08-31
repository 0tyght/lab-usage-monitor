<?php
// Used both by the authorized fragment endpoint and the class-list fallback.
$panelAttendance = class_attendance($panelClass['id']);
$panelStudentUrl = public_class_url($panelClass['qr_token']);
$panelStatus = class_display_status($panelClass);
$panelAction = '?'.http_build_query(['page'=>'classes','class_id'=>$panelClass['id']]);
?>
<div class="class-panel-content" data-panel-class="<?= $panelClass['id'] ?>" data-course-code="<?= e($panelClass['course_code']) ?>">
    <div class="class-panel-meta">
        <h3><?= e($panelClass['course_code'].' · '.$panelClass['course_name']) ?></h3>
        <p><?= e($panelClass['room_code'].' — '.$panelClass['room_name']) ?></p>
        <p><?= e(thai_datetime($panelClass['starts_at']).' – '.thai_datetime($panelClass['ends_at'])) ?></p>
        <p><?= e($panelClass['lecturer_name']) ?><?= $panelClass['section']?' · กลุ่ม '.e($panelClass['section']):'' ?></p>
        <span class="status status--<?= e($panelStatus) ?>"><span></span><?= e(status_label($panelStatus)) ?></span>
    </div>
    <div class="class-panel-columns">
        <section class="class-qr-section" aria-label="QR สำหรับคลาส">
            <div class="class-qr-poster" data-qr-poster>
                <p class="qr-print-label">LUMS · สแกนเพื่อลงชื่อเข้าเรียน</p>
                <h3 data-poster-course><?= e($panelClass['course_code'].' · '.$panelClass['course_name']) ?></h3>
                <p data-poster-room><?= e($panelClass['room_code'].' — '.$panelClass['room_name']) ?></p>
                <p data-poster-time><?= e(thai_datetime($panelClass['starts_at']).' – '.thai_datetime($panelClass['ends_at'])) ?></p>
                <div class="class-qr-code" data-class-qr="<?= e($panelStudentUrl) ?>" role="img" aria-label="QR สำหรับ <?= e($panelClass['course_code']) ?>"><span>กำลังสร้าง QR…</span></div>
                <p class="qr-print-instruction">สแกน QR แล้วกรอกรหัสนิสิตและชื่อของตนเอง</p>
                <?php if (in_array($panelClass['status'],['draft','closed','cancelled'],true) || $panelStatus==='overdue'): ?><p class="qr-state-note" data-poster-state><?= e(status_label($panelStatus)) ?> · ไม่รับการลงชื่อ<?= $panelClass['status']==='draft'?' ผู้สอนต้องเปิดรับก่อนใช้งาน':'' ?></p><?php endif; ?>
            </div>
            <div class="class-qr-actions"><button class="button button--secondary" type="button" data-download-qr disabled>ดาวน์โหลด QR (PNG)</button><button class="button button--primary" type="button" data-print-qr disabled>พิมพ์ QR / PDF</button></div>
            <p class="class-panel-feedback" data-class-feedback role="status"></p>
            <label class="field"><span>ลิงก์ลงชื่อสำหรับนักศึกษา</span><input data-class-link value="<?= e($panelStudentUrl) ?>" readonly></label>
            <button class="button button--secondary" type="button" data-class-copy>คัดลอกลิงก์</button>
            <p class="helper-text">พิมพ์เฉพาะป้าย QR และข้อมูลคาบ ไม่รวมรายชื่อนักศึกษา</p>
            <div class="class-session-actions">
            <?php if ($panelClass['status']==='draft'): ?><form method="post" action="<?= e($panelAction) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><button class="button button--primary" type="submit">เปิดรับการลงชื่อ</button></form>
            <?php elseif ($panelClass['status']==='open'): ?><form method="post" action="<?= e($panelAction) ?>" data-confirm="เมื่อปิดรับแล้ว นักศึกษาจะลงชื่อเพิ่มไม่ได้ ยืนยันหรือไม่?" data-confirm-title="ปิดรับการลงชื่อ" data-confirm-label="ปิดรับลงชื่อ"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><button class="button button--secondary" type="submit">ปิดรับการลงชื่อ</button></form><?php endif; ?>
            </div>
        </section>
        <section class="class-panel-attendance" aria-label="รายชื่อผู้ลงชื่อ">
            <div class="section-heading"><div><h3>ผู้ลงชื่อเข้าเรียน</h3><p><?= count($panelAttendance) ?> / <?= e($panelClass['capacity']) ?> คน</p></div><button class="button button--secondary" type="button" data-refresh-class>รีเฟรชรายชื่อ</button></div>
            <?php render_attendance_table($panelAttendance,false); ?>
            <?php if ($panelClass['notes']): ?><p class="inline-note">หมายเหตุ: <?= e($panelClass['notes']) ?></p><?php endif; ?>
        </section>
    </div>
</div>
