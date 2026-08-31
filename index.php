<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $page, array $params = []): never
{
    $query = http_build_query(['page' => $page] + $params);
    header('Location: ?' . $query);
    exit;
}

function thai_datetime(?string $value, bool $withDate = true): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date($withDate ? 'd/m/Y H:i' : 'H:i', $timestamp) . ' น.';
}

function public_class_url(string $token): string
{
    $baseUrl = (string) app_config('app.base_url', '');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    }

    return rtrim($baseUrl, '/') . '/?page=student-checkin&token=' . rawurlencode($token);
}

function status_label(string $status): string
{
    return match ($status) {
        'active' => 'กำลังใช้งาน',
        'completed' => 'เสร็จสิ้น',
        'available' => 'ว่าง',
        'maintenance' => 'ปิดปรับปรุง',
        'open' => 'เปิดรับลงชื่อ',
        'closed' => 'ปิดรับแล้ว',
        'draft' => 'แบบร่าง',
        'scheduled' => 'รอเวลาเริ่ม',
        'overdue' => 'หมดเวลาลงชื่อ',
        'cancelled' => 'ยกเลิก',
        default => $status,
    };
}

function class_display_status(array $classSession): string
{
    if (isset($classSession['display_status'])) return (string)$classSession['display_status'];
    if (($classSession['status'] ?? '') !== 'open') return (string)($classSession['status'] ?? '');
    if (time() > strtotime((string)($classSession['ends_at'] ?? ''))) return 'overdue';
    if (time() < strtotime((string)($classSession['starts_at'] ?? ''))) return 'scheduled';
    return 'open';
}

function csv_safe_value(mixed $value): string
{
    $value = (string)$value;
    return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
}

function role_label(string $role): string
{
    return match ($role) {
        'student' => 'นักศึกษา',
        'lecturer' => 'อาจารย์',
        'staff' => 'เจ้าหน้าที่',
        'admin' => 'ผู้ดูแลระบบ',
        default => $role,
    };
}

function thai_day_label(int $day, bool $short = false): string
{
    $days = $short
        ? [1=>'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']
        : [1=>'วันจันทร์', 'วันอังคาร', 'วันพุธ', 'วันพฤหัสบดี', 'วันศุกร์', 'วันเสาร์', 'วันอาทิตย์'];
    return $days[$day] ?? '—';
}

function week_start_date(?string $value = null): DateTimeImmutable
{
    $timezone = new DateTimeZone((string) app_config('app.timezone', 'Asia/Bangkok'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)
        ? new DateTimeImmutable((string)$value, $timezone)
        : new DateTimeImmutable('today', $timezone);
    return $date->modify('monday this week');
}

if (($_GET['health'] ?? '') === '1') {
    try {
        db()->query('SELECT 1')->fetchColumn();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'ok','service'=>'lums'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'unavailable','service'=>'lums']);
    }
    exit;
}

if (($_GET['download'] ?? '') === 'schedule-template') {
    require_auth('admin');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lums-schedule-template.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['course_code','course_name','section','room_code','lecturer_email','day_of_week','starts_time','ends_time','active_from','active_until','notes']);
    fputcsv($output, ['CPE221','ปฏิบัติการฐานข้อมูล','1','CPE-101','lecturer@lums.local','1','09:00','12:00','2026-08-03','2026-11-20','ตัวอย่าง: day_of_week 1=จันทร์ ถึง 7=อาทิตย์']);
    fclose($output);
    exit;
}

if (($_GET['download'] ?? '') === 'report-csv') {
    require_auth(['admin', 'lecturer']);
    $period = (string)($_GET['period'] ?? 'month');
    $roomId = max(0, (int)($_GET['room_id'] ?? 0));
    $rows = report_class_rows($period, $roomId);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lums-class-report-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['class_id','course_code','course_name','section','room_code','room_name','lecturer','starts_at','ends_at','status','attendance_count']);
    foreach ($rows as $row) {
        fputcsv($output, array_map('csv_safe_value', [
            $row['id'], $row['course_code'], $row['course_name'], $row['section'] ?? '', $row['room_code'], $row['room_name'],
            $row['lecturer_name'], $row['starts_at'], $row['ends_at'], status_label(class_display_status($row)), $row['attendance_count'],
        ]));
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $result = attempt_login(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            set_flash('success', 'เข้าสู่ระบบสำเร็จ', 'ยินดีต้อนรับกลับเข้าสู่ LUMS');
            redirect_to('dashboard');
        }
        set_flash('error', 'เข้าสู่ระบบไม่สำเร็จ', $result['message'] ?? 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        redirect_to('login');
    }

    if ($action === 'student_attendance') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $result = register_student_attendance($token, $_POST);
        if ($result['ok']) {
            $_SESSION['attendance_success'] = [
                'token' => $token,
                'student_code' => strtoupper(trim((string)($_POST['student_code'] ?? ''))),
                'duplicate' => (bool)($result['duplicate'] ?? false),
            ];
            set_flash('success', $result['duplicate'] ?? false ? 'ลงชื่อไว้แล้ว' : 'ลงชื่อเข้าเรียนสำเร็จ', $result['message'] ?? 'ระบบบันทึกเวลาเรียบร้อย');
            redirect_to('student-checkin', ['token' => $token, 'success' => 1]);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form' => $result['message'] ?? 'ไม่สามารถลงชื่อได้'];
        $_SESSION['old_input'] = ['student_code' => $_POST['student_code'] ?? '', 'student_name' => $_POST['student_name'] ?? ''];
        redirect_to('student-checkin', ['token' => $token]);
    }

    require_auth(['admin', 'lecturer']);

    if ($action === 'logout') {
        logout_user();
        redirect_to('login');
    }

    if ($action === 'create_class') {
        $result = create_class_session($_POST);
        if ($result['ok']) {
            set_flash('success', 'สร้างคลาสเรียบร้อย', $result['message'] ?? 'เตรียม QR Code เรียบร้อย');
            redirect_to('class-detail', ['id' => (int) $result['id']]);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form' => $result['message'] ?? 'ไม่สามารถบันทึกข้อมูลได้'];
        $_SESSION['old_input'] = $_POST;
        redirect_to('classes');
    }

    if ($action === 'close_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $result = close_class_session($classId);
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'ปิดรับลงชื่อแล้ว' : 'ดำเนินการไม่สำเร็จ', $result['message'] ?? '');
        redirect_to('class-detail', ['id' => $classId]);
    }

    if ($action === 'open_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $result = open_class_session($classId);
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'เปิดรับลงชื่อแล้ว' : 'ดำเนินการไม่สำเร็จ', $result['message'] ?? '');
        redirect_to('class-detail', ['id'=>$classId]);
    }

    if ($action === 'create_term') {
        $result = create_academic_term($_POST);
        if ($result['ok']) {
            set_flash('success', 'สร้างภาคการศึกษาแล้ว', $result['message']);
            redirect_to('schedule', ['term_id'=>(int)$result['id']]);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถสร้างภาคการศึกษาได้'];
        $_SESSION['old_input'] = $_POST;
        redirect_to('schedule');
    }

    if ($action === 'create_schedule') {
        $result = create_course_schedule($_POST);
        if ($result['ok']) {
            set_flash('success', 'เพิ่มตารางเรียนแล้ว', 'ระบบตรวจสอบห้องและเวลาไม่ให้ชนกันเรียบร้อย');
            redirect_to('schedule', ['term_id'=>(int)($_POST['term_id'] ?? 0), 'selected'=>(int)$result['id']]);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถเพิ่มตารางเรียนได้'];
        $_SESSION['old_input'] = $_POST;
        redirect_to('schedule', ['term_id'=>(int)($_POST['term_id'] ?? 0)]);
    }

    if ($action === 'cancel_schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $result = cancel_course_schedule($scheduleId);
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'ยกเลิกตารางเรียนแล้ว' : 'ยกเลิกตารางไม่ได้', $result['message'] ?? '');
        redirect_to('schedule', ['term_id'=>(int)($_POST['term_id'] ?? 0)]);
    }

    if ($action === 'import_schedule') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        $result = import_course_schedule_csv($termId, $_FILES['schedule_file'] ?? []);
        if ($result['ok']) {
            set_flash('success', 'นำเข้าตารางเรียนแล้ว', $result['message']);
            redirect_to('schedule', ['term_id'=>$termId]);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถนำเข้าตารางเรียนได้'];
        redirect_to('schedule', ['term_id'=>$termId, 'import'=>1]);
    }

    if ($action === 'create_schedule_session') {
        $result = create_session_from_schedule((int)($_POST['schedule_id'] ?? 0), (string)($_POST['scheduled_date'] ?? ''));
        if ($result['ok']) {
            set_flash('success', $result['existing'] ?? false ? 'มี QR สำหรับคาบนี้แล้ว' : 'เตรียม QR เรียบร้อย', $result['message']);
            redirect_to('class-detail', ['id'=>(int)$result['id']]);
        }
        set_flash('error', 'สร้าง QR ไม่สำเร็จ', $result['message'] ?? 'กรุณาตรวจสอบวันที่คาบเรียน');
        redirect_to('schedule', ['selected'=>(int)($_POST['schedule_id'] ?? 0)]);
    }
}

