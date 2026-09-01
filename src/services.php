<?php

declare(strict_types=1);

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function room_select_sql(): string
{
    return "SELECT r.id, r.code, r.name, r.building, r.floor, r.capacity, r.status,
                   r.qr_token AS qr_code, r.description,
                   CASE
                       WHEN r.status <> 'available' THEN r.status
                       WHEN EXISTS (
                           SELECT 1 FROM class_sessions current_session
                           WHERE current_session.room_id = r.id
                             AND current_session.status IN ('open','closed')
                             AND current_session.starts_at <= strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                             AND current_session.ends_at > strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                       ) THEN 'active'
                       ELSE 'available'
                   END AS live_status,
                   (
                       SELECT current_session.id FROM class_sessions current_session
                       WHERE current_session.room_id = r.id
                         AND current_session.status IN ('open','closed')
                         AND current_session.starts_at <= strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                         AND current_session.ends_at > strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                       ORDER BY current_session.starts_at DESC LIMIT 1
                   ) AS active_usage_id
            FROM rooms r";
}

function list_rooms(): array
{
    $rows = db()->query(room_select_sql() . " ORDER BY CASE r.status WHEN 'available' THEN 0 WHEN 'maintenance' THEN 1 ELSE 2 END, r.code")
        ->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['capacity'] = (int) $row['capacity'];
        $row['active_usage_id'] = $row['active_usage_id'] === null ? null : (int) $row['active_usage_id'];
    }

    return $rows;
}

function find_room_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $statement = db()->prepare(room_select_sql() . ' WHERE r.code = :code OR r.qr_token = :token LIMIT 1');
    $statement->execute([':code' => strtoupper($code), ':token' => $code]);
    $room = $statement->fetch();
    if (!$room) {
        return null;
    }

    $room['id'] = (int) $room['id'];
    $room['capacity'] = (int) $room['capacity'];
    $room['active_usage_id'] = $room['active_usage_id'] === null ? null : (int) $room['active_usage_id'];

    return $room;
}

function local_period_bounds(string $period = 'day'): array
{
    $timezone = new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok'));
    $now = new DateTimeImmutable('now', $timezone);

    $start = match ($period) {
        'week' => $now->modify('monday this week')->setTime(0, 0),
        'month' => $now->modify('first day of this month')->setTime(0, 0),
        default => $now->setTime(0, 0),
    };
    $end = match ($period) {
        'week' => $start->modify('+7 days'),
        'month' => $start->modify('+1 month'),
        default => $start->modify('+1 day'),
    };

    return [
        $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        $start,
        $end,
    ];
}

function local_datetime_to_utc(string $value): ?string
{
    if (str_contains($value, "\0")) return null;
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
        return null;
    }

    $timezone = new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function list_lecturers(): array
{
    $statement = db()->query("SELECT id, email, full_name, department FROM users WHERE is_active = 1 AND role IN ('admin', 'lecturer') ORDER BY CASE role WHEN 'lecturer' THEN 0 ELSE 1 END, full_name");
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) $row['id'] = (int) $row['id'];

    return $rows;
}

function list_academic_terms(): array
{
    $rows = db()->query("SELECT id, name, academic_year, semester, starts_on, ends_on, status FROM academic_terms ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END, starts_on DESC")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['academic_year'] = (int) $row['academic_year'];
        $row['name'] = academic_term_code($row['academic_year'], $row['semester']);
    }

    return $rows;
}

function get_academic_term(int $id): ?array
{
    $statement = db()->prepare('SELECT id, name, academic_year, semester, starts_on, ends_on, status FROM academic_terms WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['academic_year'] = (int) $row['academic_year'];
    $row['name'] = academic_term_code($row['academic_year'], $row['semester']);

    return $row;
}

function current_academic_term(): ?array
{
    $row = db()->query("SELECT id FROM academic_terms ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END, starts_on DESC LIMIT 1")->fetchColumn();

    return $row === false ? null : get_academic_term((int) $row);
}

function create_academic_term(array $input): array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        return ['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้นที่สร้างภาคการศึกษาได้', 'errors' => ['form' => 'เฉพาะผู้ดูแลระบบเท่านั้นที่สร้างภาคการศึกษาได้']];
    }
    $year = filter_var($input['academic_year'] ?? null, FILTER_VALIDATE_INT);
    $semester = (string) ($input['semester'] ?? '');
    if ($semester === '3') $semester = 'summer';
    $name = academic_term_code((int)$year, $semester);
    $errors = [];
    $calendar = nu_academic_presets()[(int)$year] ?? null;
    if (!$calendar) $errors['academic_year'] = 'กรุณาเลือกปีที่มีปฏิทิน ม.นเรศวรในระบบ';
    if (!in_array($semester, ['1', '2', 'summer'], true)) $errors['semester'] = 'กรุณาเลือกภาคการศึกษา';
    $preset = $calendar['terms'][$semester] ?? null;
    if (!$errors && !$preset) $errors['semester'] = 'ยังไม่มีวันภาคการศึกษานี้ในปฏิทินของระบบ';
    if ($errors) return ['ok' => false, 'message' => 'กรุณาตรวจสอบข้อมูลภาคการศึกษา', 'errors' => $errors];
    // Dates are authoritative catalog values, never client-supplied dates/confirmation.
    $startsOn = $preset['start'];
    $endsOn = $preset['end'];

    try {
        $now = utc_now();
        $statement = db()->prepare("INSERT INTO academic_terms (name, academic_year, semester, starts_on, ends_on, status, created_at, updated_at) VALUES (:name, :year, :semester, :starts_on, :ends_on, 'planned', :created_at, :updated_at)");
        $statement->execute([':name'=>$name, ':year'=>$year, ':semester'=>$semester, ':starts_on'=>$startsOn, ':ends_on'=>$endsOn, ':created_at'=>$now, ':updated_at'=>$now]);
        $id = (int) db()->lastInsertId();
        audit_log('academic_term_created', 'academic_term', $id, ['academic_year'=>$year, 'semester'=>$semester], (int) $user['id']);
        return ['ok'=>true, 'id'=>$id, 'message'=>'สร้างภาคการศึกษาเรียบร้อย'];
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'unique')) {
            return ['ok'=>false, 'message'=>'มีภาคการศึกษานี้อยู่แล้ว', 'errors'=>['academic_year'=>'มีปีและภาคการศึกษานี้อยู่แล้ว']];
        }
        throw $exception;
    }
}

function schedule_select_sql(): string
{
    return "SELECT s.id, s.term_id, s.room_id, s.lecturer_user_id, s.course_code, s.course_name,
                   s.section, s.day_of_week, s.starts_time, s.ends_time, s.active_from, s.active_until,
                   s.status, s.notes, r.code AS room_code, r.name AS room_name, r.capacity,
                   u.full_name AS lecturer_name, u.email AS lecturer_email, t.name AS term_name
            FROM course_schedules s
            JOIN rooms r ON r.id = s.room_id
            JOIN users u ON u.id = s.lecturer_user_id
            JOIN academic_terms t ON t.id = s.term_id";
}

function normalize_schedule_row(array $row): array
{
    foreach (['id', 'term_id', 'room_id', 'lecturer_user_id', 'day_of_week', 'capacity'] as $key) $row[$key] = (int) $row[$key];
    return $row;
}

