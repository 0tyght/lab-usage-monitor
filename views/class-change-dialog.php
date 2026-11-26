<?php
$editId=(int)($_GET['edit_class'] ?? 0);
$editClass=$editId?get_class_session($editId):null;
$changeErrors=$_SESSION['class_change_errors'] ?? [];
$changeInput=$_SESSION['class_change_input'] ?? [];
unset($_SESSION['class_change_errors'],$_SESSION['class_change_input']);
if ($editId && !$editClass): ?><p class="alert alert--error">ไม่พบคลาสหรือไม่มีสิทธิ์แก้ไข</p><?php endif;
if ($editClass):
$viewer=current_user();
$defaultScope=$editClass['series_key']?'all':'once';
$requestedScope=(string)($_GET['scope'] ?? $defaultScope);
if (!in_array($requestedScope,['once','following','all'],true)) $requestedScope=$defaultScope;
$changeValues=array_replace(['scope'=>$requestedScope,'operation'=>'edit','room_id'=>$editClass['room_id'],'class_date'=>date('Y-m-d',strtotime($editClass['starts_at'])),'starts_time'=>date('H:i',strtotime($editClass['starts_at'])),'ends_time'=>date('H:i',strtotime($editClass['ends_at'])),'notes'=>$editClass['notes'],'course_code'=>$editClass['course_code'],'course_name'=>$editClass['course_name'],'section'=>$editClass['section'] ?? '', 'lecturer_user_id'=>$editClass['lecturer_user_id']],$changeInput);
$seriesLecturers=list_lecturers();
$activeLecturerIds=array_column($seriesLecturers,'id');
?>
<dialog class="term-dialog class-change-dialog" id="class-change-dialog" aria-labelledby="class-change-title" open>
    <header class="term-dialog__header">
        <div>
            <h2 id="class-change-title"><?= $editClass['series_key']?'แก้ไขคลาสและข้อมูลร่วมของชุด':'แก้ไขคลาสเรียน' ?></h2>
            <p><?= e($editClass['course_code'].' · '.thai_datetime($editClass['starts_at'])) ?></p>
        </div>
        <a class="icon-button" href="?page=classes&amp;range=all" data-close-change aria-label="ปิดหน้าต่างแก้ไข"><span data-icon="x"></span></a>
    </header>
    <form method="post" data-class-change-form data-confirm="ยืนยันเปลี่ยนคลาสตามรายการที่ตรวจสอบแล้ว? ประวัติการลงชื่อเดิมจะคงอยู่" data-confirm-title="ยืนยันการเปลี่ยนคลาส" data-confirm-label="ยืนยัน">
        <input type="hidden" name="action" value="change_class_batch">
        <input type="hidden" name="class_id" value="<?= $editClass['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="revision" value="<?= e($editClass['updated_at']) ?>">
        <div class="term-dialog__body">
            <?php if ($changeErrors): ?><div class="alert alert--error" role="alert"><?= e(implode(' ',$changeErrors)) ?></div><?php endif; ?>
            <div class="form-grid">
                <label class="field"><span>ต้องการทำอะไร</span><select name="operation"><option value="edit">แก้ไขข้อมูล</option><option value="cancel" <?= $changeValues['operation']==='cancel'?'selected':'' ?>>ยกเลิกคลาส</option></select></label>
                <label class="field"><span>คลาสที่จะเปลี่ยน</span><select name="scope"><option value="once" <?= $changeValues['scope']==='once'?'selected':'' ?>>เฉพาะครั้งนี้</option><?php if ($editClass['series_key']): ?><option value="following" <?= $changeValues['scope']==='following'?'selected':'' ?>>ครั้งนี้และคลาสถัดไป</option><option value="all" <?= $changeValues['scope']==='all'?'selected':'' ?>>ทุกคลาสในชุด</option><?php endif; ?></select></label>
            </div>
            <?php if ($editClass['series_key']): ?>
                <p class="inline-note" data-change-scope-note>รหัสวิชา ชื่อวิชา และกลุ่มเรียนจะซิงค์กับทุกคลาสในขอบเขต ส่วนคลาสที่มีประวัติจะคงห้อง เวลา ผู้สอน และประวัติการลงชื่อเดิม</p>
            <?php else: ?>
                <p class="helper-text">แก้ไขหรือยกเลิกได้เฉพาะคลาสที่ยังไม่เริ่มและยังไม่มีผู้ลงชื่อ</p>
            <?php endif; ?>
            <div data-change-fields>
                <section class="class-change-section" aria-labelledby="change-course-heading">
                    <h3 id="change-course-heading">ข้อมูลรายวิชาและผู้สอน</h3>
                    <p class="helper-text" data-change-shared-help>รหัสวิชา ชื่อวิชา และกลุ่มเรียนจะอัปเดตทุกคลาสที่เลือก แม้คลาสเก่ามีผู้ลงชื่อ ส่วนห้อง เวลา หมายเหตุ และผู้สอนจะแก้ได้เฉพาะคลาสที่ยังเปลี่ยนได้</p>
                    <div class="form-grid class-change-course-fields">
                        <label class="field"><span>รหัสวิชา</span><input name="course_code" value="<?= e($changeValues['course_code']) ?>" maxlength="30" required></label>
                        <label class="field"><span>ชื่อวิชา / กิจกรรม</span><input name="course_name" value="<?= e($changeValues['course_name']) ?>" maxlength="150" required></label>
                        <label class="field"><span>กลุ่มเรียน (ไม่บังคับ)</span><input name="section" value="<?= e($changeValues['section']) ?>" maxlength="30"></label>
                        <?php if ($viewer['role']==='admin'): ?>
                            <label class="field"><span>ผู้สอน</span><select name="lecturer_user_id" required><?php if (!in_array((int)$changeValues['lecturer_user_id'],$activeLecturerIds,true)): ?><option value="<?= (int)$editClass['lecturer_user_id'] ?>" selected><?= e($editClass['lecturer_name'].' · บัญชีปิดใช้งาน (คงผู้สอนเดิม)') ?></option><?php endif; ?><?php foreach ($seriesLecturers as $person): ?><option value="<?= $person['id'] ?>" <?= (int)$changeValues['lecturer_user_id']===$person['id']?'selected':'' ?>><?= e($person['full_name'].($person['role']==='admin'?' · ผู้ดูแลระบบ':'')) ?></option><?php endforeach; ?></select></label>
                        <?php else: ?>
                            <label class="field"><span>ผู้สอน</span><input value="<?= e($editClass['lecturer_name']) ?>" readonly><input type="hidden" name="lecturer_user_id" value="<?= (int)$editClass['lecturer_user_id'] ?>"></label>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="class-change-section" aria-labelledby="change-schedule-heading">
                    <h3 id="change-schedule-heading">วัน เวลา และห้อง</h3>
                    <div class="form-grid">
                        <label class="field"><span>วันที่เรียน</span><input type="date" name="class_date" value="<?= e($changeValues['class_date']) ?>" data-original-date="<?= e(date('Y-m-d',strtotime($editClass['starts_at']))) ?>" required></label>
                        <label class="field"><span>ห้องปฏิบัติการ</span><select name="room_id" required><?php foreach (list_rooms() as $room): if ($room['status']!=='available') continue; ?><option value="<?= $room['id'] ?>" <?= (int)$changeValues['room_id']===$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>เวลาเริ่ม</span><input type="time" name="starts_time" value="<?= e($changeValues['starts_time']) ?>" required></label>
                        <label class="field"><span>เวลาสิ้นสุด</span><input type="time" name="ends_time" value="<?= e($changeValues['ends_time']) ?>" required></label>
                    </div>
                    <p class="inline-note" data-bulk-time-help hidden>เมื่อแก้หลายคลาส ระบบจะเลื่อนเวลาแต่ละครั้งตามส่วนต่างที่แก้ และคงวันกับระยะเวลาของแต่ละครั้งไว้ หากเลือกห้องเดิมจะคงห้องเดิมของแต่ละคลาส หากเปลี่ยนห้องจะใช้ห้องใหม่กับทั้งหมด</p>
                    <label class="field"><span>หมายเหตุ</span><textarea name="notes" rows="2" maxlength="500"><?= e($changeValues['notes']) ?></textarea></label>
                </section>
            </div>
            <div class="class-change-preview">
                <strong data-change-summary role="status">กำลังตรวจรายการ…</strong>
                <p class="class-change-template-preview" data-change-template hidden></p>
                <div data-change-lessons></div>
                <button type="button" class="text-button" data-change-retry hidden>ลองตรวจอีกครั้ง</button>
            </div>
        </div>
        <footer class="term-dialog__actions"><a href="?page=classes&amp;range=all" class="button button--secondary" data-close-change>กลับ</a><button type="submit" class="button button--primary" disabled>ตรวจและยืนยัน</button></footer>
    </form>
</dialog>
<?php endif; ?>
