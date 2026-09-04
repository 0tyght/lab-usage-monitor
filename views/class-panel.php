<?php
// Used both by the authorized fragment endpoint and the class-list fallback.
$panelAttendance = class_attendance($panelClass['id']);
$panelStudentUrl = public_class_url($panelClass['qr_token']);
$panelStatus = class_display_status($panelClass);
$panelAction = '?'.http_build_query(['page'=>'classes','class_id'=>$panelClass['id']]);
$panelMode = $panelClass['checkin_mode'];
$panelPast = time() >= strtotime($panelClass['ends_at']);
?>
<div class="class-panel-content" data-panel-class="<?= $panelClass['id'] ?>" data-course-code="<?= e($panelClass['course_code']) ?>">
    <div class="class-panel-meta">
        <p class="inline-note">นักศึกษาสแกน QR หน้าห้อง แล้วเลือกคลาสนี้ได้ <a class="text-link" href="?page=rooms&amp;qr_room=<?= $panelClass['room_id'] ?>">เปิด QR ประจำห้อง / พิมพ์ป้าย</a></p>
        <h3><?= e($panelClass['course_code'].' · '.$panelClass['course_name']) ?></h3>
        <p><?= e($panelClass['room_code'].' — '.$panelClass['room_name']) ?></p>
        <p><?= e(thai_datetime($panelClass['starts_at']).' – '.thai_datetime($panelClass['ends_at'])) ?></p>
        <p><?= e($panelClass['lecturer_name']) ?><?= $panelClass['section']?' · กลุ่ม '.e($panelClass['section']):'' ?></p>
        <span class="status status--<?= e($panelStatus) ?>"><span></span><?= e(status_label($panelStatus)) ?></span>
        <p class="helper-text">เวลาเรียนและเวลารับลงชื่อเป็นคนละส่วน · เปิดรับเพิ่มไม่เปลี่ยนการจองห้องหรือเวลาในรายงาน</p>
    </div>
    <section class="class-admission-controls" aria-label="ควบคุมการรับลงชื่อ">
        <?php if ((int)$panelClass['admission_lead_minutes']===10 && $panelMode==='scheduled' && $panelClass['status']==='open'): ?><p class="inline-note">QR พร้อมใช้งาน · เปิดรับอัตโนมัติ <?= e(thai_datetime(gmdate('Y-m-d\TH:i:s\Z',strtotime($panelClass['starts_at'])-600))) ?> และสิ้นสุด <?= e(thai_datetime($panelClass['ends_at'])) ?> เจ้าของคลาสกดปิดก่อนกำหนดได้</p><?php endif; ?>
        <p class="inline-note"><?= $panelClass['status']==='cancelled'?'คลาสนี้ยกเลิกแล้ว ไม่รับลงชื่อและไม่จองห้องในตาราง':($panelClass['status']==='closed'?'เจ้าของคลาสปิดรับแล้ว ระบบจะไม่เปิดรับเองจนกว่าจะสั่งเปิดใหม่':($panelMode==='manual'?'วิธีรับลงชื่อ: ผู้สอนกดเปิดและปิดเอง ไม่หยุดรับตามเวลาสิ้นสุดเวลาเรียน':'วิธีรับลงชื่อ: ตามเวลาเรียน จะหยุดรับอัตโนมัติเมื่อถึง '.e(thai_datetime($panelClass['ends_at'])))) ?><?= $panelStatus==='overdue'?' · ยังไม่ได้กดปิดเอง แต่ระบบหยุดรับตามเวลาที่ตั้งไว้':'' ?></p>
        <?php if ($panelClass['status']!=='cancelled'): ?>
        <div class="admission-actions">
            <form method="post" action="<?= e($panelAction) ?>" data-confirm="ยืนยันวิธีรับลงชื่อที่เลือก? หากเลือกเปิดจนกดปิดเอง นักศึกษาที่มีลิงก์จะลงชื่อได้ทันทีแม้พ้นเวลาเรียน กรุณาปิดรับเมื่อเสร็จ" data-confirm-title="ยืนยันการเปิดรับลงชื่อ" data-confirm-label="ยืนยันเปิดรับ">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>">
                <label class="field"><span>วิธีเปิดรับลงชื่อ</span><select name="checkin_mode" aria-describedby="admission-mode-help"><option value="scheduled" <?= !$panelPast && $panelMode==='scheduled'?'selected':'' ?> <?= $panelPast?'disabled':'' ?>>รับตามเวลาที่กำหนด (ปิดอัตโนมัติ)</option><option value="manual" <?= $panelPast || $panelMode==='manual'?'selected':'' ?>>เปิดรับตอนนี้จนกดปิดเอง</option></select></label>
                <button class="button button--primary" type="submit"><?= $panelStatus==='open'?'ใช้วิธีที่เลือก':'เปิดรับการลงชื่อ' ?></button>
            </form>
            <?php if ($panelClass['status']==='open'): ?><form method="post" action="<?= e($panelAction) ?>" data-confirm="เมื่อปิดรับแล้ว นักศึกษาจะลงชื่อเพิ่มไม่ได้ ยืนยันหรือไม่?" data-confirm-title="ปิดรับการลงชื่อ" data-confirm-label="ปิดรับลงชื่อ"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_class"><input type="hidden" name="class_id" value="<?= $panelClass['id'] ?>"><button class="button button--secondary" type="submit">ปิดรับการลงชื่อ</button></form><?php endif; ?>
        </div>
        <p class="helper-text" id="admission-mode-help">เลือก “เปิดรับตอนนี้จนกดปิดเอง” เพื่อรับนอกเวลาเรียน การเลือกอย่างเดียวยังไม่เปลี่ยนสถานะ ต้องกดปุ่มยืนยันก่อน</p>
        <?php endif; ?>
    </section>
    <p><a class="button button--secondary" href="?page=classes&edit_class=<?= $panelClass['id'] ?>">แก้ไข / ยกเลิกคลาสเรียน</a></p>
    <div class="class-panel-columns">
        <section class="class-qr-section" aria-label="QR สำหรับคลาส">
            <p class="helper-text">QR เฉพาะคลาสเดิม · ใช้ส่งลิงก์ตรงได้ หากติดหน้าห้องให้ใช้ QR ประจำห้องด้านบน</p>
            <div class="class-qr-poster" data-qr-poster>
                <p class="qr-print-label">LUMS · สแกนเพื่อลงชื่อเข้าเรียน</p>
                <h3 data-poster-course><?= e($panelClass['course_code'].' · '.$panelClass['course_name']) ?></h3>
                <p data-poster-room><?= e($panelClass['room_code'].' — '.$panelClass['room_name']) ?></p>
                <p data-poster-time><?= e(thai_datetime($panelClass['starts_at']).' – '.thai_datetime($panelClass['ends_at'])) ?></p>
                <div class="class-qr-code" data-class-qr="<?= e($panelStudentUrl) ?>" role="img" aria-label="QR สำหรับ <?= e($panelClass['course_code']) ?>"><span>กำลังสร้าง QR…</span></div>
                <p class="qr-print-instruction">สแกน QR แล้วกรอกรหัสนิสิตและชื่อของตนเอง</p>
                <p class="qr-state-note" data-poster-state><?= e(status_label($panelStatus)) ?> · <?= $panelMode==='manual'?'ผู้สอนควบคุมการเปิด–ปิดรับ':((int)$panelClass['admission_lead_minutes']===10?'รับก่อนเรียน 10 นาที ถึงเวลาเลิกเรียน':'รับเฉพาะช่วงเวลาเรียน') ?></p>
            </div>
            <div class="class-qr-actions"><button class="button button--secondary" type="button" data-download-qr disabled>ดาวน์โหลด QR (PNG)</button><button class="button button--primary" type="button" data-print-qr disabled>พิมพ์ QR / PDF</button></div>
            <p class="class-panel-feedback" data-class-feedback role="status"></p>
            <label class="field"><span>ลิงก์ลงชื่อสำหรับนักศึกษา</span><input data-class-link value="<?= e($panelStudentUrl) ?>" readonly></label>
            <button class="button button--secondary" type="button" data-class-copy>คัดลอกลิงก์</button>
            <p class="helper-text">พิมพ์เฉพาะป้าย QR และข้อมูลคลาสเรียน ไม่รวมรายชื่อนักศึกษา</p>
        </section>
        <section class="class-panel-attendance" aria-label="รายชื่อผู้ลงชื่อ">
            <div class="section-heading"><div><h3>ผู้ลงชื่อเข้าเรียน</h3><p><?= count($panelAttendance) ?> คน · ไม่จำกัดจำนวนผู้ลงชื่อ</p></div><button class="button button--secondary" type="button" data-refresh-class>รีเฟรชรายชื่อ</button></div>
            <?php render_attendance_table($panelAttendance,false); ?>
            <?php if ($panelClass['notes']): ?><p class="inline-note">หมายเหตุ: <?= e($panelClass['notes']) ?></p><?php endif; ?>
        </section>
    </div>
</div>
