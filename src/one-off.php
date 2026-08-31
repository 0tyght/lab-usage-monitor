<?php

declare(strict_types=1);

/** Availability is deliberately anonymous: no other lecturer's course/QR data. */
function one_off_busy_times(int $roomId, int $lecturerId, string $date): array
{
    if (!valid_iso_date($date)) throw new InvalidArgumentException('กรุณาเลือกวันที่ให้ถูกต้อง');
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Asia/Bangkok'));
    $day = new DateTimeImmutable($date, $tz);
    $start = $day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $end = $day->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $query = db()->prepare("SELECT room_id, lecturer_user_id, starts_at, ends_at FROM class_sessions WHERE status<>'cancelled' AND (room_id=:room OR lecturer_user_id=:lecturer) AND starts_at<:end AND ends_at>:start");
    $query->execute([':room'=>$roomId, ':lecturer'=>$lecturerId, ':start'=>$start, ':end'=>$end]);
    $busy = [];
    foreach ($query->fetchAll() as $row) {
        $begin = max(strtotime($start), strtotime($row['starts_at']));
        $finish = min(strtotime($end), strtotime($row['ends_at']));
        $busy[] = ['start'=>(int)(($begin-strtotime($start))/60), 'end'=>(int)(($finish-strtotime($start))/60), 'reason'=>(int)$row['room_id']===$roomId?'ห้องมีคาบแล้ว':'ผู้สอนมีคาบแล้ว'];
    }
    $query = db()->prepare("SELECT s.room_id,s.starts_time,s.ends_time FROM course_schedules s WHERE s.status='active' AND (s.room_id=:room OR s.lecturer_user_id=:lecturer) AND s.day_of_week=:day AND s.active_from<=:date AND s.active_until>=:date AND NOT EXISTS (SELECT 1 FROM class_sessions cs WHERE cs.schedule_id=s.id AND cs.scheduled_date=:date)");
    $query->execute([':room'=>$roomId, ':lecturer'=>$lecturerId, ':day'=>(int)$day->format('N'), ':date'=>$date]);
    foreach ($query->fetchAll() as $row) {
        $minutes = static fn(string $t): int => (int)substr($t,0,2)*60+(int)substr($t,3,2);
        $busy[] = ['start'=>$minutes($row['starts_time']), 'end'=>$minutes($row['ends_time']), 'reason'=>(int)$row['room_id']===$roomId?'ห้องมีตารางทั้งภาค':'ผู้สอนมีตารางทั้งภาค'];
    }
    usort($busy, static fn(array $a,array $b): int => $a['start']<=>$b['start']);
    return $busy;
}

function one_off_lecturer_id(array $input): int
{
    $viewer = current_user();
    if (!$viewer) return 0;
    return $viewer['role']==='lecturer' ? (int)$viewer['id'] : (int)($input['lecturer_user_id'] ?? $viewer['id']);
}

function create_one_off_session(array $input): array
{
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'], ['admin','lecturer'], true)) return ['ok'=>false,'errors'=>['form'=>'ไม่มีสิทธิ์เพิ่มคาบ']];
    $date = (string)($input['class_date'] ?? '');
    $start = (string)($input['starts_time'] ?? '');
    $end = (string)($input['ends_time'] ?? '');
    $errors = [];
    if (!valid_iso_date($date)) $errors['class_date']='กรุณาเลือกวันที่ให้ถูกต้อง';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start)) $errors['starts_time']='กรุณาเลือกเวลาเริ่ม';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end) || $end <= $start) $errors['ends_time']='เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มในวันเดียวกัน';
    if ($errors) return ['ok'=>false,'errors'=>$errors];
    $roomId = (int)($input['room_id'] ?? 0);
    $lecturerId = one_off_lecturer_id($input);
    $begin = (int)substr($start,0,2)*60+(int)substr($start,3,2);
    $finish = (int)substr($end,0,2)*60+(int)substr($end,3,2);
    $connection = db();
    $connection->beginTransaction();
    try {
        foreach (one_off_busy_times($roomId,$lecturerId,$date) as $busy) {
            if ($begin < $busy['end'] && $finish > $busy['start']) {
                $connection->rollBack();
                return ['ok'=>false,'errors'=>['ends_time'=>$busy['reason'].'ในช่วงที่เลือก กรุณาเปลี่ยนเวลา ห้อง หรือผู้สอน']];
            }
        }
        $result = create_class_session(array_replace($input, ['starts_at'=>$date.'T'.$start, 'ends_at'=>$date.'T'.$end, 'lecturer_user_id'=>$lecturerId]));
        if ($result['ok']) $connection->commit(); else $connection->rollBack();
        return $result;
    } catch (Throwable) {
        if ($connection->inTransaction()) $connection->rollBack();
        return ['ok'=>false,'errors'=>['form'=>'บันทึกไม่สำเร็จ อาจมีผู้จองเวลาเดียวกัน กรุณาตรวจเวลาแล้วลองอีกครั้ง']];
    }
}

/** Prevent a later recurring import from overwriting a one-off reservation. */
function recurring_conflicts_with_one_off(int $roomId, int $lecturerId, int $weekday, string $from, string $to, string $start, string $end): bool
{
    $tz = new DateTimeZone((string)app_config('app.timezone','Asia/Bangkok'));
    $first = new DateTimeImmutable($from,$tz);
    $last = (new DateTimeImmutable($to,$tz))->modify('+1 day');
    $query = db()->prepare("SELECT starts_at,ends_at FROM class_sessions WHERE schedule_id IS NULL AND status<>'cancelled' AND (room_id=:room OR lecturer_user_id=:lecturer) AND starts_at<:end AND ends_at>:start");
    $query->execute([':room'=>$roomId, ':lecturer'=>$lecturerId, ':start'=>$first->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), ':end'=>$last->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
    foreach ($query->fetchAll() as $row) {
        $begin=(new DateTimeImmutable($row['starts_at']))->setTimezone($tz);
        $finish=(new DateTimeImmutable($row['ends_at']))->setTimezone($tz);
        for ($day=max($first,$begin->setTime(0,0)); $day<$last && $day<$finish; $day=$day->modify('+1 day')) {
            if ((int)$day->format('N')!==$weekday) continue;
            if ($begin < new DateTimeImmutable($day->format('Y-m-d').' '.$end,$tz) && $finish > new DateTimeImmutable($day->format('Y-m-d').' '.$start,$tz)) return true;
        }
    }
    return false;
}
