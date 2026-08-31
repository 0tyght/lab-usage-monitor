<?php
$onceErrors = $_SESSION['one_off_errors'] ?? [];
$onceInput = $_SESSION['one_off_input'] ?? [];
unset($_SESSION['one_off_errors'],$_SESSION['one_off_input']);
$onceRequest = $onceInput['one_off_request'] ?? '';
if (!isset($_SESSION['one_off_requests'][$onceRequest])) {
    $onceRequest = bin2hex(random_bytes(16));
    $_SESSION['one_off_requests'][$onceRequest] = 0;
    $_SESSION['one_off_requests'] = array_slice($_SESSION['one_off_requests'],-20,null,true);
}
$onceValues = array_replace(['room_id'=>$_GET['room_id'] ?? '', 'lecturer_user_id'=>$user['id'], 'class_date'=>$_GET['once_date'] ?? date('Y-m-d'), 'starts_time'=>'09:00', 'ends_time'=>'10:00', 'course_code'=>'', 'course_name'=>'', 'section'=>'', 'notes'=>''],$onceInput);
$onceRooms = array_filter(list_rooms(),static fn(array $r): bool => $r['status']==='available');
?>
<dialog id="one-off-dialog" class="term-dialog one-off-dialog" aria-labelledby="one-off-title" aria-describedby="one-off-description" <?= isset($_GET['new_once']) || $onceErrors?'open':'' ?>>
    <header class="term-dialog__header"><div><h2 id="one-off-title">เพิ่มคาบครั้งเดียว</h2><p id="one-off-description">ใช้เฉพาะวันที่เลือก ไม่ทำซ้ำรายสัปดาห์ และไม่ต้องสร้างภาคการศึกษา</p></div><a class="icon-button" href="<?= e($oneOffReturnUrl) ?>" data-close-once aria-label="ปิดหน้าต่างเพิ่มคาบ"><span data-icon="x"></span></a></header>
    <form method="post" action="<?= e($oneOffReturnUrl) ?>" data-one-off-form novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_one_off"><input type="hidden" name="one_off_request" value="<?= e($onceRequest) ?>">
        <div class="term-dialog__body">
            <?php if ($onceErrors): ?><div class="alert alert--error" role="alert" tabindex="-1" data-once-errors><strong>ยังไม่ได้บันทึกคาบ</strong><span><?= e(implode(' ',array_values($onceErrors))) ?></span></div><?php endif; ?>
            <div class="form-grid">
                <?php
                $onceFields = ['class_date'=>['วันที่ใช้ห้อง','date',true], 'room_id'=>['ห้องปฏิบัติการ','select',true], 'lecturer_user_id'=>['ผู้สอน','select',true], 'course_code'=>['รหัสรายวิชา','text',true], 'course_name'=>['ชื่อวิชา / กิจกรรม','text',true], 'section'=>['กลุ่มเรียน (ไม่บังคับ)','text',false]];
                foreach ($onceFields as $name=>[$label,$type,$required]):
                    if ($name==='lecturer_user_id' && $user['role']!=='admin'): ?><input type="hidden" name="lecturer_user_id" value="<?= (int)$user['id'] ?>"><?php continue; endif;
                ?>
                    <div class="field"><label for="once-<?= $name ?>"><?= e($label) ?></label>
                    <?php if ($type==='select'): ?><select id="once-<?= $name ?>" name="<?= $name ?>" required aria-describedby="once-error-<?= $name ?>"><option value="">เลือก<?= e($label) ?></option><?php foreach ($name==='room_id'?$onceRooms:list_lecturers() as $option): ?><option value="<?= $option['id'] ?>" <?= (string)$onceValues[$name]===(string)$option['id']?'selected':'' ?>><?= e($name==='room_id'?$option['code'].' — '.$option['name']:$option['full_name']) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input id="once-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= e($onceValues[$name]) ?>" <?= $required?'required':'' ?> <?= $name==='course_code'?'pattern="[A-Za-z0-9._-]{2,30}" maxlength="30"':($name==='course_name'?'minlength="2" maxlength="150"':($name==='section'?'maxlength="30"':'')) ?> aria-describedby="once-error-<?= $name ?>"><?php endif; ?>
                    <span class="field-error" id="once-error-<?= $name ?>" <?= isset($onceErrors[$name])?'':'hidden' ?>><?= e($onceErrors[$name] ?? '') ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if (!$onceRooms): ?><p class="alert alert--error">ยังไม่มีห้องพร้อมใช้งาน กรุณาตรวจสถานะห้องก่อนเพิ่มคาบ</p><?php endif; ?>
            <div class="one-off-time-heading"><strong>เลือกช่วงเวลา</strong><small>คลิกช่องว่างเพื่อเลือก 1 ชั่วโมง หรือระบุเวลาเองด้านล่าง</small></div>
            <p class="inline-note" role="status" data-once-availability>เลือกห้อง วันที่ และผู้สอนเพื่อตรวจเวลาว่าง</p>
            <div class="one-off-slots" data-once-slots aria-label="เลือกเวลาใช้ห้อง"></div>
            <button type="button" class="button button--secondary" data-once-retry hidden>ตรวจเวลาอีกครั้ง</button>
            <div class="form-grid">
                <?php foreach (['starts_time'=>'เวลาเริ่ม','ends_time'=>'เวลาสิ้นสุด'] as $name=>$label): ?><div class="field"><label for="once-<?= $name ?>"><?= $label ?></label><input id="once-<?= $name ?>" type="time" name="<?= $name ?>" value="<?= e($onceValues[$name]) ?>" required aria-describedby="once-error-<?= $name ?>"><span class="field-error" id="once-error-<?= $name ?>" <?= isset($onceErrors[$name])?'':'hidden' ?>><?= e($onceErrors[$name] ?? '') ?></span></div><?php endforeach; ?>
                <label class="field field--full"><span>หมายเหตุ (ไม่บังคับ)</span><textarea name="notes" rows="2" maxlength="500"><?= e($onceValues['notes']) ?></textarea></label>
            </div>
            <noscript><p>ระบบจะตรวจเวลาชนเมื่อกดบันทึก หากมีข้อผิดพลาด ข้อมูลที่กรอกจะยังอยู่</p></noscript>
        </div>
        <footer class="term-dialog__actions"><a class="button button--secondary" href="<?= e($oneOffReturnUrl) ?>" data-close-once>ยกเลิก</a><button class="button button--primary" type="submit" <?= !$onceRooms?'disabled':'' ?>>บันทึกคาบและเตรียม QR</button></footer>
    </form>
</dialog>