function list_course_schedules(int $termId, array $filters = []): array
{
    $viewer = current_user();
    $where = ['s.term_id = :term_id', "s.status = 'active'"];
    $params = [':term_id' => $termId];
    if ($viewer && $viewer['role'] === 'lecturer') {
        $where[] = 's.lecturer_user_id = :viewer_id';
        $params[':viewer_id'] = $viewer['id'];
    }
    $roomId = filter_var($filters['room_id'] ?? null, FILTER_VALIDATE_INT);
    if ($roomId !== false && $roomId !== null && $roomId > 0) {
        $where[] = 's.room_id = :room_id';
        $params[':room_id'] = $roomId;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(s.course_code LIKE :search OR s.course_name LIKE :search OR r.code LIKE :search OR u.full_name LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    $statement = db()->prepare(schedule_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.day_of_week, s.starts_time, r.code');
    $statement->execute($params);
    return array_map('normalize_schedule_row', $statement->fetchAll());
}

function get_course_schedule(int $id, bool $enforceOwnership = true): ?array
{
    $statement = db()->prepare(schedule_select_sql() . ' WHERE s.id = :id LIMIT 1');
    $statement->execute([':id'=>$id]);
    $row = $statement->fetch();
    if (!$row) return null;
    $row = normalize_schedule_row($row);
    $viewer = current_user();
    if ($enforceOwnership && $viewer && $viewer['role'] === 'lecturer' && $row['lecturer_user_id'] !== (int) $viewer['id']) return null;
    return $row;
}

function validate_schedule_input(array $input, int $ignoreId = 0): array
{
    // The unified class form reserves the entire configured term. Posted dates
    // cannot shorten the conflict check or create a misleading partial schedule.
    if (($input['class_mode'] ?? '') === 'semester') {
        $selectedTerm = get_academic_term((int)($input['term_id'] ?? 0));
        $input['active_from'] = $selectedTerm['starts_on'] ?? '';
        $input['active_until'] = $selectedTerm['ends_on'] ?? '';
    }
    $viewer = current_user();
    $termId = filter_var($input['term_id'] ?? null, FILTER_VALIDATE_INT);
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $lecturerId = $viewer && $viewer['role'] === 'lecturer' ? (int) $viewer['id'] : filter_var($input['lecturer_user_id'] ?? null, FILTER_VALIDATE_INT);
    $courseCode = strtoupper(trim((string) ($input['course_code'] ?? '')));
    $courseName = trim((string) ($input['course_name'] ?? ''));
    $section = trim((string) ($input['section'] ?? ''));
    $day = filter_var($input['day_of_week'] ?? null, FILTER_VALIDATE_INT);
    $startsTime = trim((string) ($input['starts_time'] ?? ''));
    $endsTime = trim((string) ($input['ends_time'] ?? ''));
    $activeFrom = trim((string) ($input['active_from'] ?? ''));
    $activeUntil = trim((string) ($input['active_until'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $errors = [];
    if ($termId === false || !$term = get_academic_term((int) $termId)) $errors['term_id'] = 'ไม่พบภาคการศึกษาที่เลือก';
    if ($roomId === false || $roomId < 1) $errors['room_id'] = 'กรุณาเลือกห้อง';
    if ($lecturerId === false || $lecturerId < 1) $errors['lecturer_user_id'] = 'กรุณาเลือกอาจารย์ผู้สอน';
    if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $courseCode)) $errors['course_code'] = 'รหัสรายวิชาไม่ถูกต้อง';
    if (text_length($courseName) < 2 || text_length($courseName) > 150) $errors['course_name'] = 'ชื่อรายวิชาต้องมีความยาว 2–150 ตัวอักษร';
    if ($section !== '' && text_length($section) > 30) $errors['section'] = 'กลุ่มเรียนต้องไม่เกิน 30 ตัวอักษร';
    if ($day === false || $day < 1 || $day > 7) $errors['day_of_week'] = 'กรุณาเลือกวันเรียน';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startsTime)) $errors['starts_time'] = 'เวลาเริ่มไม่ถูกต้อง';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endsTime) || $endsTime <= $startsTime) $errors['ends_time'] = 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม';
    if (!valid_iso_date($activeFrom)) $errors['active_from'] = 'วันเริ่มใช้ตารางไม่ถูกต้อง';
    if (!valid_iso_date($activeUntil) || $activeUntil < $activeFrom) $errors['active_until'] = 'วันสิ้นสุดใช้ตารางไม่ถูกต้อง';
    if (isset($term) && ($activeFrom < $term['starts_on'] || $activeUntil > $term['ends_on'])) $errors['active_from'] = 'ช่วงวันที่ต้องอยู่ภายในภาคการศึกษา';
    if (text_length($notes) > 500) $errors['notes'] = 'หมายเหตุต้องไม่เกิน 500 ตัวอักษร';
    if ($errors) return ['ok'=>false, 'message'=>'กรุณาตรวจสอบข้อมูลตารางเรียน', 'errors'=>$errors];

    $room = db()->prepare("SELECT id, code, status FROM rooms WHERE id = :id LIMIT 1");
    $room->execute([':id'=>$roomId]);
    $roomRow = $room->fetch();
    if (!$roomRow || $roomRow['status'] !== 'available') return ['ok'=>false, 'message'=>'ห้องนี้ไม่พร้อมจัดตาราง', 'errors'=>['room_id'=>'ห้องนี้ไม่พร้อมจัดตาราง']];
    $lecturer = db()->prepare("SELECT id, full_name FROM users WHERE id = :id AND is_active = 1 AND role IN ('admin','lecturer') LIMIT 1");
    $lecturer->execute([':id'=>$lecturerId]);
    if (!$lecturerRow = $lecturer->fetch()) return ['ok'=>false, 'message'=>'ไม่พบอาจารย์ผู้สอน', 'errors'=>['lecturer_user_id'=>'ไม่พบอาจารย์ผู้สอน']];

    $conflict = db()->prepare("SELECT s.id, s.room_id, s.lecturer_user_id, s.course_code, s.active_from, s.active_until, r.code AS room_code, u.full_name AS lecturer_name
        FROM course_schedules s JOIN rooms r ON r.id=s.room_id JOIN users u ON u.id=s.lecturer_user_id
        WHERE s.day_of_week=:day AND s.status='active' AND s.id<>:ignore_id
          AND s.active_from<=:active_until AND s.active_until>=:active_from
          AND s.starts_time<:ends_time AND s.ends_time>:starts_time
          AND (s.room_id=:room_id OR s.lecturer_user_id=:lecturer_id)");
    $conflict->execute([':day'=>$day, ':ignore_id'=>$ignoreId, ':active_until'=>$activeUntil, ':active_from'=>$activeFrom, ':ends_time'=>$endsTime, ':starts_time'=>$startsTime, ':room_id'=>$roomId, ':lecturer_id'=>$lecturerId]);
    while ($row = $conflict->fetch()) {
        $overlapStart = new DateTimeImmutable(max($activeFrom,$row['active_from']));
        $firstLesson = $overlapStart->modify('+' . (((int)$day-(int)$overlapStart->format('N')+7)%7) . ' days');
        if ($firstLesson->format('Y-m-d') > min($activeUntil,$row['active_until'])) continue;
        $message = (int) $row['room_id'] === (int) $roomId
            ? 'ห้อง ' . $row['room_code'] . ' มีวิชา ' . $row['course_code'] . ' ในช่วงเวลานี้แล้ว'
            : 'อาจารย์ ' . $row['lecturer_name'] . ' มีตารางสอนในช่วงเวลานี้แล้ว';
        return ['ok'=>false, 'message'=>$message, 'errors'=>['form'=>$message]];
    }

    if (recurring_conflicts_with_one_off((int)$roomId,(int)$lecturerId,(int)$day,$activeFrom,$activeUntil,$startsTime,$endsTime)) {
        return ['ok'=>false,'message'=>'ห้องหรือผู้สอนมีคลาสเรียนแบบครั้งเดียวในช่วงนี้แล้ว','errors'=>['form'=>'ห้องหรือผู้สอนมีคลาสเรียนแบบครั้งเดียวในช่วงนี้แล้ว กรุณาเปลี่ยนช่วงเวลา']];
    }
    return ['ok'=>true, 'data'=>[
        'term_id'=>(int)$termId, 'room_id'=>(int)$roomId, 'lecturer_user_id'=>(int)$lecturerId,
        'course_code'=>$courseCode, 'course_name'=>$courseName, 'section'=>$section === '' ? null : $section,
        'day_of_week'=>(int)$day, 'starts_time'=>$startsTime, 'ends_time'=>$endsTime,
        'active_from'=>$activeFrom, 'active_until'=>$activeUntil, 'notes'=>$notes === '' ? null : $notes,
    ]];
}

function create_course_schedule(array $input): array
{
    $user = current_user();
    if (!$user || !in_array($user['role'], ['admin','lecturer'], true)) return ['ok'=>false, 'message'=>'ไม่มีสิทธิ์จัดตารางเรียน', 'errors'=>['form'=>'ไม่มีสิทธิ์จัดตารางเรียน']];
    $validated = validate_schedule_input($input);
    if (!$validated['ok']) return $validated;
    $data = $validated['data'];
    $now = utc_now();
    $statement = db()->prepare("INSERT INTO course_schedules (term_id, room_id, lecturer_user_id, course_code, course_name, section, day_of_week, starts_time, ends_time, active_from, active_until, status, notes, created_at, updated_at) VALUES (:term_id,:room_id,:lecturer_user_id,:course_code,:course_name,:section,:day_of_week,:starts_time,:ends_time,:active_from,:active_until,'active',:notes,:created_at,:updated_at)");
    $statement->execute([
        ':term_id'=>$data['term_id'], ':room_id'=>$data['room_id'], ':lecturer_user_id'=>$data['lecturer_user_id'],
        ':course_code'=>$data['course_code'], ':course_name'=>$data['course_name'], ':section'=>$data['section'],
        ':day_of_week'=>$data['day_of_week'], ':starts_time'=>$data['starts_time'], ':ends_time'=>$data['ends_time'],
        ':active_from'=>$data['active_from'], ':active_until'=>$data['active_until'], ':notes'=>$data['notes'],
        ':created_at'=>$now, ':updated_at'=>$now,
    ]);
    $id = (int) db()->lastInsertId();
    audit_log('course_schedule_created', 'course_schedule', $id, ['course_code'=>$data['course_code'], 'room_id'=>$data['room_id']], (int)$user['id']);
    return ['ok'=>true, 'id'=>$id, 'message'=>'เพิ่มตารางเรียนรายสัปดาห์แล้ว'];
}

function cancel_course_schedule(int $id): array
{
    $schedule = get_course_schedule($id);
    $user = current_user();
    if (!$schedule || !$user) return ['ok'=>false, 'message'=>'ไม่พบตารางเรียนหรือไม่มีสิทธิ์จัดการ'];
    if ($schedule['status'] === 'cancelled') return ['ok'=>true, 'message'=>'ตารางนี้ถูกยกเลิกแล้ว'];

    $connection = db();
    $connection->beginTransaction();
    try {
        $now = utc_now();
        $statement = $connection->prepare("UPDATE course_schedules SET status='cancelled', updated_at=:updated_at WHERE id=:id AND status='active'");
        $statement->execute([':updated_at'=>$now, ':id'=>$id]);
        if ($statement->rowCount() !== 1) {
            $connection->rollBack();
            return ['ok'=>false, 'message'=>'ตารางนี้ไม่อยู่ในสถานะที่ยกเลิกได้'];
        }

        $sessions = $connection->prepare("UPDATE class_sessions SET status='cancelled', updated_at=:updated_at WHERE schedule_id=:schedule_id AND status='draft' AND starts_at>:now");
        $sessions->execute([':updated_at'=>$now, ':schedule_id'=>$id, ':now'=>$now]);
        $cancelledSessions = $sessions->rowCount();
        audit_log('course_schedule_cancelled', 'course_schedule', $id, ['future_draft_sessions_cancelled'=>$cancelledSessions], (int)$user['id']);
        $connection->commit();

        return ['ok'=>true, 'message'=>'ยกเลิกตารางเรียนแล้ว' . ($cancelledSessions ? " และยกเลิก QR แบบร่างในอนาคต {$cancelledSessions} รายการ" : '')];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

function import_course_schedule_csv(int $termId, array $file, array $input = []): array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') return ['ok'=>false, 'message'=>'เฉพาะผู้ดูแลระบบเท่านั้นที่นำเข้าตารางได้', 'errors'=>['form'=>'เฉพาะผู้ดูแลระบบเท่านั้นที่นำเข้าตารางได้']];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) return ['ok'=>false, 'message'=>'กรุณาเลือกไฟล์ CSV', 'errors'=>['schedule_file'=>'กรุณาเลือกไฟล์ CSV']];
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) return ['ok'=>false, 'message'=>'ไฟล์ต้องมีขนาดไม่เกิน 2 MB', 'errors'=>['schedule_file'=>'ไฟล์ต้องมีขนาดไม่เกิน 2 MB']];
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') return ['ok'=>false, 'message'=>'รองรับเฉพาะไฟล์ .csv', 'errors'=>['schedule_file'=>'รองรับเฉพาะไฟล์ .csv']];
    $term = get_academic_term($termId);
    if (isset($input['academic_term_key'])) {
        $parts=explode('/',(string)$input['academic_term_key']);
        $term=count($parts)===2?class_term_choice(['academic_year'=>$parts[0],'semester'=>$parts[1]]):null;
        $termId=0;
    }
    if (!$term) return ['ok'=>false, 'message'=>'ไม่พบภาคการศึกษา', 'errors'=>['term_id'=>'ไม่พบภาคการศึกษา']];

    $handle = fopen((string)$file['tmp_name'], 'rb');
    if (!$handle) return ['ok'=>false, 'message'=>'ไม่สามารถอ่านไฟล์ได้', 'errors'=>['schedule_file'=>'ไม่สามารถอ่านไฟล์ได้']];
    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); return ['ok'=>false, 'message'=>'ไฟล์ไม่มีหัวตาราง', 'errors'=>['schedule_file'=>'ไฟล์ไม่มีหัวตาราง']]; }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(fn($value)=>strtolower(trim((string)$value)), $header);
    if (count(array_unique($header)) !== count($header)) { fclose($handle); return ['ok'=>false,'errors'=>['schedule_file'=>'หัวตาราง CSV มีชื่อคอลัมน์ซ้ำ กรุณาใช้ไฟล์ตัวอย่าง']]; }
    $required = ['course_code','course_name','section','room_code','lecturer_email','day_of_week','starts_time','ends_time','active_from','active_until','notes'];
    if (array_diff($required, $header)) { fclose($handle); return ['ok'=>false, 'message'=>'หัวตาราง CSV ไม่ครบ กรุณาใช้ไฟล์ตัวอย่าง', 'errors'=>['schedule_file'=>'หัวตาราง CSV ไม่ครบ กรุณาใช้ไฟล์ตัวอย่าง']]; }

    $rooms = db()->query('SELECT UPPER(code), id FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
    $lecturers = db()->query("SELECT LOWER(email), id FROM users WHERE is_active=1 AND role IN ('admin','lecturer')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $dayMap = ['monday'=>1,'mon'=>1,'จันทร์'=>1,'tuesday'=>2,'tue'=>2,'อังคาร'=>2,'wednesday'=>3,'wed'=>3,'พุธ'=>3,'thursday'=>4,'thu'=>4,'พฤหัสบดี'=>4,'friday'=>5,'fri'=>5,'ศุกร์'=>5,'saturday'=>6,'sat'=>6,'เสาร์'=>6,'sunday'=>7,'sun'=>7,'อาทิตย์'=>7];
    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle)) !== false) {
        $line++;
        if (count(array_filter($values, fn($v)=>trim((string)$v) !== '')) === 0) continue;
        if (count($values) > count($header)) { fclose($handle); return ['ok'=>false,'errors'=>['schedule_file'=>"แถว {$line}: จำนวนคอลัมน์เกินหัวตาราง ยังไม่ได้นำเข้าข้อมูล"]]; }
        $record = array_combine($header, array_pad($values, count($header), ''));
        $roomCode = strtoupper(trim((string)$record['room_code']));
        $email = strtolower(trim((string)$record['lecturer_email']));
        $dayRaw = strtolower(trim((string)$record['day_of_week']));
        $day = ctype_digit($dayRaw) ? (int)$dayRaw : ($dayMap[$dayRaw] ?? 0);
        if (!isset($rooms[$roomCode])) { fclose($handle); return ['ok'=>false, 'message'=>"แถว {$line}: ไม่พบห้อง {$roomCode}", 'errors'=>['schedule_file'=>"แถว {$line}: ไม่พบห้อง {$roomCode}"]]; }
        if (!isset($lecturers[$email])) { fclose($handle); return ['ok'=>false, 'message'=>"แถว {$line}: ไม่พบบัญชีอาจารย์ {$email}", 'errors'=>['schedule_file'=>"แถว {$line}: ไม่พบบัญชีอาจารย์ {$email}"]]; }
        $rows[] = [
            'term_id'=>$termId, 'room_id'=>$rooms[$roomCode], 'lecturer_user_id'=>$lecturers[$email],
            'course_code'=>$record['course_code'], 'course_name'=>$record['course_name'], 'section'=>$record['section'],
            'day_of_week'=>$day, 'starts_time'=>$record['starts_time'], 'ends_time'=>$record['ends_time'],
            'active_from'=>$record['active_from'] ?: $term['starts_on'], 'active_until'=>$record['active_until'] ?: $term['ends_on'], 'notes'=>$record['notes'], '_csv_line'=>$line,
        ];
        if (count($rows)>100) { fclose($handle); return class_batch_error('นำเข้าได้ไม่เกิน 100 แถวต่อไฟล์ กรุณาแบ่งไฟล์'); }
    }
    fclose($handle);
    if (!$rows) return ['ok'=>false, 'message'=>'ไม่พบข้อมูลตารางเรียนในไฟล์', 'errors'=>['schedule_file'=>'ไม่พบข้อมูลตารางเรียนในไฟล์']];

    $connection = db();
    $connection->beginTransaction();
    try {
        $connection->exec('UPDATE rooms SET id=id WHERE id IN (SELECT id FROM rooms)');
        if (!$termId) {
            $find=$connection->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?');
            $find->execute([$term['academic_year'],$term['semester']]);
            $termId=(int)$find->fetchColumn();
            if (!$termId) {
                $connection->prepare("INSERT INTO academic_terms (name,academic_year,semester,starts_on,ends_on,status,created_at,updated_at) VALUES (?,?,?,?,?,'planned',?,?)")->execute([$term['name'],$term['academic_year'],$term['semester'],$term['starts_on'],$term['ends_on'],utc_now(),utc_now()]);
                $termId=(int)$connection->lastInsertId();
            }
        }
        foreach ($rows as $index=>$record) {
            $record['term_id']=$termId;
            $result = create_course_schedule($record);
            if (!$result['ok']) {
                $connection->rollBack();
                $rowNumber = $record['_csv_line'];
                $reason = implode(' ',array_values($result['errors'] ?? [])) ?: $result['message'];
                return ['ok'=>false, 'message'=>"แถว {$rowNumber}: " . $reason, 'errors'=>['schedule_file'=>"แถว {$rowNumber}: " . $reason . ' ยังไม่ได้นำเข้าข้อมูลทั้งไฟล์']];
            }
            // Imported lessons are operational classes too, with one QR per date.
            $series = bin2hex(random_bytes(16));
            $cursor = new DateTimeImmutable($record['active_from']);
            $cursor = $cursor->modify('+'.(((int)$record['day_of_week']-(int)$cursor->format('N')+7)%7).' days');
            while ($cursor->format('Y-m-d') <= $record['active_until']) {
                $lesson = create_session_from_schedule((int)$result['id'], $cursor->format('Y-m-d'));
                if (!$lesson['ok']) throw new RuntimeException('Imported lesson could not be created');
                $connection->prepare("UPDATE class_sessions SET status='open',checkin_mode='scheduled',admission_lead_minutes=10,series_key=?,term_id=? WHERE id=?")->execute([$series,$termId,$lesson['id']]);
                $cursor = $cursor->modify('+7 days');
            }
        }
        audit_log('course_schedule_imported', 'academic_term', $termId, ['count'=>count($rows)], (int)$user['id']);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
    return ['ok'=>true, 'term_id'=>$termId, 'count'=>count($rows), 'message'=>'นำเข้าตารางเรียน ' . count($rows) . ' รายการ พร้อม QR ของทุกคลาสเรียบร้อย'];
}

