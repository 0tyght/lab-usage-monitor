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
