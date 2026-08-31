<?php
$calendarFilters = planning_filters($_GET);
$calendarMonth = new DateTimeImmutable($calendarFilters['month'] . '-01');
$gridStart = $calendarMonth->modify('monday this week');
$gridEnd = $calendarMonth->modify('last day of this month')->modify('sunday this week');
$calendarRooms = list_rooms();
$calendarTerms = list_academic_terms();
$calendarErrors = $calendarFilters['errors'];
$calendarRows = [];
if (!$calendarErrors) {
    try {
        $calendarRows = room_usage_slices(room_usage_events($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'), array_replace($calendarFilters, ['room_id'=>0])), $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
    } catch (Throwable) { $calendarErrors[] = 'โหลดปฏิทินไม่สำเร็จ กรุณาลองอีกครั้งหรือเลือกภาคการศึกษา'; }
}
$calendarDays = [];
foreach ($calendarRows as $row) {
    if (!$calendarFilters['room_id'] || $row['room_id'] === $calendarFilters['room_id']) $calendarDays[$row['date']][] = $row;
}
$calendarQuery = ['page'=>'calendar', 'month'=>$calendarFilters['month'], 'room_id'=>$calendarFilters['room_id'], 'term_id'=>$calendarFilters['term_id'], 'source'=>$calendarFilters['source']];
$dayDate = (string)($_GET['date'] ?? '');
if (!valid_iso_date($dayDate) || $dayDate < $gridStart->format('Y-m-d') || $dayDate > $gridEnd->format('Y-m-d')) $dayDate = '';
$dayRoomId = (int)($_GET['day_room_id'] ?? $calendarFilters['room_id']);
$dayRows = array_values(array_filter($calendarRows, static fn(array $r): bool => $r['date'] === $dayDate && (!$dayRoomId || $r['room_id'] === $dayRoomId)));
$calendarCloseUrl = '?' . http_build_query($calendarQuery);
?>
<header class="page-header"><div><p class="eyebrow">มองทั้งเดือน · เจาะรายละเอียดรายวัน</p><h1>ปฏิทินการใช้ห้อง</h1><p>วันที่มีรายการจะแสดงจำนวน ชี้เพื่อดูสรุป หรือคลิกเพื่อเปิดตารางเวลา</p></div><div class="schedule-header-actions"><a class="button button--primary" href="<?= e($oneOffOpenUrl) ?>" data-open-once aria-haspopup="dialog" aria-controls="one-off-dialog"><span data-icon="plus"></span>เพิ่มคาบครั้งเดียว</a><a class="button button--secondary" href="?page=schedule"><span data-icon="calendar-days"></span>จัดตารางรายสัปดาห์</a></div></header>
<form method="get" class="filter-bar calendar-filters">
    <input type="hidden" name="page" value="calendar">
    <label><span>เดือนที่ต้องการดู</span><input type="month" name="month" value="<?= e($calendarFilters['month']) ?>" required></label>
    <label><span>ห้องปฏิบัติการ</span><select name="room_id"><option value="0">ทุกห้อง</option><?php foreach ($calendarRooms as $r): ?><option value="<?= $r['id'] ?>" <?= $r['id']===$calendarFilters['room_id']?'selected':'' ?>><?= e($r['code'].' — '.$r['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>ปี / ภาค</span><select name="term_id"><option value="0">ทุกภาคการศึกษา</option><?php foreach ($calendarTerms as $t): ?><option value="<?= $t['id'] ?>" <?= $t['id']===$calendarFilters['term_id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>รายการที่แสดง</span><select name="source"><?php foreach (['all'=>'แผนและคลาสที่สร้าง', 'schedule'=>'แผนที่ยังไม่สร้างคลาส', 'classes'=>'เฉพาะคลาสที่สร้าง'] as $v=>$text): ?><option value="<?= $v ?>" <?= $v===$calendarFilters['source']?'selected':'' ?>><?= e($text) ?></option><?php endforeach; ?></select></label>
    <button class="button button--secondary" type="submit">แสดงปฏิทิน</button>
</form>
<?php if ($calendarErrors): ?><div class="alert alert--error" role="alert"><?= e(implode(' ', $calendarErrors)) ?><a href="?page=calendar">ล้างตัวกรอง</a></div><?php else: ?>
<div class="calendar-toolbar"><div class="button-group"><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($calendarQuery, ['month'=>$calendarMonth->modify('-1 month')->format('Y-m')]))) ?>" aria-label="เดือนก่อน"><span data-icon="chevron-left"></span></a><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($calendarQuery, ['month'=>date('Y-m')]))) ?>">เดือนนี้</a><a class="button button--secondary" href="?<?= e(http_build_query(array_replace($calendarQuery, ['month'=>$calendarMonth->modify('+1 month')->format('Y-m')]))) ?>" aria-label="เดือนถัดไป"><span data-icon="chevron-right"></span></a></div><h2><?= e(thai_month_label($calendarFilters['month'])) ?></h2><span class="result-count">เวลาไทย (UTC+7)</span></div>
<p class="calendar-legend"><span><i class="calendar-dot"></i>มีรายการตามแผนหรือคลาส</span><span>ตัวเลข = รายการในวันนั้น · แตะวันที่ได้บนมือถือ</span></p>
<div class="month-grid" aria-label="ปฏิทินรายเดือน">
    <?php foreach (['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'] as $dayLabel): ?><div class="month-weekday"><?= e($dayLabel) ?></div><?php endforeach; ?>
    <?php for ($day=$gridStart; $day <= $gridEnd; $day=$day->modify('+1 day')):
        $date = $day->format('Y-m-d'); $dayEvents = $calendarDays[$date] ?? [];
        $tooltip = thai_date_label($date) . "\n";
        foreach (array_slice($dayEvents, 0, 5) as $event) $tooltip .= $event['start_time'].'–'.$event['end_time'].' '.$event['room_code'].' · '.$event['course_code'].' · '.usage_source_label($event)."\n";
        if (count($dayEvents)>5) $tooltip .= 'และอีก '.(count($dayEvents)-5).' รายการ';
        if (!$dayEvents) $tooltip .= 'ไม่พบรายการตามตัวกรอง';
    ?>
        <a class="month-day <?= $dayEvents?'has-events':'' ?> <?= $day->format('m')!==$calendarMonth->format('m')?'is-outside':'' ?> <?= $date===date('Y-m-d')?'is-today':'' ?>" href="?<?= e(http_build_query($calendarQuery+['date'=>$date])) ?>" data-calendar-day="<?= e($date) ?>" data-day-tooltip="<?= e(trim($tooltip)) ?>" aria-haspopup="dialog" aria-controls="calendar-day-dialog" aria-label="<?= e(thai_date_label($date).' · '.count($dayEvents).' รายการ') ?>" <?= $date===date('Y-m-d')?'aria-current="date"':'' ?>>
            <span class="month-day-number"><?= $day->format('j') ?></span>
            <?php if ($dayEvents): ?><span class="month-day-count"><i class="calendar-dot"></i><?= count($dayEvents) ?><span class="desktop-day-text"> รายการ</span></span><span class="month-day-preview"><?= e(implode(' · ', array_unique(array_column($dayEvents, 'room_code')))) ?></span><?php endif; ?>
        </a>
    <?php endfor; ?>
</div>
<p class="helper-text">แสดงข้อมูลตามสิทธิ์ของบัญชี รายการตามแผนไม่ใช่หลักฐานว่ามีการใช้ห้องจริง และอาจต้องงดบางคาบในวันหยุดหรือช่วงสอบ</p>
<?php endif; ?>
<dialog id="calendar-day-dialog" class="term-dialog day-dialog" aria-labelledby="calendar-day-title" <?= $dayDate && !$calendarErrors?'open':'' ?>>
    <header class="term-dialog__header"><div><h2 id="calendar-day-title">การใช้ห้อง · <?= e($dayDate ? thai_date_label($dayDate) : '') ?></h2><p>เลือกห้องด้านล่างเพื่อดูเวลาและรายละเอียด</p></div><a class="icon-button" href="<?= e($calendarCloseUrl) ?>" data-close-day aria-label="ปิดตารางรายวัน"><span data-icon="x"></span></a></header>
    <div class="term-dialog__body day-dialog__body">
        <form method="get" class="day-room-filter" data-day-filter>
            <?php foreach ($calendarQuery as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><input type="hidden" name="date" value="<?= e($dayDate) ?>">
            <label class="field"><span>ห้องที่ต้องการดู</span><select name="day_room_id"><option value="0">ทุกห้อง</option><?php foreach ($calendarRooms as $r): ?><option value="<?= $r['id'] ?>" <?= $dayRoomId===$r['id']?'selected':'' ?>><?= e($r['code'].' — '.$r['name']) ?></option><?php endforeach; ?></select></label><button class="button button--secondary" type="submit">แสดงห้อง</button>
        </form>
        <p data-day-count aria-live="polite"><?= count($dayRows) ?> รายการ</p>
        <p><a class="button button--secondary" data-day-once href="?<?= e(http_build_query(array_replace($calendarQuery,['new_once'=>1,'once_date'=>$dayDate,'room_id'=>$dayRoomId]))) ?>">เพิ่มคาบครั้งเดียวในวันนี้</a></p>
        <div data-day-content>
            <?php if (!$dayRows): ?><div class="empty-state"><strong>ไม่พบรายการในห้องและวันที่เลือก</strong><span>ลองเลือกทุกห้อง หรือเลือกวันอื่น</span></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>เวลา</th><th>ห้อง</th><th>รายวิชา</th><th>ผู้สอน</th></tr></thead><tbody><?php foreach ($dayRows as $r): ?><tr><td><?= e($r['start_time'].'–'.$r['end_time']) ?></td><td><?= e($r['room_code']) ?></td><td><?= e($r['course_code'].' '.$r['course_name']) ?></td><td><?= e($r['lecturer_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
    </div>
    <footer class="term-dialog__actions"><a class="button button--secondary" href="<?= e($calendarCloseUrl) ?>" data-close-day>ปิดหน้าต่าง</a><a class="button button--primary" data-day-report href="?<?= e(http_build_query(['page'=>'reports', 'date_from'=>$dayDate, 'date_to'=>$dayDate, 'room_id'=>$dayRoomId, 'term_id'=>$calendarFilters['term_id'], 'source'=>$calendarFilters['source']])) ?>">ดูรายงานวันนี้</a></footer>
</dialog>
<script type="application/json" data-calendar-events><?= json_encode(array_map(static fn(array $r): array => $r + ['source_label'=>usage_source_label($r)], $calendarRows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" data-calendar-context><?= json_encode(['date'=>$dayDate, 'room_id'=>$dayRoomId, 'term_id'=>$calendarFilters['term_id'], 'source'=>$calendarFilters['source']], JSON_HEX_TAG) ?></script>