$user = current_user();
$page = (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'login'));

if ($page === 'login' && $user) {
    redirect_to('dashboard');
}

if (!in_array($page, ['login', 'student-checkin'], true)) {
    require_auth(['admin', 'lecturer']);
    $user = current_user();
}

$allowedPages = ['login', 'student-checkin', 'dashboard', 'schedule', 'classes', 'class-detail', 'records', 'rooms', 'reports'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'not-found';
}

$flash = pull_flash();
$formErrors = $_SESSION['form_errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old_input']);

if ($page === 'student-checkin'):
    $token = trim((string) ($_GET['token'] ?? ''));
    $classSession = get_public_class_by_token($token);
    $nowUtc = time();
    $attendanceReceipt = $_SESSION['attendance_success'] ?? [];
    $hasSavedAttendance = isset($_GET['success']) && ($attendanceReceipt['token'] ?? null) === $token;
    $canCheckIn = $classSession
        && $classSession['status'] === 'open'
        && $nowUtc >= strtotime($classSession['starts_at'])
        && $nowUtc <= strtotime($classSession['ends_at']);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B2545">
    <title>ลงชื่อเข้าเรียน — LUMS</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="student-page">
    <main class="student-shell">
        <header class="student-brand"><span class="brand-mark">LU</span><span><strong>LUMS</strong><small>Student Check-in</small></span></header>
        <?php if (!$classSession): ?>
            <section class="student-panel student-panel--center"><span class="student-state-icon" data-icon="circle-alert"></span><h1>ไม่พบคลาสเรียน</h1><p>QR Code นี้อาจไม่ถูกต้องหรือคลาสถูกนำออกจากระบบ กรุณาติดต่ออาจารย์ผู้สอน</p></section>
        <?php else: ?>
            <section class="student-class-summary" aria-labelledby="student-class-title">
                <?php $publicDisplayStatus = class_display_status($classSession); ?>
                <span class="status status--<?= e($publicDisplayStatus) ?>"><span></span><?= e(status_label($publicDisplayStatus)) ?></span>
                <p class="eyebrow"><?= e($classSession['course_code']) ?><?= $classSession['section'] ? ' · กลุ่ม ' . e($classSession['section']) : '' ?></p>
                <h1 id="student-class-title"><?= e($classSession['course_name']) ?></h1>
                <dl class="student-class-meta">
                    <div><dt>ห้องเรียน</dt><dd><?= e($classSession['room_code'] . ' · ' . $classSession['room_name']) ?></dd></div>
                    <div><dt>ผู้สอน</dt><dd><?= e($classSession['lecturer_name']) ?></dd></div>
                    <div><dt>เวลาเรียน</dt><dd><?= e(thai_datetime($classSession['starts_at'])) ?> – <?= e(thai_datetime($classSession['ends_at'], false)) ?></dd></div>
                </dl>
            </section>
            <section class="student-panel" aria-labelledby="attendance-title">
                <?php if ($flash): ?>
                    <div class="alert alert--<?= e($flash['type']) ?>" role="status"><strong><?= e($flash['title']) ?></strong><?php if ($flash['message']): ?><span><?= e($flash['message']) ?></span><?php endif; ?></div>
                <?php endif; ?>
                <?php if ($canCheckIn && !$hasSavedAttendance): ?>
                    <div class="section-heading"><div><p class="eyebrow">สำหรับนักศึกษา</p><h2 id="attendance-title">ลงชื่อเข้าเรียน</h2><p>กรอกข้อมูลของตนเอง ระบบจะบันทึกเวลาปัจจุบันโดยอัตโนมัติ</p></div></div>
                    <?php if ($formErrors): ?><div class="alert alert--error" role="alert"><strong>กรุณาตรวจสอบข้อมูล</strong><span><?= e(implode(' ', array_values($formErrors))) ?></span></div><?php endif; ?>
                    <form method="post" class="form-stack" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="student_attendance">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <input type="hidden" name="client_request_id" value="<?= e('web-' . bin2hex(random_bytes(12))) ?>">
                        <label class="field"><span>รหัสนักศึกษา <b aria-hidden="true">*</b></span><input name="student_code" value="<?= e($oldInput['student_code'] ?? '') ?>" inputmode="numeric" autocomplete="off" placeholder="เช่น 66010001" minlength="4" maxlength="30" required autofocus></label>
                        <label class="field"><span>ชื่อ–นามสกุล <b aria-hidden="true">*</b></span><input name="student_name" value="<?= e($oldInput['student_name'] ?? '') ?>" autocomplete="name" placeholder="กรอกชื่อและนามสกุล" minlength="2" maxlength="100" required></label>
                        <button class="button button--primary button--block" type="submit"><span data-icon="circle-check-big"></span>ยืนยันการลงชื่อเข้าเรียน</button>
                    </form>
                    <p class="privacy-note">ข้อมูลนี้ใช้สำหรับบันทึกการเข้าเรียนในคลาสนี้เท่านั้น โปรดอย่าส่ง QR Code ให้บุคคลอื่น</p>
                <?php elseif ($hasSavedAttendance): ?>
                    <div class="student-success"><span data-icon="circle-check-big"></span><h2 id="attendance-title"><?= !empty($attendanceReceipt['duplicate']) ? 'ลงชื่อในคลาสนี้ไว้แล้ว' : 'บันทึกเรียบร้อยแล้ว' ?></h2><p>รหัสนักศึกษา <?= e($attendanceReceipt['student_code']) ?><br>คุณสามารถปิดหน้านี้ได้ ไม่ต้องลงชื่อซ้ำ</p></div>
                <?php else: ?>
                    <?php $waitingToOpen = $classSession['status'] === 'draft' || ($classSession['status'] === 'open' && strtotime($classSession['starts_at']) > $nowUtc); ?>
                    <div class="student-success student-success--muted"><span data-icon="clock"></span><h2 id="attendance-title"><?= $waitingToOpen ? 'ยังไม่เปิดรับการลงชื่อ' : 'ปิดรับการลงชื่อแล้ว' ?></h2><p><?= $waitingToOpen ? 'เมื่อถึงเวลาเรียนหรืออาจารย์แจ้งให้ลงชื่อ กดตรวจสอบอีกครั้ง' : 'หากต้องการแก้ไขข้อมูล กรุณาติดต่ออาจารย์ผู้สอน' ?></p><?php if ($waitingToOpen): ?><a class="button button--secondary" href="?page=student-checkin&amp;token=<?= e($token) ?>">ตรวจสอบอีกครั้ง</a><?php endif; ?></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script src="assets/app.js" defer></script>
</body>
</html>
<?php
exit;
endif;

