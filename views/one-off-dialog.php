<?php
$onceErrors=$_SESSION['one_off_errors'] ?? [];
$onceInput=$_SESSION['one_off_input'] ?? [];
if (isset($_GET['new_schedule']) && !empty($formErrors)) {
    $onceErrors=$formErrors;
    $legacyTerm=get_academic_term((int)($oldInput['term_id'] ?? 0));
    $onceInput=array_replace($oldInput ?? [],['class_mode'=>'semester','academic_year'=>$legacyTerm['academic_year'] ?? 2569,'semester'=>$legacyTerm['semester'] ?? '1']);
    $onceInput['slots_json']=json_encode([array_intersect_key($onceInput,array_flip(['room_id','day_of_week','starts_time','ends_time']))]);
}
unset($_SESSION['one_off_errors'],$_SESSION['one_off_input']);
$onceRequest=$onceInput['one_off_request'] ?? '';
if (!isset($_SESSION['one_off_requests'][$onceRequest]) || $_SESSION['one_off_requests'][$onceRequest]!==0) {
    $onceRequest=bin2hex(random_bytes(16)); $_SESSION['one_off_requests'][$onceRequest]=0;
    $_SESSION['one_off_requests']=array_slice($_SESSION['one_off_requests'],-20,null,true);
}
$catalog=nu_academic_presets();
$contextTerm=get_academic_term((int)($_GET['term_id'] ?? 0));
$onceValues=array_replace(['class_mode'=>'once','academic_year'=>$contextTerm['academic_year'] ?? array_key_first($catalog),'semester'=>$contextTerm['semester'] ?? '1','class_date'=>$_GET['once_date'] ?? date('Y-m-d'),'room_id'=>$_GET['room_id'] ?? '','lecturer_user_id'=>$user['id'],'course_code'=>'','course_name'=>'','section'=>'','notes'=>'','slots_json'=>'[]'],$onceInput);
$onceRooms=array_values(array_filter(list_rooms(),static fn($r)=>$r['status']==='available'));
?>
<dialog id="one-off-dialog" class="term-dialog class-create-dialog" aria-labelledby="one-off-title" <?= isset($_GET['new_once']) || isset($_GET['new_schedule']) || isset($_GET['new_term']) || $onceErrors?'open':'' ?>>
    <header class="term-dialog__header"><div><h2 id="one-off-title">สร้างคลาสเรียน</h2><p>เลือกได้หลายช่วงเวลาและหลายห้อง แล้วบันทึกพร้อมกันครั้งเดียว</p></div><a href="<?= e($oneOffReturnUrl) ?>" class="icon-button" data-close-once aria-label="ปิดหน้าต่างสร้างคลาสเรียน"><span data-icon="x"></span></a></header>
    <form method="post" action="<?= e($oneOffReturnUrl) ?>" data-one-off-form novalidate>
        <input type="hidden" name="action" value="create_class_batch"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="one_off_request" value="<?= e($onceRequest) ?>"><input type="hidden" name="slots_json" value="<?= e($onceValues['slots_json']) ?>">
        <div class="term-dialog__body">
            <fieldset class="class-mode-picker"><legend>รูปแบบคลาสเรียน</legend><div class="class-mode-options">
                <label><input type="radio" name="class_mode" value="once" <?= $onceValues['class_mode']!=='semester'?'checked':'' ?>><span><strong>ครั้งเดียว</strong><small>เลือกวันที่และช่วงเวลาที่ใช้ห้อง</small></span></label>
                <label><input type="radio" name="class_mode" value="semester" <?= $onceValues['class_mode']==='semester'?'checked':'' ?>><span><strong>ทั้งภาคเรียน</strong><small>ทำซ้ำทุกสัปดาห์ในช่วงที่เลือก</small></span></label>
            </div></fieldset>
            <?php if ($onceErrors): ?><div class="alert alert--error" data-once-errors role="alert" tabindex="-1"><strong>ยังไม่ได้สร้างคลาสเรียน</strong><span><?= e(implode(' ',array_values($onceErrors))) ?></span></div><?php endif; ?>
            <div class="form-grid class-create-context">
                <label class="field" data-class-mode-panel="semester"><span>ปีการศึกษา (พ.ศ.)</span><select name="academic_year"><?php foreach ($catalog as $year=>$item): ?><option value="<?= $year ?>" <?= (int)$onceValues['academic_year']===$year?'selected':'' ?>><?= $year ?></option><?php endforeach; ?></select></label>
                <label class="field" data-class-mode-panel="semester"><span>ภาคการศึกษา</span><select name="semester"><?php foreach (semester_labels() as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)$onceValues['semester']===(string)$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="field" data-class-mode-panel="once"><span>วันที่สำหรับช่วงใหม่</span><input type="date" name="class_date" value="<?= e($onceValues['class_date']) ?>"></label>
                <?php if ($user['role']==='admin'): ?><label class="field"><span>ผู้สอน</span><select name="lecturer_user_id" required><?php foreach (list_lecturers() as $lecturer): ?><option value="<?= $lecturer['id'] ?>" <?= (int)$onceValues['lecturer_user_id']===$lecturer['id']?'selected':'' ?>><?= e($lecturer['full_name']) ?></option><?php endforeach; ?></select></label><?php else: ?><input type="hidden" name="lecturer_user_id" value="<?= $user['id'] ?>"><?php endif; ?>
            </div>
            <p class="helper-text" data-class-term-dates data-class-mode-panel="semester"></p>
            <div class="form-grid class-course-fields">
                <label class="field"><span>รหัสรายวิชา</span><input name="course_code" value="<?= e($onceValues['course_code']) ?>" pattern="[A-Za-z0-9._-]{2,30}" maxlength="30" required></label>
                <label class="field"><span>ชื่อวิชา / กิจกรรม</span><input name="course_name" value="<?= e($onceValues['course_name']) ?>" minlength="2" maxlength="150" required></label>
                <label class="field"><span>กลุ่มเรียน (ไม่บังคับ)</span><input name="section" value="<?= e($onceValues['section']) ?>" maxlength="30"></label>
            </div>
            <div class="class-picker-heading"><div><h3>เลือกช่วงเวลาใช้ห้อง</h3><p>คลิกช่องเริ่มและช่องสุดท้ายเพื่อเพิ่มหนึ่งช่วง แล้วเลือกช่วงถัดไปได้ทันที</p></div><label class="field"><span>ห้องสำหรับช่วงใหม่</span><select name="room_id"><option value="">เลือกห้องปฏิบัติการ</option><?php foreach ($onceRooms as $room): ?><option value="<?= $room['id'] ?>" <?= (int)$onceValues['room_id']===$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label></div>
            <div class="class-picker-scroll" role="region" aria-label="เลือกเวลาแนวนอนและวันแนวตั้ง" tabindex="0"><div class="class-picker-grid" data-class-picker></div></div>
            <div class="class-pending-range"><span data-pending-range>เลือกห้อง แล้วคลิกช่วงเวลาในตาราง</span><button type="button" class="button button--secondary" data-add-range disabled>เพิ่มช่วงที่เลือก</button></div>
            <div class="section-heading"><h3>ช่วงเวลาที่เลือก <span data-slot-count>0</span></h3><span class="helper-text">แก้เวลาและห้องในแต่ละแถวได้</span></div>
            <div class="class-slot-list" data-class-slots></div>
            <p class="helper-text">เปิดรับลงชื่ออัตโนมัติก่อนเวลาเรียน 10 นาที ปิดเมื่อสิ้นสุดเวลาเรียน หรือเจ้าของคลาสกดปิดก่อน · QR แยกทุกคลาส</p>
            <label class="field"><span>หมายเหตุ (ไม่บังคับ)</span><textarea name="notes" rows="2" maxlength="500"><?= e($onceValues['notes']) ?></textarea></label>
            <noscript><p class="alert alert--error">กรุณาเปิด JavaScript เพื่อเลือกช่วงเวลาและตรวจสอบตารางก่อนบันทึก</p></noscript>
        </div>
        <footer class="term-dialog__actions class-create-footer">
            <div class="class-booking-summary"><strong data-class-summary>ยังไม่ได้เลือกช่วงเวลา</strong><p data-class-preview role="status">เลือกช่วงเวลาและกรอกรายวิชาให้ครบ ระบบจะตรวจทุกรายการก่อนบันทึก</p><button type="button" class="text-button" data-class-retry hidden>ตรวจสอบอีกครั้ง</button></div>
            <div class="class-create-buttons"><a href="<?= e($oneOffReturnUrl) ?>" class="button button--secondary" data-close-once>ยกเลิก</a><button type="submit" class="button button--primary" disabled>สร้างคลาสเรียน</button></div>
        </footer>
    </form>
</dialog>
<script type="application/json" data-class-catalog><?= json_encode($catalog,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
