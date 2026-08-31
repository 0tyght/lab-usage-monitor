<?php
$onceFrom=$weekStart->format('Y-m-d');
$onceTo=$weekStart->modify('+'.($dayCount-1).' days')->format('Y-m-d');
$onceWeek=[];
$onceLoadError=false;
try {
    $onceWeek=array_values(array_filter(room_usage_events($onceFrom,$onceTo,['source'=>'classes','room_id'=>$roomFilter,'q'=>trim((string)($_GET['q'] ?? ''))]),static fn(array $r): bool => !$r['schedule_id']));
} catch (Throwable) { $onceLoadError=true; }
?>
<section class="one-off-week" aria-labelledby="one-off-week-title">
    <div class="section-heading"><div><h2 id="one-off-week-title">คาบครั้งเดียวในสัปดาห์นี้</h2><p><?= e(thai_date_label($onceFrom).' – '.thai_date_label($onceTo)) ?> · ไม่ผูกกับภาคการศึกษา · เรียงตามเวลาเริ่ม</p></div><a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'calendar','month'=>$weekStart->format('Y-m'),'room_id'=>$roomFilter])) ?>">ดูในปฏิทิน</a></div>
    <?php if ($onceLoadError): ?><p class="alert alert--error" role="alert">โหลดคาบไม่สำเร็จ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง</p><?php elseif (!$onceWeek): ?><p class="inline-note">ไม่พบคาบครั้งเดียวตามสัปดาห์และตัวกรองนี้ ใช้ปุ่ม “เพิ่มคาบครั้งเดียว” เพื่อเพิ่มรายการ</p><?php else: ?>
    <p class="result-count"><?= count($onceWeek) ?> คาบ</p><div class="table-wrap"><table class="data-table"><thead><tr><th>วันและเวลา</th><th>ห้อง</th><th>รายวิชา / ผู้สอน</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach ($onceWeek as $row): ?><tr><td><?= e(thai_datetime($row['starts_at']).' – '.thai_datetime($row['ends_at'],false)) ?></td><td><?= e($row['room_code']) ?></td><td><strong><?= e($row['course_code'].' · '.$row['course_name']) ?></strong><small><?= e($row['lecturer_name']) ?></small></td><td><a class="button button--secondary" data-class-id="<?= (int)$row['class_id'] ?>" href="?page=classes&amp;class_id=<?= $row['class_id'] ?>">ดูคาบ / QR</a></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
