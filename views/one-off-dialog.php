<?php
$onceErrors = $_SESSION['one_off_errors'] ?? [];
$onceInput = $_SESSION['one_off_input'] ?? [];
// Old bookmarked recurring-editor links and failed legacy submissions use the same dialog.
if (isset($_GET['new_schedule'])) {
    $onceErrors = $formErrors ?? [];
    $onceInput = array_replace($oldInput ?? [], ['class_mode'=>'semester']);
}
unset($_SESSION['one_off_errors'],$_SESSION['one_off_input']);
$onceRequest = $onceInput['one_off_request'] ?? '';
if (!isset($_SESSION['one_off_requests'][$onceRequest])) {
    $onceRequest = bin2hex(random_bytes(16));
    $_SESSION['one_off_requests'][$onceRequest] = 0;
    $_SESSION['one_off_requests'] = array_slice($_SESSION['one_off_requests'],-20,null,true);
}
$onceTerms = list_academic_terms();
$onceValues = array_replace(['class_mode'=>isset($_GET['new_schedule'])?'semester':'once', 'term_id'=>$_GET['term_id'] ?? current_academic_term()['id'] ?? '', 'day_of_week'=>date('N'), 'room_id'=>$_GET['room_id'] ?? '', 'lecturer_user_id'=>$user['id'], 'class_date'=>$_GET['once_date'] ?? date('Y-m-d'), 'starts_time'=>'09:00', 'ends_time'=>'10:00', 'course_code'=>'', 'course_name'=>'', 'section'=>'', 'notes'=>'', 'checkin_mode'=>'scheduled'],$onceInput);
$onceRooms = array_filter(list_rooms(),static fn(array $r): bool => $r['status']==='available');
?>
<dialog id="one-off-dialog" class="term-dialog one-off-dialog" aria-labelledby="one-off-title" aria-describedby="one-off-description" <?= isset($_GET['new_once']) || isset($_GET['new_schedule']) || $onceErrors?'open':'' ?>>
    <header class="term-dialog__header"><div><h2 id="one-off-title">สร้างคลาสเรียน</h2><p id="one-off-description">เลือกรูปแบบ แล้วระบุห้อง รายวิชา และเวลาใช้ห้อง</p></div><a class="icon-button" href="<?= e($oneOffReturnUrl) ?>" data-close-once aria-label="ปิดหน้าต่างสร้างคลาสเรียน"><span data-icon="x"></span></a></header>
    <form method="post" action="<?= e($oneOffReturnUrl) ?>" data-one-off-form novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_one_off"><input type="hidden" name="one_off_request" value="<?= e($onceRequest) ?>">
        <div class="term-dialog__body">
            <fieldset class="class-mode-picker"><legend>รูปแบบคลาสเรียน</legend><div class="class-mode-options">
                <label><input type="radio" name="class_mode" value="once" <?= $onceValues['class_mode']!=='semester'?'checked':'' ?>><span><strong>ครั้งเดียว</strong><small>ใช้ห้องเฉพาะวันที่เลือก</small></span></label>
                <label><input type="radio" name="class_mode" value="semester" <?= $onceValues['class_mode']==='semester'?'checked':'' ?>><span><strong>ทั้งภาคเรียน</strong><small>วันและเวลาเดิมทุกสัปดาห์</small></span></label>
            </div></fieldset>
            <?php if ($onceErrors): ?><div class="alert alert--error" role="alert" tabindex="-1" data-once-errors><strong>ยังไม่ได้สร้างคลาสเรียน</strong><span><?= e(implode(' ',array_values($onceErrors))) ?></span></div><?php endif; ?>
            <div data-class-mode-panel="semester" class="class-semester-fields">
                <div class="form-grid">
                    <label class="field"><span>ภาคการศึกษา</span><select name="term_id" required><option value="">เลือกภาคการศึกษา</option><?php foreach ($onceTerms as $item): ?><option value="<?= (int)$item['id'] ?>" data-start="<?= e($item['starts_on']) ?>" data-end="<?= e($item['ends_on']) ?>" <?= (string)$onceValues['term_id']===(string)$item['id']?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>วันเรียนทุกสัปดาห์</span><select name="day_of_week" required><?php for ($day=1;$day<=7;$day++): ?><option value="<?= $day ?>" <?= (int)$onceValues['day_of_week']===$day?'selected':'' ?>><?= e(thai_day_label($day)) ?></option><?php endfor; ?></select></label>
                </div>
                <?php if (!$onceTerms): ?><p class="alert alert--error">ยังไม่มีภาคการศึกษา <?= $user['role']==='admin'?'กรุณาเพิ่มภาคการศึกษาที่หน้าตารางเรียนก่อน':'กรุณาให้ผู้ดูแลเพิ่มภาคการศึกษาก่อน' ?> หรือเลือกสร้างแบบครั้งเดียวได้ทันที</p><?php endif; ?>
                <p class="helper-text" data-class-term-dates>ใช้วันเริ่ม–สิ้นสุดตามภาคการศึกษาที่เลือก ไม่ต้องกรอกวันที่เอง</p>
            </div>
            <div class="form-grid">
                <?php
                $onceFields = ['class_date'=>['วันที่ใช้ห้อง','date',true], 'room_id'=>['ห้องปฏิบัติการ','select',true], 'lecturer_user_id'=>['ผู้สอน','select',true], 'course_code'=>['รหัสรายวิชา','text',true], 'course_name'=>['ชื่อวิชา / กิจกรรม','text',true], 'section'=>['กลุ่มเรียน (ไม่บังคับ)','text',false]];
                foreach ($onceFields as $name=>[$label,$type,$required]):
                    if ($name==='lecturer_user_id' && $user['role']!=='admin'): ?><input type="hidden" name="lecturer_user_id" value="<?= (int)$user['id'] ?>"><?php continue; endif;
                ?>
                    <div class="field" <?= $name==='class_date'?'data-class-mode-panel="once"':'' ?>><label for="once-<?= $name ?>"><?= e($label) ?></label>
                    <?php if ($type==='select'): ?><select id="once-<?= $name ?>" name="<?= $name ?>" required aria-describedby="once-error-<?= $name ?>"><option value="">เลือก<?= e($label) ?></option><?php foreach ($name==='room_id'?$onceRooms:list_lecturers() as $option): ?><option value="<?= $option['id'] ?>" <?= (string)$onceValues[$name]===(string)$option['id']?'selected':'' ?>><?= e($name==='room_id'?$option['code'].' — '.$option['name']:$option['full_name']) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input id="once-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= e($onceValues[$name]) ?>" <?= $required?'required':'' ?> <?= $name==='course_code'?'pattern="[A-Za-z0-9._-]{2,30}" maxlength="30"':($name==='course_name'?'minlength="2" maxlength="150"':($name==='section'?'maxlength="30"':'')) ?> aria-describedby="once-error-<?= $name ?>"><?php endif; ?>
                    <span class="field-error" id="once-error-<?= $name ?>" <?= isset($onceErrors[$name])?'':'hidden' ?>><?= e($onceErrors[$name] ?? '') ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if (!$onceRooms): ?><p class="alert alert--error">ยังไม่มีห้องพร้อมใช้งาน กรุณาตรวจสถานะห้องก่อนสร้างคลาสเรียน</p><?php endif; ?>
            <div class="one-off-time-heading"><strong>เลือกช่วงเวลาได้หลายชั่วโมง</strong><small>คลิกช่องแรก แล้วคลิกช่องสุดท้ายเพื่อเลือกช่วงต่อเนื่อง หรือระบุเวลาเริ่ม–สิ้นสุดเอง</small></div>
            <p class="inline-note" role="status" data-once-availability>เลือกห้อง วันที่ และผู้สอนเพื่อตรวจเวลาว่าง</p>
            <div class="one-off-slots" data-once-slots aria-label="เลือกเวลาใช้ห้อง"></div>
            <div class="one-off-range-summary"><p class="inline-note" role="status" data-once-range>เลือกเวลาเริ่มและสิ้นสุดด้านล่าง</p>
            <button type="button" class="button button--secondary" data-once-reset-range>เริ่มเลือกช่วงใหม่</button></div>
            <button type="button" class="button button--secondary" data-once-retry hidden>ตรวจเวลาอีกครั้ง</button>
            <div class="form-grid">
                <?php foreach (['starts_time'=>'เวลาเริ่ม','ends_time'=>'เวลาสิ้นสุด'] as $name=>$label): ?><div class="field"><label for="once-<?= $name ?>"><?= $label ?></label><input id="once-<?= $name ?>" type="time" name="<?= $name ?>" value="<?= e($onceValues[$name]) ?>" required aria-describedby="once-error-<?= $name ?>"><span class="field-error" id="once-error-<?= $name ?>" <?= isset($onceErrors[$name])?'':'hidden' ?>><?= e($onceErrors[$name] ?? '') ?></span></div><?php endforeach; ?>
                <label class="field field--full" data-class-mode-panel="once"><span>การรับลงชื่อ</span><select name="checkin_mode" aria-describedby="once-mode-help"><option value="scheduled" <?= $onceValues['checkin_mode']==='scheduled'?'selected':'' ?>>รับตามเวลาเรียน และปิดอัตโนมัติเมื่อจบเวลาเรียน</option><option value="manual" <?= $onceValues['checkin_mode']==='manual'?'selected':'' ?>>เปิดรับทันทีเมื่อบันทึก จนกว่าผู้สอนกดปิดเอง</option></select></label>
            </div>
            <p class="helper-text" id="once-mode-help" data-class-mode-panel="once" data-once-mode-help data-local-now="<?= e(date('Y-m-d\TH:i')) ?>">คลาสเรียนที่เลือกปิดอัตโนมัติจะรับเฉพาะเวลาเรียน หากเป็นแบบร่าง ให้กดเปิดรับในหน้าคลาส ส่วนโหมดปิดเองจะเปิดรับทันทีแม้อยู่นอกเวลาเรียน</p>
            <label class="field"><span>หมายเหตุ (ไม่บังคับ)</span><textarea name="notes" rows="2" maxlength="500"><?= e($onceValues['notes']) ?></textarea></label>
            <noscript><p>ระบบจะตรวจเวลาชนเมื่อกดบันทึก หากมีข้อผิดพลาด ข้อมูลที่กรอกจะยังอยู่</p></noscript>
        </div>
        <footer class="term-dialog__actions class-create-footer">
            <section class="class-booking-summary" aria-label="สรุปก่อนสร้างคลาสเรียน"><strong>รายการที่จะบันทึก</strong><p data-class-booking-summary aria-live="polite">เลือกวันที่หรือภาคการศึกษา ห้อง และช่วงเวลา</p><small data-class-result-help>ระบบตรวจห้องและผู้สอนซ้ำอีกครั้งก่อนบันทึก</small></section>
            <div class="class-create-buttons"><a class="button button--secondary" href="<?= e($oneOffReturnUrl) ?>" data-close-once>ยกเลิก</a><button class="button button--primary" type="submit" <?= !$onceRooms?'disabled':'' ?>>สร้างคลาสเรียน</button></div>
        </footer>
    </form>
</dialog>
