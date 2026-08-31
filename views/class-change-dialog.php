<?php
$editId=(int)($_GET['edit_class'] ?? 0);
$editClass=$editId?get_class_session($editId):null;
$changeErrors=$_SESSION['class_change_errors'] ?? [];
$changeInput=$_SESSION['class_change_input'] ?? [];
unset($_SESSION['class_change_errors'],$_SESSION['class_change_input']);
if ($editId && !$editClass): ?><p class="alert alert--error">ไม่พบคลาสหรือไม่มีสิทธิ์แก้ไข</p><?php endif;
if ($editClass):
$changeValues=array_replace(['scope'=>'once','operation'=>'edit','room_id'=>$editClass['room_id'],'class_date'=>date('Y-m-d',strtotime($editClass['starts_at'])),'starts_time'=>date('H:i',strtotime($editClass['starts_at'])),'ends_time'=>date('H:i',strtotime($editClass['ends_at'])),'notes'=>$editClass['notes']],$changeInput);
?>
<dialog class="term-dialog class-change-dialog" id="class-change-dialog" aria-labelledby="class-change-title" open>
    <header class="term-dialog__header"><div><h2 id="class-change-title">แก้ไข / ยกเลิกคลาสเรียน</h2><p><?= e($editClass['course_code'].' · '.thai_datetime($editClass['starts_at'])) ?></p></div><a class="icon-button" href="?page=classes&range=all" data-close-change aria-label="ปิดหน้าต่างแก้ไข"><span data-icon="x"></span></a></header>
    <form method="post" data-class-change-form data-confirm="ยืนยันเปลี่ยนคลาสตามรายการที่ตรวจสอบแล้ว? ประวัติการลงชื่อเดิมจะคงอยู่" data-confirm-title="ยืนยันการเปลี่ยนคลาส" data-confirm-label="ยืนยัน">
        <input type="hidden" name="action" value="change_class_batch"><input type="hidden" name="class_id" value="<?= $editClass['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revision" value="<?= e($editClass['updated_at']) ?>">
        <div class="term-dialog__body">
            <?php if ($changeErrors): ?><div class="alert alert--error" role="alert"><?= e(implode(' ',$changeErrors)) ?></div><?php endif; ?>
            <div class="form-grid">
                <label class="field"><span>ต้องการทำอะไร</span><select name="operation"><option value="edit">แก้ไขคลาส</option><option value="cancel" <?= $changeValues['operation']==='cancel'?'selected':'' ?>>ยกเลิกคลาส</option></select></label>
                <label class="field"><span>ขอบเขตการเปลี่ยนแปลง</span><select name="scope"><option value="once">เฉพาะครั้งนี้</option><?php if ($editClass['series_key']): ?><option value="following" <?= $changeValues['scope']==='following'?'selected':'' ?>>ครั้งนี้และครั้งถัดไปในชุด</option><option value="all" <?= $changeValues['scope']==='all'?'selected':'' ?>>ทั้งชุดที่สร้างพร้อมกัน</option><?php endif; ?></select></label>
            </div>
            <p class="helper-text">คลาสที่เริ่มแล้วหรือมีผู้ลงชื่อจะไม่เปลี่ยนย้อนหลัง การยกเลิกไม่ลบคลาสหรือรายชื่อเดิม และ QR ของคลาสที่ยกเลิกจะหยุดรับลงชื่อ</p>
            <div data-change-fields>
                <div class="form-grid">
                    <label class="field"><span>วันที่เรียน</span><input type="date" name="class_date" value="<?= e($changeValues['class_date']) ?>" data-original-date="<?= e(date('Y-m-d',strtotime($editClass['starts_at']))) ?>" required></label>
                    <label class="field"><span>ห้องปฏิบัติการ</span><select name="room_id" required><?php foreach (list_rooms() as $room): if ($room['status']!=='available') continue; ?><option value="<?= $room['id'] ?>" <?= (int)$changeValues['room_id']===$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>เวลาเริ่ม</span><input type="time" name="starts_time" value="<?= e($changeValues['starts_time']) ?>" required></label>
                    <label class="field"><span>เวลาสิ้นสุด</span><input type="time" name="ends_time" value="<?= e($changeValues['ends_time']) ?>" required></label>
                </div>
                <p class="inline-note" data-bulk-time-help hidden>เมื่อเลือกหลายคลาส ระบบจะเลื่อนเวลาเริ่มและสิ้นสุดแต่ละช่วงเท่ากับที่แก้จากครั้งนี้ โดยคงวันเดิม ถ้าเปลี่ยนห้องจะใช้ห้องใหม่กับทุกรายการในขอบเขต</p>
                <label class="field"><span>หมายเหตุ</span><textarea name="notes" rows="2" maxlength="500"><?= e($changeValues['notes']) ?></textarea></label>
            </div>
            <div class="class-change-preview"><strong data-change-summary role="status">กำลังตรวจรายการ…</strong><div data-change-lessons></div><button type="button" class="text-button" data-change-retry hidden>ลองตรวจอีกครั้ง</button></div>
        </div>
        <footer class="term-dialog__actions"><a href="?page=classes&range=all" class="button button--secondary" data-close-change>กลับ</a><button type="submit" class="button button--primary" disabled>ตรวจและยืนยัน</button></footer>
    </form>
</dialog>
<?php endif; ?>
