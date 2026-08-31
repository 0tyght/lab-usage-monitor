<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function asset_url(string $filename): string
{
    // Content-based URLs prevent mixed old/new UI files after a deployment.
    return 'assets/'.$filename.'?v='.substr(hash_file('sha256', __DIR__.'/assets/'.$filename),0,12);
}

function redirect_to(string $page, array $params = []): never
{
    $query = http_build_query(['page' => $page] + $params);
    header('Location: ?' . $query);
    exit;
}

function redirect_class_panel(int $id): never
{
    if (($_GET['page'] ?? '') === 'calendar') {
        $month = valid_iso_date((string)($_POST['class_date'] ?? '')) ? substr($_POST['class_date'],0,7) : ($_GET['month'] ?? date('Y-m'));
        redirect_to('calendar',['month'=>$month,'class_id'=>$id]);
    }
    redirect_to('classes',array_intersect_key($_GET,array_flip(['q','status']))+['class_id'=>$id]);
}

function redirect_schedule_panel(int $id): never
{
    $scheduleContext = array_intersect_key($_GET, array_flip(['room_id','week','weekend','q']));
    $saved = get_course_schedule($id);
    if (!$saved) redirect_to('schedule');
    $week = week_start_date((string)($scheduleContext['week'] ?? ''));
    $occurrence = $week->modify('+'.($saved['day_of_week']-1).' days')->format('Y-m-d');
    if ($occurrence < $saved['active_from'] || $occurrence > $saved['active_until']) {
        $first = new DateTimeImmutable($saved['active_from']);
        $first = $first->modify('+'.(($saved['day_of_week']-(int)$first->format('N')+7)%7).' days');
        $scheduleContext['week'] = week_start_date($first->format('Y-m-d'))->format('Y-m-d');
    }
    if ($saved['day_of_week'] > 5) $scheduleContext['weekend'] = 1;
    if (!empty($scheduleContext['room_id'])) $scheduleContext['room_id'] = $saved['room_id'];
    if (!empty($scheduleContext['q']) && stripos(implode(' ',[$saved['course_code'],$saved['course_name'],$saved['lecturer_name'],$saved['room_code']]),$scheduleContext['q']) === false) unset($scheduleContext['q']);
    redirect_to('schedule', ['term_id'=>$saved['term_id'], 'selected'=>$id] + $scheduleContext);
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
        'scheduled' => 'รอเวลาเปิดรับ',
        'overdue' => 'สิ้นสุดเวลารับอัตโนมัติ',
        'cancelled' => 'ยกเลิก',
        default => $status,
    };
}

function class_display_status(array $classSession): string
{
    return class_checkin_status($classSession);
}

function csv_safe_value(mixed $value): string
{
    $value = (string)$value;
    return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) ? "'" . $value : $value;
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
    $date = valid_iso_date((string)$value)
        ? new DateTimeImmutable((string)$value, $timezone)
        : new DateTimeImmutable('today', $timezone);
    return $date->modify('monday this week');
}

if (($_GET['fragment'] ?? '') === 'class-panel') {
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=UTF-8');
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'],['admin','lecturer'],true)) { http_response_code(401); echo 'กรุณาเข้าสู่ระบบอีกครั้ง'; exit; }
    $panelClass = get_class_session((int)($_GET['id'] ?? 0));
    if (!$panelClass) { http_response_code(404); echo 'ไม่พบคลาสหรือไม่มีสิทธิ์เข้าถึง'; exit; }
    require __DIR__.'/views/class-panel.php';
    exit;
}

