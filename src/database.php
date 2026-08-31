<?php

declare(strict_types=1);

/** @var array<string, mixed> $lumsConfig */
$lumsConfig = $lumsConfig ?? [];

function app_config(?string $key = null, mixed $default = null): mixed
{
    global $lumsConfig;

    if ($key === null || $key === '') {
        return $lumsConfig;
    }

    $value = $lumsConfig;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = (string) app_config('database.dsn');
    $username = app_config('database.username');
    $password = app_config('database.password');

    if (str_starts_with($dsn, 'sqlite:') && $dsn !== 'sqlite::memory:') {
        $path = substr($dsn, 7);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ฐานข้อมูลได้');
        }
    }

    try {
        $connection = new PDO(
            $dsn,
            is_string($username) && $username !== '' ? $username : null,
            is_string($password) ? $password : null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        throw new RuntimeException('ไม่สามารถเชื่อมต่อฐานข้อมูล LUMS ได้', 0, $exception);
    }

    $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');
        if ($dsn !== 'sqlite::memory:') {
            $connection->exec('PRAGMA journal_mode = WAL');
        }
    } elseif ($driver === 'mysql') {
        $connection->exec("SET time_zone = '+00:00'");
    }

    return $connection;
}

function utc_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function initialize_database(?PDO $connection = null, bool $withDemoData = true): array
{
    $connection ??= db();
    $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver !== 'sqlite') {
        throw new RuntimeException(
            'สคริปต์เริ่มต้นนี้รองรับ SQLite สำหรับเครื่องพัฒนาเท่านั้น โปรดใช้ migration สำหรับฐานข้อมูล production'
        );
    }

    $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'staff', 'lecturer', 'student')),
    student_code TEXT NULL,
    department TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL COLLATE NOCASE UNIQUE,
    name TEXT NOT NULL,
    building TEXT NOT NULL,
    floor TEXT NULL,
    capacity INTEGER NOT NULL CHECK (capacity > 0),
    status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'occupied', 'maintenance', 'inactive')),
    qr_token TEXT NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS academic_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    academic_year INTEGER NOT NULL,
    semester TEXT NOT NULL CHECK (semester IN ('1', '2', 'summer')),
    starts_on TEXT NOT NULL,
    ends_on TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'active', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (academic_year, semester)
);

CREATE TABLE IF NOT EXISTS course_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    term_id INTEGER NOT NULL,
    room_id INTEGER NOT NULL,
    lecturer_user_id INTEGER NOT NULL,
    course_code TEXT NOT NULL,
    course_name TEXT NOT NULL,
    section TEXT NULL,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    starts_time TEXT NOT NULL,
    ends_time TEXT NOT NULL,
    active_from TEXT NOT NULL,
    active_until TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'cancelled')),
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (lecturer_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS class_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    lecturer_user_id INTEGER NOT NULL,
    course_code TEXT NOT NULL,
    course_name TEXT NOT NULL,
    section TEXT NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('draft', 'open', 'closed', 'cancelled')),
    checkin_mode TEXT NOT NULL DEFAULT 'scheduled' CHECK (checkin_mode IN ('scheduled', 'manual')),
    qr_token TEXT NOT NULL UNIQUE,
    notes TEXT NULL,
    schedule_id INTEGER NULL,
    scheduled_date TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (lecturer_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (schedule_id) REFERENCES course_schedules(id) ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attendance_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_session_id INTEGER NOT NULL,
    student_code TEXT NOT NULL,
    student_name TEXT NOT NULL,
    check_in_at TEXT NOT NULL,
    client_request_id TEXT NULL UNIQUE,
    created_at TEXT NOT NULL,
    FOREIGN KEY (class_session_id) REFERENCES class_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (class_session_id, student_code)
);