function create_session_from_schedule(int $scheduleId, string $date): array
{
    $schedule = get_course_schedule($scheduleId);
    $user = current_user();
    if (!$schedule || !$user || !in_array($user['role'],['admin','lecturer'],true)) return ['ok'=>false, 'message'=>'ไม่พบตารางเรียนหรือไม่มีสิทธิ์'];
    if ($schedule['status']!=='active') return ['ok'=>false,'message'=>'ตารางนี้ถูกยกเลิกแล้ว ไม่สามารถสร้างคลาสเรียนใหม่'];
    if (!valid_iso_date($date)) return ['ok'=>false, 'message'=>'วันที่คลาสเรียนไม่ถูกต้อง'];
    $day = (int)(new DateTimeImmutable($date))->format('N');
    if ($day !== $schedule['day_of_week'] || $date < $schedule['active_from'] || $date > $schedule['active_until']) return ['ok'=>false, 'message'=>'วันที่นี้ไม่ตรงกับตารางเรียน'];
    $existing = db()->prepare('SELECT id FROM class_sessions WHERE schedule_id=:schedule_id AND scheduled_date=:date LIMIT 1');
    $existing->execute([':schedule_id'=>$scheduleId, ':date'=>$date]);
    if (($id = $existing->fetchColumn()) !== false) return ['ok'=>true, 'id'=>(int)$id, 'existing'=>true, 'message'=>'มี QR สำหรับคลาสเรียนนี้แล้ว'];
    $startsAt = local_datetime_to_utc($date . 'T' . $schedule['starts_time']);
    $endsAt = local_datetime_to_utc($date . 'T' . $schedule['ends_time']);
    if (!$startsAt || !$endsAt) return ['ok'=>false, 'message'=>'ไม่สามารถคำนวณเวลาเรียนได้'];
    $nowTs = time();
    $status = $nowTs >= strtotime($startsAt) - 1800 && $nowTs < strtotime($endsAt) ? 'open' : 'draft';
    $now = utc_now();
    $insert = db()->prepare("INSERT INTO class_sessions (room_id, lecturer_user_id, course_code, course_name, section, starts_at, ends_at, status, qr_token, notes, schedule_id, scheduled_date, created_at, updated_at) VALUES (:room_id,:lecturer_id,:course_code,:course_name,:section,:starts_at,:ends_at,:status,:qr_token,:notes,:schedule_id,:scheduled_date,:created_at,:updated_at)");
    $insert->execute([
        ':room_id'=>$schedule['room_id'], ':lecturer_id'=>$schedule['lecturer_user_id'], ':course_code'=>$schedule['course_code'], ':course_name'=>$schedule['course_name'], ':section'=>$schedule['section'],
        ':starts_at'=>$startsAt, ':ends_at'=>$endsAt, ':status'=>$status, ':qr_token'=>bin2hex(random_bytes(16)), ':notes'=>$schedule['notes'], ':schedule_id'=>$scheduleId, ':scheduled_date'=>$date, ':created_at'=>$now, ':updated_at'=>$now,
    ]);
    $id = (int)db()->lastInsertId();
    audit_log('class_created_from_schedule', 'class_session', $id, ['schedule_id'=>$scheduleId, 'scheduled_date'=>$date], (int)$user['id']);
    return ['ok'=>true, 'id'=>$id, 'status'=>$status, 'message'=>$status === 'open' ? 'สร้าง QR และเปิดรับลงชื่อแล้ว' : 'เตรียม QR สำหรับคลาสเรียนนี้แล้ว'];
}