if (($_GET['api'] ?? '') === 'one-off-availability') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'], ['admin','lecturer'], true)) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'กรุณาเข้าสู่ระบบอีกครั้ง']);
        exit;
    }
    try {
        $roomId = (int)($_GET['room_id'] ?? 0);
        $lecturerId = one_off_lecturer_id($_GET);
        if (!array_filter(list_rooms(), static fn(array $r): bool => $r['id']===$roomId && $r['status']==='available') || !array_filter(list_lecturers(), static fn(array $u): bool => $u['id']===$lecturerId)) throw new InvalidArgumentException();
        echo json_encode(['ok'=>true,'busy'=>one_off_busy_times($roomId,$lecturerId,(string)($_GET['date'] ?? ''))], JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'message'=>'ตรวจเวลาไม่สำเร็จ กรุณาตรวจวันที่ ห้อง และผู้สอน แล้วลองอีกครั้ง'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (in_array($_GET['api'] ?? '', ['schedule-preview','class-batch-preview','class-change-preview'],true)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $viewer = current_user();
    $previewToken = $_POST['csrf_token'] ?? null;
    $previewCsrfValid = is_string($previewToken) && is_string($_SESSION['_csrf_token'] ?? null)
        && (int)($_SESSION['_csrf_issued_at'] ?? 0) >= time() - (int)app_config('security.csrf_ttl',7200)
        && hash_equals($_SESSION['_csrf_token'], $previewToken);
    if (!$viewer || !in_array($viewer['role'], ['admin','lecturer'], true)) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'กรุณาเข้าสู่ระบบอีกครั้ง']);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$previewCsrfValid) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
    } else {
        try {
            $preview = match ($_GET['api']) {
                'class-batch-preview' => class_batch_preview($_POST),
                'class-change-preview' => class_change_preview($_POST),
                default => validate_schedule_input($_POST),
            };
            if ($_GET['api']==='schedule-preview' && $viewer['role']==='lecturer' && isset($preview['errors']['form'])) $preview['errors']['form']='ห้องหรือผู้สอนมีรายการในช่วงนี้แล้ว กรุณาเลือกเวลาอื่น';
            echo json_encode(['ok'=>$preview['ok'], 'count'=>$preview['count'] ?? 0, 'protected'=>$preview['protected'] ?? 0, 'lessons'=>array_map(static fn($r)=>['date'=>$r['date'] ?? substr($r['starts_at'],0,10),'room'=>$r['room_code'],'start'=>$r['starts_time'] ?? thai_datetime($r['starts_at']),'end'=>$r['ends_time'] ?? thai_datetime($r['ends_at'])],array_slice($preview['rows'] ?? [],0,12)), 'errors'=>$preview['errors'] ?? [], 'message'=>$preview['ok'] ? ($preview['message'] ?? 'ห้องและผู้สอนไม่ชนกับรายการอื่นในช่วงที่เลือก') : implode(' ', array_values($preview['errors'] ?? []))], JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            http_response_code(503);
            echo json_encode(['ok'=>false,'message'=>'ตรวจตารางไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }
    }
    exit;
}

if (($_GET['health'] ?? '') === '1') {
    header('Cache-Control: no-store');
    header('Vary: Origin');
    $gatewayOrigin = (string) app_config('app.gateway_origin', '');
    if ($gatewayOrigin !== '' && ($_SERVER['HTTP_ORIGIN'] ?? '') === $gatewayOrigin) {
        // Only the status endpoint is cross-origin; login and records stay same-origin.
        header('Access-Control-Allow-Origin: ' . $gatewayOrigin);
    }
    try {
        db()->query('SELECT 1')->fetchColumn();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'ok','service'=>'lums','gatewayId'=>(string)app_config('app.gateway_id', '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    $filters = planning_filters($_GET, true);
    $report = usage_report($filters);
    if ($report['errors']) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo implode(' ', $report['errors']);
        exit;
    }
    $table = usage_report_table($report);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="lums-report-' . $filters['date_from'] . '-' . $filters['date_to'] . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    $roomLabel = 'ทุกห้อง';
    foreach (list_rooms() as $room) if ($room['id'] === $filters['room_id']) $roomLabel = $room['code'];
    $metadata = [
        ['รายงานการใช้ห้อง LUMS'],
        ['ช่วงวันที่', $filters['date_from'], $filters['date_to']],
        ['ช่วงเวลาในแต่ละวัน (UTC+7)', $filters['time_from'], $filters['time_to']],
        ['ห้อง', $roomLabel],
        ['ปี/ภาค', $filters['term_id'] ? get_academic_term($filters['term_id'])['name'] : 'ทุกภาค'],
        ['ข้อมูล', ['all'=>'แผนและคลาสที่สร้าง', 'classes'=>'คลาสที่สร้างแล้ว', 'schedule'=>'แผนที่ยังไม่สร้างคลาส'][$filters['source']]],
        ['หน่วย', $report['unit_label'], $filters['unit']==='periods' ? '1 คาบ = '.$filters['period_minutes'].' นาที' : '1 ชั่วโมง = 60 นาที'],
        ['รวม', number_format($report['quantity'], 2, '.', ''), 'รายการไม่ซ้ำ', $report['events']],
        ['คำค้น', $filters['q'], 'เรียงลำดับ', $filters['sort']==='desc' ? 'มากไปน้อย' : 'น้อยไปมาก'],
        ['หมายเหตุ', 'เวลาตามกำหนดการเฉพาะช่วงที่กรอง ไม่ใช่เวลาตรวจวัดจริง; การลงชื่อเป็นยอดทั้งคลาสนับครั้งเดียว'],
        [],
    ];
    foreach (array_merge($metadata, [$table['headers']], $table['rows']) as $row) fputcsv($output, array_map('csv_safe_value', $row));
    fclose($output);
    exit;
}

if (($_GET['download'] ?? '') === 'room-visits-csv') {
    require_auth('admin');
    $visitReport=room_visit_report($_GET);
    if ($visitReport['errors']) { http_response_code(422); echo e(implode(' ',$visitReport['errors'])); exit; }
    $visitTable=room_visit_table($visitReport); $vf=$visitReport['filters'];
    header('Content-Type: text/csv; charset=UTF-8'); header('Cache-Control: no-store'); header('Content-Disposition: attachment; filename="lums-room-visits-'.$vf['date_from'].'-'.$vf['date_to'].'.csv"');
    echo "\xEF\xBB\xBF"; $out=fopen('php://output','wb');
    $metadata=[['รายงานเข้าใช้นอกคลาส LUMS'],['ช่วงวันที่เข้า',$vf['date_from'],$vf['date_to']],['ช่วงเวลาเข้า (UTC+7)',$vf['time_from'],$vf['time_to']],['ห้อง ID',$vf['room_id'] ?: 'ทุกห้อง'],['สถานะ',$vf['visit_status'] ?: 'ทุกสถานะ'],['คำค้น',$vf['q']],['หน่วย',$vf['unit'],'นาทีต่อคาบ',$vf['period_minutes']],['เรียงลำดับ',$vf['sort']],['หมายเหตุ','ระยะเวลารายบุคคลจากเวลาที่ผู้ใช้กดเข้า–ออก ไม่ใช่ชั่วโมงครองห้อง; รายการค้างหรือผู้ดูแลปิดไม่คำนวณระยะเวลา'],[]];
    foreach (array_merge($metadata,[$visitTable['headers']],$visitTable['rows']) as $row) fputcsv($out,array_map('csv_safe_value',$row)); fclose($out); exit;
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

    if (in_array($action,['room_visit_start','room_visit_end'],true)) {
        $token=(string)($_POST['token'] ?? '');
        $holder=$_SESSION['room_visit_holders'][$token] ?? null;
        if ($action==='room_visit_start') {
            $result=$holder && hash_equals($holder['request'],(string)($_POST['client_request_id'] ?? '')) ? record_room_visit($token,$_POST,$holder['receipt']) : visit_error('แบบฟอร์มหมดอายุ กรุณาเปิดหน้าห้องอีกครั้ง');
            if ($result['ok']) { $_SESSION['room_visit_holders'][$token]['visit_id']=$result['id']; remember_room_visit($token,$holder['receipt']); }
        } else $result=$holder ? checkout_room_visit($holder['receipt']) : visit_error('ไม่พบรายการของเบราว์เซอร์นี้');
        set_flash($result['ok']?'success':'error',$result['ok']?($action==='room_visit_start'?'บันทึกการเข้าใช้แล้ว':'บันทึกเวลาออกแล้ว'):'ยังบันทึกไม่ได้',$result['message'] ?? '');
        if (!$result['ok']) $_SESSION['room_visit_input']=array_intersect_key($_POST,array_flip(['person_code','person_name','person_role','purpose']));
        redirect_to('room-checkin',['token'=>$token]);
    }

    require_auth(['admin', 'lecturer']);

    if ($action==='room_visit_admin_end') {
        require_auth('admin');
        $result=checkout_room_visit('',(int)($_POST['visit_id'] ?? 0),(string)($_POST['checkout_note'] ?? ''));
        set_flash($result['ok']?'success':'error',$result['ok']?'ปิดรายการแล้ว':'ปิดรายการไม่สำเร็จ',$result['message'] ?? 'บันทึกว่าเป็นการปิดโดยผู้ดูแล ไม่แทนเวลาออกที่ผู้ใช้ยืนยัน');
        redirect_to('reports',['tab'=>'walkins']+room_visit_report_query($_GET));
    }

    if ($action === 'logout') {
        logout_user();
        redirect_to('login');
    }

    if ($action === 'create_class_batch') {
        $requestId = (string)($_POST['one_off_request'] ?? '');
        $known = $_SESSION['one_off_requests'][$requestId] ?? null;
        if (is_array($known) && isset($known['id'])) redirect_to('classes',['series'=>$known['series_key'],'range'=>'all','class_id'=>$known['id']]);
        $result = $known === 0 ? create_class_batch($_POST) : class_batch_error('แบบฟอร์มหมดอายุ กรุณาเปิดหน้าต่างสร้างคลาสเรียนใหม่');
        if ($result['ok']) {
            $_SESSION['one_off_requests'][$requestId] = $result;
            set_flash('success','สร้างคลาสเรียนแล้ว','บันทึก '.$result['count'].' คลาส ใช้ QR ประจำห้องลงชื่อได้ เปิดรับอัตโนมัติก่อนเรียน 10 นาที');
            redirect_to('classes',['series'=>$result['series_key'],'range'=>'all']);
        }
        $_SESSION['one_off_errors']=$result['errors'];
        $_SESSION['one_off_input']=array_intersect_key($_POST,array_flip(['class_mode','academic_year','semester','class_date','room_id','lecturer_user_id','course_code','course_name','section','notes','slots_json','one_off_request']));
        redirect_to(in_array($_GET['page'] ?? '',['schedule','calendar','classes'],true)?$_GET['page']:'classes',array_intersect_key($_GET,array_flip(['term_id','room_id','week','weekend','month']))+['new_once'=>1]);
    }

    if ($action === 'change_class_batch') {
        $result=apply_class_change($_POST);
        if ($result['ok']) {
            set_flash('success','บันทึกการเปลี่ยนแปลงแล้ว',$result['message']);
            redirect_to('classes',['range'=>'all','class_id'=>(int)$_POST['class_id']]);
        }
        $_SESSION['class_change_errors']=$result['errors'];
        $_SESSION['class_change_input']=array_intersect_key($_POST,array_flip(['class_id','scope','operation','room_id','class_date','starts_time','ends_time','notes']));
        redirect_to('classes',['edit_class'=>(int)$_POST['class_id']]);
    }

    if ($action === 'create_one_off') {
        $requestId = (string)($_POST['one_off_request'] ?? '');
        $known = $_SESSION['one_off_requests'][$requestId] ?? null;
        if (is_int($known) && $known > 0 && get_class_session($known)) redirect_class_panel($known);
        $mode = (string)($_POST['class_mode'] ?? 'once');
        if (is_string($known) && preg_match('/^schedule:(\d+)$/', $known, $savedMatch)) redirect_schedule_panel((int)$savedMatch[1]);
        $result = $known === 0 ? match ($mode) {
            'once' => create_one_off_session($_POST),
            'semester' => create_course_schedule($_POST),
            default => ['ok'=>false,'errors'=>['class_mode'=>'กรุณาเลือกครั้งเดียวหรือทั้งภาคเรียน']],
        } : ['ok'=>false,'errors'=>['form'=>'แบบฟอร์มหมดอายุ กรุณาเปิดหน้าต่างสร้างคลาสเรียนใหม่']];
        if ($result['ok']) {
            if ($mode === 'semester') {
                $_SESSION['one_off_requests'][$requestId] = 'schedule:'.$result['id'];
                set_flash('success','สร้างคลาสเรียนทั้งภาคเรียนแล้ว','บันทึกการใช้ห้องในวันและเวลาเดิมทุกสัปดาห์ตลอดภาคเรียนแล้ว เลือกวันที่ในตารางเพื่อเตรียม QR ของแต่ละครั้ง');
                redirect_schedule_panel((int)$result['id']);
            }
            $_SESSION['one_off_requests'][$requestId] = (int)$result['id'];
            set_flash('success','สร้างคลาสเรียนแล้ว','บันทึกเฉพาะวันที่เลือก ไม่มีการทำซ้ำ และเตรียม QR สำหรับคลาสเรียนนี้แล้ว');
            redirect_class_panel((int)$result['id']);
        }
        $_SESSION['one_off_errors'] = $result['errors'] ?? ['form'=>'บันทึกไม่สำเร็จ กรุณาลองอีกครั้ง'];
        $_SESSION['one_off_input'] = array_intersect_key($_POST,array_flip(['room_id','lecturer_user_id','class_date','starts_time','ends_time','course_code','course_name','section','notes','checkin_mode','one_off_request','class_mode','term_id','day_of_week']));
        $returnPage = in_array($_GET['page'] ?? '', ['schedule','classes'],true) ? $_GET['page'] : 'calendar';
        $context = array_intersect_key($_GET,array_flip(['month','term_id','room_id','week','weekend','q','source']));
        redirect_to($returnPage,$context+['new_once'=>1]);
    }

    if ($action === 'create_class') {
        $result = create_class_session($_POST);
        if ($result['ok']) {
            set_flash('success', 'สร้างคลาสเรียนแล้ว', $result['message'] ?? 'เตรียม QR Code เรียบร้อย');
            redirect_class_panel((int)$result['id']);
        }
        $_SESSION['one_off_errors'] = $result['errors'] ?? ['form' => $result['message'] ?? 'ไม่สามารถบันทึกข้อมูลได้'];
        $_SESSION['one_off_input'] = array_intersect_key($_POST,array_flip(['room_id','lecturer_user_id','course_code','course_name','section','notes','checkin_mode']));
        $_SESSION['one_off_input'] += ['class_date'=>substr((string)($_POST['starts_at'] ?? ''),0,10),'starts_time'=>substr((string)($_POST['starts_at'] ?? ''),11,5),'ends_time'=>substr((string)($_POST['ends_at'] ?? ''),11,5)];
        redirect_to('classes',['new_once'=>1]);
    }

    if ($action === 'close_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $result = close_class_session($classId);
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'ปิดรับลงชื่อแล้ว' : 'ดำเนินการไม่สำเร็จ', $result['message'] ?? '');
        redirect_class_panel($classId);
    }

    if ($action === 'open_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $result = open_class_session($classId, (string)($_POST['checkin_mode'] ?? 'scheduled'));
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'เปิดรับลงชื่อแล้ว' : 'ดำเนินการไม่สำเร็จ', $result['message'] ?? '');
        redirect_class_panel($classId);
    }

    if ($action === 'create_term') {
        $result = create_academic_term($_POST);
        if ($result['ok']) {
            set_flash('success', 'สร้างภาคการศึกษาแล้ว', $result['message']);
            redirect_to('schedule', ['term_id'=>(int)$result['id']]);
        }
        $_SESSION['term_form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถสร้างภาคการศึกษาได้'];
        $_SESSION['term_old_input'] = array_intersect_key($_POST, array_flip(['academic_year', 'semester']));
        $context = array_intersect_key($_GET, array_flip(['term_id', 'room_id', 'week', 'weekend', 'q', 'selected']));
        redirect_to('schedule', $context + ['new_term'=>1]);
    }

    if ($action === 'create_schedule') {
        $result = create_course_schedule($_POST);
        $scheduleContext = array_intersect_key($_GET, array_flip(['room_id','week','weekend','q']));
        if ($result['ok']) {
            set_flash('success', 'เพิ่มตารางเรียนรายสัปดาห์แล้ว', 'ระบบตรวจสอบห้องและเวลาไม่ให้ชนกันเรียบร้อย');
            redirect_schedule_panel((int)$result['id']);
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถเพิ่มตารางเรียนได้'];
        $_SESSION['old_input'] = $_POST;
        redirect_to('schedule', ['term_id'=>(int)($_POST['term_id'] ?? 0), 'new_schedule'=>1] + $scheduleContext);
    }

    if ($action === 'cancel_schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $result = cancel_course_schedule($scheduleId);
        set_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'ยกเลิกตารางเรียนแล้ว' : 'ยกเลิกตารางไม่ได้', $result['message'] ?? '');
        redirect_to('schedule', ['term_id'=>(int)($_POST['term_id'] ?? 0)] + array_intersect_key($_GET,array_flip(['room_id','week','weekend','q'])));
    }

    if ($action === 'import_schedule') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        $result = import_course_schedule_csv($termId, $_FILES['schedule_file'] ?? [], $_POST);
        if ($result['ok']) {
            set_flash('success', 'นำเข้าตารางเรียนแล้ว', $result['message']);
            redirect_to('schedule', ['term_id'=>$result['term_id'] ?? $termId] + array_intersect_key($_GET,array_flip(['room_id','week','weekend','q'])));
        }
        $_SESSION['form_errors'] = $result['errors'] ?? ['form'=>$result['message'] ?? 'ไม่สามารถนำเข้าตารางเรียนได้'];
        $_SESSION['import_term_key'] = (string)($_POST['academic_term_key'] ?? '');
        redirect_to('schedule', ['term_id'=>$termId, 'import'=>1] + array_intersect_key($_GET,array_flip(['room_id','week','weekend','q'])));
    }

    if ($action === 'create_schedule_session') {
        $result = create_session_from_schedule((int)($_POST['schedule_id'] ?? 0), (string)($_POST['scheduled_date'] ?? ''));
        if ($result['ok']) {
            set_flash('success', $result['existing'] ?? false ? 'มี QR สำหรับคลาสเรียนนี้แล้ว' : 'เตรียม QR เรียบร้อย', $result['message']);
            redirect_class_panel((int)$result['id']);
        }
        set_flash('error', 'สร้าง QR ไม่สำเร็จ', $result['message'] ?? 'กรุณาตรวจสอบวันที่คลาสเรียน');
        redirect_to('schedule', ['selected'=>(int)($_POST['schedule_id'] ?? 0)]);
    }
}

$user = current_user();
$page = (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'login'));