if ($page === 'login'):
    $isDemoEnvironment = (string)app_config('app.env', 'local') !== 'production';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B2545">
    <title>เข้าสู่ระบบ — LUMS</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand" aria-labelledby="brand-title">
            <div class="brand-mark" aria-hidden="true">LU</div>
            <div>
                <p class="eyebrow">Laboratory Usage Monitoring System</p>
                <h1 id="brand-title">จัดการการใช้ห้องปฏิบัติการ<br>ให้ตรวจสอบได้ในที่เดียว</h1>
                <p class="auth-intro">สร้างคลาสและ QR Code ติดตามการลงชื่อเข้าเรียน และเรียกดูข้อมูลเพื่อวางแผนได้อย่างแม่นยำ</p>
            </div>
            <dl class="auth-features">
                <div><dt>รวดเร็ว</dt><dd>เช็กอินและยืนยันผลได้ทันที</dd></div>
                <div><dt>ตรวจสอบได้</dt><dd>มีเวลา ห้อง และผู้ใช้งานครบถ้วน</dd></div>
                <div><dt>พร้อมวิเคราะห์</dt><dd>ข้อมูลรวมศูนย์สำหรับรายงาน</dd></div>
            </dl>
        </section>
        <section class="auth-panel" aria-labelledby="login-title">
            <div class="auth-panel-inner">
                <div class="mobile-brand"><span class="brand-mark">LU</span><strong>LUMS</strong></div>
                <p class="eyebrow">ยินดีต้อนรับ</p>
                <h2 id="login-title">เข้าสู่ระบบ</h2>
                <p class="muted">สำหรับอาจารย์และผู้ดูแลระบบเท่านั้น นักศึกษาเข้าใช้งานผ่าน QR Code ของคลาส</p>

                <?php if ($flash): ?>
                    <div class="alert alert--<?= e($flash['type']) ?>" role="alert">
                        <strong><?= e($flash['title']) ?></strong>
                        <?php if ($flash['message']): ?><span><?= e($flash['message']) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="form-stack" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <label class="field">
                        <span>อีเมล</span>
                        <input type="email" name="email" autocomplete="username" value="<?= $isDemoEnvironment ? 'admin@lums.local' : '' ?>" required autofocus>
                    </label>
                    <label class="field">
                        <span>รหัสผ่าน</span>
                        <span class="password-field">
                            <input id="login-password" type="password" name="password" autocomplete="current-password" value="<?= $isDemoEnvironment ? 'admin123' : '' ?>" required>
                            <button type="button" class="text-button" data-toggle-password="login-password">แสดง</button>
                        </span>
                    </label>
                    <button class="button button--primary button--block" type="submit">เข้าสู่ระบบ</button>
                </form>
                <?php if($isDemoEnvironment): ?><div class="demo-note"><strong>บัญชีทดลอง</strong><span>admin@lums.local / admin123</span></div><?php endif; ?>
            </div>
        </section>
    </main>
    <script src="assets/app.js" defer></script>
</body>
</html>
<?php
exit;
endif;

