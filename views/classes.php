<?php
$classList=operational_class_list($_GET);
$classQuery=array_intersect_key($_GET,array_flip(['range','date','q','room_id','series','sort','status']));
$scopeFilters=array_intersect_key($classQuery,array_flip(['q','room_id','series','sort','status']));
$filtered=!empty($scopeFilters['q']) || !empty($scopeFilters['room_id']) || !empty($scopeFilters['status']) || !empty($scopeFilters['series']);
?>
<header class="page-header"><div><h1><?= $isLecturer?'คลาสของฉัน':'คลาสเรียน' ?></h1><p>เลือกคลาสเพื่อดูรายชื่อและจัดการการรับลงชื่อ</p></div><a class="button button--primary" href="<?= e($oneOffOpenUrl) ?>" data-open-once>สร้างคลาสเรียน</a></header>
<nav class="segmented class-ranges" aria-label="ช่วงคลาสที่แสดง"><?php foreach (['today'=>'รายวัน','week'=>'รายสัปดาห์','all'=>'ทุกคลาส'] as $value=>$label):
    $rangeQuery=['page'=>'classes','range'=>$value]+$scopeFilters;
    if ($value!=='all' && isset($classQuery['date'])) $rangeQuery['date']=$classList['date'];
?><a href="?<?= e(http_build_query($rangeQuery)) ?>" class="<?= $classList['range']===$value?'is-active':'' ?>" <?= $classList['range']===$value?'aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?></nav>
<?php if (!empty($_GET['series'])): ?><p class="inline-note">แสดงเฉพาะชุดคลาสที่เพิ่งสร้าง <a href="?page=classes&range=all">ดูทุกชุดคลาส</a></p><?php endif; ?>
<form class="class-day-controls" method="get">
    <input type="hidden" name="page" value="classes"><input type="hidden" name="range" value="<?= e($classList['range']) ?>">
    <?php if (!empty($_GET['series'])): ?><input type="hidden" name="series" value="<?= e($_GET['series']) ?>"><?php endif; ?>
    <?php if ($classList['range']!=='all'): ?><label class="field"><span>วันที่</span><input type="date" name="date" value="<?= e($classList['date']) ?>"></label><?php endif; ?>
    <label class="field"><span>ห้อง</span><select name="room_id"><option value="">ทุกห้อง</option><?php foreach (list_rooms() as $room): ?><option value="<?= $room['id'] ?>" <?= (int)($_GET['room_id'] ?? 0)===$room['id']?'selected':'' ?>><?= e($room['code']) ?></option><?php endforeach; ?></select></label>
    <label class="field search-field"><span>ค้นหาวิชา / ห้อง</span><input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="รหัสวิชา ชื่อวิชา หรือห้อง"></label>
    <details class="class-extra-filters" <?= !empty($_GET['status']) || ($_GET['sort'] ?? '')==='desc'?'open':'' ?>><summary>ตัวเลือกเพิ่มเติม</summary><div>
    <label class="field"><span>เรียงวันที่</span><select name="sort"><option value="asc">เก่าไปใหม่</option><option value="desc" <?= ($_GET['sort'] ?? '')==='desc'?'selected':'' ?>>ใหม่ไปเก่า</option></select></label>
    <label class="field"><span>สถานะรับลงชื่อ</span><select name="status"><option value="">ทุกสถานะ</option><?php foreach (['scheduled','open','overdue','closed','cancelled','draft'] as $status): ?><option value="<?= $status ?>" <?= ($_GET['status'] ?? '')===$status?'selected':'' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select></label>
    </div></details>
    <button class="button button--secondary" type="submit">แสดงคลาส</button>
</form>
<div class="section-heading"><h2><?= $classList['range']==='today'?'คลาสวันที่ '.e(thai_date_label($classList['date'])):($classList['range']==='week'?'คลาสประจำสัปดาห์':'คลาสทั้งหมด') ?></h2><span class="result-count">พบ <?= $classList['total'] ?> คลาส</span></div>
<?php if (!$classList['rows']): ?><div class="empty-state"><strong><?= $filtered?'ไม่พบคลาสตามตัวกรอง':'ไม่มีคลาสในช่วงที่เลือก' ?></strong>
    <?php if ($filtered): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'classes','range'=>$classList['range'],'date'=>$classList['date']])) ?>">ล้างตัวกรอง</a>
    <?php elseif ($classList['range']!=='all'): ?><a class="button button--secondary" href="?page=classes&amp;range=all">ดูทุกคลาส</a><?php endif; ?></div>
<?php else: render_class_table($classList['rows']); endif; ?>
<?php if ($classList['pages']>1): ?>
<nav class="class-list-pagination" aria-label="เปลี่ยนหน้าคลาส"><span>หน้า <?= $classList['page'] ?> / <?= $classList['pages'] ?> · หน้าละ 50 คลาส</span><div class="button-group"><?php if ($classList['page']>1): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'classes','p'=>$classList['page']-1]+$classQuery)) ?>">ก่อนหน้า</a><?php endif; ?><?php if ($classList['page']<$classList['pages']): ?><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'classes','p'=>$classList['page']+1]+$classQuery)) ?>">ถัดไป</a><?php endif; ?></div></nav>
<?php endif; ?>