if ($page === 'login' && $user) {
    redirect_to('dashboard');
}

if (!in_array($page, ['login', 'student-checkin','room-checkin'], true)) {
    require_auth(['admin', 'lecturer']);
    $user = current_user();
}

// Keep old bookmarked class links working without a separate QR/detail page.
if ($page === 'class-detail') redirect_to('classes',['class_id'=>(int)($_GET['id'] ?? 0)]);
$allowedPages = ['login', 'student-checkin','room-checkin', 'dashboard', 'schedule', 'calendar', 'classes', 'records', 'rooms', 'reports'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'not-found';
}

$flash = pull_flash();
$formErrors = $_SESSION['form_errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old_input']);

if ($page === 'room-checkin') { require __DIR__.'/views/room-checkin.php'; exit; }

if ($page === 'student-checkin'):
    $token = trim((string) ($_GET['token'] ?? ''));
    $classSession = get_public_class_by_token($token);
    $nowUtc = time();
    $attendanceReceipt = $_SESSION['attendance_success'] ?? [];
    $hasSavedAttendance = isset($_GET['success']) && ($attendanceReceipt['token'] ?? null) === $token;
    $canCheckIn = $classSession && class_checkin_status($classSession, $nowUtc)==='open';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B2545">
    <title>ลงชื่อเข้าเรียน — LUMS</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
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
                    <?php $waitingToOpen = in_array(class_checkin_status($classSession),['draft','scheduled'],true); $autoEnded=class_checkin_status($classSession)==='overdue'; ?>
                    <div class="student-success student-success--muted"><span data-icon="clock"></span><h2 id="attendance-title"><?= $waitingToOpen ? 'ยังไม่เปิดรับการลงชื่อ' : ($autoEnded?'สิ้นสุดเวลารับอัตโนมัติ':'ปิดรับการลงชื่อแล้ว') ?></h2><p><?= $waitingToOpen ? ((int)($classSession['admission_lead_minutes'] ?? 0)===10 ? 'เปิดรับอัตโนมัติก่อนเรียน 10 นาที กดตรวจสอบอีกครั้งเมื่อถึงเวลา' : 'เมื่อถึงเวลาเรียนหรืออาจารย์แจ้งให้ลงชื่อ กดตรวจสอบอีกครั้ง') : 'หากผู้สอนเปิดรับเพิ่มเติม ให้กดตรวจสอบอีกครั้ง เวลาที่ลงชื่อจะบันทึกตามจริง' ?></p><a class="button button--secondary" href="?page=student-checkin&amp;token=<?= e($token) ?>">ตรวจสอบอีกครั้ง</a></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script src="<?= e(asset_url('app.js')) ?>" defer></script>
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
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand" aria-labelledby="brand-title">
            <div class="brand-mark" aria-hidden="true">LU</div>
            <div>
                <p class="eyebrow">Laboratory Usage Monitoring System</p>
                <h1 id="brand-title">จัดการการใช้ห้องปฏิบัติการ<br>ให้ตรวจสอบได้ในที่เดียว</h1>
                <p class="auth-intro">จัดตารางเรียน ติด QR ประจำห้อง ติดตามการลงชื่อทั้งในคลาสและนอกคลาส และเรียกดูรายงานการใช้ห้อง</p>
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
                <p class="muted">สำหรับอาจารย์และผู้ดูแลระบบเท่านั้น นักศึกษาลงชื่อผ่าน QR Code หน้าห้อง</p>

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
    <script src="<?= e(asset_url('app.js')) ?>" defer></script>
