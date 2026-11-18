<?php
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$roomToken=(string)($_GET['token'] ?? '');
$scanRoom=public_room($roomToken);
$visitInput=$_SESSION['room_visit_input'] ?? []; unset($_SESSION['room_visit_input']);
$receipt=null;$roomClasses=[];$legacyPlan=false;
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
    $receipt=$holder?room_visit_receipt($holder['receipt']):null;
    if (isset($_GET['new_visit']) && $receipt && $receipt['check_out_at']) { $holder=null; $receipt=null; }
    if (!$holder || (!$receipt && isset($holder['visit_id']))) {
        $holder=['receipt'=>bin2hex(random_bytes(32)),'request'=>bin2hex(random_bytes(16))];
        $_SESSION['room_visit_holders'][$roomToken]=$holder;
    }
    $roomClasses=room_current_classes((int)$scanRoom['id']);
    $legacyPlan=room_has_legacy_plan((int)$scanRoom['id']);
}
if (!$scanRoom) http_response_code(404);
$checkoutSearch=$_SESSION['room_checkout_search'] ?? null;
unset($_SESSION['room_checkout_search']);
$checkoutCode=$checkoutSearch && hash_equals($roomToken,(string)$checkoutSearch['token']) ? (string)$checkoutSearch['code'] : '';
$checkoutRows=$checkoutCode!=='' ? find_active_checkout($roomToken,$checkoutCode) : [];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ลงชื่อเข้าใช้ห้อง — LUMS</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('room-qr.css')) ?>"></head>
<body class="student-page"><main class="student-shell"><header class="student-brand"><span class="brand-mark">LU</span><span><strong>LUMS</strong><small>ลงชื่อเข้าใช้ห้องปฏิบัติการ</small></span></header>
<?php if (!$scanRoom): ?><section class="student-panel"><h1>ไม่พบห้องปฏิบัติการ</h1><p>กรุณาสแกน QR หน้าห้องอีกครั้ง หรือขอลิงก์จากผู้ดูแลห้อง</p></section>
<?php else: ?>
<section class="student-panel"><p class="eyebrow">QR ประจำห้อง</p><h1><?= e($scanRoom['code']) ?></h1><h2><?= e($scanRoom['name']) ?></h2><p><?= e($scanRoom['building'].' · ชั้น '.$scanRoom['floor']) ?></p><p class="helper-text">ตรวจชื่อห้องให้ตรงกับห้องที่กำลังเข้าใช้ · <?= e(thai_datetime(utc_now())) ?></p></section>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>" role="status"><strong><?= e($flash['title']) ?></strong><span><?= e($flash['message']) ?></span></div><?php endif; ?>
<details class="student-panel room-checkout" <?= $checkoutCode!=='' ? 'open' : '' ?>><summary><strong>ออกจากห้อง</strong> · กรอกรหัสเพื่อเช็กเอาต์</summary><p>กรอกรหัสที่ใช้ลงชื่อเข้า แล้วตรวจสอบรายการก่อนยืนยันออก</p>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="action" value="room_checkout_find"><label class="field"><span>รหัสนิสิต / รหัสบุคลากร</span><input name="person_code" value="<?= e($checkoutCode) ?>" minlength="4" maxlength="30" required></label><button class="button button--secondary button--block" type="submit">ค้นหารายการเพื่อเช็กเอาต์</button></form>
<?php if ($checkoutCode!=='' && !$checkoutRows): ?><p role="status">ไม่พบรายการที่ยังเปิดอยู่ในห้องนี้ หากหมดเวลาแล้วระบบจะบันทึก Time out อัตโนมัติ</p><?php endif; ?>
<?php foreach ($checkoutRows as $entry): ?><form method="post" class="form-stack"><p><strong><?= e($entry['name']) ?></strong> · <?= e($entry['purpose']) ?><br>เข้า <?= e(thai_datetime($entry['check_in_at'])) ?> · สิ้นสุด <?= e(thai_datetime($entry['deadline'])) ?></p><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="room_checkout_confirm"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="person_code" value="<?= e($checkoutCode) ?>"><input type="hidden" name="kind" value="<?= e($entry['kind']) ?>"><input type="hidden" name="record_id" value="<?= (int)$entry['id'] ?>"><button class="button button--primary button--block" type="submit">ยืนยันออกจากห้อง</button></form><?php endforeach; ?></details>
<?php if ($receipt): ?>
<section class="student-panel"><h2><?= $receipt['check_out_at']?'บันทึกเวลาออกแล้ว':'คุณลงชื่อเข้าใช้ห้องนี้แล้ว' ?></h2><p><?= e($receipt['person_name']) ?> · <?= e($receipt['person_code']) ?></p><p>เข้า <?= e(thai_datetime($receipt['check_in_at'])) ?></p>
<?php if ($receipt['check_out_at']): ?><p>ออก / ปิดรายการ <?= e(thai_datetime($receipt['check_out_at'])) ?></p><p><?= !empty($receipt['auto_closed_at'])?'Time out · ระบบปิดรายการตามเวลาที่กำหนด':($receipt['checkout_method']==='admin'?'ผู้ดูแลปิดรายการนี้แล้ว':'ขอบคุณที่บันทึกการใช้ห้อง') ?></p><a class="button button--secondary" href="?page=room-checkin&amp;token=<?= e($roomToken) ?>&amp;new_visit=1">เริ่มการเข้าใช้ครั้งใหม่</a>
<?php else: ?><p><?= e($receipt['purpose']) ?></p><p class="helper-text">เมื่อใช้ห้องเสร็จ ให้เปิด “ออกจากห้อง” ด้านบน แล้วกรอกรหัสเพื่อยืนยันเวลาออก</p><?php endif; ?></section>
<?php elseif ($scanRoom['status']!=='available'): ?><section class="student-panel"><h2>ห้องนี้งดรับการเข้าใช้ชั่วคราว</h2><p>กรุณาติดต่อผู้ดูแลห้อง</p></section>
<?php elseif ($roomClasses || $legacyPlan): ?>
<section class="student-panel"><h2>คลาสในช่วงเวลานี้</h2><p>เลือกคลาสที่คุณกำลังเข้าเรียน ระบบจะบันทึกเข้าคลาสนั้น</p>
<?php foreach ($roomClasses as $lesson): $state=class_checkin_status($lesson); ?><article class="room-current-class"><h3><?= e($lesson['course_code'].' · '.$lesson['course_name']) ?></h3><p><?= e($lesson['lecturer_name']) ?><?= $lesson['section']?' · กลุ่ม '.e($lesson['section']):'' ?></p><p><?= e(thai_datetime($lesson['starts_at']).' – '.thai_datetime($lesson['ends_at'],false)) ?></p><span class="status status--<?= e($state) ?>"><?= e(status_label($state)) ?></span><?php if ($state==='open'): ?><a class="button button--primary button--block" href="?page=student-checkin&amp;token=<?= e($lesson['qr_token']) ?>">ลงชื่อเข้าเรียนคลาสนี้</a><?php else: ?><p class="helper-text">คลาสนี้ยังไม่รับลงชื่อ กรุณาติดต่อผู้สอน ไม่สามารถใช้แบบฟอร์มนอกคลาสแทนการลงชื่อเข้าเรียนได้</p><?php endif; ?></article><?php endforeach; ?>
<?php if ($legacyPlan): ?><p class="inline-note">มีตารางเรียนเดิมในช่วงนี้ แต่ยังไม่ได้เตรียมคลาสลงชื่อ กรุณาแจ้งผู้สอน</p><?php endif; ?></section>
<?php else: ?>
<section class="student-panel"><h2>ลงชื่อเข้าใช้ห้องนอกคลาส</h2><p>ขณะนี้ไม่มีคลาสในช่วงเวลาใช้งาน กรอกข้อมูลของตนเอง ระบบบันทึกเวลาเข้าให้ทันที</p><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="room_visit_start"><input type="hidden" name="token" value="<?= e($roomToken) ?>"><input type="hidden" name="client_request_id" value="<?= e($holder['request']) ?>">
<label class="field"><span>รหัสนิสิต / รหัสบุคลากร</span><input name="person_code" value="<?= e($visitInput['person_code'] ?? '') ?>" autocomplete="off" minlength="4" maxlength="30" required></label>
<label class="field"><span>ชื่อ–นามสกุล</span><input name="person_name" value="<?= e($visitInput['person_name'] ?? '') ?>" autocomplete="name" minlength="2" maxlength="100" required></label>
<label class="field"><span>ประเภทผู้เข้าใช้</span><select name="person_role"><?php foreach (['student'=>'นิสิต','lecturer'=>'อาจารย์','staff'=>'บุคลากร'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($visitInput['person_role'] ?? 'student')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label class="field"><span>วัตถุประสงค์การใช้ห้อง</span><input name="purpose" value="<?= e($visitInput['purpose'] ?? '') ?>" placeholder="เช่น ทำโครงงาน ฝึกปฏิบัติ หรือทำงานกลุ่ม" minlength="2" maxlength="255" required></label>
<label class="field"><span>เวลาที่คาดว่าจะใช้เสร็จ</span><input type="datetime-local" name="planned_end_at" required><small>หากไม่ได้สแกนออก ระบบจะปิดรายการตามเวลานี้และระบุ Time out</small></label>
<button class="button button--primary button--block" type="submit">ลงชื่อเข้าใช้ห้อง</button></form><p class="privacy-note">ลงชื่อเฉพาะตัวเอง ข้อมูลใช้ติดตามการใช้ห้อง ผู้ดูแลระบบเห็นข้อมูลนี้ การลงชื่อไม่ใช่การอนุมัติเข้าห้อง โปรดปฏิบัติตามระเบียบห้อง</p></section>
<?php endif; ?>
<p><a class="button button--secondary button--block" href="?page=room-checkin&amp;token=<?= e($roomToken) ?>">ตรวจสอบห้องอีกครั้ง</a></p><p class="privacy-note">เปิดลิงก์ที่ผู้ดูแลให้ได้โดยไม่ต้องใช้กล้อง หากสแกนไม่ได้ให้เปิดสิทธิ์กล้องของแอปสแกน หรือขอลิงก์หน้าห้องจากผู้ดูแล</p>
<?php endif; ?></main><script src="<?= e(asset_url('app.js')) ?>" defer></script></body></html>