/** Attendance policy is independent of room reservation time. */
function class_checkin_status(array $session, ?int $now = null): string
{
    if (($session['status'] ?? '') !== 'open') return (string)($session['status'] ?? '');
    if (($session['checkin_mode'] ?? 'scheduled') === 'manual') return 'open';
    $now ??= time();
    if ($now >= strtotime($session['ends_at'])) return 'overdue';
    if ($now < strtotime($session['starts_at']) - 60 * (int)($session['admission_lead_minutes'] ?? 0)) return 'scheduled';
    return 'open';
}

function open_class_session(int $id, string $mode = 'scheduled'): array
{
    $session = get_class_session($id);
    $user = current_user();
    if (!$session || !$user || !in_array($user['role'],['admin','lecturer'],true)) return ['ok'=>false, 'message'=>'ไม่พบคลาสหรือไม่มีสิทธิ์'];
    if (!in_array($mode,['scheduled','manual'],true)) return ['ok'=>false,'message'=>'กรุณาเลือกวิธีเปิดรับลงชื่อ'];
    if ($session['status'] === 'cancelled') return ['ok'=>false, 'message'=>'คลาสถูกยกเลิก ไม่สามารถเปิดรับลงชื่อได้'];
    if ($mode === 'scheduled' && time() >= strtotime($session['ends_at'])) return ['ok'=>false,'message'=>'เวลาเรียนผ่านไปแล้ว หากต้องการรับเพิ่ม ให้เลือก “เปิดรับตอนนี้จนกดปิดเอง” โดยเวลาเรียนเดิมจะไม่เปลี่ยน'];
    $statement = db()->prepare("UPDATE class_sessions SET status='open', checkin_mode=:mode, updated_at=:updated_at WHERE id=:id AND status IN ('draft','open','closed')");
    $statement->execute([':mode'=>$mode, ':updated_at'=>utc_now(), ':id'=>$id]);
    audit_log('class_opened', 'class_session', $id, ['checkin_mode'=>$mode,'previous_status'=>$session['status']], (int)$user['id']);
    return ['ok'=>true, 'message'=>$mode==='manual'?'เปิดรับทันทีจนกว่าผู้สอนจะกดปิดเอง เวลาเรียนและการจองห้องเดิมไม่เปลี่ยน':(time()<strtotime($session['starts_at'])-60*(int)$session['admission_lead_minutes']?'ตั้งให้รับลงชื่อตามเวลาที่กำหนดแล้ว ขณะนี้ยังรอเวลาเปิดรับ':'เปิดรับลงชื่อแล้ว และจะหยุดรับอัตโนมัติเมื่อสิ้นสุดเวลาเรียน')];
}

function class_session_select_sql(): string
{
    return "SELECT cs.id, cs.room_id, cs.lecturer_user_id, cs.course_code, cs.course_name,
                   cs.section, cs.starts_at, cs.ends_at, cs.status, cs.qr_token, cs.notes,
                   cs.schedule_id, cs.scheduled_date, cs.checkin_mode, cs.admission_lead_minutes, cs.series_key, cs.term_id,
                   cs.created_at, cs.updated_at, r.code AS room_code, r.name AS room_name,
                   r.building, r.floor, r.capacity, u.full_name AS lecturer_name,
                   COUNT(ar.id) AS attendance_count
            FROM class_sessions cs
            JOIN rooms r ON r.id = cs.room_id
            JOIN users u ON u.id = cs.lecturer_user_id
            LEFT JOIN attendance_records ar ON ar.class_session_id = cs.id";
}

function normalize_class_session(array $row): array
{
    foreach (['id', 'room_id', 'lecturer_user_id', 'capacity', 'attendance_count'] as $key) {
        $row[$key] = (int) ($row[$key] ?? 0);
    }
    $row['schedule_id'] = isset($row['schedule_id']) && $row['schedule_id'] !== null ? (int) $row['schedule_id'] : null;
    $row['display_status'] = class_checkin_status($row);

    return $row;
}

function list_class_sessions(array $filters = [], int $limit = 100): array
{
    $viewer = current_user();
    $where = [];
    $params = [];
    if ($viewer && $viewer['role'] === 'lecturer') {
        $where[] = 'cs.lecturer_user_id = :viewer_id';
        $params[':viewer_id'] = $viewer['id'];
    }

    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, ['draft', 'closed', 'cancelled'], true)) {
        $where[] = 'cs.status = :status';
        $params[':status'] = $status;
    } elseif (in_array($status, ['open', 'scheduled', 'overdue'], true)) {
        $where[] = "cs.status = 'open'";
        $params[':status_now'] = utc_now();
        if ($status === 'open') {
            $params[':early_now'] = gmdate('Y-m-d\TH:i:s\Z',time()+600);
            $where[] = "(cs.checkin_mode='manual' OR ((cs.starts_at <= :status_now OR (cs.admission_lead_minutes=10 AND cs.starts_at<=:early_now)) AND cs.ends_at > :status_now))";
        } elseif ($status === 'scheduled') {
            $params[':early_now'] = gmdate('Y-m-d\TH:i:s\Z',time()+600);
            $where[] = "cs.checkin_mode='scheduled' AND cs.starts_at > CASE WHEN cs.admission_lead_minutes=10 THEN :early_now ELSE :status_now END";
        } else {
            $where[] = "cs.checkin_mode='scheduled' AND cs.ends_at <= :status_now";
        }
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(cs.course_code LIKE :search OR cs.course_name LIKE :search OR r.code LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $sql = class_session_select_sql() . $whereSql
        . ' GROUP BY cs.id, r.id, u.id ORDER BY CASE cs.status WHEN \'open\' THEN 0 WHEN \'draft\' THEN 1 ELSE 2 END, cs.starts_at DESC LIMIT :limit';
    $statement = db()->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value);
    }
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return array_map('normalize_class_session', $statement->fetchAll());
}