$nav = [
    'dashboard' => ['ภาพรวม', 'layout-dashboard'],
    'schedule' => ['ตารางเรียน', 'calendar-days'],
    'classes' => ['คลาสเรียนและ QR', 'qr-code'],
    'rooms' => ['ห้องปฏิบัติการ', 'door-open'],
    'records' => ['ประวัติการเข้าเรียน', 'history'],
    'reports' => ['รายงาน', 'chart-no-axes-combined'],
];
$navPage = $page === 'class-detail' ? 'classes' : $page;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B2545">
    <title><?= e($nav[$navPage][0] ?? 'LUMS') ?> — LUMS</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="app-page">
    <a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหา</a>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar" aria-label="เมนูหลัก">
            <div class="sidebar-brand"><span class="brand-mark">LU</span><span><strong>LUMS</strong><small>Lab Management</small></span></div>
            <nav class="main-nav">
                <?php foreach ($nav as $key => [$label, $icon]): ?>
                    <a href="?page=<?= e($key) ?>" class="nav-item <?= $navPage === $key ? 'is-active' : '' ?>" <?= $navPage === $key ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">
                <div class="user-summary"><span class="avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span><span><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="nav-item nav-item--button" type="submit"><span class="nav-icon" data-icon="log-out" aria-hidden="true"></span><span>ออกจากระบบ</span></button>
                </form>
            </div>
        </aside>
        <div class="app-main">
            <header class="topbar">
                <button class="icon-button mobile-menu-button" type="button" data-mobile-nav aria-controls="sidebar" aria-expanded="false" aria-label="เปิดเมนู"><span data-icon="menu"></span></button>
                <div class="topbar-context"><strong><?= e($nav[$navPage][0] ?? 'LUMS') ?></strong><span><?= e(date('d/m/Y')) ?></span></div>
                <a class="button button--primary button--compact" href="<?= $page === 'schedule' ? '#schedule-form' : '?page=classes#new-class' ?>"><span data-icon="plus" aria-hidden="true"></span><?= $page === 'schedule' ? 'เพิ่มตาราง' : 'สร้างคลาส' ?></a>
            </header>
            <main id="main-content" class="content">
                <?php if ($flash): ?>
                    <div class="alert alert--<?= e($flash['type']) ?> alert--dismissible" role="status">
                        <span><strong><?= e($flash['title']) ?></strong><?php if ($flash['message']): ?><span><?= e($flash['message']) ?></span><?php endif; ?></span>
                        <button class="icon-button" type="button" data-dismiss-alert aria-label="ปิดข้อความ"><span data-icon="x"></span></button>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <?php $data = dashboard_data(); ?>
                    <header class="page-header">
                        <div><p class="eyebrow">ภาพรวมวันนี้</p><h1>สวัสดี <?= e($user['name']) ?></h1><p>ติดตามคลาส ห้องเรียน และการลงชื่อของนักศึกษาจาก QR Code</p></div>
                        <a href="?page=classes" class="button button--secondary">ดูคลาสทั้งหมด</a>
                    </header>
                    <section class="metrics-grid" aria-label="สรุปข้อมูลวันนี้">
                        <article class="metric"><span>คลาสวันนี้</span><strong><?= e($data['today_total']) ?></strong><small>คลาส</small></article>
                        <article class="metric metric--active"><span>เปิดรับลงชื่อ</span><strong><?= e($data['active_total']) ?></strong><small>คลาส</small></article>
                        <article class="metric"><span>ห้องที่กำลังใช้</span><strong><?= e($data['rooms_in_use']) ?></strong><small>จาก <?= e($data['room_total']) ?> ห้อง</small></article>
                        <?php if ($data['issues_total']): ?>
                            <a class="metric metric--warning metric--link" href="?page=classes&amp;status=overdue" aria-label="ตรวจสอบคลาสเกินเวลา <?= e($data['issues_total']) ?> คลาส"><span>คลาสเกินเวลา</span><strong><?= e($data['issues_total']) ?></strong><small>ตรวจสอบและปิดรับลงชื่อ <span aria-hidden="true">→</span></small></a>
                        <?php else: ?>
                            <article class="metric"><span>คลาสเกินเวลา</span><strong>0</strong><small>ไม่มีรายการ</small></article>
                        <?php endif; ?>
                    </section>
                    <div class="dashboard-grid">
                        <section class="section-block">
                            <div class="section-heading"><div><h2>สถานะห้องปฏิบัติการ</h2><p>อัปเดตจากคลาสที่กำลังเปิดรับลงชื่อ</p></div><a href="?page=rooms" class="text-link">ดูทุกห้อง</a></div>
                            <div class="room-list">
                                <?php foreach ($data['rooms'] as $room): ?>
                                    <article class="room-row">
                                        <span class="room-code"><?= e($room['code']) ?></span>
                                        <span class="room-info"><strong><?= e($room['name']) ?></strong><small><?= e($room['building']) ?> · ชั้น <?= e($room['floor']) ?></small></span>
                                        <span class="status status--<?= e($room['live_status']) ?>"><span aria-hidden="true"></span><?= e(status_label($room['live_status'])) ?></span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <section class="section-block quick-panel">
                            <div class="section-heading"><div><h2>ทางลัด</h2><p>งานที่ใช้เป็นประจำ</p></div></div>
                            <a class="quick-action" href="?page=schedule"><span class="quick-icon" data-icon="calendar-days"></span><span><strong>เปิดตารางเรียน</strong><small>เลือกเวลาและตรวจห้องว่าง</small></span><span data-icon="chevron-right"></span></a>
                            <a class="quick-action" href="?page=classes#new-class"><span class="quick-icon" data-icon="plus"></span><span><strong>สร้างคลาสและ QR</strong><small>เปิดรับให้นักศึกษาลงชื่อ</small></span><span data-icon="chevron-right"></span></a>
                            <a class="quick-action" href="?page=records"><span class="quick-icon" data-icon="search"></span><span><strong>ค้นหาการเข้าเรียน</strong><small>กรองตามนักศึกษา ห้อง และช่วงเวลา</small></span><span data-icon="chevron-right"></span></a>
                            <a class="quick-action" href="?page=reports"><span class="quick-icon" data-icon="download"></span><span><strong>ออกรายงาน</strong><small>สรุปข้อมูลการใช้งาน</small></span><span data-icon="chevron-right"></span></a>
                        </section>
                    </div>
                    <section class="section-block recent-section">
                        <div class="section-heading"><div><h2>คลาสล่าสุด</h2><p>เปิดดู QR Code และรายชื่อนักศึกษา</p></div><span class="result-count"><?= count($data['recent']) ?> คลาส</span></div>
                        <?php render_class_table($data['recent']); ?>
                    </section>

                <?php elseif ($page === 'schedule'): ?>
                    <?php
                    $terms = list_academic_terms();
                    $defaultTerm = current_academic_term();
                    $termId = (int)($_GET['term_id'] ?? $defaultTerm['id'] ?? 0);
                    $term = get_academic_term($termId) ?? $defaultTerm;
                    $termId = (int)($term['id'] ?? 0);
                    $rooms = list_rooms();
                    $lecturers = list_lecturers();
                    $roomFilter = (string)($_GET['room_id'] ?? '');
                    $weekStart = week_start_date((string)($_GET['week'] ?? ''));
                    $showWeekend = (string)($_GET['weekend'] ?? '') === '1';
                    $dayCount = $showWeekend ? 7 : 5;
                    $schedules = $term ? list_course_schedules($termId, ['room_id'=>$roomFilter, 'q'=>(string)($_GET['q'] ?? '')]) : [];
                    $selectedSchedule = get_course_schedule((int)($_GET['selected'] ?? 0));
                    if ($selectedSchedule && $selectedSchedule['term_id'] !== $termId) $selectedSchedule = null;
                    $selectedDate = $selectedSchedule ? $weekStart->modify('+' . ($selectedSchedule['day_of_week'] - 1) . ' days')->format('Y-m-d') : '';
                    $previousWeek = $weekStart->modify('-7 days')->format('Y-m-d');
                    $nextWeek = $weekStart->modify('+7 days')->format('Y-m-d');
                    $todayWeek = week_start_date()->format('Y-m-d');
                    $scheduleQueryBase = ['page'=>'schedule','term_id'=>$termId,'room_id'=>$roomFilter,'q'=>(string)($_GET['q'] ?? ''),'weekend'=>$showWeekend ? 1 : 0];
                    $weekSchedules = array_values(array_filter($schedules, static function (array $schedule) use ($weekStart, $dayCount): bool {
                        $date = $weekStart->modify('+' . ($schedule['day_of_week'] - 1) . ' days')->format('Y-m-d');
                        return $schedule['day_of_week'] <= $dayCount && $date >= $schedule['active_from'] && $date <= $schedule['active_until'];
                    }));
                    ?>
                    <header class="page-header"><div><p class="eyebrow">วางแผนการใช้ห้องทั้งเทอม</p><h1>ตารางเรียนห้องปฏิบัติการ</h1><p>ดูตารางรายสัปดาห์ คลิกช่องว่างเพื่อเพิ่มคาบ และตรวจการชนกันก่อนบันทึก</p></div><a class="button button--secondary" href="#import-schedule"><span data-icon="upload"></span>นำเข้าทั้งเทอม</a></header>
                    <?php if (!$term): ?>
                        <section class="empty-feature"><span data-icon="calendar-days"></span><h2>ยังไม่มีภาคการศึกษา</h2><p>ผู้ดูแลระบบต้องสร้างภาคการศึกษาก่อนเริ่มจัดตารางเรียน</p><a class="button button--primary" href="#term-settings">สร้างภาคการศึกษา</a></section>
                    <?php else: ?>
                        <form method="get" class="filter-bar schedule-filter">
                            <input type="hidden" name="page" value="schedule">
                            <label><span>ภาคการศึกษา</span><select name="term_id"><?php foreach($terms as $item): ?><option value="<?= e($item['id']) ?>" <?= $item['id']===$termId?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
                            <label><span>ห้อง</span><select name="room_id"><option value="">ทุกห้อง</option><?php foreach($rooms as $room): ?><option value="<?= e($room['id']) ?>" <?= $roomFilter===(string)$room['id']?'selected':'' ?>><?= e($room['code']) ?></option><?php endforeach; ?></select></label>
                            <label class="search-field"><span data-icon="search"></span><span class="sr-only">ค้นหาตาราง</span><input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="ค้นหารายวิชา ห้อง หรืออาจารย์"></label>
                            <input type="hidden" name="week" value="<?= e($weekStart->format('Y-m-d')) ?>">
                            <input type="hidden" name="weekend" value="<?= $showWeekend ? '1' : '0' ?>">
                            <button class="button button--secondary" type="submit">แสดงตาราง</button>
                        </form>
                        <div class="schedule-toolbar" aria-label="เปลี่ยนสัปดาห์">
                            <div class="button-group"><a class="button button--secondary button--small" href="?<?= e(http_build_query($scheduleQueryBase + ['week'=>$previousWeek])) ?>" aria-label="สัปดาห์ก่อน"><span data-icon="chevron-left"></span></a><a class="button button--secondary button--small" href="?<?= e(http_build_query($scheduleQueryBase + ['week'=>$todayWeek])) ?>">สัปดาห์นี้</a><a class="button button--secondary button--small" href="?<?= e(http_build_query($scheduleQueryBase + ['week'=>$nextWeek])) ?>" aria-label="สัปดาห์ถัดไป"><span data-icon="chevron-right"></span></a></div>
                            <div><strong><?= e($weekStart->format('d/m/Y')) ?> – <?= e($weekStart->modify('+' . ($dayCount - 1) . ' days')->format('d/m/Y')) ?></strong><small><?= e($term['name']) ?></small></div>
                            <div class="segmented" aria-label="จำนวนวันที่แสดง"><a class="<?= !$showWeekend?'is-active':'' ?>" href="?<?= e(http_build_query(array_merge($scheduleQueryBase, ['week'=>$weekStart->format('Y-m-d'),'weekend'=>0]))) ?>" <?= !$showWeekend ? 'aria-current="page"' : '' ?>>จ.–ศ.</a><a class="<?= $showWeekend?'is-active':'' ?>" href="?<?= e(http_build_query(array_merge($scheduleQueryBase, ['week'=>$weekStart->format('Y-m-d'),'weekend'=>1]))) ?>" <?= $showWeekend ? 'aria-current="page"' : '' ?>>7 วัน</a></div>
                        </div>
                        <div class="schedule-layout">
                            <section class="schedule-calendar-panel" aria-labelledby="weekly-calendar-title">
                                <div class="section-heading"><div><h2 id="weekly-calendar-title">ตารางประจำสัปดาห์</h2><p>คลิกช่องเวลาเพื่อกรอกวันและเวลาในแบบฟอร์มอัตโนมัติ</p></div><span class="result-count"><?= count($weekSchedules) ?> รายการในสัปดาห์นี้</span></div>
                                <?php if (!$weekSchedules): ?><p class="inline-note">ไม่พบตารางในสัปดาห์และตัวกรองที่เลือก ลองเปลี่ยนสัปดาห์หรือปรับตัวกรอง</p><?php endif; ?>
                                <div class="schedule-legend"><span><i class="legend-swatch legend-swatch--class"></i>มีตารางเรียน</span><span><i class="legend-swatch legend-swatch--selected"></i>รายการที่เลือก</span><span><i class="legend-swatch legend-swatch--today"></i>วันนี้</span></div>
                                <div class="schedule-scroll" role="region" aria-label="ตารางเรียนรายสัปดาห์" tabindex="0">
                                    <div class="schedule-week" style="--day-count:<?= e($dayCount) ?>">
                                        <div class="schedule-corner">เวลา</div>
                                        <?php for($day=1;$day<=$dayCount;$day++): $date=$weekStart->modify('+' . ($day-1) . ' days'); ?>
                                            <div class="schedule-day-heading <?= $date->format('Y-m-d')===date('Y-m-d')?'is-today':'' ?>"><strong><?= e(thai_day_label($day, true)) ?></strong><span><?= e($date->format('d/m')) ?></span></div>
                                        <?php endfor; ?>
                                        <div class="schedule-time-rail"><?php for($hour=8;$hour<=20;$hour++): ?><span style="--hour-index:<?= e($hour-8) ?>"><?= e(sprintf('%02d:00',$hour)) ?></span><?php endfor; ?></div>
                                        <?php for($day=1;$day<=$dayCount;$day++): $date=$weekStart->modify('+' . ($day-1) . ' days'); $dateValue=$date->format('Y-m-d'); ?>
                                            <div class="schedule-day-column <?= $dateValue===date('Y-m-d')?'is-today':'' ?>" data-schedule-day="<?= e($day) ?>" data-schedule-date="<?= e($dateValue) ?>">
                                                <?php for($hour=8;$hour<20;$hour++): ?><button type="button" class="schedule-empty-slot" data-slot-day="<?= e($day) ?>" data-slot-start="<?= e(sprintf('%02d:00',$hour)) ?>" aria-label="เลือก<?= e(thai_day_label($day)) ?> เวลา <?= e(sprintf('%02d:00',$hour)) ?>"></button><?php endfor; ?>
                                                <?php foreach($schedules as $schedule): if($schedule['day_of_week']!==$day || $dateValue<$schedule['active_from'] || $dateValue>$schedule['active_until']) continue; [$sh,$sm]=array_map('intval',explode(':',$schedule['starts_time'])); [$eh,$em]=array_map('intval',explode(':',$schedule['ends_time'])); $startMinute=max(0,$sh*60+$sm-480); $duration=max(30,$eh*60+$em-($sh*60+$sm)); ?>
                                                    <a class="schedule-block <?= $selectedSchedule && $selectedSchedule['id']===$schedule['id']?'is-selected':'' ?>" style="--slot-start:<?= e($startMinute) ?>;--slot-duration:<?= e($duration) ?>" href="?<?= e(http_build_query($scheduleQueryBase + ['week'=>$weekStart->format('Y-m-d'),'selected'=>$schedule['id']])) ?>" aria-label="<?= e($schedule['course_code'].' '.$schedule['course_name'].' ห้อง '.$schedule['room_code'].' เวลา '.$schedule['starts_time'].' ถึง '.$schedule['ends_time']) ?>">
                                                        <strong><?= e($schedule['course_code']) ?></strong><span><?= e($schedule['room_code']) ?></span><small><?= e($schedule['starts_time'].'–'.$schedule['ends_time']) ?></small>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </section>
                            <aside class="schedule-context" aria-label="รายละเอียดตารางที่เลือก">
                                <?php if($selectedSchedule): ?>
                                    <p class="eyebrow">รายการที่เลือก</p><h2><?= e($selectedSchedule['course_code']) ?></h2><p><?= e($selectedSchedule['course_name']) ?><?= $selectedSchedule['section']?' · กลุ่ม '.e($selectedSchedule['section']):'' ?></p>
                                    <dl class="compact-details"><div><dt>วันและเวลา</dt><dd><?= e(thai_day_label($selectedSchedule['day_of_week']).' '.$selectedSchedule['starts_time'].'–'.$selectedSchedule['ends_time']) ?></dd></div><div><dt>ห้อง</dt><dd><?= e($selectedSchedule['room_code'].' · '.$selectedSchedule['room_name']) ?></dd></div><div><dt>ผู้สอน</dt><dd><?= e($selectedSchedule['lecturer_name']) ?></dd></div><div><dt>ช่วงใช้งาน</dt><dd><?= e($selectedSchedule['active_from'].' – '.$selectedSchedule['active_until']) ?></dd></div></dl>
                                    <?php if($selectedDate >= $selectedSchedule['active_from'] && $selectedDate <= $selectedSchedule['active_until']): ?><form method="post" class="form-stack schedule-qr-action"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_schedule_session"><input type="hidden" name="schedule_id" value="<?= e($selectedSchedule['id']) ?>"><label class="field"><span>วันที่ต้องการสร้าง QR</span><input type="date" name="scheduled_date" value="<?= e($selectedDate) ?>" min="<?= e($selectedSchedule['active_from']) ?>" max="<?= e($selectedSchedule['active_until']) ?>" required></label><button class="button button--primary button--block" type="submit"><span data-icon="qr-code"></span>เตรียม QR สำหรับคาบนี้</button></form><?php else: ?><div class="inline-note">สัปดาห์นี้อยู่นอกช่วงใช้งานของตารางรายการนี้</div><?php endif; ?>
                                    <form method="post" class="schedule-cancel-action" data-confirm="ตารางรายสัปดาห์นี้จะถูกยกเลิก และ QR แบบร่างของคาบในอนาคตจะถูกยกเลิกด้วย ข้อมูลการเข้าเรียนเดิมจะยังคงอยู่" data-confirm-title="ยกเลิกตารางเรียน" data-confirm-label="ยกเลิกตาราง" data-confirm-danger="true">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_schedule"><input type="hidden" name="schedule_id" value="<?= e($selectedSchedule['id']) ?>"><input type="hidden" name="term_id" value="<?= e($termId) ?>">
                                        <button class="button button--danger-ghost button--block" type="submit"><span data-icon="calendar-x"></span>ยกเลิกตารางนี้</button>
                                    </form>
                                <?php else: ?>
                                    <div class="schedule-help"><span data-icon="mouse-pointer-click"></span><h2>เลือกตารางหรือช่องว่าง</h2><p>คลิกรายวิชาเพื่อดูรายละเอียดและเตรียม QR หรือคลิกช่องว่างเพื่อเพิ่มตารางใหม่</p></div>
                                <?php endif; ?>
                                <div class="term-summary"><span>ช่วงภาคเรียน</span><strong><?= e($term['starts_on']) ?> – <?= e($term['ends_on']) ?></strong><small>สถานะ: <?= e($term['status']==='active'?'กำลังใช้งาน':($term['status']==='planned'?'กำลังวางแผน':'เก็บถาวร')) ?></small></div>
                            </aside>
                        </div>
                    <?php endif; ?>

                    <div class="schedule-tools-layout">
                        <section id="schedule-form" class="form-panel" aria-labelledby="schedule-form-title">
                            <div class="section-heading"><div><p class="eyebrow">เพิ่มทีละรายการ</p><h2 id="schedule-form-title">เพิ่มตารางเรียน</h2><p>เลือกช่องในตารางด้านบน หรือกรอกวันและเวลาด้วยตนเอง</p></div></div>
                            <?php if($formErrors && !isset($_GET['import'])): ?><div class="alert alert--error" role="alert"><strong>บันทึกไม่ได้</strong><span><?= e(implode(' ',array_values($formErrors))) ?></span></div><?php endif; ?>
                            <form method="post" class="form-grid" novalidate data-schedule-form>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_schedule">
                                <label class="field"><span>ภาคการศึกษา <b>*</b></span><select name="term_id" required><?php foreach($terms as $item): ?><option value="<?= e($item['id']) ?>" <?= (string)($oldInput['term_id']??$termId)===(string)$item['id']?'selected':'' ?> data-start="<?= e($item['starts_on']) ?>" data-end="<?= e($item['ends_on']) ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>ห้องปฏิบัติการ <b>*</b></span><select name="room_id" required><option value="">เลือกห้อง</option><?php foreach($rooms as $room): if($room['status']!=='available') continue; ?><option value="<?= e($room['id']) ?>" <?= (string)($oldInput['room_id']??'')===(string)$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>รหัสรายวิชา <b>*</b></span><input name="course_code" value="<?= e($oldInput['course_code']??'') ?>" placeholder="เช่น CPE221" maxlength="30" required></label>
                                <label class="field"><span>กลุ่มเรียน</span><input name="section" value="<?= e($oldInput['section']??'') ?>" placeholder="เช่น 1" maxlength="30"></label>
                                <label class="field field--full"><span>ชื่อรายวิชา <b>*</b></span><input name="course_name" value="<?= e($oldInput['course_name']??'') ?>" placeholder="ชื่อรายวิชาหรือปฏิบัติการ" maxlength="150" required></label>
                                <?php if($user['role']==='admin'): ?><label class="field field--full"><span>อาจารย์ผู้สอน <b>*</b></span><select name="lecturer_user_id" required><option value="">เลือกอาจารย์</option><?php foreach($lecturers as $lecturer): ?><option value="<?= e($lecturer['id']) ?>" <?= (string)($oldInput['lecturer_user_id']??'')===(string)$lecturer['id']?'selected':'' ?>><?= e($lecturer['full_name'].' · '.$lecturer['email']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <label class="field"><span>วันเรียน <b>*</b></span><select name="day_of_week" required><?php for($day=1;$day<=7;$day++): ?><option value="<?= e($day) ?>" <?= (string)($oldInput['day_of_week']??'1')===(string)$day?'selected':'' ?>><?= e(thai_day_label($day)) ?></option><?php endfor; ?></select></label>
                                <div class="time-field-pair"><label class="field"><span>เริ่ม <b>*</b></span><input type="time" name="starts_time" value="<?= e($oldInput['starts_time']??'09:00') ?>" step="1800" required></label><label class="field"><span>สิ้นสุด <b>*</b></span><input type="time" name="ends_time" value="<?= e($oldInput['ends_time']??'12:00') ?>" step="1800" required></label></div>
                                <label class="field"><span>เริ่มใช้ตาราง <b>*</b></span><input type="date" name="active_from" value="<?= e($oldInput['active_from']??$term['starts_on']??'') ?>" required></label>
                                <label class="field"><span>ใช้ถึงวันที่ <b>*</b></span><input type="date" name="active_until" value="<?= e($oldInput['active_until']??$term['ends_on']??'') ?>" required></label>
                                <label class="field field--full"><span>หมายเหตุ</span><textarea name="notes" rows="2" maxlength="500" placeholder="เช่น งดเรียนสัปดาห์สอบกลางภาค"><?= e($oldInput['notes']??'') ?></textarea></label>
                                <div class="slot-selection-message field--full" data-slot-selection aria-live="polite">ยังไม่ได้เลือกช่องเวลา สามารถกรอกเองได้</div>
                                <div class="form-actions field--full"><button class="button button--primary" type="submit">บันทึกตารางเรียน</button><button class="button button--ghost" type="reset">ล้างข้อมูล</button></div>
                            </form>
                        </section>
                        <div class="schedule-admin-stack">
                            <?php if($user['role']==='admin'): ?><section id="import-schedule" class="form-panel"><div class="section-heading"><div><p class="eyebrow">งานจำนวนมาก</p><h2>นำเข้าตารางทั้งเทอม</h2><p>รองรับ CSV UTF-8 และตรวจการชนกันก่อนนำเข้าทั้งชุด</p></div></div><?php if($formErrors && isset($_GET['import'])): ?><div class="alert alert--error" role="alert"><strong>นำเข้าไม่ได้</strong><span><?= e(implode(' ',array_values($formErrors))) ?></span></div><?php endif; ?><ol class="import-steps"><li>ดาวน์โหลดไฟล์ตัวอย่าง</li><li>กรอกหนึ่งรายวิชาต่อหนึ่งแถว</li><li>เลือกภาคเรียนและอัปโหลด</li></ol><a class="button button--secondary button--block" href="?download=schedule-template"><span data-icon="download"></span>ดาวน์โหลด CSV ตัวอย่าง</a><form method="post" enctype="multipart/form-data" class="form-stack import-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_schedule"><label class="field"><span>ภาคการศึกษา</span><select name="term_id" required><?php foreach($terms as $item): ?><option value="<?= e($item['id']) ?>" <?= $item['id']===$termId?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label class="field"><span>ไฟล์ตารางเรียน (.csv สูงสุด 2 MB)</span><input type="file" name="schedule_file" accept=".csv,text/csv" required></label><button class="button button--primary button--block" type="submit">ตรวจสอบและนำเข้า</button></form></section><?php endif; ?>
                            <?php if($user['role']==='admin'): ?><details id="term-settings" class="management-details"><summary><span><strong>จัดการภาคการศึกษา</strong><small>สร้างปีหรือเทอมใหม่</small></span><span data-icon="chevron-down"></span></summary><form method="post" class="form-grid details-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_term"><label class="field field--full"><span>ชื่อภาคการศึกษา</span><input name="term_name" placeholder="เช่น ภาคการศึกษาที่ 2/2569" required></label><label class="field"><span>ปีการศึกษา</span><input type="number" name="academic_year" min="2500" max="2700" value="<?= e(date('Y')+543) ?>" required></label><label class="field"><span>ภาค</span><select name="semester"><option value="1">ภาค 1</option><option value="2">ภาค 2</option><option value="summer">ภาคฤดูร้อน</option></select></label><label class="field"><span>วันเปิดภาค</span><input type="date" name="term_starts_on" required></label><label class="field"><span>วันปิดภาค</span><input type="date" name="term_ends_on" required></label><div class="form-actions field--full"><button class="button button--primary" type="submit">สร้างภาคการศึกษา</button></div></form></details><?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'classes'): ?>
                    <?php
                    $rooms = array_values(array_filter(list_rooms(), fn(array $room): bool => $room['status'] === 'available'));
                    $classFilters = ['q' => (string) ($_GET['q'] ?? ''), 'status' => (string) ($_GET['status'] ?? '')];
                    $classes = list_class_sessions($classFilters);
                    $defaultStart = date('Y-m-d\TH:i');
                    $defaultEnd = date('Y-m-d\TH:i', time() + 7200);
                    ?>
                    <header class="page-header"><div><p class="eyebrow">สำหรับอาจารย์และผู้ดูแล</p><h1>คลาสเรียนและ QR Code</h1><p>สร้างคลาส เปิด QR ให้นักศึกษาลงชื่อ และติดตามจำนวนผู้เข้าเรียนแบบทันที</p></div></header>
                    <div class="class-management-layout">
                        <section class="section-block" aria-labelledby="class-list-title">
                            <div class="section-heading"><div><h2 id="class-list-title"><?= $user['role'] === 'admin' ? 'คลาสทั้งหมด' : 'คลาสของฉัน' ?></h2><p>คลาสที่เปิดรับและแบบร่างอยู่ด้านบน</p></div><span class="result-count">แสดง <?= count($classes) ?> คลาส<?= count($classes) === 100 ? ' (สูงสุด 100)' : '' ?></span></div>
                            <form method="get" class="filter-bar filter-bar--compact">
                                <input type="hidden" name="page" value="classes">
                                <label class="search-field"><span data-icon="search"></span><span class="sr-only">ค้นหา</span><input name="q" value="<?= e($classFilters['q']) ?>" placeholder="ค้นหารหัสวิชา ชื่อวิชา หรือห้อง"></label>
                                <label><span class="sr-only">สถานะ</span><select name="status"><option value="">ทุกสถานะ</option><?php foreach (['draft'=>'แบบร่าง','scheduled'=>'รอเวลาเริ่ม','open'=>'เปิดรับลงชื่อ','overdue'=>'หมดเวลาลงชื่อ','closed'=>'ปิดรับแล้ว','cancelled'=>'ยกเลิก'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $classFilters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                                <button class="button button--secondary" type="submit">ค้นหา</button>
                                <?php if (trim($classFilters['q']) !== '' || $classFilters['status'] !== ''): ?><a class="button button--ghost" href="?page=classes">ล้างตัวกรอง</a><?php endif; ?>
                            </form>
                            <?php render_class_table($classes, trim($classFilters['q']) !== '' || $classFilters['status'] !== ''); ?>
                        </section>
                        <section id="new-class" class="form-panel form-panel--sticky" aria-labelledby="new-class-title">
                            <div class="section-heading"><div><p class="eyebrow">สร้าง QR ใหม่</p><h2 id="new-class-title">สร้างคลาสเรียน</h2><p>QR หนึ่งรหัสใช้กับคลาสนี้เท่านั้น</p></div></div>
                            <?php if ($formErrors): ?><div class="alert alert--error" role="alert"><strong>กรุณาตรวจสอบข้อมูล</strong><span><?= e(implode(' ', array_values($formErrors))) ?></span></div><?php endif; ?>
                            <form method="post" class="form-grid" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="create_class">
                                <label class="field field--full"><span>ห้องปฏิบัติการ <b aria-hidden="true">*</b></span><select name="room_id" required><option value="">เลือกห้องปฏิบัติการ</option><?php foreach ($rooms as $room): ?><option value="<?= e($room['id']) ?>" <?= (string)($oldInput['room_id'] ?? $_GET['room_id'] ?? '') === (string)$room['id'] ? 'selected' : '' ?>><?= e($room['code'] . ' — ' . $room['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>รหัสรายวิชา <b aria-hidden="true">*</b></span><input name="course_code" value="<?= e($oldInput['course_code'] ?? '') ?>" placeholder="เช่น CPE221" maxlength="30" required></label>
                                <label class="field"><span>กลุ่มเรียน <small>(ไม่บังคับ)</small></span><input name="section" value="<?= e($oldInput['section'] ?? '') ?>" placeholder="เช่น 1" maxlength="30"></label>
                                <label class="field field--full"><span>ชื่อรายวิชา <b aria-hidden="true">*</b></span><input name="course_name" value="<?= e($oldInput['course_name'] ?? '') ?>" placeholder="เช่น ปฏิบัติการฐานข้อมูล" maxlength="150" required></label>
                                <label class="field"><span>เริ่มคลาส <b aria-hidden="true">*</b></span><input type="datetime-local" name="starts_at" value="<?= e($oldInput['starts_at'] ?? $defaultStart) ?>" required></label>
                                <label class="field"><span>สิ้นสุดคลาส <b aria-hidden="true">*</b></span><input type="datetime-local" name="ends_at" value="<?= e($oldInput['ends_at'] ?? $defaultEnd) ?>" required></label>
                                <label class="field field--full"><span>หมายเหตุ <small>(ไม่บังคับ)</small></span><textarea name="notes" rows="3" maxlength="500" placeholder="รายละเอียดสำหรับอาจารย์"><?= e($oldInput['notes'] ?? '') ?></textarea></label>
                                <div class="form-actions field--full"><button class="button button--primary" type="submit"><span data-icon="qr-code"></span>สร้างคลาสและ QR Code</button></div>
                            </form>
                        </section>
                    </div>

                <?php elseif ($page === 'class-detail'): ?>
                    <?php $classSession = get_class_session((int) ($_GET['id'] ?? 0)); ?>
                    <?php if (!$classSession): ?>
                        <section class="empty-feature"><span data-icon="circle-alert"></span><h1>ไม่พบคลาสเรียน</h1><p>คลาสนี้อาจไม่มีอยู่หรือคุณไม่มีสิทธิ์เปิดดู</p><a class="button button--primary" href="?page=classes">กลับไปหน้าคลาส</a></section>
                    <?php else: $attendance = class_attendance($classSession['id']); $studentUrl = public_class_url($classSession['qr_token']); ?>
                        <header class="page-header"><div><p class="eyebrow"><?= e($classSession['course_code']) ?><?= $classSession['section'] ? ' · กลุ่ม ' . e($classSession['section']) : '' ?></p><h1><?= e($classSession['course_name']) ?></h1><p><?= e($classSession['room_code'] . ' · ' . $classSession['room_name']) ?> · <?= e(thai_datetime($classSession['starts_at'])) ?> – <?= e(thai_datetime($classSession['ends_at'], false)) ?></p></div><a class="button button--secondary" href="?page=classes">กลับไปคลาสทั้งหมด</a></header>
                        <div class="class-detail-layout">
                            <section class="qr-panel" aria-labelledby="qr-title">
                                <?php $detailDisplayStatus = class_display_status($classSession); ?>
                                <div class="section-heading"><div><h2 id="qr-title">QR Code สำหรับนักศึกษา</h2><p>ให้นักศึกษาสแกนเพื่อเปิดหน้าลงชื่อของคลาสนี้</p></div><span class="status status--<?= e($detailDisplayStatus) ?>"><span></span><?= e(status_label($detailDisplayStatus)) ?></span></div>
                                <div class="qr-code-box" data-qr-value="<?= e($studentUrl) ?>" aria-label="QR Code สำหรับคลาส <?= e($classSession['course_code']) ?>"><span class="qr-loading">กำลังสร้าง QR Code…</span></div>
                                <label class="field"><span>ลิงก์สำหรับนักศึกษา</span><span class="copy-field"><input id="student-checkin-url" value="<?= e($studentUrl) ?>" readonly><button class="button button--secondary" type="button" data-copy-target="student-checkin-url">คัดลอก</button></span></label>
                                <p class="helper-text">ลิงก์นี้เปิดได้โดยไม่ต้องล็อกอิน และใช้ลงชื่อได้เฉพาะช่วงเวลาของคลาส</p>
                                <?php if ($classSession['status'] === 'draft'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="open_class"><input type="hidden" name="class_id" value="<?= e($classSession['id']) ?>"><button class="button button--primary button--block" type="submit">เปิดรับการลงชื่อ</button></form><?php elseif ($classSession['status'] === 'open'): ?><form method="post" data-confirm="เมื่อปิดรับแล้ว นักศึกษาจะลงชื่อเพิ่มไม่ได้ ยืนยันหรือไม่?" data-confirm-title="ปิดรับการลงชื่อ" data-confirm-label="ปิดรับลงชื่อ"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_class"><input type="hidden" name="class_id" value="<?= e($classSession['id']) ?>"><button class="button button--secondary button--block" type="submit">ปิดรับการลงชื่อ</button></form><?php endif; ?>
                            </section>
                            <section class="section-block" aria-labelledby="attendance-list-title">
                                <div class="section-heading"><div><h2 id="attendance-list-title">รายชื่อนักศึกษา</h2><p>ข้อมูลอัปเดตเมื่อมีการลงชื่อผ่าน QR Code</p></div><span class="result-count"><?= count($attendance) ?> / <?= e($classSession['capacity']) ?> คน</span></div>
                                <?php render_attendance_table($attendance, false); ?>
                            </section>
                        </div>
                    <?php endif; ?>

                <?php elseif ($page === 'records'): ?>
                    <?php $filters = ['q'=>(string)($_GET['q']??''),'room_id'=>(string)($_GET['room_id']??''),'date_from'=>(string)($_GET['date_from']??''),'date_to'=>(string)($_GET['date_to']??'')]; $result = list_attendance_records($filters); $rooms = list_rooms(); ?>
                    <header class="page-header"><div><p class="eyebrow">ข้อมูลการเข้าเรียน</p><h1>ประวัติการเข้าเรียน</h1><p>ค้นหานักศึกษา รายวิชา ห้อง และเวลาที่ลงชื่อผ่าน QR Code</p></div><a class="button button--primary" href="?page=classes#new-class">สร้างคลาสใหม่</a></header>
                    <form method="get" class="filter-bar">
                        <input type="hidden" name="page" value="records">
                        <label class="search-field"><span data-icon="search"></span><span class="sr-only">ค้นหา</span><input name="q" value="<?= e($filters['q']) ?>" placeholder="ค้นหาชื่อ รหัสนักศึกษา หรือรายวิชา"></label>
                        <label><span class="sr-only">ห้อง</span><select name="room_id"><option value="">ทุกห้อง</option><?php foreach($rooms as $room): ?><option value="<?= e($room['id']) ?>" <?= $filters['room_id']==(string)$room['id']?'selected':'' ?>><?= e($room['code']) ?></option><?php endforeach; ?></select></label>
                        <label class="date-filter"><span>จาก</span><input type="date" name="date_from" value="<?= e($filters['date_from']) ?>"></label>
                        <label class="date-filter"><span>ถึง</span><input type="date" name="date_to" value="<?= e($filters['date_to']) ?>"></label>
                        <button class="button button--secondary" type="submit">ใช้ตัวกรอง</button>
                        <a class="button button--ghost" href="?page=records">ล้าง</a>
                    </form>
                    <section class="section-block">
                        <div class="section-heading"><div><h2>รายการลงชื่อทั้งหมด</h2><p>เรียงจากเวลาล่าสุด</p></div><span class="result-count">พบ <?= e($result['total']) ?> รายการ</span></div>
                        <?php render_attendance_table($result['items'], true, (bool)array_filter($filters, static fn(string $value): bool => trim($value) !== '')); ?>
                    </section>

                <?php elseif ($page === 'rooms'): ?>
                    <?php $rooms = list_rooms(); ?>
                    <header class="page-header"><div><p class="eyebrow">ทรัพยากร</p><h1>ห้องปฏิบัติการ</h1><p>ตรวจสอบรหัสห้อง ตำแหน่ง และสถานะการใช้งานปัจจุบัน</p></div></header>
                    <section class="section-block"><div class="section-heading"><div><h2>รายการห้อง</h2><p>ทั้งหมด <?= count($rooms) ?> ห้อง</p></div></div><div class="rooms-grid"><?php foreach ($rooms as $room): ?><article class="room-card"><div class="room-card-header"><span class="room-code"><?= e($room['code']) ?></span><span class="status status--<?= e($room['live_status']) ?>"><span></span><?= e(status_label($room['live_status'])) ?></span></div><h2><?= e($room['name']) ?></h2><p><?= e($room['building']) ?> · ชั้น <?= e($room['floor']) ?></p><dl><div><dt>ความจุ</dt><dd><?= e($room['capacity']) ?> คน</dd></div><div><dt>สถานะระบบ</dt><dd><?= e(match($room['live_status']) { 'active'=>'มีคลาสกำลังใช้งาน', 'maintenance'=>'งดจัดคลาสชั่วคราว', default=>'พร้อมจัดคลาส' }) ?></dd></div></dl><?php if($room['status']==='available'): ?><a class="text-link" href="?page=classes&amp;room_id=<?= e($room['id']) ?>#new-class">สร้างคลาสในห้องนี้</a><?php else: ?><span class="muted">ยังไม่สามารถสร้างคลาสในห้องนี้</span><?php endif; ?></article><?php endforeach; ?></div></section>

                <?php elseif ($page === 'reports'): ?>
                    <?php
                    $reportPeriod = (string)($_GET['period'] ?? 'month');
                    $reportRoomId = max(0, (int)($_GET['room_id'] ?? 0));
                    $report = report_data($reportPeriod, $reportRoomId);
                    $reportRows = report_class_rows($report['period'], $reportRoomId);
                    $reportRooms = list_rooms();
                    $exportQuery = http_build_query(['download'=>'report-csv','period'=>$report['period'],'room_id'=>$reportRoomId]);
                    ?>
                    <header class="page-header"><div><p class="eyebrow">วิเคราะห์ข้อมูล</p><h1>รายงานการใช้งาน</h1><p>สรุปคลาสและจำนวนผู้ลงชื่อเพื่อวางแผนห้องปฏิบัติการ</p></div><a class="button button--primary" href="?<?= e($exportQuery) ?>"><span data-icon="download"></span>ส่งออก CSV</a></header>
                    <form method="get" class="filter-bar report-filter">
                        <input type="hidden" name="page" value="reports">
                        <label><span>ช่วงเวลา</span><select name="period"><option value="day" <?= $report['period']==='day'?'selected':'' ?>>วันนี้</option><option value="week" <?= $report['period']==='week'?'selected':'' ?>>สัปดาห์นี้</option><option value="month" <?= $report['period']==='month'?'selected':'' ?>>เดือนนี้</option></select></label>
                        <label><span>ห้อง</span><select name="room_id"><option value="0">ทุกห้อง</option><?php foreach($reportRooms as $room): ?><option value="<?= e($room['id']) ?>" <?= $reportRoomId===$room['id']?'selected':'' ?>><?= e($room['code'].' — '.$room['name']) ?></option><?php endforeach; ?></select></label>
                        <button class="button button--secondary" type="submit">แสดงรายงาน</button>
                    </form>
                    <section class="report-overview" aria-labelledby="report-summary-title">
                        <div class="section-heading"><div><h2 id="report-summary-title">สรุป <?= e($report['label']) ?></h2><p>ช่วงวันที่ <?= e($report['date_range']) ?> · <?= $reportRoomId ? 'กรองตามห้องที่เลือก' : 'ทุกห้อง' ?></p></div><span class="result-count"><?= e($report['total']) ?> คลาส</span></div>
                        <div class="report-summary"><span><strong><?= e($report['attendees']) ?></strong>การลงชื่อทั้งหมด</span><span><strong><?= e($report['active']) ?></strong>กำลังเปิดรับลงชื่อ</span><span><strong><?= e($report['overdue']) ?></strong>คลาสเกินเวลา</span><span><strong><?= e($report['completed']) ?></strong>ปิดรับแล้ว</span><span><strong><?= e($report['top_room'] ?: '—') ?></strong>ห้องที่มีผู้ลงชื่อสูงสุด</span></div>
                    </section>
                    <section class="section-block">
                        <div class="section-heading"><div><h2>รายละเอียดคลาส</h2><p>ข้อมูลบนหน้าจอและไฟล์ CSV ใช้ช่วงเวลาและห้องเดียวกัน</p></div><span class="result-count">พบ <?= count($reportRows) ?> รายการ</span></div>
                        <?php if(!$reportRows): ?><div class="empty-state"><span data-icon="inbox"></span><strong>ไม่พบข้อมูลในช่วงที่เลือก</strong><span>ลองเปลี่ยนช่วงเวลาหรือเลือกทุกห้อง</span></div><?php else: ?>
                        <div class="table-wrap"><table class="data-table">
                            <thead><tr><th>รายวิชา</th><th>วันและเวลา</th><th>ห้อง</th><th>ผู้สอน</th><th>ผู้ลงชื่อ</th><th>สถานะ</th></tr></thead>
                            <tbody><?php foreach ($reportRows as $item): $reportStatus = class_display_status($item); ?>
                                <tr>
                                    <td data-label="รายวิชา"><strong><?= e($item['course_code']) ?></strong><small><?= e($item['course_name']) ?><?= $item['section'] ? ' · กลุ่ม ' . e($item['section']) : '' ?></small></td>
                                    <td data-label="วันและเวลา"><strong><?= e(thai_datetime($item['starts_at'])) ?></strong><small>ถึง <?= e(thai_datetime($item['ends_at'], false)) ?></small></td>
                                    <td data-label="ห้อง"><?= e($item['room_code']) ?></td>
                                    <td data-label="ผู้สอน"><?= e($item['lecturer_name']) ?></td>
                                    <td data-label="ผู้ลงชื่อ"><span><?= e($item['attendance_count']) ?> คน</span></td>
                                    <td data-label="สถานะ"><span class="status status--<?= e($reportStatus) ?>"><span></span><?= e(status_label($reportStatus)) ?></span></td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        </table></div>
                        <?php endif; ?>
                    </section>

                <?php else: ?>
                    <section class="empty-feature"><span data-icon="circle-alert"></span><h1>ไม่พบหน้าที่ต้องการ</h1><p>ลิงก์อาจไม่ถูกต้องหรือหน้านี้ถูกย้ายแล้ว</p><a class="button button--primary" href="?page=dashboard">กลับหน้าภาพรวม</a></section>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <div class="nav-scrim" data-nav-scrim hidden></div>
    <script src="assets/qrcode.min.js" defer></script>
    <script src="assets/app.js" defer></script>
</body>
</html>

<?php
function render_class_table(array $items, bool $filtered = false): void
{
    if (!$items) {
        echo $filtered
            ? '<div class="empty-state"><span data-icon="search"></span><strong>ไม่พบคลาสที่ตรงกับตัวกรอง</strong><span>ลองเปลี่ยนคำค้นหรือสถานะ เพื่อดูคลาสที่ต้องการ</span><a class="button button--secondary" href="?page=classes">ล้างตัวกรอง</a></div>'
            : '<div class="empty-state"><span data-icon="inbox"></span><strong>ยังไม่มีคลาสเรียน</strong><span>สร้างคลาสแรกเพื่อรับ QR Code สำหรับนักศึกษา</span><a class="button button--secondary" href="?page=classes#new-class">สร้างคลาสแรก</a></div>';
        return;
    }
?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>รายวิชา</th><th>วันและเวลา</th><th>ห้อง</th><th>ผู้สอน</th><th>นักศึกษา</th><th>สถานะ</th><th><span class="sr-only">การดำเนินการ</span></th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td data-label="รายวิชา"><strong><?= e($item['course_code']) ?></strong><small><?= e($item['course_name']) ?><?= $item['section'] ? ' · กลุ่ม ' . e($item['section']) : '' ?></small></td>
                    <td data-label="วันและเวลา"><strong><?= e(thai_datetime($item['starts_at'])) ?></strong><small>ถึง <?= e(thai_datetime($item['ends_at'], false)) ?></small></td>
                    <td data-label="ห้อง"><strong><?= e($item['room_code']) ?></strong><small><?= e($item['room_name']) ?></small></td>
                    <td data-label="ผู้สอน"><?= e($item['lecturer_name']) ?></td>
                    <td data-label="นักศึกษา"><strong><?= e($item['attendance_count']) ?></strong><small>จาก <?= e($item['capacity']) ?> คน</small></td>
                    <?php $displayStatus = class_display_status($item); ?>
                    <td data-label="สถานะ"><span class="status status--<?= e($displayStatus) ?>"><span></span><?= e(status_label($displayStatus)) ?></span></td>
                    <td class="table-action"><a class="button button--small button--secondary" href="?page=class-detail&id=<?= e($item['id']) ?>">เปิดดู</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}

function render_attendance_table(array $items, bool $showClass, bool $filtered = false): void
{
    if (!$items) {
        echo $filtered
            ? '<div class="empty-state"><span data-icon="search"></span><strong>ไม่พบการลงชื่อที่ตรงกับตัวกรอง</strong><span>ลองเปลี่ยนคำค้น ห้อง หรือช่วงวันที่</span><a class="button button--secondary" href="?page=records">ล้างตัวกรอง</a></div>'
            : '<div class="empty-state"><span data-icon="inbox"></span><strong>ยังไม่มีนักศึกษาลงชื่อ</strong><span>รายการจะปรากฏที่นี่เมื่อมีการส่งแบบฟอร์มผ่าน QR Code</span></div>';
        return;
    }
?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>เวลาลงชื่อ</th><th>รหัสนักศึกษา</th><th>ชื่อ–นามสกุล</th><?php if ($showClass): ?><th>รายวิชา</th><th>ห้อง</th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($items as $item): ?><tr>
                <td data-label="เวลาลงชื่อ"><strong><?= e(thai_datetime($item['check_in_at'])) ?></strong><small>ผ่าน QR Code</small></td>
                <td data-label="รหัสนักศึกษา"><strong><?= e($item['student_code']) ?></strong></td>
                <td data-label="ชื่อ–นามสกุล"><?= e($item['student_name']) ?></td>
                <?php if ($showClass): ?><td data-label="รายวิชา"><a class="text-link" href="?page=class-detail&id=<?= e($item['class_id']) ?>"><?= e($item['course_code']) ?></a><small><?= e($item['course_name']) ?></small></td><td data-label="ห้อง"><strong><?= e($item['room_code']) ?></strong><small><?= e($item['room_name']) ?></small></td><?php endif; ?>
            </tr><?php endforeach; ?></tbody>
        </table>
    </div>
<?php
}
