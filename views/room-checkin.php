<?php
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$roomToken=(string)($_GET['token'] ?? '');
$scanRoom=public_room($roomToken);
$visitInput=$_SESSION['room_visit_input'] ?? []; unset($_SESSION['room_visit_input']);
$classInput=$_SESSION['room_class_input'] ?? []; unset($_SESSION['room_class_input']);
$classSuccess=$_SESSION['room_class_success'] ?? null; unset($_SESSION['room_class_success']);
if ($classSuccess && !hash_equals($roomToken,(string)$classSuccess['token'])) $classSuccess=null;
$receipt=null; $roomClasses=[]; $legacyPlan=false; $holder=null;
if ($scanRoom) {
    close_expired_room_usage(db());
    $holder=$_SESSION['room_visit_holders'][$roomToken] ?? null;
    if (!$holder && isset($_COOKIE['lums_visit_'.$roomToken])) {
        $savedReceipt=(string)$_COOKIE['lums_visit_'.$roomToken];
        $savedVisit=room_visit_receipt($savedReceipt);
        if ($savedVisit && (int)$savedVisit['room_id']===(int)$scanRoom['id']) {
            $holder=['receipt'=>$savedReceipt,'request'=>bin2hex(random_bytes(16)),'visit_id'=>$savedVisit['id']];
            $_SESSION['room_visit_holders'][$roomToken]=$holder;
        }
    }
    $receipt=$holder ? room_visit_receipt($holder['receipt']) : null;
    if ($receipt) { $holder=null; }
    if (!$holder) {
        $holder=['receipt'=>bin2hex(random_bytes(32)),'request'=>bin2hex(random_bytes(16))];
        $_SESSION['room_visit_holders'][$roomToken]=$holder;
    }
    $roomClasses=room_current_classes((int)$scanRoom['id']);
    $legacyPlan=room_has_legacy_plan((int)$scanRoom['id']);
}
if (!$scanRoom) http_response_code(404);
$checkoutSearch=$_SESSION['room_checkout_search'] ?? null; unset($_SESSION['room_checkout_search']);
$checkoutCode=$checkoutSearch && hash_equals($roomToken,(string)$checkoutSearch['token']) ? (string)$checkoutSearch['code'] : '';
$checkoutRows=$checkoutCode!=='' ? find_active_checkout($roomToken,$checkoutCode) : [];
$mode=($_GET['mode'] ?? '')==='exit' || $checkoutCode!=='' ? 'exit' : 'entry';
$openClasses=array_values(array_filter($roomClasses,static fn($lesson)=>class_checkin_status($lesson)==='open'));
$nextClass=$scanRoom ? room_next_class_start((int)$scanRoom['id'],time()) : null;
$roomLink='?page=room-checkin&token='.rawurlencode($roomToken);
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>เข้า–ออกห้อง — LUMS</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('room-qr.css')) ?>"></head>
<body class="student-page"><main class="student-shell room-flow">
<header class="student-brand"><span class="brand-mark">LU</span><span><strong>LUMS</strong><small>บันทึกการใช้ห้องปฏิบัติการ</small></span></header>
<?php if (!$scanRoom): ?>
<section class="student-panel"><h1>ไม่พบห้องปฏิบัติการ</h1><p>กรุณาสแกน QR หน้าห้องอีกครั้ง หรือขอลิงก์จากผู้ดูแลห้อง</p></section>
<?php else: ?>
<section class="student-panel room-flow__header"><h1><?= e($scanRoom['code']) ?></h1><p class="room-flow__room"><?= e($scanRoom['name']) ?></p><p class="helper-text"><?= e($scanRoom['building'].' · ชั้น '.$scanRoom['floor']) ?></p></section>
<nav class="room-flow__tabs" aria-label="เลือกการเข้าใช้หรือออกจากห้อง" <?= $classSuccess ? 'hidden' : '' ?>>
<a class="<?= $mode==='entry'?'is-active':'' ?>" <?= $mode==='entry'?'aria-current="page"':'' ?> href="<?= e($roomLink.'&mode=entry') ?>">เข้าใช้ห้อง</a>
<a class="<?= $mode==='exit'?'is-active':'' ?>" <?= $mode==='exit'?'aria-current="page"':'' ?> href="<?= e($roomLink.'&mode=exit') ?>">ออกจากห้อง</a>
</nav>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>" role="status"><strong><?= e($flash['title']) ?></strong><?php if ($flash['message']): ?><span><?= e($flash['message']) ?></span><?php endif; ?></div><?php endif; ?>
<section class="student-panel room-smart" id="room-smart" data-room-token="<?= e($roomToken) ?>" data-static="<?= $classSuccess || $mode==='exit' ? 'yes' : 'no' ?>" hidden>
<h2>บันทึกการใช้ห้อง</h2><p class="helper-text">กรอกรหัส ระบบจะตรวจสอบการเข้า–ออกให้</p>
<label class="field"><span>รหัสนิสิต / รหัสบุคลากร</span><input id="room-smart-code" autocomplete="off" minlength="4" maxlength="30"></label>
<p id="room-smart-status" role="status" aria-live="polite">กรอกรหัสเพื่อเริ่มต้น</p>
<button type="button" id="room-smart-retry" class="button button--secondary" hidden>ตรวจสอบอีกครั้ง</button>
<form method="post" id="room-smart-exit" class="form-stack" hidden><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="action" value="room_checkout_direct"><input type="hidden" name="person_code"><button class="button button--primary button--block" type="submit">บันทึกเวลาออก</button></form>
</section>
<section class="student-panel room-flow__content">
<?php if ($mode==='exit'): ?>
<h2>ออกจากห้อง</h2><p class="helper-text">กรอกรหัสที่ใช้ลงชื่อเข้า ระบบจะบันทึกเวลาออกทันที</p>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="action" value="room_checkout_direct">
<label class="field"><span>รหัสนิสิต / รหัสบุคลากร</span><input name="person_code" value="<?= e($checkoutCode) ?>" autocomplete="off" minlength="4" maxlength="30" required></label>
<button class="button button--primary button--block" type="submit">บันทึกเวลาออก</button></form>
<?php if ($checkoutRows): ?><div class="room-flow__choice"><h3>เลือกรายการที่ต้องการออก</h3><p>รหัสนี้มีรายการที่ยังใช้งานมากกว่าหนึ่งรายการ</p>
<?php foreach ($checkoutRows as $entry): ?><form method="post" class="room-flow__match"><p><strong><?= e($entry['name']) ?></strong> · <?= e($entry['purpose']) ?><br>เข้า <?= e(thai_datetime($entry['check_in_at'])) ?></p><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="room_checkout_confirm"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="person_code" value="<?= e($checkoutCode) ?>"><input type="hidden" name="kind" value="<?= e($entry['kind']) ?>"><input type="hidden" name="record_id" value="<?= (int)$entry['id'] ?>"><button class="button button--secondary button--block" type="submit">ยืนยันออกจากรายการนี้</button></form><?php endforeach; ?></div><?php endif; ?>
<?php elseif ($classSuccess): ?>
<h2><?= !empty($classSuccess['duplicate'])?'ลงชื่อในคลาสนี้ไว้แล้ว':'ลงชื่อเข้าเรียนเรียบร้อย' ?></h2><p><?= e($classSuccess['class']) ?> · <?= e($classSuccess['code']) ?></p><p>เวลาเข้า <?= e(thai_datetime($classSuccess['at'])) ?></p><p class="helper-text">สแกน QR ประจำห้องอีกครั้งเมื่อออกจากห้อง</p><a class="button button--secondary" href="<?= e($roomLink) ?>">กลับหน้าห้อง</a>
<?php elseif ($scanRoom['status']!=='available'): ?>
<h2>ห้องนี้งดรับการเข้าใช้ชั่วคราว</h2><p>กรุณาติดต่อผู้ดูแลห้อง หากต้องการบันทึกเวลาออกให้เลือก “ออกจากห้อง”</p>
<?php elseif ($openClasses): ?>
<h2>ลงชื่อเข้าเรียน</h2><p class="helper-text">ตรวจสอบคลาสและกรอกข้อมูลเพื่อบันทึกเวลาเข้า</p>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="room_class_attendance"><input type="hidden" name="room_token" value="<?= e($roomToken) ?>"><input type="hidden" name="client_request_id" value="<?= e('web-'.bin2hex(random_bytes(12))) ?>">
<?php if (count($openClasses)===1): $lesson=$openClasses[0]; ?><div class="room-flow__class"><strong><?= e($lesson['course_code'].' · '.$lesson['course_name']) ?></strong><span><?= e(thai_datetime($lesson['starts_at']).' – '.thai_datetime($lesson['ends_at'],false)) ?></span><span><?= e($lesson['lecturer_name']) ?></span></div><input type="hidden" name="class_token" value="<?= e($lesson['qr_token']) ?>">
<?php else: ?><fieldset class="room-flow__classes"><legend>เลือกคลาสที่เข้าเรียน</legend><?php foreach ($openClasses as $lesson): ?><label><input type="radio" name="class_token" value="<?= e($lesson['qr_token']) ?>" required><span><strong><?= e($lesson['course_code'].' · '.$lesson['course_name']) ?></strong><br><?= e(thai_datetime($lesson['starts_at']).' – '.thai_datetime($lesson['ends_at'],false)) ?></span></label><?php endforeach; ?></fieldset><?php endif; ?>
<label class="field"><span>รหัสนิสิต</span><input name="student_code" value="<?= e($classInput['student_code'] ?? '') ?>" autocomplete="off" minlength="4" maxlength="30" required></label>
<label class="field"><span>ชื่อ–นามสกุล</span><input name="student_name" value="<?= e($classInput['student_name'] ?? '') ?>" autocomplete="name" minlength="2" maxlength="100" required></label>
<button class="button button--primary button--block" type="submit">บันทึกเวลาเข้า</button></form>
<p class="privacy-note">เมื่อใช้ห้องเสร็จ ให้สแกน QR ห้องอีกครั้งเพื่อบันทึกเวลาออก</p>
<?php elseif ($roomClasses || $legacyPlan): ?>
<h2>ยังไม่เปิดรับลงชื่อ</h2><p>ห้องนี้มีคลาสในช่วงเวลานี้ กรุณาติดต่อผู้สอนหากต้องการลงชื่อ</p>
<?php foreach ($roomClasses as $lesson): ?><p><strong><?= e($lesson['course_code'].' · '.$lesson['course_name']) ?></strong><br><?= e(thai_datetime($lesson['starts_at']).' – '.thai_datetime($lesson['ends_at'],false)) ?></p><?php endforeach; ?>
<a class="button button--secondary" href="<?= e($roomLink) ?>">ตรวจสอบอีกครั้ง</a>
<?php else: ?>
<h2>เข้าใช้ห้องนอกคลาส</h2><p class="helper-text">ขณะนี้ไม่มีคลาสที่เปิดรับ กรอกข้อมูลเพื่อบันทึกเวลาเข้า</p>
<?php if ($nextClass): ?><p class="room-flow__notice">คลาสถัดไปเริ่ม <?= e(thai_datetime($nextClass)) ?> · หากมาเข้าเรียน กรุณารอช่วงเปิดรับก่อนลงชื่อ</p><?php endif; ?>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="room_visit_start"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="client_request_id" value="<?= e($holder['request']) ?>">
<label class="field"><span>รหัสนิสิต / รหัสบุคลากร</span><input name="person_code" value="<?= e($visitInput['person_code'] ?? '') ?>" autocomplete="off" minlength="4" maxlength="30" required></label>
<label class="field"><span>ชื่อ–นามสกุล</span><input name="person_name" value="<?= e($visitInput['person_name'] ?? '') ?>" autocomplete="name" minlength="2" maxlength="100" required></label>
<label class="field"><span>ประเภทผู้เข้าใช้</span><select name="person_role"><?php foreach (['student'=>'นิสิต','lecturer'=>'อาจารย์','staff'=>'บุคลากร'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($visitInput['person_role'] ?? 'student')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label class="field"><span>วัตถุประสงค์การใช้ห้อง</span><input name="purpose" value="<?= e($visitInput['purpose'] ?? '') ?>" placeholder="เช่น ทำโครงงาน" minlength="2" maxlength="255" required></label>
<label class="field"><span>เวลาที่คาดว่าจะใช้เสร็จ</span><input type="datetime-local" name="planned_end_at" required><small>หากไม่ได้สแกนออก ระบบจะปิดรายการตามเวลานี้และระบุ Time out</small></label>
<button class="button button--primary button--block" type="submit">บันทึกเวลาเข้า</button></form>
<?php endif; ?>
</section>
<p class="privacy-note">ลงชื่อเฉพาะตัวเอง ข้อมูลใช้ติดตามการใช้ห้อง หากสแกนไม่ได้สามารถขอลิงก์หน้าห้องจากผู้ดูแล</p>
<?php endif; ?></main><script src="<?= e(asset_url('app.js')) ?>" defer></script><script src="<?= e(asset_url('room-smart.js')) ?>" defer></script></body></html>