function get_class_session(int $id, bool $enforceOwnership = true): ?array
{
    if ($id < 1) {
        return null;
    }
    $statement = db()->prepare(class_session_select_sql() . ' WHERE cs.id = :id GROUP BY cs.id, r.id, u.id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }
    $row = normalize_class_session($row);
    $viewer = current_user();
    if ($enforceOwnership && $viewer && $viewer['role'] === 'lecturer' && $row['lecturer_user_id'] !== (int) $viewer['id']) {
        return null;
    }

    return $row;
}

function get_public_class_by_token(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $statement = db()->prepare(class_session_select_sql() . ' WHERE cs.qr_token = :token GROUP BY cs.id, r.id, u.id LIMIT 1');
    $statement->execute([':token' => $token]);
    $row = $statement->fetch();

    return $row ? normalize_class_session($row) : null;
}

function create_class_session(array $input): array
{
    $connection=db();
    $ownsTransaction=!$connection->inTransaction();
    if ($ownsTransaction) $connection->beginTransaction();
    try {
        $result=create_validated_class_session($input);
        if ($ownsTransaction && $result['ok']) $connection->commit();
        return $result;
    } catch (Throwable) {
        return ['ok'=>false,'errors'=>['form'=>'บันทึกไม่สำเร็จ กรุณาตรวจเวลาแล้วลองอีกครั้ง']];
    } finally {
        if ($ownsTransaction && $connection->inTransaction()) $connection->rollBack();
    }
}

function create_validated_class_session(array $input): array
{
    $operator = current_user();
    if (!$operator || !in_array($operator['role'], ['admin', 'lecturer'], true)) {
        return ['ok' => false, 'message' => 'ไม่มีสิทธิ์สร้างคลาสเรียน', 'errors' => ['form' => 'ไม่มีสิทธิ์สร้างคลาสเรียน']];
    }

    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $lecturerId = one_off_lecturer_id($input);
    $courseCode = strtoupper(trim((string) ($input['course_code'] ?? '')));
    $courseName = trim((string) ($input['course_name'] ?? ''));
    $section = trim((string) ($input['section'] ?? ''));
    $startsAt = local_datetime_to_utc((string) ($input['starts_at'] ?? ''));
    $endsAt = local_datetime_to_utc((string) ($input['ends_at'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $checkinMode=(string)($input['checkin_mode'] ?? 'scheduled');
    $errors = [];

    if ($roomId === false || $roomId === null || $roomId < 1) $errors['room_id'] = 'กรุณาเลือกห้องปฏิบัติการ';
    if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $courseCode)) $errors['course_code'] = 'กรุณากรอกรหัสรายวิชาให้ถูกต้อง';
    if (text_length($courseName) < 2 || text_length($courseName) > 150) $errors['course_name'] = 'ชื่อรายวิชาต้องมีความยาว 2–150 ตัวอักษร';
    if ($section !== '' && text_length($section) > 30) $errors['section'] = 'กลุ่มเรียนต้องไม่เกิน 30 ตัวอักษร';
    if (!$startsAt) $errors['starts_at'] = 'กรุณาระบุเวลาเริ่มคลาส';
    if (!$endsAt) $errors['ends_at'] = 'กรุณาระบุเวลาสิ้นสุดคลาส';
    if ($startsAt && $endsAt && strtotime($endsAt) <= strtotime($startsAt)) $errors['ends_at'] = 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม';
    if ($startsAt && $endsAt && strtotime($endsAt) - strtotime($startsAt) > 12 * 3600) $errors['ends_at'] = 'ระยะเวลาคลาสต้องไม่เกิน 12 ชั่วโมง';
    if (text_length($notes) > 500) $errors['notes'] = 'หมายเหตุต้องไม่เกิน 500 ตัวอักษร';
    if (!in_array($checkinMode,['scheduled','manual'],true)) $errors['checkin_mode']='กรุณาเลือกวิธีรับลงชื่อ';
    if ($errors) return ['ok' => false, 'message' => 'กรุณาตรวจสอบข้อมูลคลาส', 'errors' => $errors];

    $connection = db();
    $lecturer = $connection->prepare("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('admin','lecturer')");
    $lecturer->execute([$lecturerId]);
    if (!$lecturer->fetchColumn()) return ['ok'=>false,'errors'=>['lecturer_user_id'=>'กรุณาเลือกผู้สอนที่ใช้งานได้']];
    $room = $connection->prepare("SELECT id, status FROM rooms WHERE id = :id LIMIT 1");
    $room->execute([':id' => $roomId]);
    $roomRow = $room->fetch();
    if (!$roomRow || $roomRow['status'] !== 'available') {
        return ['ok' => false, 'message' => 'ห้องนี้ยังไม่พร้อมเปิดคลาส', 'errors' => ['room_id' => 'ห้องนี้ยังไม่พร้อมเปิดคลาส']];
    }

    // Every creation entry point checks room + lecturer + recurring plans.
    $tz=new DateTimeZone((string)app_config('app.timezone','Asia/Bangkok'));
    $begin=(new DateTimeImmutable($startsAt))->setTimezone($tz);
    $finish=(new DateTimeImmutable($endsAt))->setTimezone($tz);
    for ($day=$begin->setTime(0,0); $day<$finish; $day=$day->modify('+1 day')) {
        $a=max(0,($begin->getTimestamp()-$day->getTimestamp())/60);
        $b=min(1440,($finish->getTimestamp()-$day->getTimestamp())/60);
        foreach (one_off_busy_times((int)$roomId,$lecturerId,$day->format('Y-m-d')) as $busy) {
            if ($a<$busy['end'] && $b>$busy['start']) return ['ok'=>false,'message'=>$busy['reason'].'ในช่วงที่เลือก','errors'=>['ends_at'=>$busy['reason'].'ในช่วงที่เลือก กรุณาเปลี่ยนเวลา ห้อง หรือผู้สอน']];
        }
    }

    $now = utc_now();
    $status = $checkinMode==='manual' || (time() >= strtotime((string)$startsAt) - 1800 && time() < strtotime((string)$endsAt)) ? 'open' : 'draft';
    $insert = $connection->prepare(
        "INSERT INTO class_sessions (room_id, lecturer_user_id, course_code, course_name, section, starts_at, ends_at, status, checkin_mode, qr_token, notes, created_at, updated_at)
         VALUES (:room_id, :lecturer_id, :course_code, :course_name, :section, :starts_at, :ends_at, :status, :checkin_mode, :qr_token, :notes, :created_at, :updated_at)"
    );
    $insert->execute([
        ':room_id' => $roomId,
        ':lecturer_id' => $lecturerId,
        ':course_code' => $courseCode,
        ':course_name' => $courseName,
        ':section' => $section === '' ? null : $section,
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':status' => $status,
        ':checkin_mode' => $checkinMode,
        ':qr_token' => bin2hex(random_bytes(16)),
        ':notes' => $notes === '' ? null : $notes,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $id = (int) $connection->lastInsertId();
    audit_log('class_created', 'class_session', $id, ['room_id' => (int) $roomId, 'course_code' => $courseCode], (int) $operator['id']);

    return ['ok' => true, 'id' => $id, 'status'=>$status, 'message' => $status === 'open' ? 'สร้างคลาสเรียนและเปิดรับลงชื่อแล้ว' : 'สร้างคลาสเรียนและเตรียม QR Code แบบร่างแล้ว'];
}

function close_class_session(int $id): array
{
    $session = get_class_session($id);
    $user = current_user();
    if (!$session || !$user || !in_array($user['role'],['admin','lecturer'],true)) return ['ok' => false, 'message' => 'ไม่พบคลาสหรือไม่มีสิทธิ์จัดการ'];
    if ($session['status'] === 'closed') return ['ok' => true, 'message' => 'คลาสนี้ปิดรับแล้ว'];
    if ($session['status'] === 'cancelled') return ['ok'=>false,'message'=>'คลาสนี้ถูกยกเลิกแล้ว'];

    $statement = db()->prepare("UPDATE class_sessions SET status = 'closed', updated_at = :updated_at WHERE id = :id AND status IN ('draft','open')");
    $statement->execute([':updated_at' => utc_now(), ':id' => $id]);
    audit_log('class_closed', 'class_session', $id, [], (int) $user['id']);

    return ['ok' => true, 'message' => 'ปิดรับการลงชื่อเข้าเรียนแล้ว'];
}

function class_attendance(int $classSessionId): array
{
    $statement = db()->prepare('SELECT id, student_code, student_name, check_in_at FROM attendance_records WHERE class_session_id = :class_id ORDER BY check_in_at DESC, id DESC');
    $statement->execute([':class_id' => $classSessionId]);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) $row['id'] = (int) $row['id'];

    return $rows;
}

function list_attendance_records(array $filters = []): array
{
    $viewer = current_user();
    $where = [];
    $params = [];
    if ($viewer && $viewer['role'] === 'lecturer') {
        $where[] = 'cs.lecturer_user_id = :viewer_id';
        $params[':viewer_id'] = $viewer['id'];
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(ar.student_code LIKE :search OR ar.student_name LIKE :search OR cs.course_code LIKE :search OR cs.course_name LIKE :search OR r.code LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    $roomId = filter_var($filters['room_id'] ?? null, FILTER_VALIDATE_INT);
    if ($roomId !== false && $roomId !== null && $roomId > 0) {
        $where[] = 'cs.room_id = :room_id';
        $params[':room_id'] = $roomId;
    }
    foreach (['date_from' => '>=', 'date_to' => '<'] as $key => $operator) {
        $date = (string) ($filters[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $local = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok')));
            if ($key === 'date_to') $local = $local->modify('+1 day');
            $where[] = 'ar.check_in_at ' . $operator . ' :' . $key;
            $params[':' . $key] = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT ar.id, ar.student_code, ar.student_name, ar.check_in_at,
                   cs.id AS class_id, cs.course_code, cs.course_name, cs.section,
                   r.code AS room_code, r.name AS room_name, u.full_name AS lecturer_name
            FROM attendance_records ar
            JOIN class_sessions cs ON cs.id = ar.class_session_id
            JOIN rooms r ON r.id = cs.room_id
            JOIN users u ON u.id = cs.lecturer_user_id
            {$whereSql} ORDER BY ar.check_in_at DESC, ar.id DESC LIMIT 200";
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $items = $statement->fetchAll();
    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['class_id'] = (int) $item['class_id'];
    }

    return ['ok' => true, 'items' => $items, 'total' => count($items)];
}

function attendance_report_filters(array $input): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok')));
    $from = (string) ($input['date_from'] ?? $today->modify('first day of this month')->format('Y-m-d'));
    $to = (string) ($input['date_to'] ?? $today->modify('last day of this month')->format('Y-m-d'));
    $errors = [];
    if (!valid_iso_date($from) || !valid_iso_date($to) || $from > $to) {
        $errors[] = 'กรุณาเลือกวันที่เริ่มและสิ้นสุดให้ถูกต้อง';
    } elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 365) {
        $errors[] = 'เลือกช่วงรายงานได้ไม่เกิน 366 วันต่อครั้ง';
    }
    $roomId = max(0, (int) ($input['room_id'] ?? 0));
    if ($roomId && !array_filter(list_rooms(), static fn(array $room): bool => $room['id'] === $roomId)) {
        $errors[] = 'ไม่พบห้องที่เลือก กรุณาเลือกห้องใหม่';
    }
    preg_match('/^.{0,100}/us', trim((string) ($input['q'] ?? '')), $search);
    return [
        'q' => $search[0] ?? '',
        'room_id' => $roomId,
        'date_from' => $from,
        'date_to' => $to,
        'errors' => $errors,
    ];
}

function attendance_report_query(array $filters): array
{
    return array_diff_key($filters, ['errors' => true]);
}

function attendance_report_table(array $items): array
{
    $timezone = new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok'));
    $headers = ['เวลาลงชื่อ', 'รหัสนักศึกษา', 'ชื่อ–นามสกุล', 'รหัสวิชา', 'ชื่อวิชา', 'กลุ่ม', 'ห้อง', 'ผู้สอน'];
    $rows = [];
    foreach ($items as $item) {
        $time = (new DateTimeImmutable($item['check_in_at']))->setTimezone($timezone)->format('d/m/Y H:i');
        $rows[] = [$time, $item['student_code'], $item['student_name'], $item['course_code'], $item['course_name'], $item['section'] ?? '', $item['room_code'], $item['lecturer_name']];
    }
    return ['headers' => $headers, 'rows' => $rows];
}

function register_student_attendance(string $token, array $input): array
{
    $session = get_public_class_by_token($token);
    if (!$session) return ['ok' => false, 'message' => 'ไม่พบคลาสเรียนจาก QR Code นี้', 'errors' => ['form' => 'ไม่พบคลาสเรียนจาก QR Code นี้']];

    $studentCode = strtoupper(trim((string) ($input['student_code'] ?? '')));
    $studentName = trim((string) ($input['student_name'] ?? ''));
    $requestId = trim((string) ($input['client_request_id'] ?? ''));
    $errors = [];
    if (!preg_match('/^[A-Z0-9._-]{4,30}$/', $studentCode)) $errors['student_code'] = 'กรุณากรอกรหัสนักศึกษาให้ถูกต้อง';
    if (text_length($studentName) < 2 || text_length($studentName) > 100) $errors['student_name'] = 'ชื่อ–นามสกุลต้องมีความยาว 2–100 ตัวอักษร';
    if ($errors) return ['ok' => false, 'message' => 'กรุณาตรวจสอบข้อมูล', 'errors' => $errors];

    $requestId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestId) ? $requestId : 'student-' . bin2hex(random_bytes(12));
    $connection = db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) $connection->beginTransaction();
    try {
        // Serialize capacity/status checks with insert and concurrent close actions.
        $lock=$connection->prepare('UPDATE class_sessions SET id=id WHERE id=?');
        $lock->execute([$session['id']]);
        $session=get_public_class_by_token($token);
        if (!$session) return ['ok'=>false,'message'=>'ไม่พบคลาสเรียน','errors'=>['form'=>'ไม่พบคลาสเรียน']];
        $existing=$connection->prepare('SELECT id FROM attendance_records WHERE class_session_id=? AND student_code=?');
        $existing->execute([$session['id'],$studentCode]);
        if (($existingId=$existing->fetchColumn())!==false) return ['ok'=>true,'id'=>(int)$existingId,'duplicate'=>true,'message'=>'รหัสนักศึกษานี้ลงชื่อในคลาสแล้ว'];
        $state=class_checkin_status($session);
        if ($state!=='open') {
            $message=match($state) {'scheduled','draft'=>'คลาสนี้ยังไม่เปิดให้ลงชื่อ','overdue'=>'สิ้นสุดเวลารับอัตโนมัติแล้ว กรุณาติดต่อผู้สอนเพื่อเปิดรับเพิ่ม',default=>'คลาสนี้ปิดรับการลงชื่อแล้ว'};
            return ['ok'=>false,'message'=>$message,'errors'=>['form'=>$message]];
        }
        if ($session['attendance_count'] >= $session['capacity']) return ['ok'=>false,'message'=>'จำนวนผู้ลงชื่อครบความจุห้องแล้ว','errors'=>['form'=>'จำนวนผู้ลงชื่อครบความจุห้องแล้ว']];
        $now=utc_now();
        $insert = $connection->prepare('INSERT INTO attendance_records (class_session_id, student_code, student_name, check_in_at, client_request_id, created_at) VALUES (:class_id, :student_code, :student_name, :check_in_at, :request_id, :created_at)');
        $insert->execute([
            ':class_id' => $session['id'],
            ':student_code' => $studentCode,
            ':student_name' => $studentName,
            ':check_in_at' => $now,
            ':request_id' => $requestId,
            ':created_at' => $now,
        ]);
        $id=(int)$connection->lastInsertId();
        audit_log('student_attendance_created', 'class_session', $session['id'], ['student_code' => $studentCode,'checkin_mode'=>$session['checkin_mode']], null);
        if ($ownsTransaction) $connection->commit();
        return ['ok' => true, 'id' => $id, 'message' => 'ลงชื่อเข้าเรียนเรียบร้อย'];
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'unique')) {
            return ['ok'=>false,'message'=>'รหัสการส่งแบบฟอร์มถูกใช้แล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง','errors'=>['form'=>'รหัสการส่งแบบฟอร์มถูกใช้แล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง']];
        }
        throw $exception;
    } finally {
        if ($ownsTransaction && $connection->inTransaction()) $connection->rollBack();
    }
}

function usage_rows(string $where = '', array $params = [], int $limit = 50, int $offset = 0): array
{
    $sql = "SELECT ur.id, ur.person_code, ur.person_name, ur.person_role, ur.purpose,
                   ur.course_code, ur.participant_count AS attendee_count,
                   ur.check_in_method AS checkin_method, ur.check_in_at AS checkin_at,
                   ur.check_out_at AS checkout_at, ur.status, ur.notes AS note,
                   r.id AS room_id, r.code AS room_code, r.name AS room_name,
                   r.building, u.full_name AS recorded_by
            FROM usage_records ur
            JOIN rooms r ON r.id = ur.room_id
            JOIN users u ON u.id = ur.user_id
            {$where}
            ORDER BY ur.check_in_at DESC, ur.id DESC
            LIMIT :limit OFFSET :offset";
    $statement = db()->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['room_id'] = (int) $row['room_id'];
        $row['attendee_count'] = (int) $row['attendee_count'];
    }

    return $rows;
}

