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
$expect(str_contains($empty,'data-horizontal-timetable') && !str_contains($empty,'data-open-term'),'Fresh timetable is usable with no separate add-term action');
[, $fallback]=$request('/?page=schedule&new_once=1');
$termDom=new DOMDocument(); @$termDom->loadHTML('<?xml encoding="UTF-8">'.$fallback); $termXPath=new DOMXPath($termDom);
$expect($termXPath->query('//*[@id="term-settings"]')->length===0 && $termXPath->query('//*[@data-one-off-form]//select[@name="academic_year"]/option')->length===2,'Year and semester live inside the class form; no term-creation dialog');
$expect($termXPath->query('//*[@data-one-off-form]//select[@name="semester"]/option')->length===3,'All three semester choices are available without creating them first');
$expect($termXPath->query('//*[@data-class-picker]')->length===1 && $termXPath->query('//*[@data-class-slots]')->length===1,'One graphical picker supports multiple selected ranges');
$context='/?page=schedule';
$term=['action'=>'create_term','academic_year'=>'2569','semester'=>'2'];
$request($context,$term+['csrf_token'=>$csrf($fallback)]);
$request($context,$term+['csrf_token'=>$csrf($fallback)]);
require dirname(__DIR__).'/src/bootstrap.php';
$expect((int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn()===1,'Legacy term endpoint still prevents duplicates');
$savedTerm=db()->query('SELECT * FROM academic_terms LIMIT 1')->fetch();
$expect($savedTerm['starts_on']==='2026-11-16' && $savedTerm['ends_on']==='2027-03-21','Official dates are preserved');
$forgedTerm=['action'=>'create_term','academic_year'=>'2569','semester'=>'1','term_starts_on'=>'1900-01-01','term_ends_on'=>'1900-02-01','csrf_token'=>$csrf($fallback)];
$request($context,$forgedTerm);
$forgedSaved=db()->query("SELECT starts_on,ends_on FROM academic_terms WHERE academic_year=2569 AND semester='1'")->fetch();
$expect($forgedSaved['starts_on']==='2026-06-22' && $forgedSaved['ends_on']==='2026-10-25','Tampered dates cannot override the catalog');
$countBeforeUnknown=(int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn();
$request($context,array_replace($forgedTerm,['academic_year'=>'2570']));
$expect((int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn()===$countBeforeUnknown,'Unpublished years cannot be invented');

// Same disposable database: verify calendar, report rendering and CSV as one flow.
$roomId = (int)db()->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
$adminId = (int)db()->query("SELECT id FROM users WHERE email='admin@example.invalid'")->fetchColumn();
$termId = (int)$savedTerm['id'];
$fixture = db()->prepare("INSERT INTO course_schedules (term_id,room_id,lecturer_user_id,course_code,course_name,day_of_week,starts_time,ends_time,active_from,active_until,created_at,updated_at) VALUES (?,?,?,'HTTP101',?,1,'09:00','11:00','2026-11-16','2026-11-30',?,?)");
$fixture->execute([$termId, $roomId, $adminId, 'ทดสอบรายงานสมมติ', utc_now(), utc_now()]);
[, $calendar] = $request('/?page=calendar&month=2026-11&date=2026-11-16&term_id='.$termId);
$expect(str_contains($calendar, 'data-calendar-day="2026-11-16"') && str_contains($calendar, 'ทดสอบรายงานสมมติ'), 'Calendar includes weekly occurrences and daily details');
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
$expect(count($uiRows) === 3 && $uiRows[0][0] === '2026-11-30', 'Report finds the three Mondays within the official term and respects descending order');
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
$onceInput=['action'=>'create_one_off','one_off_request'=>$onceToken[1],'csrf_token'=>$csrf($onceForm),'room_id'=>$roomId,'lecturer_user_id'=>$adminId,'class_date'=>'2030-05-06','starts_time'=>'09:00','ends_time'=>'10:00','course_code'=>'ONCEHTTP','course_name'=>'คลาสเรียนทดสอบ'];
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
$expect(str_contains($onceCalendar,'ONCEHTTP') && str_contains($onceCalendar,'คลาสเรียนแบบครั้งเดียว'),'Saved one-off appears in the calendar');
[, $onceWeek] = $request('/?page=schedule&week=2030-05-06');
$expect(str_contains($onceWeek,'ONCEHTTP') && str_contains($onceWeek,'คลาสเรียนแบบครั้งเดียวในสัปดาห์นี้'),'Weekly page includes standalone classes');
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
$expect(str_contains($classHub,'data-one-off-form') && str_contains($classHub,'data-class-slots') && str_contains($classHub,'ก่อนเวลาเรียน 10 นาที'),'Class creation uses the shared multi-hour form and explicit admission policy');
$expect(str_contains($classHub,'กดช่องสีเขียวที่เลือกแล้วเพื่อเอาชั่วโมงนั้นออก'),'Class picker explains that a selected hour can be toggled off');
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
$row="CSVGOOD,สมมตินำเข้า,1,{$roomCode},admin@example.invalid,7,17:00,19:00,2026-11-16,2026-11-30,";
[,,$outsideTerm]=$request('/?page=schedule',$importData,$csvHeader."\n".str_replace('2026-11-16','2026-11-01',$row));
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='CSVGOOD'")->fetchColumn()===0 && str_contains($outsideTerm,'page=schedule'),'CSV cannot start before the locked semester date');
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
foreach (['classes','calendar','schedule'] as $copyPage) {
    [, $copyHtml]=$request('/?page='.$copyPage.'&new_once=1');
    $copyDom=new DOMDocument(); @$copyDom->loadHTML('<?xml encoding="UTF-8">'.$copyHtml);
    $copyXPath=new DOMXPath($copyDom);
    $expect($copyXPath->evaluate('string(//*[@id="one-off-title"])')==='สร้างคลาสเรียน','Shared dialog uses canonical title on '.$copyPage);
    $expect(trim($copyXPath->evaluate('string(//*[@id="one-off-dialog"]//button[@type="submit"])'))==='สร้างคลาสเรียน','Submit uses canonical action on '.$copyPage);
    $copyActions=$copyXPath->query('//*[@data-open-once]');
    $sameLabel=$copyActions->length>0;
    foreach ($copyActions as $copyAction) $sameLabel=$sameLabel && trim($copyAction->textContent)==='สร้างคลาสเรียน';
    $expect($sameLabel && !str_contains($copyHtml,'เพิ่มคาบ'),'Entry points use one action name on '.$copyPage);
}
echo "HTTP workflows and terminology checks passed: $checks\n";

// Horizontal timetable workflow, preview-only validation, and modal recovery.
[, $weekHtml]=$request('/?page=schedule&term_id='.$importTerm.'&week=2026-11-16');
$weekDom=new DOMDocument(); @$weekDom->loadHTML('<?xml encoding="UTF-8">'.$weekHtml); $weekXPath=new DOMXPath($weekDom);
$expect($weekXPath->query('//*[@data-horizontal-timetable]')->length===1 && $weekXPath->query('//*[contains(@class,"schedule-hour") and @class="schedule-hour"]')->length===12,'Timetable has twelve horizontal hour columns');
$expect($weekXPath->query('//*[@data-schedule-date]')->length===5,'Weekdays are five separate date rows');
$expect($weekXPath->query('//aside[contains(@class,"schedule-context")]')->length===0 && !str_contains($weekHtml,'academic-year-slots'),'Timetable no longer has an empty sidebar or duplicate term selector');
$expect($weekXPath->query('//*[@data-one-off-form]/ancestor::dialog')->length===1 && $weekXPath->query('//*[@id="schedule-import"]')->length===1 && $weekXPath->query('//*[@id="schedule-editor"]')->length===0,'One unified creation dialog and a separate bulk import dialog');
[$status,$invalidWeek]=$request('/?page=schedule&term_id='.$importTerm.'&week=2026-99-99');
$expect($status===200 && str_contains($invalidWeek,'data-horizontal-timetable'),'Invalid week query falls back safely instead of crashing');
$previewInput=['term_id'=>$importTerm,'room_id'=>$roomId,'lecturer_user_id'=>$adminId,'course_code'=>'TIME102','course_name'=>'ทดสอบตารางแนวนอน','day_of_week'=>1,'starts_time'=>'09:30','ends_time'=>'12:00','active_from'=>'2026-11-16','active_until'=>'2026-11-30','csrf_token'=>$csrf($weekHtml)];
$scheduleCount=(int)db()->query('SELECT COUNT(*) FROM course_schedules')->fetchColumn();
[$status,$previewBody]=$request('/?api=schedule-preview',$previewInput);
$expect($status===200 && !json_decode($previewBody,true)['ok'],'Preview catches recurring conflicts before saving');
$expect((int)db()->query('SELECT COUNT(*) FROM course_schedules')->fetchColumn()===$scheduleCount,'Preview cannot create timetable records');
[$status]=$request('/?api=schedule-preview',array_replace($previewInput,['csrf_token'=>'invalid']));
$expect($status===403,'Preview requires valid CSRF and returns JSON rather than a redirect');
$otherRoom=(int)db()->query('SELECT id FROM rooms WHERE id<>'.$roomId.' ORDER BY id LIMIT 1')->fetchColumn();
$otherLecturer=(int)db()->query("SELECT id FROM users WHERE email='other@example.invalid'")->fetchColumn();
$parallelInput=array_replace($previewInput,['room_id'=>$otherRoom,'lecturer_user_id'=>$otherLecturer]);
[$status,$previewBody]=$request('/?api=schedule-preview',$parallelInput);
$expect($status===200 && json_decode($previewBody,true)['ok'],'Different room and lecturer can use parallel times');
$expect((int)db()->query('SELECT COUNT(*) FROM course_schedules')->fetchColumn()===$scheduleCount,'Successful preview is also read-only');
[$status,,$scheduleSaved]=$request('/?page=schedule&term_id='.$importTerm.'&week=2026-11-16&room_id='.$otherRoom,$parallelInput+['action'=>'create_schedule']);
$expect($status===302 && str_contains($scheduleSaved,'selected=') && str_contains($scheduleSaved,'week=2026-11-16') && str_contains($scheduleSaved,'room_id='.$otherRoom),'Save preserves selected room/week and opens details');
[, $savedDetails]=$request('/'.$scheduleSaved);
$expect((bool)preg_match('/<dialog id="schedule-detail"[^>]*open/',$savedDetails),'Saved recurring item opens a detail popup');
[, $parallelHtml]=$request('/?page=schedule&term_id='.$importTerm.'&week=2026-11-16');
$expect(str_contains($parallelHtml,'--lane-count:2') && str_contains($parallelHtml,'--event-lane:1'),'Concurrent classrooms render in separate lanes');
[$status,,$scheduleRejected]=$request('/?page=schedule&term_id='.$importTerm.'&week=2026-11-16&room_id='.$otherRoom,$parallelInput+['action'=>'create_schedule']);
[, $scheduleFailed]=$request('/'.$scheduleRejected);
$expect(str_contains($scheduleRejected,'new_schedule=1') && str_contains($scheduleRejected,'room_id='.$otherRoom) && str_contains($scheduleFailed,'value="TIME102"') && str_contains($scheduleFailed,'data-once-errors'),'Legacy recurring errors recover in the unified dialog with room/week context');
$adminCookies=$cookies; $cookies=[];
[$status]=$request('/?api=schedule-preview',$previewInput);
$expect($status===401,'Anonymous callers cannot inspect timetable conflicts');
$cookies=$adminCookies;
echo "HTTP workflows and horizontal timetable checks passed: $checks\n";

// Unified creation: no empty semester records, full-term conflicts and replay protection.
[, $unifiedHtml]=$request('/?page=schedule&term_id='.$importTerm.'&new_once=1');
$unifiedDom=new DOMDocument(); @$unifiedDom->loadHTML('<?xml encoding="UTF-8">'.$unifiedHtml); $unifiedXPath=new DOMXPath($unifiedDom);
$expect($unifiedXPath->query('//input[@name="class_mode"]')->length===2 && !str_contains($unifiedHtml,'เพิ่มตารางเรียนรายสัปดาห์'),'One creation entry offers once and semester modes at the top');
$expect($unifiedXPath->query('//*[@data-one-off-form]//input[@name="active_from" or @name="active_until"]')->length===0,'Full-term class form has no editable term dates');
preg_match('/name="one_off_request" value="([a-f0-9]+)"/',$unifiedHtml,$unifiedToken);
$unifiedInput=['action'=>'create_one_off','class_mode'=>'semester','one_off_request'=>$unifiedToken[1],'csrf_token'=>$csrf($unifiedHtml),'term_id'=>$importTerm,'room_id'=>$roomId,'lecturer_user_id'=>$adminId,'day_of_week'=>2,'starts_time'=>'09:00','ends_time'=>'13:00','course_code'=>'FULLTERM','course_name'=>'ทดสอบคลาสทั้งภาคเรียน','active_from'=>'2026-11-24','active_until'=>'2026-11-24'];
$beforeSchedules=(int)db()->query('SELECT COUNT(*) FROM course_schedules')->fetchColumn();
$beforeTerms=(int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn();
[$status,,$missingRoom]=$request('/?page=schedule',array_replace($unifiedInput,['room_id'=>'']));
[, $missingHtml]=$request('/'.$missingRoom);
$expect($status===302 && str_contains($missingHtml,'data-once-errors') && str_contains($missingHtml,'value="semester" checked'),'Missing room retains semester mode and draft in the dialog');
$expect((int)db()->query('SELECT COUNT(*) FROM course_schedules')->fetchColumn()===$beforeSchedules && (int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn()===$beforeTerms,'Incomplete full-term creation writes neither an empty schedule nor a term');
[$status,,$savedUnified]=$request('/?page=schedule&week=2026-11-16',$unifiedInput);
$full=db()->query("SELECT * FROM course_schedules WHERE course_code='FULLTERM'")->fetch();
$official=get_academic_term($importTerm);
$expect($status===302 && str_contains($savedUnified,'selected=') && $full && $full['active_from']===$official['starts_on'] && $full['active_until']===$official['ends_on'],'Semester saves the full configured term even when posted dates try to shorten it');
$expect($full['day_of_week']===2 && $full['room_id']===$roomId && $full['starts_time']==='09:00' && $full['ends_time']==='13:00','Semester persists selected room, weekday and four-hour range');
foreach (['2026-11-17','2027-03-16'] as $occurrenceDate) {
    [, $occurrenceHtml]=$request('/?page=calendar&month='.substr($occurrenceDate,0,7).'&date='.$occurrenceDate);
    $expect(str_contains($occurrenceHtml,'FULLTERM'),'Full-term room reservation appears in calendar on '.$occurrenceDate);
}
$request('/?page=schedule',$unifiedInput);
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='FULLTERM'")->fetchColumn()===1,'Replaying full-term submit cannot duplicate the schedule');
[, $nextForm]=$request('/?page=classes&new_once=1');
preg_match('/name="one_off_request" value="([a-f0-9]+)"/',$nextForm,$nextToken);
$collision=array_replace($unifiedInput,['one_off_request'=>$nextToken[1],'course_code'=>'WHOLECHECK','day_of_week'=>1,'starts_time'=>'09:30','ends_time'=>'10:30','active_from'=>'2027-03-01','active_until'=>'2027-03-01']);
[$status,$collisionPreview]=$request('/?api=schedule-preview',$collision);
$expect($status===200 && !json_decode($collisionPreview,true)['ok'],'Full-term preview catches a collision outside the forged short date range');
$request('/?page=classes',$collision);
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='WHOLECHECK'")->fetchColumn()===0,'Save also rejects full-term collisions without partial reservations');
$request('/?page=classes',array_replace($collision,['class_mode'=>'unknown']));
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='WHOLECHECK'")->fetchColumn()===0,'Unknown mode cannot silently create a recurring schedule');
$oneFromUnified=array_replace($collision,['class_mode'=>'once','class_date'=>'2030-07-02','course_code'=>'UNIFIEDONCE','starts_time'=>'08:00','ends_time'=>'12:00']);
[$status,,$onceUnifiedLocation]=$request('/?page=classes',$oneFromUnified);
$expect($status===302 && str_contains($onceUnifiedLocation,'class_id=') && (int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='UNIFIEDONCE'")->fetchColumn()===1,'The same form can recover by switching to once and create a real four-hour class');
$expect((int)db()->query("SELECT COUNT(*) FROM course_schedules WHERE course_code='UNIFIEDONCE'")->fetchColumn()===0,'Once mode never creates an accidental semester reservation');
echo "Unified class creation checks passed: $checks\n";

// New multi-slot creation and change APIs: exercise real CSRF, replay and redirects.
[, $batchForm]=$request('/?page=classes&new_once=1');
preg_match('/name="one_off_request" value="([a-f0-9]+)"/',$batchForm,$batchToken);
$batchInput=['action'=>'create_class_batch','csrf_token'=>$csrf($batchForm),'one_off_request'=>$batchToken[1],'class_mode'=>'semester','academic_year'=>2569,'semester'=>'summer','lecturer_user_id'=>$adminId,'course_code'=>'HTTPBATCH','course_name'=>'ทดสอบหลายช่วงผ่าน HTTP','slots_json'=>json_encode([
    ['room_id'=>$roomId,'day_of_week'=>3,'starts_time'=>'08:00','ends_time'=>'11:00'],
    ['room_id'=>$otherRoom,'day_of_week'=>5,'starts_time'=>'13:00','ends_time'=>'16:00'],
])];
$beforeBatch=(int)db()->query('SELECT COUNT(*) FROM class_sessions')->fetchColumn();
[$status,$body]=$request('/?api=class-batch-preview',$batchInput);
$expect($status===200 && json_decode($body,true)['count']===18,'Batch HTTP preview expands two ranges across the locked summer term');
$expect((int)db()->query('SELECT COUNT(*) FROM class_sessions')->fetchColumn()===$beforeBatch,'Batch preview never writes classes');
[$status]=$request('/?api=class-batch-preview',array_replace($batchInput,['csrf_token'=>'invalid']));
$expect($status===403,'Batch preview enforces CSRF');
[$status,,$batchLocation]=$request('/?page=classes',$batchInput);
$expect($status===302 && str_contains($batchLocation,'series=') && str_contains($batchLocation,'range=all'),'Batch save opens the whole created series');
$httpClasses=db()->query("SELECT * FROM class_sessions WHERE course_code='HTTPBATCH' ORDER BY starts_at")->fetchAll();
$expect(count($httpClasses)===18 && count(array_unique(array_column($httpClasses,'qr_token')))===18,'One submit creates all 18 lessons and distinct QR tokens');
$request('/?page=classes',$batchInput);
$expect((int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='HTTPBATCH'")->fetchColumn()===18,'Replayed batch form cannot create duplicates');
[, $batchList]=$request('/'.$batchLocation);
$expect(str_contains($batchList,'พบ 18 คลาส') && !str_contains($batchList,'name="term_starts_on"'),'Series view shows actual lessons without term setup');
$sample=$httpClasses[0];
[, $waiting]=$request('/?page=student-checkin&token='.$sample['qr_token']);
$expect(str_contains($waiting,'ก่อนเรียน 10 นาที') && !str_contains($waiting,'name="student_code"'),'Future QR explains automatic admission and does not expose the submission form');
[, $changePage]=$request('/?page=classes&edit_class='.$sample['id']);
$expect(str_contains($changePage,'class-change-dialog') && str_contains($changePage,'ทั้งชุดที่สร้างพร้อมกัน'),'Class change dialog offers series scope');
$changeInput=['action'=>'change_class_batch','csrf_token'=>$csrf($changePage),'class_id'=>$sample['id'],'operation'=>'cancel','scope'=>'once','revision'=>$sample['updated_at']];
[$status,$body]=$request('/?api=class-change-preview',$changeInput);
$expect($status===200 && json_decode($body,true)['count']===1 && json_decode($body,true)['lessons'][0]['start']==='08:00','Cancellation preview uses local lesson time and exact affected count');
[$status]=$request('/?api=class-change-preview',array_replace($changeInput,['csrf_token'=>'invalid']));
$expect($status===403,'Change preview enforces CSRF');
[$status]=$request('/?page=classes',$changeInput);
$expect($status===302 && (int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='HTTPBATCH' AND status='cancelled'")->fetchColumn()===1,'Cancel only this lesson preserves every other date');
[, $cancelledStudent]=$request('/?page=student-checkin&token='.$sample['qr_token']);
$expect(!str_contains($cancelledStudent,'name="student_code"'),'Cancelled class cannot receive student check-ins');
$adminCookies=$cookies; $cookies=[];
[$status]=$request('/?api=class-change-preview',$changeInput);
$expect($status===401,'Anonymous callers cannot preview changes to a series');
$cookies=$adminCookies;
$expect((int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='CSVGOOD'")->fetchColumn()>0,'CSV import materializes scheduled classes with unique QR');
$expect((int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='CSVCONFLICT'")->fetchColumn()===0,'Failed CSV import leaves no partial lessons');
echo "Multi-slot HTTP checks passed: $checks\n";

// A new semester can also be imported without pre-creating an empty term.
$catalogBefore=(int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn();
$catalogCsv=$csvHeader."\nCATALOGCSV,ทดสอบนำเข้าภาคใหม่,1,".db()->query('SELECT code FROM rooms WHERE id='.$roomId)->fetchColumn().",admin@example.invalid,1,17:00,18:00,,,ทดสอบสมมติ";
$catalogImport=['action'=>'import_schedule','academic_term_key'=>'2568/summer','csrf_token'=>$csrf($batchForm)];
$request('/?page=schedule',$catalogImport,$catalogCsv."\n".str_replace('CATALOGCSV','CATALOGBAD',explode("\n",$catalogCsv)[1]));
$expect((int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn()===$catalogBefore && (int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code LIKE 'CATALOG%'")->fetchColumn()===0,'New-term CSV conflict rolls back the term, plans and lessons together');
[$status,,$catalogLocation]=$request('/?page=schedule',$catalogImport,$catalogCsv);
$catalogTerm=(int)db()->query("SELECT id FROM academic_terms WHERE academic_year=2568 AND semester='summer'")->fetchColumn();
$expect($status===302 && $catalogTerm>0 && str_contains($catalogLocation,'term_id='.$catalogTerm),'CSV creates its selected catalog term only together with valid classes');
$expect((int)db()->query("SELECT COUNT(*) FROM class_sessions WHERE course_code='CATALOGCSV' AND admission_lead_minutes=10")->fetchColumn()>1,'Catalog import creates all weekly QRs with the ten-minute policy');
echo "Catalog import checks passed: $checks\n";
