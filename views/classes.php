<?php
$classList=operational_class_list($_GET);
$classQuery=array_intersect_key($_GET,array_flip(['range','date','q','room_id','series','sort','status']));
?>
<header class="page-header"><div><h1>คลาสเรียน</h1><p>ดู QR และจำนวนผู้ลงชื่อของแต่ละครั้ง · คลาสใหม่ตั้งให้เปิดรับก่อนเรียน 10 นาที</p></div><a class="button button--primary" href="<?= e($oneOffOpenUrl) ?>" data-open-once>สร้างคลาสเรียน</a></header>
<div class="segmented" aria-label="ช่วงคลาสที่แสดง"><?php foreach (['today'=>'วันนี้','week'=>'สัปดาห์นี้','all'=>'ทุกคลาส'] as $value=>$label): ?><a href="?<?= e(http_build_query(['page'=>'classes','range'=>$value])) ?>" class="<?= $classList['range']===$value?'is-active':'' ?>"><?= $label ?></a><?php endforeach; ?></div>
<?php if (!empty($_GET['series'])): ?><p class="inline-note">แสดงเฉพาะชุดคลาสที่เพิ่งสร้าง <a href="?page=classes&range=all">ดูทุกชุดคลาส</a></p><?php endif; ?>
<form class="class-day-controls" method="get">
    <input type="hidden" name="page" value="classes"><input type="hidden" name="range" value="<?= e($classList['range']) ?>">
    <?php if (!empty($_GET['series'])): ?><input type="hidden" name="series" value="<?= e($_GET['series']) ?>"><?php endif; ?>
    <?php if ($classList['range']!=='all'): ?><label class="field"><span>วันที่</span><input type="date" name="date" value="<?= e($classList['date']) ?>"></label><?php endif; ?>
    <label class="field"><span>ห้อง</span><select name="room_id"><option value="">ทุกห้อง</option><?php foreach (list_rooms() as $room): ?><option value="<?= $room['id'] ?>" <?= (int)($_GET['room_id'] ?? 0)===$room['id']?'selected':'' ?>><?= e($room['code']) ?></option><?php endforeach; ?></select></label>
    <label class="field search-field"><span>ค้นหาวิชา / ห้อง</span><input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="รหัสวิชา ชื่อวิชา หรือห้อง"></label>
    <label class="field"><span>เรียงวันที่</span><select name="sort"><option value="asc">เก่าไปใหม่</option><option value="desc" <?= ($_GET['sort'] ?? '')==='desc'?'selected':'' ?>>ใหม่ไปเก่า</option></select></label>
    <label class="field"><span>สถานะรับลงชื่อ</span><select name="status"><option value="">ทุกสถานะ</option><?php foreach (['scheduled','open','overdue','closed','cancelled','draft'] as $status): ?><option value="<?= $status ?>" <?= ($_GET['status'] ?? '')===$status?'selected':'' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select></label>
    <button class="button button--secondary" type="submit">แสดงคลาส</button>
</form>
<div class="section-heading"><h2><?= $classList['range']==='today'?'คลาสวันที่ '.e(thai_date_label($classList['date'])):($classList['range']==='week'?'คลาสประจำสัปดาห์':'คลาสทั้งหมด') ?></h2><span class="result-count">พบ <?= $classList['total'] ?> คลาส</span></div>
<?php if (!$classList['rows']): ?><div class="empty-state"><span data-icon="calendar-days"></span><strong>ไม่มีคลาสในช่วงที่เลือก</strong><span>เปลี่ยนวันที่หรือเลือกทุกคลาส เพื่อดูตารางที่สร้างไว้</span><a class="button button--secondary" href="?page=classes&range=all">ดูทุกคลาส</a></div><?php else: render_class_table($classList['rows']); endif; ?>
<nav class="class-list-pagination" aria-label="เปลี่ยนหน้าคลาส"><span>หน้า <?= $classList['page'] ?> / <?= $classList['pages'] ?> · หน้าละ 50 คลาส</span><div class="button-group"><?php if ($classList['page']>1): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'classes','p'=>$classList['page']-1]+$classQuery)) ?>">ก่อนหน้า</a><?php endif; ?><?php if ($classList['page']<$classList['pages']): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'classes','p'=>$classList['page']+1]+$classQuery)) ?>">ถัดไป</a><?php endif; ?></div></nav>
