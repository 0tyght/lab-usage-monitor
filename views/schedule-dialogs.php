<?php $scheduleFormErrors = isset($_GET['import']) ? [] : $formErrors; ?>
<dialog id="schedule-editor" class="term-dialog schedule-dialog" data-schedule-overlay aria-labelledby="schedule-form-title" <?= isset($_GET['new_schedule']) || $scheduleFormErrors ? 'open' : '' ?>>
    <header class="term-dialog__header"><div><h2 id="schedule-form-title">เพิ่มตารางเรียนรายสัปดาห์</h2><p>ทำซ้ำทุกสัปดาห์ในภาคการศึกษา · ถ้าใช้เพียงวันเดียว ให้ใช้ “สร้างคลาสเรียน”</p></div><a href="<?= e($scheduleReturnUrl) ?>" class="icon-button" data-close-schedule aria-label="ปิดหน้าต่างเพิ่มตารางเรียน"><span data-icon="x"></span></a></header>
    <form method="post" action="<?= e($scheduleReturnUrl) ?>" data-schedule-form novalidate>
        <div class="term-dialog__body">
            <?php if ($scheduleFormErrors): ?><div class="alert alert--error" role="alert" data-schedule-errors tabindex="-1"><strong>ยังไม่ได้บันทึกตาราง</strong><span><?= e(implode(' ',array_values($scheduleFormErrors))) ?></span></div><?php endif; ?>
            <div class="form-grid">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_schedule">
                                <label class="field"><span>ภาคการศึกษา <b>*</b></span><select name="term_id" required><?php foreach($terms as $item): ?><option value="<?= e($item['id']) ?>" <?= (string)($oldInput['term_id']??$termId)===(string)$item['id']?'selected':'' ?> data-start="<?= e($item['starts_on']) ?>" data-end="<?= e($item['ends_on']) ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>ห้องปฏิบัติการ <b>*</b></span><select name="room_id" required><option value="">เลือกห้อง</option><?php foreach($rooms as $room): if($room['status']!=='available') continue; ?><option value="<?= e($room['id']) ?>" <?= (string)($oldInput['room_id']??$roomFilter)===(string)$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>รหัสรายวิชา <b>*</b></span><input name="course_code" value="<?= e($oldInput['course_code']??'') ?>" placeholder="เช่น CPE221" maxlength="30" required></label>
                                <label class="field"><span>กลุ่มเรียน</span><input name="section" value="<?= e($oldInput['section']??'') ?>" placeholder="เช่น 1" maxlength="30"></label>
                                <label class="field field--full"><span>ชื่อรายวิชา <b>*</b></span><input name="course_name" value="<?= e($oldInput['course_name']??'') ?>" placeholder="ชื่อรายวิชาหรือปฏิบัติการ" maxlength="150" required></label>
                                <?php if($user['role']==='admin'): ?><label class="field field--full"><span>อาจารย์ผู้สอน <b>*</b></span><select name="lecturer_user_id" required><option value="">เลือกอาจารย์</option><?php foreach($lecturers as $lecturer): ?><option value="<?= e($lecturer['id']) ?>" <?= (string)($oldInput['lecturer_user_id']??'')===(string)$lecturer['id']?'selected':'' ?>><?= e($lecturer['full_name'].' · '.$lecturer['email']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <label class="field"><span>วันเรียน <b>*</b></span><select name="day_of_week" required><?php for($day=1;$day<=7;$day++): ?><option value="<?= e($day) ?>" <?= (string)($oldInput['day_of_week']??'1')===(string)$day?'selected':'' ?>><?= e(thai_day_label($day)) ?></option><?php endfor; ?></select></label>
                                <div class="time-field-pair"><label class="field"><span>เริ่ม <b>*</b></span><input type="time" name="starts_time" value="<?= e($oldInput['starts_time']??'09:00') ?>" step="1800" required></label><label class="field"><span>สิ้นสุด <b>*</b></span><input type="time" name="ends_time" value="<?= e($oldInput['ends_time']??'12:00') ?>" step="1800" required></label></div>
                                <label class="field"><span>เริ่มใช้ตาราง <b>*</b></span><input type="date" name="active_from" value="<?= e($oldInput['active_from']??$term['starts_on']??'') ?>" required></label>
                                <label class="field"><span>ใช้ถึงวันที่ <b>*</b></span><input type="date" name="active_until" value="<?= e($oldInput['active_until']??$term['ends_on']??'') ?>" required></label>
                                <label class="field field--full"><span>หมายเหตุ</span><textarea name="notes" rows="2" maxlength="500" placeholder="เช่น งดเรียนสัปดาห์สอบกลางภาค"><?= e($oldInput['notes']??'') ?></textarea></label>
                                <p class="helper-text field--full">ทำซ้ำในวันและเวลาเดิมทุกสัปดาห์ เฉพาะช่วงวันที่ด้านบน</p>

            </div>
        </div>
        <div class="schedule-preview" data-schedule-preview aria-live="polite"><span data-schedule-preview-text>กรอกข้อมูลให้ครบ ระบบจะตรวจเวลาซ้ำก่อนบันทึก</span><button type="button" class="text-button" data-retry-schedule-preview hidden>ลองตรวจอีกครั้ง</button></div>
        <footer class="term-dialog__actions"><a href="<?= e($scheduleReturnUrl) ?>" class="button button--secondary" data-close-schedule>ยกเลิก</a><button class="button button--primary" type="submit">บันทึกตารางเรียน</button></footer>
    </form>
</dialog>
<?php if ($selectedSchedule): ?>
<dialog id="schedule-detail" class="term-dialog schedule-detail-dialog" data-schedule-overlay aria-label="รายละเอียดตารางเรียน" open>
    <header class="term-dialog__header"><div><h2>รายละเอียดตารางเรียน</h2><p><?= e(thai_date_label($selectedDate)) ?> · ตารางรายสัปดาห์</p></div><a href="<?= e($scheduleReturnUrl) ?>" class="icon-button" data-close-schedule aria-label="ปิดรายละเอียดตาราง"><span data-icon="x"></span></a></header>
    <div class="term-dialog__body">
<p class="eyebrow">รายการที่เลือก</p><h2><?= e($selectedSchedule['course_code']) ?></h2><p><?= e($selectedSchedule['course_name']) ?><?= $selectedSchedule['section']?' · กลุ่ม '.e($selectedSchedule['section']):'' ?></p>
                                    <dl class="compact-details"><div><dt>วันและเวลา</dt><dd><?= e(thai_day_label($selectedSchedule['day_of_week']).' '.$selectedSchedule['starts_time'].'–'.$selectedSchedule['ends_time']) ?></dd></div><div><dt>ห้อง</dt><dd><?= e($selectedSchedule['room_code'].' · '.$selectedSchedule['room_name']) ?></dd></div><div><dt>ผู้สอน</dt><dd><?= e($selectedSchedule['lecturer_name']) ?></dd></div><div><dt>ช่วงใช้งาน</dt><dd><?= e($selectedSchedule['active_from'].' – '.$selectedSchedule['active_until']) ?></dd></div></dl>
                                    <?php if($selectedDate >= $selectedSchedule['active_from'] && $selectedDate <= $selectedSchedule['active_until']): ?><form method="post" class="form-stack schedule-qr-action"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_schedule_session"><input type="hidden" name="schedule_id" value="<?= e($selectedSchedule['id']) ?>"><label class="field"><span>วันที่ต้องการสร้าง QR</span><input type="date" name="scheduled_date" value="<?= e($selectedDate) ?>" min="<?= e($selectedSchedule['active_from']) ?>" max="<?= e($selectedSchedule['active_until']) ?>" required></label><button class="button button--primary button--block" type="submit"><span data-icon="qr-code"></span>เตรียม QR สำหรับคลาสเรียนนี้</button></form><?php else: ?><div class="inline-note">สัปดาห์นี้อยู่นอกช่วงใช้งานของตารางรายการนี้</div><?php endif; ?>
                                    <form method="post" class="schedule-cancel-action" data-confirm="ตารางรายสัปดาห์นี้จะถูกยกเลิก และ QR แบบร่างของคลาสในอนาคตจะถูกยกเลิกด้วย ข้อมูลการเข้าเรียนเดิมจะยังคงอยู่" data-confirm-title="ยกเลิกตารางเรียน" data-confirm-label="ยกเลิกตาราง" data-confirm-danger="true">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_schedule"><input type="hidden" name="schedule_id" value="<?= e($selectedSchedule['id']) ?>"><input type="hidden" name="term_id" value="<?= e($termId) ?>">
                                        <button class="button button--danger-ghost button--block" type="submit"><span data-icon="calendar-x"></span>ยกเลิกตารางนี้</button>
                                    </form>
    </div>
    <footer class="term-dialog__actions"><a href="<?= e($scheduleReturnUrl) ?>" class="button button--secondary" data-close-schedule>ปิดหน้าต่าง</a></footer>
</dialog>
<?php endif; ?>
<?php if ($user['role']==='admin'): ?>
<dialog id="schedule-import" class="term-dialog schedule-dialog" data-schedule-overlay aria-labelledby="schedule-import-title" <?= isset($_GET['import']) ? 'open' : '' ?>>
    <header class="term-dialog__header"><div><h2 id="schedule-import-title">นำเข้าตารางทั้งเทอม</h2><p>ใช้ไฟล์ CSV เพิ่มหลายรายวิชาในครั้งเดียว</p></div><a href="<?= e($scheduleReturnUrl) ?>" class="icon-button" data-close-schedule aria-label="ปิดหน้าต่างนำเข้า"><span data-icon="x"></span></a></header>
    <form method="post" action="<?= e($scheduleReturnUrl) ?>" enctype="multipart/form-data">
        <div class="term-dialog__body form-stack">
            <?php if ($formErrors && isset($_GET['import'])): ?><div class="alert alert--error" role="alert" tabindex="-1" data-schedule-errors><strong>ยังไม่ได้นำเข้าข้อมูล</strong><span><?= e(implode(' ',array_values($formErrors))) ?></span></div><?php endif; ?>
            <ol class="import-steps"><li>ดาวน์โหลดไฟล์ตัวอย่างและกรอกหนึ่งรายวิชาต่อแถว</li><li>เลือกภาคการศึกษาและไฟล์ที่ต้องการนำเข้า</li><li>ระบบตรวจทั้งชุด หากมีข้อผิดพลาดจะไม่บันทึกบางส่วน</li></ol>
            <a class="button button--secondary" href="?download=schedule-template"><span data-icon="download"></span>ดาวน์โหลด CSV ตัวอย่าง</a>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_schedule">
            <label class="field"><span>ภาคการศึกษา</span><select name="term_id" required><?php foreach ($terms as $item): ?><option value="<?= $item['id'] ?>" <?= $item['id']===$termId ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>ไฟล์ตารางเรียน (.csv สูงสุด 2 MB)</span><input type="file" name="schedule_file" accept=".csv,text/csv" required></label>
        </div>
        <footer class="term-dialog__actions"><a href="<?= e($scheduleReturnUrl) ?>" class="button button--secondary" data-close-schedule>ยกเลิก</a><button class="button button--primary" type="submit">ตรวจสอบและนำเข้า</button></footer>
    </form>
</dialog>
<?php endif; ?>
