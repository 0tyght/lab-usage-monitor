<?php

declare(strict_types=1);

// Explicit opt-in and a synthetic administrator: never run on the real database.
if (PHP_SAPI !== 'cli' || getenv('LUMS_HTTP_TEST') !== '1' || getenv('LUMS_ADMIN_EMAIL') !== 'admin@example.invalid') {
    exit(1);
}

$cookies = [];
$checks = 0;
$expect = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};
$request = static function (string $path, ?array $data = null, ?string $csvUpload = null) use (&$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    $content=$data === null ? '' : http_build_query($data);
    if ($csvUpload !== null) {
        $boundary='LumsTest'.bin2hex(random_bytes(12));
        $headers=['Content-Type: multipart/form-data; boundary='.$boundary];
        $content='';
        foreach ($data ?? [] as $name=>$value) $content.="--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        $content.="--{$boundary}\r\nContent-Disposition: form-data; name=\"schedule_file\"; filename=\"test.csv\"\r\nContent-Type: text/csv\r\n\r\n{$csvUpload}\r\n--{$boundary}--\r\n";
    }
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', $cookies);
    $context = stream_context_create(['http'=>[
        'method'=>$data === null ? 'GET' : 'POST', 'timeout'=>5,
        'ignore_errors'=>true, 'follow_location'=>0,
        'header'=>implode("\r\n", $headers),
        'content'=>$content,
    ]]);
    $body = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $location = '';
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[1] . '=' . $match[2];
        if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
    }
    preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0] ?? '', $status);
    return [(int)($status[1] ?? 0), (string)$body, $location];
};
$csrf = static function (string $body): string {
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match)) throw new RuntimeException('Missing CSRF form field.');
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};

[, $login] = $request('/?page=login');
[$status] = $request('/?page=login', ['action'=>'login', 'csrf_token'=>$csrf($login), 'email'=>'admin@example.invalid', 'password'=>getenv('LUMS_ADMIN_PASSWORD')]);
$expect($status === 302, 'Synthetic admin can sign in');
[, $empty] = $request('/?page=schedule');
$expect(str_contains($empty, 'ยังไม่มีภาคการศึกษา'), 'Fresh database shows the empty-term state');
$expect(!str_contains($empty, 'data-schedule-form'), 'No unusable schedule form before the first term');
$expect(str_contains($empty, 'data-open-term'), 'Empty-term state offers the popup action');
[, $fallback] = $request('/?page=schedule&new_term=1');
$expect((bool)preg_match('/<dialog[^>]+\bopen\b/', $fallback), 'Direct link opens the form without JavaScript');

$context = '/?page=schedule&room_id=2&week=2026-08-31&weekend=1&q=TEST';
[$status, , $location] = $request($context, ['action'=>'create_term', 'csrf_token'=>$csrf($fallback), 'term_starts_on'=>'"><script>test</script>', 'academic_year'=>'2499']);
$expect($status === 302 && str_contains($location, 'new_term=1'), 'Invalid form redirects back to the dialog');
$expect(str_contains($location, 'room_id=2') && str_contains($location, 'week=2026-08-31') && str_contains($location, 'weekend=1') && str_contains($location, 'q=TEST'), 'Validation preserves timetable filters');
[, $invalid] = $request('/' . $location);
$expect(str_contains($invalid, 'data-term-error-summary'), 'Server errors have a dedicated dialog summary');
$expect(str_contains($invalid, '&lt;script&gt;test&lt;/script&gt;') && !str_contains($invalid, '<script>test</script>'), 'Submitted draft is retained and safely escaped');
$expect(str_contains($invalid, 'value="2499"') && str_contains($invalid, 'aria-invalid="true"'), 'Invalid fields retain their values and accessible error state');

