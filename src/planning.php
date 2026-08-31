<?php

declare(strict_types=1);

function planning_filters(array $input, bool $report = false): array
{
    $today = new DateTimeImmutable('today');
    $month = (string)($input['month'] ?? $today->format('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = $today->format('Y-m');
    $first = new DateTimeImmutable($month . '-01');
    $from = (string)($input['date_from'] ?? $first->format('Y-m-d'));
    $to = (string)($input['date_to'] ?? $first->modify('last day of this month')->format('Y-m-d'));
    if ($report && !isset($input['date_from']) && in_array($input['period'] ?? '', ['day', 'week'], true)) {
        [, , $start, $end] = local_period_bounds($input['period']);
        $from = $start->format('Y-m-d');
        $to = $end->modify('-1 day')->format('Y-m-d');
    }
    $errors = [];
    if (!valid_iso_date($from) || !valid_iso_date($to) || $from > $to) $errors[] = 'กรุณาเลือกวันที่เริ่มและสิ้นสุดให้ถูกต้อง';
    elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 365) $errors[] = 'เลือกช่วงรายงานได้ไม่เกิน 366 วันต่อครั้ง';
    $timeFrom = (string)($input['time_from'] ?? '00:00');
    $timeTo = (string)($input['time_to'] ?? '24:00');
    $validTime = static fn(string $t): bool => (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $t);
    if (!$validTime($timeFrom) || (!$validTime($timeTo) && $timeTo !== '24:00') || $timeFrom >= $timeTo) $errors[] = 'เวลาเริ่มต้องน้อยกว่าเวลาสิ้นสุดในวันเดียวกัน (สิ้นสุดเต็มวันใช้ 24:00)';
    $roomId = max(0, (int)($input['room_id'] ?? 0));
    if ($roomId && !array_filter(list_rooms(), static fn(array $r): bool => $r['id'] === $roomId)) $errors[] = 'ไม่พบห้องที่เลือก กรุณาเลือกห้องใหม่';
    $termId = max(0, (int)($input['term_id'] ?? 0));
    if ($termId && !get_academic_term($termId)) $errors[] = 'ไม่พบภาคการศึกษาที่เลือก';
    preg_match('/^.{0,100}/us', trim((string)($input['q'] ?? '')), $search);
    return [
        'month'=>$month, 'date_from'=>$from, 'date_to'=>$to,
        'room_id'=>$roomId, 'term_id'=>$termId,
        'time_from'=>$timeFrom, 'time_to'=>$timeTo,
        'unit'=>($input['unit'] ?? '') === 'periods' ? 'periods' : 'hours',
        'period_minutes'=>in_array((int)($input['period_minutes'] ?? 60), [50, 60, 90], true) ? (int)($input['period_minutes'] ?? 60) : 60,
        'group'=>in_array($input['group'] ?? '', ['day', 'room'], true) ? $input['group'] : 'detail',
        'q'=>$search[0] ?? '',
        'sort'=>($input['sort'] ?? '') === 'desc' ? 'desc' : 'asc',
        'source'=>in_array($input['source'] ?? '', ['all', 'classes', 'schedule'], true) ? $input['source'] : ($report ? 'classes' : 'all'),
        'errors'=>$errors,
    ];
}

/** One event per continuous class/weekly occurrence, before day/time slicing. */
function room_usage_events(string $from, string $to, array $filters = []): array
{
    if (!valid_iso_date($from) || !valid_iso_date($to) || $from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 365) {
        throw new InvalidArgumentException('ช่วงวันที่ไม่ถูกต้องหรือเกิน 366 วัน');
    }
    $viewer = current_user();
    if (!$viewer) return [];
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Asia/Bangkok'));
    $start = new DateTimeImmutable($from, $tz);
    $end = (new DateTimeImmutable($to, $tz))->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    $params = [':start'=>$start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), ':end'=>$end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), ':from'=>$from, ':to'=>$to];
    // Also fetch linked cancellations/overrides by occurrence date, even if moved.
    $where = ['((cs.starts_at < :end AND cs.ends_at > :start) OR cs.scheduled_date BETWEEN :from AND :to)'];
    $scheduleWhere = ["s.status='active'", 's.active_from <= :to', 's.active_until >= :from'];
    $scheduleParams = [':from'=>$from, ':to'=>$to];
    if ($viewer['role'] === 'lecturer') {
        $where[] = 'cs.lecturer_user_id=:viewer';
        $scheduleWhere[] = 's.lecturer_user_id=:viewer';
        $params[':viewer'] = $scheduleParams[':viewer'] = (int)$viewer['id'];
    }
    // Apply room/term filters after reconciliation so a moved occurrence does
    // not leave a phantom reservation in its original room.
    $query = db()->prepare('SELECT cs.*, r.code AS room_code, r.name AS room_name, u.full_name AS lecturer_name, s.term_id,
        (SELECT COUNT(*) FROM attendance_records ar WHERE ar.class_session_id=cs.id) AS attendance_count
        FROM class_sessions cs JOIN rooms r ON r.id=cs.room_id JOIN users u ON u.id=cs.lecturer_user_id
        LEFT JOIN course_schedules s ON s.id=cs.schedule_id WHERE ' . implode(' AND ', $where));
    $query->execute($params);
    $classes = $query->fetchAll();
    if (count($classes) > 20000) throw new RuntimeException('ข้อมูลมากเกินไป กรุณาลดช่วงวันที่');
    $overrides = [];
    $events = [];
    foreach ($classes as $row) {
        if ($row['schedule_id'] && $row['scheduled_date']) $overrides[$row['schedule_id'] . '|' . $row['scheduled_date']] = true;
        if ($row['status'] === 'cancelled' || $row['starts_at'] >= $params[':end'] || $row['ends_at'] <= $params[':start']) continue;
        $events[] = [
            'key'=>'class-' . $row['id'], 'class_id'=>(int)$row['id'], 'schedule_id'=>(int)$row['schedule_id'],
            'term_id'=>(int)$row['term_id'], 'room_id'=>(int)$row['room_id'], 'room_code'=>$row['room_code'], 'room_name'=>$row['room_name'],
            'course_code'=>$row['course_code'], 'course_name'=>$row['course_name'], 'section'=>$row['section'] ?? '', 'lecturer_name'=>$row['lecturer_name'],
            'starts_at'=>$row['starts_at'], 'ends_at'=>$row['ends_at'], 'status'=>$row['status'], 'display_status'=>class_checkin_status($row), 'source'=>'classes', 'attendance_count'=>(int)$row['attendance_count'],
        ];
    }
    $query = db()->prepare(schedule_select_sql() . ' WHERE ' . implode(' AND ', $scheduleWhere));
    $query->execute($scheduleParams);
    foreach ($query->fetchAll() as $row) {
        $activeStart = new DateTimeImmutable(max($from, $row['active_from']), $tz);
        $offset = ((int)$row['day_of_week'] - (int)$activeStart->format('N') + 7) % 7;
        for ($day=$activeStart->modify('+' . $offset . ' days'); $day->format('Y-m-d') <= min($to, $row['active_until']); $day=$day->modify('+7 days')) {
            $date = $day->format('Y-m-d');
            if (isset($overrides[$row['id'] . '|' . $date])) continue;
            $events[] = [
                'key'=>'schedule-' . $row['id'] . '-' . $date, 'class_id'=>0, 'schedule_id'=>(int)$row['id'], 'term_id'=>(int)$row['term_id'],
                'room_id'=>(int)$row['room_id'], 'room_code'=>$row['room_code'], 'room_name'=>$row['room_name'], 'course_code'=>$row['course_code'],
                'course_name'=>$row['course_name'], 'section'=>$row['section'] ?? '', 'lecturer_name'=>$row['lecturer_name'],
                'starts_at'=>(new DateTimeImmutable($date . ' ' . $row['starts_time'], $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                'ends_at'=>(new DateTimeImmutable($date . ' ' . $row['ends_time'], $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                'status'=>'planned', 'source'=>'schedule', 'attendance_count'=>0,
            ];
            if (count($events) > 20000) throw new RuntimeException('ข้อมูลมากเกินไป กรุณาลดช่วงวันที่');
        }
    }
    $term = !empty($filters['term_id']) ? get_academic_term((int)$filters['term_id']) : null;
    $events = array_values(array_filter($events, static function (array $event) use ($filters, $term, $tz): bool {
        if (!empty($filters['room_id']) && $event['room_id'] !== (int)$filters['room_id']) return false;
        if (($filters['source'] ?? 'all') !== 'all' && $event['source'] !== $filters['source']) return false;
        if (($filters['q'] ?? '') !== '' && stripos(implode(' ', [$event['course_code'], $event['course_name'], $event['lecturer_name'], $event['room_code'], $event['section']]), $filters['q']) === false) return false;
        if ($term) {
            if ($event['term_id']) return $event['term_id'] === $term['id'];
            $date = (new DateTimeImmutable($event['starts_at']))->setTimezone($tz)->format('Y-m-d');
            return $date >= $term['starts_on'] && $date <= $term['ends_on'];
        }
        return true;
    }));
    usort($events, static fn(array $a, array $b): int => [$a['starts_at'], $a['room_code'], $a['key']] <=> [$b['starts_at'], $b['room_code'], $b['key']]);
    return $events;
}

/** Clip each event to the selected local days and daily clock-time window. */
function room_usage_slices(array $events, string $from, string $to, string $timeFrom = '00:00', string $timeTo = '24:00'): array
{
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Asia/Bangkok'));
    $rows = [];
    foreach ($events as $event) {
        $start = (new DateTimeImmutable($event['starts_at']))->setTimezone($tz);
        $end = (new DateTimeImmutable($event['ends_at']))->setTimezone($tz);
        $first = max($from, $start->format('Y-m-d'));
        $last = min($to, $end->modify('-1 second')->format('Y-m-d'));
        $attendanceAssigned = false;
        for ($date=new DateTimeImmutable($first, $tz); $date->format('Y-m-d') <= $last; $date=$date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $windowStart = new DateTimeImmutable($day . ' ' . $timeFrom, $tz);
            $windowEnd = $timeTo === '24:00' ? $date->modify('+1 day') : new DateTimeImmutable($day . ' ' . $timeTo, $tz);
            $sliceStart = max($start->getTimestamp(), $windowStart->getTimestamp());
            $sliceEnd = min($end->getTimestamp(), $windowEnd->getTimestamp());
            if ($sliceEnd <= $sliceStart) continue;
            $localStart = (new DateTimeImmutable('@' . $sliceStart))->setTimezone($tz);
            $localEnd = (new DateTimeImmutable('@' . $sliceEnd))->setTimezone($tz);
            $rows[] = $event + [
                'date'=>$day, 'start_time'=>$localStart->format('H:i'),
                'end_time'=>$localEnd->format('Y-m-d') > $day ? '24:00' : $localEnd->format('H:i'),
                'minutes'=>($sliceEnd-$sliceStart)/60,
                'counted_attendance'=>$attendanceAssigned ? 0 : $event['attendance_count'],
            ];
            $attendanceAssigned = true;
        }
    }
    usort($rows, static fn(array $a, array $b): int => [$a['date'], $a['start_time'], $a['room_code']] <=> [$b['date'], $b['start_time'], $b['room_code']]);
    return $rows;
}

function usage_source_label(array $event): string
{
    return $event['source'] === 'schedule' ? 'ตารางตามแผน' : (empty($event['schedule_id']) ? 'คาบครั้งเดียว · ' : '') . match ($event['display_status'] ?? class_checkin_status($event)) {
        'draft'=>'คลาสแบบร่าง', 'closed'=>'คลาสปิดรับแล้ว',
        'overdue'=>'สิ้นสุดเวลารับอัตโนมัติ', 'scheduled'=>'คลาสรอเวลาเริ่ม', 'cancelled'=>'คลาสยกเลิก', default=>'คลาสเปิดลงชื่อ',
    };
}

function usage_report(array $filters): array
{
    $rows = [];
    $errors = $filters['errors'];
    if (!$errors) {
        try {
            $rows = room_usage_slices(room_usage_events($filters['date_from'], $filters['date_to'], $filters), $filters['date_from'], $filters['date_to'], $filters['time_from'], $filters['time_to']);
        } catch (Throwable) {
            $errors[] = 'โหลดรายงานไม่สำเร็จ กรุณาลดช่วงวันที่แล้วลองอีกครั้ง';
        }
    }
    $factor = $filters['unit'] === 'periods' ? $filters['period_minutes'] : 60;
    $groups = [];
    foreach ($rows as &$row) {
        $row['quantity'] = $row['minutes'] / $factor;
        $key = $filters['group'] === 'room' ? $row['room_code'] : $row['date'];
        $groups[$key] ??= ['label'=>$key, 'quantity'=>0, 'minutes'=>0, 'events'=>[], 'attendance'=>0];
        $groups[$key]['quantity'] += $row['quantity'];
        $groups[$key]['minutes'] += $row['minutes'];
        $groups[$key]['events'][$row['key']] = true;
        $groups[$key]['attendance'] += $row['counted_attendance'];
    }
    unset($row);
    ksort($groups);
    return ['filters'=>$filters, 'errors'=>$errors, 'rows'=>$rows, 'groups'=>array_values($groups),
        'quantity'=>array_sum(array_column($rows, 'quantity')), 'minutes'=>array_sum(array_column($rows, 'minutes')),
        'events'=>count(array_unique(array_column($rows, 'key'))), 'attendees'=>array_sum(array_column($rows, 'counted_attendance')),
        'unit_label'=>$filters['unit'] === 'periods' ? 'คาบเทียบเท่า' : 'ชั่วโมง',
    ];
}

function usage_report_query(array $filters): array
{
    return array_diff_key($filters, ['errors'=>true, 'month'=>true]);
}

function usage_report_table(array $report): array
{
    $unit = $report['unit_label'];
    $rows = [];
    if ($report['filters']['group'] !== 'detail') {
        $headers = [$report['filters']['group'] === 'room' ? 'ห้อง' : 'วันที่', 'รายการใช้ห้อง', $unit, 'จำนวนการลงชื่อ'];
        foreach ($report['groups'] as $group) $rows[] = [$group['label'], count($group['events']), number_format($group['quantity'], 2, '.', ''), $group['attendance']];
    } else {
        $headers = ['วันที่', 'เริ่ม (ในช่วงที่กรอง)', 'สิ้นสุด (ในช่วงที่กรอง)', 'ห้อง', 'รหัสวิชา', 'ชื่อวิชา', 'กลุ่ม', 'ผู้สอน', 'ประเภท', $unit, 'จำนวนการลงชื่อ'];
        foreach ($report['rows'] as $row) $rows[] = [$row['date'], $row['start_time'], $row['end_time'], $row['room_code'], $row['course_code'], $row['course_name'], $row['section'], $row['lecturer_name'], usage_source_label($row), number_format($row['quantity'], 2, '.', ''), $row['counted_attendance']];
    }
    if (($report['filters']['sort'] ?? 'asc') === 'desc') $rows = array_reverse($rows);
    return ['headers'=>$headers, 'rows'=>$rows];
}
