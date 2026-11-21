<?php
$agenda = lecturer_agenda();
$remaining = [];
$finished = [];
foreach ($agenda['today'] as $lesson) {
    if (strtotime($lesson['ends_at']) <= time() || $lesson['status'] === 'cancelled') {
        $finished[] = $lesson;
    } else {
        $remaining[] = $lesson;
    }
}

function render_teaching_lesson(array $lesson): void
{
    $start = strtotime($lesson['starts_at']);
    $end = strtotime($lesson['ends_at']);
    $status = class_display_status($lesson);
    $actionLabel = $status==='scheduled' ? 'ดูรายละเอียด' : 'ดูรายชื่อ';
    ?>
    <li class="teaching-lesson">
        <div class="lesson-time"><strong><?= e(date('H:i', $start).'–'.date('H:i', $end)) ?></strong><?php if (date('Y-m-d',$start)!==date('Y-m-d',$end)): ?><small><?= e(thai_date_label(date('Y-m-d',$start)).' – '.thai_date_label(date('Y-m-d',$end))) ?></small><?php endif; ?></div>
        <div class="lesson-course"><strong><?= e($lesson['course_name']) ?></strong><small><?= e($lesson['course_code'].' · ห้อง '.$lesson['room_code']) ?><?= $lesson['section'] ? ' · กลุ่ม '.e($lesson['section']) : '' ?></small></div>
        <?php if ($status==='planned'): ?>
        <div class="lesson-admission">ตามตารางสอน<small>ยังไม่ได้สร้างคลาส</small></div>
        <a class="button button--secondary" href="?<?= e(http_build_query(['page'=>'schedule','term_id'=>$lesson['term_id'],'week'=>date('Y-m-d',$start),'weekend'=>(int)(date('N',$start)>5),'selected'=>$lesson['schedule_id']])) ?>">ดูตารางสอน</a>
        <?php else: ?>
        <div class="lesson-admission"><span class="status status--<?= e($status) ?>"><span></span><?= e(status_label($status)) ?></span>
            <?php if ($status==='scheduled' && !$lesson['attendance_count']): $openAt=$start-60*(int)$lesson['admission_lead_minutes']; ?>
                <small>เปิด <?= e(date('Y-m-d',$openAt)!==date('Y-m-d',$start)?thai_date_label(date('Y-m-d',$openAt)).' ':'') ?><?= e(date('H:i',$openAt)) ?> น.</small>
            <?php else: ?><small>ลงชื่อแล้ว <?= (int)$lesson['attendance_count'] ?> คน</small><?php endif; ?>
        </div>
        <a class="button button--secondary" href="?page=dashboard&amp;class_id=<?= $lesson['id'] ?>" data-class-id="<?= $lesson['id'] ?>" aria-haspopup="dialog" aria-label="<?= e($actionLabel.' '.$lesson['course_name'].' เวลา '.date('H:i',$start)) ?>"><?= e($actionLabel) ?></a>
        <?php endif; ?>
    </li>
    <?php
}
?>
<div class="teaching-agenda">
    <header class="page-header">
        <div><h1>งานสอนวันนี้</h1><p><?= e(thai_day_label((int)date('N')).'ที่ '.thai_date_label(date('Y-m-d'))) ?></p></div>
        <a class="button button--primary" href="?page=classes&amp;new_once=1"><span data-icon="plus"></span>สร้างคลาสเรียน</a>
    </header>
    <section class="section-block" aria-labelledby="teaching-today">
        <div class="section-heading"><h2 id="teaching-today">คลาสของฉันวันนี้</h2><span class="result-count"><?= count($agenda['today']) ?> คลาส</span></div>
        <?php if ($remaining): ?><ul class="teaching-lessons"><?php foreach ($remaining as $lesson) render_teaching_lesson($lesson); ?></ul><?php endif; ?>
        <?php if ($finished): ?>
        <details class="teaching-history" <?= !$remaining?'open':'' ?>><summary>จบหรือยกเลิกแล้ววันนี้ (<?= count($finished) ?>)</summary>
            <ul class="teaching-lessons"><?php foreach ($finished as $lesson) render_teaching_lesson($lesson); ?></ul>
        </details>
        <?php elseif (!$remaining): ?><p class="teaching-empty">วันนี้คุณไม่มีคลาสตามตาราง</p><?php endif; ?>
    </section>
    <?php if ($agenda['next']): ?>
    <section class="section-block" aria-labelledby="teaching-next">
        <div class="section-heading"><div><h2 id="teaching-next">คลาสถัดไปที่สร้างไว้</h2><p><?= e(thai_date_label(date('Y-m-d',strtotime($agenda['next']['starts_at'])))) ?></p></div></div>
        <ul class="teaching-lessons"><?php render_teaching_lesson($agenda['next']); ?></ul>
    </section>
    <?php endif; ?>
    <a class="text-link teaching-all" href="?page=classes&amp;range=all">ดูคลาสของฉันทั้งหมด<span data-icon="chevron-right" aria-hidden="true"></span></a>
</div>