CREATE TABLE IF NOT EXISTS usage_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    person_code TEXT NOT NULL,
    person_name TEXT NOT NULL,
    person_role TEXT NOT NULL CHECK (person_role IN ('admin', 'staff', 'lecturer', 'student')),
    purpose TEXT NOT NULL,
    course_code TEXT NULL,
    participant_count INTEGER NOT NULL DEFAULT 1 CHECK (participant_count > 0),
    check_in_method TEXT NOT NULL CHECK (check_in_method IN ('qr', 'manual')),
    check_in_at TEXT NOT NULL,
    check_out_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'completed', 'cancelled')),
    notes TEXT NULL,
    client_request_id TEXT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS room_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE RESTRICT,
    person_code TEXT NOT NULL,
    person_name TEXT NOT NULL,
    person_role TEXT NOT NULL CHECK (person_role IN ('student','lecturer','staff')),
    purpose TEXT NOT NULL,
    check_in_at TEXT NOT NULL,
    check_out_at TEXT NULL,
    checkout_method TEXT NULL CHECK (checkout_method IN ('self','admin')),
    checkout_note TEXT NULL,
    closed_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT,
    receipt_hash TEXT NOT NULL UNIQUE,
    client_request_id TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_room_visit_active_person ON room_visits(person_code) WHERE check_out_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_room_visit_room_time ON room_visits(room_id,check_in_at);

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL,
    locked_until TEXT NULL,
    last_attempt_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NULL,
    details_json TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_usage_check_in ON usage_records(check_in_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_room_date ON usage_records(room_id, check_in_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_user_date ON usage_records(user_id, check_in_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_status ON usage_records(status);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity_type, entity_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uq_usage_active_room ON usage_records(room_id) WHERE status = 'active';
DROP INDEX IF EXISTS uq_usage_active_user;
CREATE UNIQUE INDEX IF NOT EXISTS uq_usage_active_person ON usage_records(person_code) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_class_sessions_date ON class_sessions(starts_at DESC);
CREATE INDEX IF NOT EXISTS idx_class_sessions_room_status ON class_sessions(room_id, status);
CREATE INDEX IF NOT EXISTS idx_class_sessions_lecturer ON class_sessions(lecturer_user_id, starts_at DESC);
CREATE INDEX IF NOT EXISTS idx_attendance_session_time ON attendance_records(class_session_id, check_in_at DESC);
CREATE INDEX IF NOT EXISTS idx_attendance_student_time ON attendance_records(student_code, check_in_at DESC);
CREATE INDEX IF NOT EXISTS idx_terms_status_dates ON academic_terms(status, starts_on, ends_on);
CREATE INDEX IF NOT EXISTS idx_schedules_term_day ON course_schedules(term_id, day_of_week, starts_time);
CREATE INDEX IF NOT EXISTS idx_schedules_room_time ON course_schedules(room_id, day_of_week, starts_time, ends_time);
CREATE INDEX IF NOT EXISTS idx_schedules_lecturer_time ON course_schedules(lecturer_user_id, day_of_week, starts_time, ends_time);
SQL;

    $connection->exec($schema);
    ensure_sqlite_usage_columns($connection);
    ensure_sqlite_academic_columns($connection);
    $seeded = $withDemoData ? seed_demo_data($connection) : false;
    if ($withDemoData) {
        seed_academic_schedule_data($connection);
    }

    return [
        'success' => true,
        'driver' => $driver,
        'seeded' => $seeded,
    ];
}

function seed_demo_data(PDO $connection): bool
{
    $existingUsers = (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($existingUsers > 0) {
        return false;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowString = $now->format('Y-m-d\TH:i:s\Z');

    $users = [
        ['ผู้ดูแลระบบ LUMS', 'admin@lums.local', 'admin', null, 'คณะวิศวกรรมศาสตร์', 'admin123'],
        ['กมลชนก ใจดี', 'staff@lums.local', 'staff', null, 'งานห้องปฏิบัติการ', 'LumsStaff!2569'],
        ['อาจารย์ณัฐวุฒิ ศึกษาดี', 'lecturer@lums.local', 'lecturer', null, 'ภาควิชาวิศวกรรมคอมพิวเตอร์', 'LumsLecturer!2569'],
        ['นักศึกษาทดสอบ หนึ่ง', 'student@lums.local', 'student', '66010001', 'วิศวกรรมคอมพิวเตอร์', 'LumsStudent!2569'],
        ['นักศึกษาทดสอบ สอง', 'student2@lums.local', 'student', '66010002', 'วิศวกรรมไฟฟ้า', 'LumsStudent!2569'],
    ];

    $rooms = [
        ['CPE-101', 'ห้องปฏิบัติการคอมพิวเตอร์ 1', 'อาคารวิศวกรรมศาสตร์', '1', 40, 'available', 'lums-cpe-101-demo', 'ห้องสำหรับการเรียนการสอนและฝึกปฏิบัติทั่วไป'],
        ['CPE-204', 'ห้องปฏิบัติการเครือข่าย', 'อาคารวิศวกรรมศาสตร์', '2', 30, 'available', 'lums-cpe-204-demo', 'อุปกรณ์เครือข่ายและระบบแม่ข่าย'],
        ['EE-301', 'ห้องปฏิบัติการวงจรไฟฟ้า', 'อาคารปฏิบัติการ', '3', 24, 'available', 'lums-ee-301-demo', 'ชุดทดลองวงจรไฟฟ้าและเครื่องมือวัด'],
        ['IOT-401', 'ห้องปฏิบัติการ IoT', 'อาคารนวัตกรรม', '4', 32, 'available', 'lums-iot-401-demo', 'ชุดพัฒนาไมโครคอนโทรลเลอร์และเซนเซอร์'],
        ['CPE-305', 'ห้องปฏิบัติการระบบฝังตัว', 'อาคารวิศวกรรมศาสตร์', '3', 24, 'maintenance', 'lums-cpe-305-demo', 'ปิดบำรุงรักษาอุปกรณ์ชั่วคราว'],
    ];

    $connection->beginTransaction();
    try {
        $insertUser = $connection->prepare(
            'INSERT INTO users (full_name, email, role, student_code, department, password_hash, is_active, created_at, updated_at)
             VALUES (:full_name, :email, :role, :student_code, :department, :password_hash, 1, :created_at, :updated_at)'
        );
        foreach ($users as [$fullName, $email, $role, $studentCode, $department, $password]) {
            $insertUser->execute([
                ':full_name' => $fullName,
                ':email' => $email,
                ':role' => $role,
                ':student_code' => $studentCode,
                ':department' => $department,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':created_at' => $nowString,
                ':updated_at' => $nowString,
            ]);
        }

        $insertRoom = $connection->prepare(
            'INSERT INTO rooms (code, name, building, floor, capacity, status, qr_token, description, created_at, updated_at)
             VALUES (:code, :name, :building, :floor, :capacity, :status, :qr_token, :description, :created_at, :updated_at)'
        );
        foreach ($rooms as [$code, $name, $building, $floor, $capacity, $status, $qrToken, $description]) {
            $insertRoom->execute([
                ':code' => $code,
                ':name' => $name,
                ':building' => $building,
                ':floor' => $floor,
                ':capacity' => $capacity,
                ':status' => $status,
                ':qr_token' => $qrToken,
                ':description' => $description,
                ':created_at' => $nowString,
                ':updated_at' => $nowString,
            ]);
        }

        $userIds = $connection->query('SELECT email, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
        $roomIds = $connection->query('SELECT code, id FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
        $records = [
            ['CPE-101', 'student@lums.local', 'ฝึกปฏิบัติการเขียนโปรแกรม', 'CPE101', 1, 'qr', -1, 150],
            ['CPE-204', 'student2@lums.local', 'ทดลองตั้งค่าเครือข่ายภายใน', 'CPE204', 2, 'qr', -2, 120],
            ['EE-301', 'lecturer@lums.local', 'เตรียมการสอนวิชาวงจรไฟฟ้า', 'EE201', 18, 'manual', -3, 180],
            ['IOT-401', 'student@lums.local', 'ทดสอบเซนเซอร์วัดอุณหภูมิ', 'CPE315', 3, 'qr', -4, 95],
            ['CPE-101', 'lecturer@lums.local', 'สอนปฏิบัติการฐานข้อมูล', 'CPE221', 32, 'manual', -5, 180],
            ['CPE-204', 'student2@lums.local', 'ฝึกตั้งค่า VLAN', 'CPE304', 4, 'qr', -6, 110],
            ['IOT-401', 'student@lums.local', 'พัฒนาโครงงานระบบตรวจวัด', 'CPE490', 4, 'qr', -7, 210],
            ['EE-301', 'lecturer@lums.local', 'ตรวจสอบอุปกรณ์ก่อนการสอน', 'EE202', 2, 'manual', -9, 75],
            ['CPE-101', 'student2@lums.local', 'ทำแบบฝึกหัดระบบปฏิบัติการ', 'CPE231', 2, 'qr', -11, 135],
            ['CPE-204', 'student@lums.local', 'ทดสอบบริการเว็บภายใน', 'CPE322', 3, 'manual', -13, 160],
        ];

        $insertUsage = $connection->prepare(
            'INSERT INTO usage_records
                (room_id, user_id, person_code, person_name, person_role, purpose, course_code, participant_count, check_in_method, check_in_at, check_out_at, status, notes, client_request_id, created_at, updated_at)
             VALUES
                (:room_id, :user_id, :person_code, :person_name, :person_role, :purpose, :course_code, :participant_count, :method, :check_in_at, :check_out_at, :status, :notes, :request_id, :created_at, :updated_at)'
        );
        foreach ($records as $index => [$roomCode, $email, $purpose, $courseCode, $participants, $method, $dayOffset, $duration]) {
            $checkIn = $now->modify(sprintf('%d days', $dayOffset))->setTime(2 + ($index % 6), 15);
            $checkOut = $checkIn->modify(sprintf('+%d minutes', $duration));
            $insertUsage->execute([
                ':room_id' => $roomIds[$roomCode],
                ':user_id' => $userIds[$email],
                ':person_code' => str_starts_with($email, 'student') ? ($email === 'student@lums.local' ? '66010001' : '66010002') : strtoupper(strtok($email, '@')),
                ':person_name' => match ($email) {
                    'student@lums.local' => 'นักศึกษาทดสอบ หนึ่ง',
                    'student2@lums.local' => 'นักศึกษาทดสอบ สอง',
                    default => 'อาจารย์ณัฐวุฒิ ศึกษาดี',
                },
                ':person_role' => str_starts_with($email, 'student') ? 'student' : 'lecturer',
                ':purpose' => $purpose,
                ':course_code' => $courseCode,
                ':participant_count' => $participants,
                ':method' => $method,
                ':check_in_at' => $checkIn->format('Y-m-d\TH:i:s\Z'),
                ':check_out_at' => $checkOut->format('Y-m-d\TH:i:s\Z'),
                ':status' => 'completed',
                ':notes' => null,
                ':request_id' => sprintf('seed-%03d', $index + 1),
                ':created_at' => $checkIn->format('Y-m-d\TH:i:s\Z'),
                ':updated_at' => $checkOut->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        // One active session makes the local dashboard useful immediately.
        $activeCheckIn = $now->modify('-45 minutes');
        $insertUsage->execute([
            ':room_id' => $roomIds['IOT-401'],
            ':user_id' => $userIds['student2@lums.local'],
            ':person_code' => '66010002',
            ':person_name' => 'นักศึกษาทดสอบ สอง',
            ':person_role' => 'student',
            ':purpose' => 'พัฒนาโครงงานระบบควบคุมอุปกรณ์ IoT',
            ':course_code' => 'CPE490',
            ':participant_count' => 3,
            ':method' => 'qr',
            ':check_in_at' => $activeCheckIn->format('Y-m-d\TH:i:s\Z'),
            ':check_out_at' => null,
            ':status' => 'active',
            ':notes' => 'ข้อมูลตัวอย่างสำหรับหน้าภาพรวม',
            ':request_id' => 'seed-active-001',
            ':created_at' => $activeCheckIn->format('Y-m-d\TH:i:s\Z'),
            ':updated_at' => $activeCheckIn->format('Y-m-d\TH:i:s\Z'),
        ]);

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    return true;
}

function seed_production_data(PDO $connection): bool
{
    if ((int)$connection->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return false;

    $adminName = trim((string)app_config('bootstrap.admin_name', 'ผู้ดูแลระบบ LUMS'));
    $adminEmail = strtolower(trim((string)app_config('bootstrap.admin_email', '')));
    $adminPassword = (string)app_config('bootstrap.admin_password', '');
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Production requires a valid LUMS_ADMIN_EMAIL environment variable');
    }
    if (strlen($adminPassword) < 12) {
        throw new RuntimeException('Production requires LUMS_ADMIN_PASSWORD with at least 12 characters');
    }

    $now = utc_now();
    $rooms = [
        ['CPE-101', 'ห้องปฏิบัติการคอมพิวเตอร์ 1', 'อาคารวิศวกรรมศาสตร์', '1', 40, 'available', 'ห้องสำหรับการเรียนการสอนและฝึกปฏิบัติทั่วไป'],
        ['CPE-204', 'ห้องปฏิบัติการเครือข่าย', 'อาคารวิศวกรรมศาสตร์', '2', 30, 'available', 'อุปกรณ์เครือข่ายและระบบแม่ข่าย'],
        ['EE-301', 'ห้องปฏิบัติการวงจรไฟฟ้า', 'อาคารปฏิบัติการ', '3', 24, 'available', 'ชุดทดลองวงจรไฟฟ้าและเครื่องมือวัด'],
        ['IOT-401', 'ห้องปฏิบัติการ IoT', 'อาคารนวัตกรรม', '4', 32, 'available', 'ชุดพัฒนาไมโครคอนโทรลเลอร์และเซนเซอร์'],
    ];

    $connection->beginTransaction();
    try {
        $admin = $connection->prepare('INSERT INTO users (email, password_hash, full_name, role, student_code, department, is_active, created_at, updated_at) VALUES (:email,:password_hash,:full_name,\'admin\',NULL,NULL,1,:created_at,:updated_at)');
        $admin->execute([
            ':email'=>$adminEmail,
            ':password_hash'=>password_hash($adminPassword, PASSWORD_DEFAULT),
            ':full_name'=>$adminName !== '' ? $adminName : 'ผู้ดูแลระบบ LUMS',
            ':created_at'=>$now,
            ':updated_at'=>$now,
        ]);

        if ((int)$connection->query('SELECT COUNT(*) FROM rooms')->fetchColumn() === 0) {
            $room = $connection->prepare("INSERT INTO rooms (code,name,building,floor,capacity,status,qr_token,description,created_at,updated_at) VALUES (:code,:name,:building,:floor,:capacity,:status,:qr_token,:description,:created_at,:updated_at)");
            foreach ($rooms as [$code,$name,$building,$floor,$capacity,$status,$description]) {
                $room->execute([
                    ':code'=>$code, ':name'=>$name, ':building'=>$building, ':floor'=>$floor, ':capacity'=>$capacity,
                    ':status'=>$status, ':qr_token'=>bin2hex(random_bytes(16)), ':description'=>$description,
                    ':created_at'=>$now, ':updated_at'=>$now,
                ]);
            }
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }

    return true;
}

function ensure_sqlite_usage_columns(PDO $connection): void
{
    $columns = $connection->query('PRAGMA table_info(usage_records)')->fetchAll();
    $names = array_column($columns, 'name');
    $additions = [
        'person_code' => "ALTER TABLE usage_records ADD COLUMN person_code TEXT NOT NULL DEFAULT 'UNKNOWN'",
        'person_name' => "ALTER TABLE usage_records ADD COLUMN person_name TEXT NOT NULL DEFAULT 'ไม่ระบุชื่อ'",
        'person_role' => "ALTER TABLE usage_records ADD COLUMN person_role TEXT NOT NULL DEFAULT 'student'",
    ];

    foreach ($additions as $name => $sql) {
        if (!in_array($name, $names, true)) {
            $connection->exec($sql);
        }
    }
}

function ensure_sqlite_academic_columns(PDO $connection): void
{
    $columns = $connection->query('PRAGMA table_info(class_sessions)')->fetchAll();
    $names = array_column($columns, 'name');
    $additions = [
        'schedule_id' => 'ALTER TABLE class_sessions ADD COLUMN schedule_id INTEGER NULL',
        'scheduled_date' => 'ALTER TABLE class_sessions ADD COLUMN scheduled_date TEXT NULL',
        'checkin_mode' => "ALTER TABLE class_sessions ADD COLUMN checkin_mode TEXT NOT NULL DEFAULT 'scheduled' CHECK (checkin_mode IN ('scheduled', 'manual'))",
        'admission_lead_minutes' => 'ALTER TABLE class_sessions ADD COLUMN admission_lead_minutes INTEGER NOT NULL DEFAULT 0',
        'series_key' => 'ALTER TABLE class_sessions ADD COLUMN series_key TEXT NULL',
        'term_id' => 'ALTER TABLE class_sessions ADD COLUMN term_id INTEGER NULL REFERENCES academic_terms(id)',
    ];

    foreach ($additions as $name => $sql) {
        if (!in_array($name, $names, true)) {
            $connection->exec($sql);
        }
    }
    $connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_class_schedule_date ON class_sessions(schedule_id, scheduled_date) WHERE schedule_id IS NOT NULL');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_class_series ON class_sessions(series_key, starts_at)');
}

function seed_academic_schedule_data(PDO $connection): bool
{
    if ((int) $connection->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn() > 0) {
        return false;
    }

    $now = utc_now();
    $year = (int) date('Y') + 543;
    $start = new DateTimeImmutable('first day of this month', new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok')));
    $start = $start->modify('monday this week');
    $end = $start->modify('+16 weeks')->modify('-1 day');
    $termName = 'ภาคการศึกษาที่ 1/' . $year;

    $connection->beginTransaction();
    try {
        $term = $connection->prepare("INSERT INTO academic_terms (name, academic_year, semester, starts_on, ends_on, status, created_at, updated_at) VALUES (:name, :year, '1', :starts_on, :ends_on, 'active', :created_at, :updated_at)");
        $term->execute([
            ':name' => $termName,
            ':year' => $year,
            ':starts_on' => $start->format('Y-m-d'),
            ':ends_on' => $end->format('Y-m-d'),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $termId = (int) $connection->lastInsertId();
        $lecturerId = (int) $connection->query("SELECT id FROM users WHERE role = 'lecturer' ORDER BY id LIMIT 1")->fetchColumn();
        if ($lecturerId < 1) {
            $lecturerId = (int) $connection->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        }
        $roomIds = $connection->query('SELECT code, id FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
        $samples = [
            ['CPE-101', 'CPE221', 'ปฏิบัติการฐานข้อมูล', '1', 1, '09:00', '12:00'],
            ['CPE-204', 'CPE304', 'เครือข่ายคอมพิวเตอร์', '1', 2, '13:00', '16:00'],
            ['IOT-401', 'CPE315', 'ระบบสมองกลฝังตัวและ IoT', '1', 3, '09:00', '12:00'],
            ['EE-301', 'EE202', 'ปฏิบัติการวงจรไฟฟ้า', '2', 4, '13:00', '16:00'],
        ];
        $insert = $connection->prepare("INSERT INTO course_schedules (term_id, room_id, lecturer_user_id, course_code, course_name, section, day_of_week, starts_time, ends_time, active_from, active_until, status, notes, created_at, updated_at) VALUES (:term_id, :room_id, :lecturer_id, :course_code, :course_name, :section, :day_of_week, :starts_time, :ends_time, :active_from, :active_until, 'active', NULL, :created_at, :updated_at)");
        foreach ($samples as [$roomCode, $courseCode, $courseName, $section, $day, $startsTime, $endsTime]) {
            if (!isset($roomIds[$roomCode])) continue;
            $insert->execute([
                ':term_id' => $termId,
                ':room_id' => $roomIds[$roomCode],
                ':lecturer_id' => $lecturerId,
                ':course_code' => $courseCode,
                ':course_name' => $courseName,
                ':section' => $section,
                ':day_of_week' => $day,
                ':starts_time' => $startsTime,
                ':ends_time' => $endsTime,
                ':active_from' => $start->format('Y-m-d'),
                ':active_until' => $end->format('Y-m-d'),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }

    return true;
}

function audit_log(
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $details = [],
    ?int $userId = null
): void {
    if ($userId === null && function_exists('current_user')) {
        $userId = current_user()['id'] ?? null;
    }

    $statement = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details_json, ip_address, created_at)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details_json, :ip_address, :created_at)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip_address' => client_ip(),
        ':created_at' => utc_now(),
    ]);
}

function client_ip(): ?string
{
    $value = $_SERVER['REMOTE_ADDR'] ?? null;

    return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
}