$term = ['action'=>'create_term', 'academic_year'=>'2569', 'semester'=>'2', 'term_starts_on'=>'2026-11-01', 'term_ends_on'=>'2027-03-31', 'dates_confirmed'=>'1'];
[$status, , $location] = $request($context, $term + ['csrf_token'=>$csrf($invalid)]);
$expect($status === 302 && str_contains($location, 'term_id=') && !str_contains($location, 'new_term'), 'Success selects the new term and closes the dialog');
[, $success] = $request('/' . $location);
$expect(str_contains($success, 'สร้างภาคการศึกษาแล้ว'), 'Success feedback is visible');
$expect(!preg_match('/<dialog[^>]+\bopen\b/', $success), 'Dialog is closed after a successful save');
[$status, , $location] = $request($context, $term + ['csrf_token'=>$csrf($success)]);
[, $duplicate] = $request('/' . $location);
$expect($status === 302 && str_contains($duplicate, 'มีปีและภาคการศึกษานี้อยู่แล้ว'), 'Repeated save returns a duplicate-term error');
$expect(!preg_match('/id="schedule-form".*?alert--error/s', strstr($duplicate, '<div class="schedule-admin-stack">', true)), 'Term errors do not leak into the schedule form');
require dirname(__DIR__) . '/src/bootstrap.php';
$expect((int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn() === 1, 'Duplicate save does not create a second record');
echo "Term dialog HTTP checks passed: $checks\n";

// Same disposable database: verify calendar, report rendering and CSV as one flow.
$roomId = (int)db()->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
$adminId = (int)db()->query("SELECT id FROM users WHERE email='admin@example.invalid'")->fetchColumn();
$termId = (int)db()->query('SELECT id FROM academic_terms LIMIT 1')->fetchColumn();
$fixture = db()->prepare("INSERT INTO course_schedules (term_id,room_id,lecturer_user_id,course_code,course_name,day_of_week,starts_time,ends_time,active_from,active_until,created_at,updated_at) VALUES (?,?,?,'HTTP101',?,1,'09:00','11:00','2026-11-01','2026-11-30',?,?)");
$fixture->execute([$termId, $roomId, $adminId, 'ทดสอบรายงานสมมติ', utc_now(), utc_now()]);
[, $calendar] = $request('/?page=calendar&month=2026-11&date=2026-11-02&term_id='.$termId);
$expect(str_contains($calendar, 'data-calendar-day="2026-11-02"') && str_contains($calendar, 'ทดสอบรายงานสมมติ'), 'Calendar includes weekly occurrences and daily details');
$expect((bool)preg_match('/<dialog[^>]+\bopen\b/', $calendar), 'Daily timetable has a server-rendered popup fallback');
$expect(!str_contains($calendar, 'qr_token') && !str_contains($calendar, 'password_hash'), 'Calendar payload excludes QR secrets and credentials');
$filters = ['date_from'=>'2026-11-02','date_to'=>'2026-11-30','room_id'=>$roomId,'term_id'=>$termId,'source'=>'all','time_from'=>'09:30','time_to'=>'10:30','unit'=>'periods','period_minutes'=>50,'group'=>'detail','q'=>'HTTP101','sort'=>'desc'];
[, $html] = $request('/?page=reports&'.http_build_query($filters));
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
$xpath = new DOMXPath($dom);
$uiRows = [];
foreach ($xpath->query('//table[contains(@class,"report-data-table")]/tbody/tr') as $tr) {
    $row = [];
    foreach ($xpath->query('./td', $tr) as $td) $row[] = trim($td->textContent);
    $uiRows[] = $row;
}
$expect(count($uiRows) === 5 && $uiRows[0][0] === '2026-11-30', 'Report finds five Mondays and respects descending order');
$expect($uiRows[0][1] === '09:30' && $uiRows[0][2] === '10:30' && $uiRows[0][9] === '1.20', 'Report clips time and calculates equivalent periods');
[$status, $csv] = $request('/?download=report-csv&'.http_build_query($filters));
$lines = array_map('str_getcsv', explode("\n", trim(substr($csv,3))));
$headerIndex = null;
foreach ($lines as $i=>$row) if (($row[0] ?? '') === 'วันที่' && count($row) === 11) $headerIndex = $i;
$expect($status === 200 && str_starts_with($csv,"\xEF\xBB\xBF") && $headerIndex !== null, 'CSV is downloadable UTF-8 with Thai headers');
$expect(array_slice($lines, $headerIndex+1) === $uiRows, 'CSV rows exactly match the filtered, sorted screen');
[, $print] = $request('/?page=reports&print=1&'.http_build_query($filters));
$expect(str_contains($print, 'data-print-report') && str_contains($print, '2026-11-30'), 'Printable report preserves data and offers PDF print action');
[$status] = $request('/?download=report-csv&date_from=2026-02-30');
$expect($status === 422, 'CSV rejects invalid date filters');
[, $emptyReport] = $request('/?page=reports&'.http_build_query(array_replace($filters,['q'=>'not-a-course'])));
$expect(str_contains($emptyReport,'ไม่พบข้อมูลตามตัวกรอง'), 'Report search has a useful empty state');
$formula = " \t=1+1";
db()->prepare('UPDATE course_schedules SET course_name=? WHERE course_code=?')->execute([$formula,'HTTP101']);
[, $safeCsv] = $request('/?download=report-csv&'.http_build_query($filters));
$expect(str_contains($safeCsv, "'".$formula), 'Export neutralizes formulas even after leading whitespace');
echo "Term and planning HTTP checks passed: $checks\n";

[, $onceForm] = $request('/?page=calendar&new_once=1&once_date=2030-05-06&room_id='.$roomId);
$expect(str_contains($onceForm,'id="one-off-dialog"') && str_contains($onceForm,'value="2030-05-06"'), 'One-off popup retains the selected calendar date');
preg_match('/name="one_off_request" value="([a-f0-9]+)"/',$onceForm,$onceToken);
$onceInput=['action'=>'create_one_off','one_off_request'=>$onceToken[1],'csrf_token'=>$csrf($onceForm),'room_id'=>$roomId,'lecturer_user_id'=>$adminId,'class_date'=>'2030-05-06','starts_time'=>'09:00','ends_time'=>'10:00','course_code'=>'ONCEHTTP','course_name'=>'คาบครั้งเดียวทดสอบ'];
[$status, , $onceLocation] = $request('/?page=calendar',$onceInput);
$expect($status===302 && str_contains($onceLocation,'class_id=') && str_contains($onceLocation,'page=calendar'), 'One-off save opens its QR popup on the calendar');
[$status, , $replayLocation] = $request('/?page=calendar',$onceInput);
$expect($status===302 && $replayLocation===$onceLocation && (int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='ONCEHTTP'")->fetchColumn()===1, 'Duplicate POST returns the same class, not a second record');
[$status,$availability] = $request('/?api=one-off-availability&date=2030-05-06&room_id='.$roomId.'&lecturer_user_id='.$adminId);
$busyResult=json_decode($availability,true);
$expect($status===200 && $busyResult['ok'] && $busyResult['busy'][0]['start']===540, 'Availability endpoint returns occupied minutes');
$expect(!str_contains($availability,'ONCEHTTP') && !str_contains($availability,'qr_token'),'Availability does not expose course or QR details');
[, $onceAgain] = $request('/?page=schedule&new_once=1');
preg_match('/name="one_off_request" value="([a-f0-9]+)"/',$onceAgain,$newToken);
[, , $conflictLocation] = $request('/?page=schedule',array_replace($onceInput,['one_off_request'=>$newToken[1],'csrf_token'=>$csrf($onceAgain)]));
[, $conflictHtml] = $request('/'.$conflictLocation);
$expect(str_contains($conflictLocation,'new_once=1') && str_contains($conflictHtml,'data-once-errors') && str_contains($conflictHtml,'value="ONCEHTTP"'),'Conflict retains the draft in the popup');
[, $onceCalendar] = $request('/?page=calendar&month=2030-05');
$expect(str_contains($onceCalendar,'ONCEHTTP') && str_contains($onceCalendar,'คาบครั้งเดียว'),'Saved one-off appears in the calendar');
[, $onceWeek] = $request('/?page=schedule&week=2030-05-06');
$expect(str_contains($onceWeek,'ONCEHTTP') && str_contains($onceWeek,'คาบครั้งเดียวในสัปดาห์นี้'),'Weekly page includes standalone classes');
echo "All term/planning/one-off HTTP checks passed: $checks\n";
$onceClassId=(int)db()->query("SELECT id FROM class_sessions WHERE course_code='ONCEHTTP'")->fetchColumn();
[$status, , $legacyLocation]=$request('/?page=class-detail&id='.$onceClassId);
$expect($status===302 && str_contains($legacyLocation,'page=classes') && str_contains($legacyLocation,'class_id='), 'Old detail links redirect to the class hub popup');
[$status,$fragment]=$request('/?fragment=class-panel&id='.$onceClassId);
$expect($status===200 && str_contains($fragment,'data-class-qr=') && str_contains($fragment,'data-download-qr') && str_contains($fragment,'data-print-qr'), 'Authorized popup includes QR, PNG export and print controls');
$expect(str_contains($fragment,'class-panel-attendance') && str_contains($fragment,'data-qr-poster'), 'QR poster and attendance occupy separate sections');
[, $cleanCalendar]=$request('/?page=calendar&month=2030-05&room_id=999999&term_id=999999&source=schedule&q=impossible');
$cleanDom=new DOMDocument();@$cleanDom->loadHTML('<?xml encoding="UTF-8">'.$cleanCalendar);$cleanXPath=new DOMXPath($cleanDom);
$expect($cleanXPath->query('//section[@class="calendar-page"]//form')->length===0 && str_contains($cleanCalendar,'ONCEHTTP'), 'Calendar has no filter form and ignores obsolete filters');
$expect(str_contains($cleanCalendar,'data-print-calendar') && str_contains($cleanCalendar,'download=report-csv'), 'Calendar exposes print and monthly export');
$expect(str_contains($cleanCalendar,'data-day-csv') && str_contains($cleanCalendar,'data-print-day'), 'Daily timetable exposes selected-room export and print');
$expect((bool)preg_match('/assets\/class-panel\.js\?v=[a-f0-9]{12}/',$cleanCalendar) && (bool)preg_match('/assets\/planning\.js\?v=[a-f0-9]{12}/',$cleanCalendar), 'Changed UI assets use content-versioned URLs');
$cookies=[];
[$status]=$request('/?fragment=class-panel&id='.$onceClassId);
$expect($status===401,'Anonymous visitors cannot fetch QR or attendance fragments');
// A second synthetic account cannot read the administrator's QR/attendance.
db()->prepare("INSERT INTO users (full_name,email,role,password_hash,is_active,created_at,updated_at) VALUES ('อาจารย์ทดสอบสิทธิ์','other@example.invalid','lecturer',?,1,?,?)")->execute([password_hash('Test-Only-Other-2569',PASSWORD_DEFAULT),utc_now(),utc_now()]);
[, $otherLogin]=$request('/?page=login');
$request('/?page=login',['action'=>'login','csrf_token'=>$csrf($otherLogin),'email'=>'other@example.invalid','password'=>'Test-Only-Other-2569']);
[$status,$otherFragment]=$request('/?fragment=class-panel&id='.$onceClassId);
$expect($status===404 && !str_contains($otherFragment,'data-class-qr'),'Another lecturer cannot retrieve this class QR or attendance');
$cookies=[];
[$status] = $request('/?api=one-off-availability&date=2030-05-06&room_id='.$roomId);
$expect($status===401,'Anonymous visitors cannot query booking availability');
echo "Authenticated planning HTTP checks passed: $checks\n";

// Admission lifecycle through the same endpoints used by the UI; synthetic data only.
[, $login]=$request('/?page=login');
$request('/?page=login',['action'=>'login','csrf_token'=>$csrf($login),'email'=>'admin@example.invalid','password'=>getenv('LUMS_ADMIN_PASSWORD')]);
[, $classHub]=$request('/?page=classes&new_once=1');
$expect(str_contains($classHub,'data-one-off-form') && str_contains($classHub,'data-once-range') && str_contains($classHub,'name="checkin_mode"'),'Class creation uses the shared multi-hour form and explicit admission policy');
[,,$oldError]=$request('/?page=classes',['action'=>'create_class','csrf_token'=>$csrf($classHub),'course_code'=>'KEEPME','course_name'=>'สมมติ','starts_at'=>'2030-05-06T09:00','ends_at'=>'2030-05-06T13:00']);
[, $oldDraft]=$request('/'.$oldError);
$expect(str_contains($oldError,'new_once=1') && str_contains($oldDraft,'value="KEEPME"') && str_contains($oldDraft,'data-once-errors'),'Old creation endpoint retains failed input in the shared dialog');
$pastStart=gmdate('Y-m-d\TH:i:s\Z',time()-14400); $pastEnd=gmdate('Y-m-d\TH:i:s\Z',time()-7200);
db()->prepare("UPDATE class_sessions SET starts_at=?,ends_at=?,status='open',checkin_mode='scheduled' WHERE id=?")->execute([$pastStart,$pastEnd,$onceClassId]);
[, $expiredPanel]=$request('/?fragment=class-panel&id='.$onceClassId);
$expect(str_contains($expiredPanel,'สิ้นสุดเวลารับอัตโนมัติ') && str_contains($expiredPanel,'ยังไม่ได้กดปิดเอง'),'Expired automatic admission explains why it stopped');
$admit=['action'=>'open_class','class_id'=>$onceClassId,'csrf_token'=>$csrf($expiredPanel),'checkin_mode'=>'scheduled'];
$request('/?page=classes',$admit);
$expect(db()->query('SELECT checkin_mode FROM class_sessions WHERE id='.$onceClassId)->fetchColumn()==='scheduled','Failed expired opening cannot silently switch policy');
$request('/?page=classes',array_replace($admit,['checkin_mode'=>'manual']));
[, $manualPanel]=$request('/?fragment=class-panel&id='.$onceClassId);
$expect(str_contains($manualPanel,'ผู้สอนกดเปิดและปิดเอง') && !str_contains($manualPanel,'สิ้นสุดเวลารับอัตโนมัติ'),'Manual opening is consistently shown in the QR panel');
$class=db()->query('SELECT * FROM class_sessions WHERE id='.$onceClassId)->fetch();
$expect($class['starts_at']===$pastStart && $class['ends_at']===$pastEnd,'HTTP opening does not rewrite the lesson');
$adminCookies=$cookies; $cookies=[];
$publicPath='/?page=student-checkin&token='.$class['qr_token'];
[, $studentForm]=$request($publicPath);
$expect(str_contains($studentForm,'name="student_code"'),'Anonymous student can access manual admission after lesson end');
$student=['action'=>'student_attendance','csrf_token'=>$csrf($studentForm),'token'=>$class['qr_token'],'student_code'=>'99998888','student_name'=>'นิสิตสมมติ ทดสอบ HTTP','client_request_id'=>'http-lifecycle-request-0001'];
[$status,,$receipt]=$request($publicPath,$student);
[, $receiptHtml]=$request('/'.$receipt);
$expect($status===302 && str_contains($receiptHtml,'บันทึกเรียบร้อยแล้ว'),'Manual sign-in returns an actual success receipt');
$request($publicPath,$student);
$expect((int)db()->query('SELECT COUNT(*) FROM attendance_records WHERE class_session_id='.$onceClassId)->fetchColumn()===1,'Repeated HTTP check-in creates exactly one attendance record');
$cookies=$adminCookies;
$request('/?page=classes',['action'=>'close_class','csrf_token'=>$admit['csrf_token'],'class_id'=>$onceClassId]);
$cookies=[];
[, $closedForm]=$request($publicPath);
$expect(str_contains($closedForm,'ปิดรับการลงชื่อแล้ว') && !str_contains($closedForm,'name="student_code"') && str_contains($closedForm,'ตรวจสอบอีกครั้ง'),'Closed public page blocks new submission and offers refresh');
$cookies=$adminCookies;
$request('/?page=classes',array_replace($admit,['checkin_mode'=>'manual']));
$cookies=[];
[, $reopened]=$request($publicPath);
$expect(str_contains($reopened,'name="student_code"'),'Same public QR works after explicit reopening');
$cookies=$adminCookies;

// Real multipart uploads verify validation and all-or-nothing import.
$importTerm=(int)db()->query("SELECT id FROM academic_terms WHERE academic_year=2569 AND semester='2'")->fetchColumn();
[, $scheduleHtml]=$request('/?page=schedule&term_id='.$importTerm);
$importData=['action'=>'import_schedule','term_id'=>$importTerm,'csrf_token'=>$csrf($scheduleHtml)];
$csvHeader='course_code,course_name,section,room_code,lecturer_email,day_of_week,starts_time,ends_time,active_from,active_until,notes';
$roomCode=db()->query('SELECT code FROM rooms WHERE id='.$roomId)->fetchColumn();
$row="CSVGOOD,สมมตินำเข้า,1,{$roomCode},admin@example.invalid,7,17:00,19:00,2026-11-01,2026-11-30,";
[,,$badCsv]=$request('/?page=schedule',$importData,$csvHeader."\n".$row.',extra');
[, $badCsvHtml]=$request('/'.$badCsv);
$expect(str_contains($badCsvHtml,'จำนวนคอลัมน์เกินหัวตาราง'),'Extra CSV columns return a readable validation error, not HTTP 500');
[,,$duplicateHeader]=$request('/?page=schedule',$importData,$csvHeader.",course_code\n".$row.',DUP');
[, $duplicateHtml]=$request('/'.$duplicateHeader);
$expect(str_contains($duplicateHtml,'ชื่อคอลัมน์ซ้ำ'),'Duplicate CSV headers are rejected');
[,,$atomicFailure]=$request('/?page=schedule',$importData,$csvHeader."\n".$row."\n\n".str_replace('CSVGOOD','CSVCONFLICT',$row));
[, $atomicHtml]=$request('/'.$atomicFailure);
$expect(str_contains($atomicHtml,'แถว 4:') && (int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code LIKE 'CSV%'")->fetchColumn()===0,'Conflict rolls back the whole import and reports the actual CSV line');
$request('/?page=schedule',$importData,$csvHeader."\n".$row);
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='CSVGOOD'")->fetchColumn()===1,'Corrected CSV imports successfully after rollback');
echo "HTTP workflow checks passed: $checks\n";