function dashboard_data(): array
{
    [$todayStart, $todayEnd] = local_period_bounds('day');
    $connection = db();
    $viewer = current_user();
    $scopeSql = $viewer && $viewer['role'] === 'lecturer' ? ' AND lecturer_user_id = :viewer_id' : '';
    $params = [':start' => $todayStart, ':end' => $todayEnd];
    if ($scopeSql !== '') $params[':viewer_id'] = $viewer['id'];

    $today = $connection->prepare('SELECT COUNT(*) FROM class_sessions WHERE starts_at >= :start AND starts_at < :end' . $scopeSql);
    $today->execute($params);
    $now = utc_now();
    $active = $connection->prepare("SELECT COUNT(*) FROM class_sessions WHERE status = 'open' AND (checkin_mode='manual' OR (starts_at <= CASE WHEN admission_lead_minutes=10 THEN :early_now ELSE :now END AND ends_at > :now))" . ($scopeSql ? ' AND lecturer_user_id = :viewer_id' : ''));
    $activeParams = [':now'=>$now];
    if ($scopeSql) $activeParams[':viewer_id'] = $viewer['id'];
    $active->execute($activeParams + [':early_now'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)]);
    $activeTotal = (int) $active->fetchColumn();
    $roomsSql = "SELECT COUNT(DISTINCT room_id) FROM class_sessions WHERE status IN ('open','closed') AND starts_at <= :now AND ends_at > :now" . ($scopeSql ? ' AND lecturer_user_id = :viewer_id' : '');
    $roomsStatement = $connection->prepare($roomsSql);
    $roomsStatement->execute($activeParams);
    $roomsInUse = (int) $roomsStatement->fetchColumn();
    $roomTotal = (int) $connection->query("SELECT COUNT(*) FROM rooms WHERE status <> 'inactive'")->fetchColumn();
    $issues = $connection->prepare("SELECT COUNT(*) FROM class_sessions WHERE status = 'open' AND checkin_mode='scheduled' AND ends_at <= :now" . ($scopeSql ? ' AND lecturer_user_id = :viewer_id' : ''));
    $issueParams = [':now' => $now];
    if ($scopeSql) $issueParams[':viewer_id'] = $viewer['id'];
    $issues->execute($issueParams);

    return [
        'today_total' => (int) $today->fetchColumn(),
        'active_total' => $activeTotal,
        'rooms_in_use' => $roomsInUse,
        'room_total' => $roomTotal,
        'issues_total' => (int) $issues->fetchColumn(),
        'rooms' => array_slice(list_rooms(), 0, 5),
        'recent' => list_class_sessions([], 8),
    ];
}