</body>
</html>
<?php
exit;
endif;

$nav = [
    'dashboard' => ['ภาพรวม', 'layout-dashboard'],
    'schedule' => ['ตารางเรียน', 'calendar-days'],
    'calendar' => ['ปฏิทินการใช้ห้อง', 'calendar-days'],
    'classes' => ['คลาสเรียน', 'book-open'],
    'rooms' => ['ห้องปฏิบัติการ', 'door-open'],
    'records' => ['ประวัติการเข้าเรียน', 'history'],
    'reports' => ['รายงาน', 'chart-no-axes-combined'],
];
$navPage = $page;
$oneOffContext = array_intersect_key($_GET,array_flip(['month','term_id','room_id','week','weekend','q','source']));
$oneOffReturnUrl = '?' . http_build_query(['page'=>in_array($page,['schedule','classes'],true) ? $page : 'calendar']+$oneOffContext);
$oneOffOpenUrl = $oneOffReturnUrl . '&new_once=1';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B2545">
    <title><?= e($nav[$navPage][0] ?? 'LUMS') ?> — LUMS</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('planning.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('class-panel.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('class-batch.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('room-qr.css')) ?>">
    <?php if ($page === 'schedule'): ?><link rel="stylesheet" href="<?= e(asset_url('timetable.css')) ?>"><?php endif; ?>
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
                <?php if (!in_array($page,['schedule','calendar','classes','records'],true)): ?><a class="button button--primary button--compact" href="?page=classes&amp;new_once=1"><span data-icon="plus" aria-hidden="true"></span>สร้างคลาสเรียน</a><?php endif; ?>
            </header>
            <main id="main-content" class="content <?= $page === 'schedule' ? 'content--schedule' : '' ?>">
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
                        <article class="metric"><span>ห้องที่มีคลาสตามตารางขณะนี้</span><strong><?= e($data['rooms_in_use']) ?></strong><small>จาก <?= e($data['room_total']) ?> ห้อง · ดูการลงชื่อในรายงาน</small></article>
                        <?php if ($data['issues_total']): ?>
                            <a class="metric metric--warning metric--link" href="?page=classes&amp;status=overdue" aria-label="สิ้นสุดเวลารับอัตโนมัติ <?= e($data['issues_total']) ?> คลาส"><span>สิ้นสุดเวลารับอัตโนมัติ</span><strong><?= e($data['issues_total']) ?></strong><small>ดูรายชื่อ หรือเปิดรับเพิ่มเติม <span aria-hidden="true">→</span></small></a>
                        <?php else: ?>
                            <article class="metric"><span>สิ้นสุดเวลารับอัตโนมัติ</span><strong>0</strong><small>ไม่มีรายการ</small></article>
                        <?php endif; ?>
                    </section>
                    <div class="dashboard-grid">
                        <section class="section-block">
                            <div class="section-heading"><div><h2>สถานะห้องปฏิบัติการ</h2><p>อิงช่วงเวลาเรียนของคลาส การปิดรับลงชื่อไม่ทำให้การจองห้องสิ้นสุดก่อนเวลา</p></div><a href="?page=rooms" class="text-link">ดูทุกห้อง</a></div>
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
                            <a class="quick-action" href="?page=classes&amp;new_once=1"><span class="quick-icon" data-icon="plus"></span><span><strong>สร้างคลาสเรียน</strong><small>เปิดรับให้นักศึกษาลงชื่อ</small></span><span data-icon="chevron-right"></span></a>
                            <a class="quick-action" href="?page=records"><span class="quick-icon" data-icon="search"></span><span><strong>ค้นหาการเข้าเรียน</strong><small>กรองตามนักศึกษา ห้อง และช่วงเวลา</small></span><span data-icon="chevron-right"></span></a>
                            <a class="quick-action" href="?page=reports"><span class="quick-icon" data-icon="download"></span><span><strong>ออกรายงาน</strong><small>สรุปข้อมูลการใช้งาน</small></span><span data-icon="chevron-right"></span></a>
                        </section>
                    </div>
                    <section class="section-block recent-section">
                        <div class="section-heading"><div><h2>คลาสล่าสุด</h2><p>เปิดดู QR Code และรายชื่อนักศึกษา</p></div><span class="result-count"><?= count($data['recent']) ?> คลาส</span></div>
                        <?php render_class_table($data['recent']); ?>
                    </section>

                <?php elseif ($page === 'calendar'): ?>
                    <?php require __DIR__ . '/views/calendar.php'; ?>
                <?php elseif ($page === 'schedule'): ?>
                    <?php require __DIR__ . '/views/schedule.php'; ?>


                <?php elseif ($page === 'classes'): ?>
                    <?php require __DIR__.'/views/classes.php'; ?>


                <?php elseif ($page === 'records'): ?>
                    <?php if ($user['role']==='admin'): ?><p><a class="button button--secondary" href="?page=reports&amp;tab=walkins">ดูการเข้าใช้นอกคลาส</a></p><?php endif; ?>
                    <?php $filters = ['q'=>(string)($_GET['q']??''),'room_id'=>(string)($_GET['room_id']??''),'date_from'=>(string)($_GET['date_from']??''),'date_to'=>(string)($_GET['date_to']??'')]; $result = list_attendance_records($filters); $rooms = list_rooms(); ?>
                    <header class="page-header"><div><p class="eyebrow">ข้อมูลการเข้าเรียน</p><h1>ประวัติการเข้าเรียน</h1><p>ค้นหานักศึกษา รายวิชา ห้อง และเวลาที่ลงชื่อผ่าน QR Code</p></div><a class="button button--primary" href="?page=classes&amp;new_once=1">สร้างคลาสเรียน</a></header>
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
                    <?php require __DIR__.'/views/rooms.php'; ?>

                <?php elseif ($page === 'reports'): ?>
                    <?php if ($user['role']==='admin'): ?><nav class="report-tabs" aria-label="ประเภทรายงาน"><a class="button button--<?= ($_GET['tab'] ?? '')==='walkins'?'secondary':'primary' ?>" href="?page=reports">ตารางและคลาสเรียน</a><a class="button button--<?= ($_GET['tab'] ?? '')==='walkins'?'primary':'secondary' ?>" href="?page=reports&amp;tab=walkins">การเข้าใช้นอกคลาส</a></nav><?php endif; ?>
                    <?php require __DIR__ . (($_GET['tab'] ?? '')==='walkins'?'/views/room-visit-report.php':'/views/reports.php'); ?>

                <?php else: ?>
                    <section class="empty-feature"><span data-icon="circle-alert"></span><h1>ไม่พบหน้าที่ต้องการ</h1><p>ลิงก์อาจไม่ถูกต้องหรือหน้านี้ถูกย้ายแล้ว</p><a class="button button--primary" href="?page=dashboard">กลับหน้าภาพรวม</a></section>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php if (in_array($page,['schedule','calendar','classes'],true)): require __DIR__.'/views/one-off-dialog.php'; endif; ?>
    <?php require __DIR__.'/views/class-dialog.php'; ?>
    <?php require __DIR__.'/views/class-change-dialog.php'; ?>
    <div class="nav-scrim" data-nav-scrim hidden></div>
    <script src="<?= e(asset_url('qrcode.min.js')) ?>" defer></script>
    <script src="<?= e(asset_url('app.js')) ?>" defer></script>
    <script src="<?= e(asset_url('planning.js')) ?>" defer></script>
    <script src="<?= e(asset_url('one-off.js')) ?>" defer></script>
    <script src="<?= e(asset_url('class-panel.js')) ?>" defer></script>
    <script src="<?= e(asset_url('class-change.js')) ?>" defer></script>
    <script src="<?= e(asset_url('room-qr.js')) ?>" defer></script>
</body>
</html>

<?php
function render_class_table(array $items, bool $filtered = false): void
{
    if (!$items) {
        echo $filtered
            ? '<div class="empty-state"><span data-icon="search"></span><strong>ไม่พบคลาสที่ตรงกับตัวกรอง</strong><span>ลองเปลี่ยนคำค้นหรือสถานะ เพื่อดูคลาสที่ต้องการ</span><a class="button button--secondary" href="?page=classes">ล้างตัวกรอง</a></div>'
            : '<div class="empty-state"><span data-icon="inbox"></span><strong>ยังไม่มีคลาสเรียน</strong><span>สร้างคลาสเรียนเพื่อให้นักศึกษาสแกน QR หน้าห้องแล้วเลือกลงชื่อได้</span><a class="button button--secondary" href="?page=classes&amp;new_once=1">สร้างคลาสเรียน</a></div>';
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
                    <td class="table-action"><a class="button button--small button--secondary" href="?page=classes&amp;class_id=<?= e($item['id']) ?>" data-class-id="<?= (int)$item['id'] ?>" aria-haspopup="dialog" aria-controls="class-info-dialog">QR / รายชื่อ</a></td>
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
                <?php if ($showClass): ?><td data-label="รายวิชา"><a class="text-link" data-class-id="<?= (int)$item['class_id'] ?>" href="?page=classes&amp;class_id=<?= e($item['class_id']) ?>"><?= e($item['course_code']) ?></a><small><?= e($item['course_name']) ?></small></td><td data-label="ห้อง"><strong><?= e($item['room_code']) ?></strong><small><?= e($item['room_name']) ?></small></td><?php endif; ?>
            </tr><?php endforeach; ?></tbody>
        </table>
    </div>
<?php
}