function create_usage(array $input): array
{
    $operator = current_user();
    if ($operator === null) {
        return ['ok' => false, 'success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนบันทึกข้อมูล', 'errors' => ['form' => 'กรุณาเข้าสู่ระบบก่อนบันทึกข้อมูล']];
    }

    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $personCode = strtoupper(trim((string) ($input['person_code'] ?? $operator['student_code'] ?? $operator['email'])));
    $personName = trim((string) ($input['person_name'] ?? $operator['name']));
    $personRole = trim((string) ($input['person_role'] ?? $operator['role']));
    $purpose = trim((string) ($input['purpose'] ?? ''));
    $courseCode = strtoupper(trim((string) ($input['course_code'] ?? '')));
    $attendeeCount = filter_var($input['attendee_count'] ?? $input['participant_count'] ?? 1, FILTER_VALIDATE_INT);
    $method = trim((string) ($input['checkin_method'] ?? $input['check_in_method'] ?? 'manual'));
    $note = trim((string) ($input['note'] ?? $input['notes'] ?? ''));
    $requestId = trim((string) ($input['client_request_id'] ?? ''));
    $errors = [];

    if ($roomId === false || $roomId === null || $roomId < 1) {
        $errors['room_id'] = 'กรุณาเลือกห้องปฏิบัติการ';
    }
    if (!preg_match('/^[\p{L}\p{N}._-]{2,30}$/u', $personCode)) {
        $errors['person_code'] = 'กรุณากรอกรหัสผู้ใช้ให้ถูกต้อง';
    }
    if (text_length($personName) < 2 || text_length($personName) > 100) {
        $errors['person_name'] = 'ชื่อผู้เข้าใช้ต้องมีความยาว 2–100 ตัวอักษร';
    }
    if (!in_array($personRole, ['admin', 'staff', 'lecturer', 'student'], true)) {
        $errors['person_role'] = 'กรุณาเลือกประเภทผู้ใช้งาน';
    }
    if (text_length($purpose) < 2 || text_length($purpose) > 255) {
        $errors['purpose'] = 'กรุณาระบุวัตถุประสงค์การเข้าใช้';
    }
    if ($courseCode !== '' && !preg_match('/^[A-Z0-9._-]{2,30}$/', $courseCode)) {
        $errors['course_code'] = 'รหัสรายวิชามีรูปแบบไม่ถูกต้อง';
    }
    if ($attendeeCount === false || $attendeeCount < 1) {
        $errors['attendee_count'] = 'จำนวนผู้เข้าใช้ต้องไม่น้อยกว่า 1 คน';
    }
    if (!in_array($method, ['qr', 'manual'], true)) {
        $errors['checkin_method'] = 'วิธีเช็กอินไม่ถูกต้อง';
    }
    if (text_length($note) > 500) {
        $errors['note'] = 'รายละเอียดเพิ่มเติมต้องไม่เกิน 500 ตัวอักษร';
    }
    if ($requestId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestId)) {
        $errors['client_request_id'] = 'รหัสคำขอไม่ถูกต้อง';
    }
    if ($errors !== []) {
        return ['ok' => false, 'success' => false, 'message' => 'กรุณาตรวจสอบข้อมูล', 'errors' => $errors];
    }

    $connection = db();
    $roomStatement = $connection->prepare('SELECT id, code, name, capacity, status FROM rooms WHERE id = :id LIMIT 1');
    $roomStatement->execute([':id' => $roomId]);
    $room = $roomStatement->fetch();
    if (!$room) {
        return ['ok' => false, 'success' => false, 'message' => 'ไม่พบห้องปฏิบัติการที่เลือก', 'errors' => ['room_id' => 'ไม่พบห้องปฏิบัติการที่เลือก']];
    }
    if ($room['status'] !== 'available') {
        return ['ok' => false, 'success' => false, 'message' => 'ห้องนี้ยังไม่พร้อมใช้งาน', 'errors' => ['room_id' => 'ห้องนี้ยังไม่พร้อมใช้งาน']];
    }
    if ($attendeeCount > (int) $room['capacity']) {
        return ['ok' => false, 'success' => false, 'message' => 'จำนวนผู้เข้าใช้เกินความจุของห้อง', 'errors' => ['attendee_count' => 'ห้องนี้รองรับได้สูงสุด ' . $room['capacity'] . ' คน']];
    }

    $requestId = $requestId !== '' ? $requestId : 'web-' . bin2hex(random_bytes(12));
    $connection->beginTransaction();
    try {
        $duplicateRequest = $connection->prepare('SELECT id FROM usage_records WHERE client_request_id = :request_id LIMIT 1');
        $duplicateRequest->execute([':request_id' => $requestId]);
        $existingId = $duplicateRequest->fetchColumn();
        if ($existingId !== false) {
            $connection->rollBack();
            return ['ok' => true, 'success' => true, 'id' => (int) $existingId, 'duplicate' => true, 'message' => 'คำขอนี้ถูกบันทึกแล้ว'];
        }

        $active = $connection->prepare("SELECT room_id, person_code FROM usage_records WHERE status = 'active' AND (room_id = :room_id OR person_code = :person_code) LIMIT 1");
        $active->execute([':room_id' => $roomId, ':person_code' => $personCode]);
        $conflict = $active->fetch();
        if ($conflict) {
            $connection->rollBack();
            $message = (int) $conflict['room_id'] === (int) $roomId
                ? 'ห้องนี้มีรายการใช้งานที่ยังไม่ได้เช็กเอาต์'
                : 'ผู้ใช้นี้มีรายการที่ยังไม่ได้เช็กเอาต์';
            return ['ok' => false, 'success' => false, 'message' => $message, 'errors' => ['form' => $message]];
        }

        $now = utc_now();
        $insert = $connection->prepare(
            'INSERT INTO usage_records
                (room_id, user_id, person_code, person_name, person_role, purpose, course_code,
                 participant_count, check_in_method, check_in_at, status, notes, client_request_id, created_at, updated_at)
             VALUES
                (:room_id, :user_id, :person_code, :person_name, :person_role, :purpose, :course_code,
                 :participant_count, :method, :check_in_at, \'active\', :notes, :request_id, :created_at, :updated_at)'
        );
        $insert->execute([
            ':room_id' => $roomId,
            ':user_id' => $operator['id'],
            ':person_code' => $personCode,
            ':person_name' => $personName,
            ':person_role' => $personRole,
            ':purpose' => $purpose,
            ':course_code' => $courseCode === '' ? null : $courseCode,
            ':participant_count' => $attendeeCount,
            ':method' => $method,
            ':check_in_at' => $now,
            ':notes' => $note === '' ? null : $note,
            ':request_id' => $requestId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = (int) $connection->lastInsertId();
        $connection->commit();
        audit_log('usage_created', 'usage_record', $id, ['room_id' => (int) $roomId, 'method' => $method], (int) $operator['id']);

        return ['ok' => true, 'success' => true, 'id' => $id, 'message' => 'บันทึกการเข้าใช้เรียบร้อย'];
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ((string) $exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'unique')) {
            return ['ok' => false, 'success' => false, 'message' => 'พบรายการเช็กอินซ้ำ กรุณาตรวจสอบรายการที่กำลังใช้งาน', 'errors' => ['form' => 'พบรายการเช็กอินซ้ำ']];
        }
        throw $exception;
    }
}

function checkout_usage(int $id): array
{
    $user = current_user();
    if ($user === null) {
        return ['ok' => false, 'success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
    }
    if ($id < 1) {
        return ['ok' => false, 'success' => false, 'message' => 'ไม่พบรายการที่ต้องการเช็กเอาต์'];
    }

    $connection = db();
    $statement = $connection->prepare('SELECT id, user_id, person_code, status FROM usage_records WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return ['ok' => false, 'success' => false, 'message' => 'ไม่พบรายการที่ต้องการเช็กเอาต์'];
    }
    if ($record['status'] === 'completed') {
        return ['ok' => true, 'success' => true, 'id' => $id, 'message' => 'รายการนี้เช็กเอาต์แล้ว'];
    }
    $canManage = in_array($user['role'], ['admin', 'staff'], true)
        || (int) $record['user_id'] === (int) $user['id']
        || ($user['student_code'] && $record['person_code'] === $user['student_code']);
    if (!$canManage) {
        return ['ok' => false, 'success' => false, 'message' => 'คุณไม่มีสิทธิ์เช็กเอาต์รายการนี้'];
    }

    $now = utc_now();
    $update = $connection->prepare("UPDATE usage_records SET status = 'completed', check_out_at = :check_out_at, updated_at = :updated_at WHERE id = :id AND status = 'active'");
    $update->execute([':check_out_at' => $now, ':updated_at' => $now, ':id' => $id]);
    if ($update->rowCount() !== 1) {
        return ['ok' => false, 'success' => false, 'message' => 'รายการนี้ไม่อยู่ในสถานะกำลังใช้งาน'];
    }

    audit_log('usage_checked_out', 'usage_record', $id, [], (int) $user['id']);

    return ['ok' => true, 'success' => true, 'id' => $id, 'message' => 'บันทึกเวลาออกเรียบร้อย'];
}

function list_usage(array $filters = []): array
{
    $where = [];
    $params = [];
    $user = current_user();

    if ($user !== null && $user['role'] === 'student') {
        $where[] = '(ur.user_id = :viewer_id OR ur.person_code = :viewer_code)';
        $params[':viewer_id'] = $user['id'];
        $params[':viewer_code'] = (string) ($user['student_code'] ?? '');
    }

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(ur.person_name LIKE :search OR ur.person_code LIKE :search OR ur.purpose LIKE :search OR r.code LIKE :search OR r.name LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    $roomId = filter_var($filters['room_id'] ?? null, FILTER_VALIDATE_INT);
    if ($roomId !== false && $roomId !== null && $roomId > 0) {
        $where[] = 'ur.room_id = :room_id';
        $params[':room_id'] = $roomId;
    }
    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, ['active', 'completed', 'cancelled'], true)) {
        $where[] = 'ur.status = :status';
        $params[':status'] = $status;
    }
    $method = (string) ($filters['checkin_method'] ?? $filters['method'] ?? '');
    if (in_array($method, ['qr', 'manual'], true)) {
        $where[] = 'ur.check_in_method = :method';
        $params[':method'] = $method;
    }
    foreach (['date_from' => '>=', 'date_to' => '<'] as $key => $operator) {
        $date = (string) ($filters[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $local = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok')));
            if ($key === 'date_to') {
                $local = $local->modify('+1 day');
            }
            $where[] = 'ur.check_in_at ' . $operator . ' :' . $key;
            $params[':' . $key] = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
    }

    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM usage_records ur JOIN rooms r ON r.id = ur.room_id {$whereSql}");
    foreach ($params as $name => $value) {
        $count->bindValue($name, $value);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $page = max(1, (int) ($filters['page_number'] ?? 1));
    $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));

    return [
        'ok' => true,
        'success' => true,
        'items' => usage_rows($whereSql, $params, $perPage, ($page - 1) * $perPage),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function report_data(string $period = 'month', int $roomId = 0): array
{
    if (!in_array($period, ['day', 'week', 'month'], true)) {
        $period = 'month';
    }
    [$start, $end, $localStart, $localEnd] = local_period_bounds($period);
    $viewer = current_user();
    $where = ['cs.starts_at >= :start', 'cs.starts_at < :end'];
    $params = [':start'=>$start, ':end'=>$end];
    if ($viewer && $viewer['role'] === 'lecturer') {
        $where[] = 'cs.lecturer_user_id = :viewer_id';
        $params[':viewer_id'] = (int)$viewer['id'];
    }
    if ($roomId > 0) {
        $where[] = 'cs.room_id = :room_id';
        $params[':room_id'] = $roomId;
    }
    $whereSql = implode(' AND ', $where);
    $now = utc_now();
    $summaryParams = $params + [':now'=>$now];
    $statement = db()->prepare(
        "SELECT COUNT(DISTINCT cs.id) AS total,
                COUNT(ar.id) AS attendees,
                COUNT(DISTINCT CASE WHEN cs.status = 'open' AND (cs.checkin_mode='manual' OR (cs.starts_at <= :now AND cs.ends_at > :now)) THEN cs.id END) AS active,
                COUNT(DISTINCT CASE WHEN cs.status = 'closed' THEN cs.id END) AS completed,
                COUNT(DISTINCT CASE WHEN cs.status = 'open' AND cs.checkin_mode='scheduled' AND cs.ends_at <= :now THEN cs.id END) AS overdue
         FROM class_sessions cs LEFT JOIN attendance_records ar ON ar.class_session_id = cs.id
         WHERE {$whereSql}"
    );
    $statement->execute($summaryParams);
    $summary = $statement->fetch();
    $top = db()->prepare(
        'SELECT r.code, COUNT(ar.id) AS usage_count FROM class_sessions cs JOIN rooms r ON r.id = cs.room_id
         LEFT JOIN attendance_records ar ON ar.class_session_id = cs.id
         WHERE ' . $whereSql . ' GROUP BY r.id, r.code ORDER BY usage_count DESC, r.code LIMIT 1'
    );
    $top->execute($params);
    $topRoom = $top->fetchColumn();
    $thaiMonths = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $label = match ($period) {
        'day' => $localStart->format('d/m/') . ((int) $localStart->format('Y') + 543),
        'week' => 'สัปดาห์นี้',
        default => $thaiMonths[(int) $localStart->format('n')] . ' ' . ((int) $localStart->format('Y') + 543),
    };
    $dateRange = $localStart->format('d/m/') . ((int)$localStart->format('Y') + 543)
        . ' – ' . $localEnd->modify('-1 second')->format('d/m/') . ((int)$localEnd->modify('-1 second')->format('Y') + 543);

    return [
        'period' => $period,
        'label' => $label,
        'total' => (int) ($summary['total'] ?? 0),
        'attendees' => (int) ($summary['attendees'] ?? 0),
        'active' => (int) ($summary['active'] ?? 0),
        'completed' => (int) ($summary['completed'] ?? 0),
        'overdue' => (int) ($summary['overdue'] ?? 0),
        'top_room' => $topRoom !== false ? (string) $topRoom : '',
        'date_range' => $dateRange,
        'room_id' => $roomId,
    ];
}

function report_class_rows(string $period = 'month', int $roomId = 0): array
{
    if (!in_array($period, ['day', 'week', 'month'], true)) $period = 'month';
    [$start, $end] = local_period_bounds($period);
    $viewer = current_user();
    $where = ['cs.starts_at >= :start', 'cs.starts_at < :end'];
    $params = [':start'=>$start, ':end'=>$end];
    if ($viewer && $viewer['role'] === 'lecturer') {
        $where[] = 'cs.lecturer_user_id = :viewer_id';
        $params[':viewer_id'] = (int)$viewer['id'];
    }
    if ($roomId > 0) {
        $where[] = 'cs.room_id = :room_id';
        $params[':room_id'] = $roomId;
    }

    $statement = db()->prepare("SELECT cs.id, cs.course_code, cs.course_name, cs.section, cs.starts_at, cs.ends_at, cs.status,
        r.code AS room_code, r.name AS room_name, u.full_name AS lecturer_name, COUNT(ar.id) AS attendance_count
        FROM class_sessions cs JOIN rooms r ON r.id=cs.room_id JOIN users u ON u.id=cs.lecturer_user_id
        LEFT JOIN attendance_records ar ON ar.class_session_id=cs.id
        WHERE " . implode(' AND ', $where) . " GROUP BY cs.id, r.id, u.id ORDER BY cs.starts_at, r.code");
    $statement->execute($params);
    return $statement->fetchAll();
}
