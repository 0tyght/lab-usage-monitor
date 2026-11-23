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

require dirname(__DIR__).'/src/bootstrap.php';
start_lums_session();
$token=bin2hex(random_bytes(16));$now=utc_now();$code='HTTP-QR-'.substr($token,0,6);
db()->prepare("INSERT INTO rooms(code,name,building,floor,capacity,status,qr_token,created_at,updated_at) VALUES (?,'ห้องทดสอบ HTTP','อาคารทดสอบ','1',5,'available',?,?,?)")->execute([$code,$token,$now,$now]);$room=(int)db()->lastInsertId();
$url='/?page=room-checkin&token='.$token;
[$status,$html]=$request($url);$expect($status===200 && str_contains($html,'เข้าใช้ห้องนอกคลาส'),'Public room renders anonymous walk-in form');
[, $initialLookup]=$request('/?api=room-lookup',['room_token'=>$token,'csrf_token'=>$csrf($html),'person_code'=>'HTTPQR001']);
$expect((json_decode($initialLookup,true)['active'] ?? null)===false && (json_decode($initialLookup,true)['role'] ?? null)===null,'Unknown code starts inactive with no guessed user type');
$knownHash=password_hash('synthetic-password',PASSWORD_DEFAULT);
db()->prepare("INSERT INTO users(email,password_hash,full_name,role,student_code,is_active,created_at,updated_at) VALUES (?,?,?,'staff',?,1,?,?)")->execute(['synthetic-staff@example.invalid',$knownHash,'บุคลากรทดสอบ','STAFF123',$now,$now]);
[, $knownLookup]=$request('/?api=room-lookup',['room_token'=>$token,'csrf_token'=>$csrf($html),'person_code'=>'STAFF123']);
$expect((json_decode($knownLookup,true)['role'] ?? null)==='staff','Known identifier gets its stored user type');
[$lookupStatus]=$request('/?api=room-lookup',['room_token'=>$token,'csrf_token'=>'bad','person_code'=>'STAFF123']);
$expect($lookupStatus===422,'Lookup rejects invalid CSRF');
preg_match('/name="client_request_id" value="([^"]+)"/',$html,$m);
$input=['action'=>'room_visit_start','token'=>$token,'csrf_token'=>$csrf($html),'client_request_id'=>$m[1],'person_code'=>'HTTPQR001','person_name'=>'นิสิตทดสอบ HTTP','person_role'=>'student','purpose'=>'=SUM(1,2)','planned_end_at'=>date('Y-m-d\TH:i',time()+3600)];
[$status]=$request($url,array_replace($input,['csrf_token'=>'bad']));$expect($status===303 && (int)db()->query('SELECT COUNT(*) FROM room_visits WHERE room_id='.$room)->fetchColumn()===0,'Invalid CSRF redirects safely without saving');
[, $afterBadCsrf]=$request($url);
[$status,,$redirect]=$request($url,$input);$expect($status===302 && str_contains($redirect,'room-checkin'),'Successful check-in uses post-redirect-get');
[, $done]=$request($url);$expect(str_contains($done,'บันทึกการเข้าใช้แล้ว'),'Successful check-in is confirmed');
[, $activeLookup]=$request('/?api=room-lookup',['room_token'=>$token,'csrf_token'=>$csrf($done),'person_code'=>'HTTPQR001']);
$activeJson=json_decode($activeLookup,true);
$expect(($activeJson['active'] ?? false) && !str_contains($activeLookup,'นิสิตทดสอบ HTTP'),'Lookup finds active status without disclosing a name');
$request($url,$input);$expect((int)db()->query('SELECT COUNT(*) FROM room_visits WHERE room_id='.$room)->fetchColumn()===1,'HTTP retries do not duplicate records');
$visit=(int)db()->query('SELECT id FROM room_visits WHERE room_id='.$room)->fetchColumn();
$ownCookies=$cookies;$cookies=[];
[, $stranger]=$request($url);$expect(!str_contains($stranger,'นิสิตทดสอบ HTTP') && !str_contains($stranger,'HTTPQR001'),'Other browsers never see the roster or own receipt');
$request($url,['action'=>'room_visit_end','token'=>$token,'csrf_token'=>$csrf($stranger),'visit_id'=>$visit]);
$expect(db()->query('SELECT check_out_at FROM room_visits WHERE id='.$visit)->fetchColumn()===null,'Forged record ID cannot close someone else visit');
[$status]=$request('/?download=room-visits-csv');$expect($status===302,'Anonymous CSV requests require authentication');
$cookies=array_filter($ownCookies,static fn($key)=>str_starts_with($key,'lums_visit_'),ARRAY_FILTER_USE_KEY);
[, $restored]=$request($url);$expect(!str_contains($restored,'นิสิตทดสอบ HTTP'),'A shared device does not reveal the previous user receipt');
[, $checkoutPage]=$request($url.'&mode=exit');
$request($url,['action'=>'room_checkout_direct','token'=>$token,'csrf_token'=>$csrf($checkoutPage),'person_code'=>'HTTPQR001']);
[, $ended]=$request($url);$expect(str_contains($ended,'บันทึกเวลาออกแล้ว'),'Self checkout success is visible');
[, $again]=$request($url.'&new_visit=1');$expect(str_contains($again,'name="person_code"'),'A completed visit can start a new visit');
preg_match('/name="client_request_id" value="([^"]+)"/',$again,$m);
$second=array_replace($input,['csrf_token'=>$csrf($again),'client_request_id'=>$m[1],'person_code'=>'HTTPQR002','person_name'=>'ผู้ใช้ค้างสมมติ']);
$request($url,$second);$id2=(int)db()->query('SELECT MAX(id) FROM room_visits WHERE room_id='.$room)->fetchColumn();
$cookies=[];[, $login]=$request('/?page=login');$request('/?page=login',['action'=>'login','csrf_token'=>$csrf($login),'email'=>'admin@example.invalid','password'=>getenv('LUMS_ADMIN_PASSWORD')]);
[, $poster]=$request('/?page=rooms&qr_room='.$room);$expect(str_contains($poster,'data-room-qr=') && str_contains($poster,'?page=room-checkin&amp;token='.$token) && !str_contains($poster,'HTTPQR001'),'Poster contains permanent room URL, no personal data');
$posterDom=new DOMDocument(); @$posterDom->loadHTML('<?xml encoding="UTF-8">'.$poster);
$posterXPath=new DOMXPath($posterDom);
$copies=$posterXPath->query('//*[@id="room-qr-dialog"]//*[@data-room-poster]//*[@data-room-qr]');
$roomLink=$posterXPath->evaluate('string(//*[@data-room-link]/@value)');
$expect($copies->length===2 && $copies->item(0)->getAttribute('data-room-qr')===$roomLink && $copies->item(1)->getAttribute('data-room-qr')===$roomLink,'Both printable signs use the same permanent room check-in link');
$params=['page'=>'reports','tab'=>'walkins','room_id'=>$room,'date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d'),'unit'=>'periods','period_minutes'=>50,'q'=>'HTTPQR'];
[, $report]=$request('/?'.http_build_query($params));$expect(str_contains($report,'นิสิตทดสอบ HTTP') && str_contains($report,'ผู้ใช้ค้างสมมติ') && str_contains($report,'คาบรายบุคคล'),'Admin report includes both completed and open visits');
[, $csv]=$request('/?'.http_build_query(['download'=>'room-visits-csv']+$params));
$expect(str_contains($csv,'HTTPQR001') && str_contains($csv,'HTTPQR002') && str_contains($csv,"'=SUM(1,2)") && !str_contains($csv,'receipt_hash'),'CSV matches filters and escapes spreadsheet formulas');
[, $print]=$request('/?'.http_build_query($params+['print'=>1]));$expect(str_contains($print,'HTTPQR002') && !str_contains($print,'name="checkout_note"'),'Print includes same rows but no admin actions');
$request('/?'.http_build_query($params),['action'=>'room_visit_admin_end','visit_id'=>$id2,'csrf_token'=>$csrf($report),'checkout_note'=>'ตรวจห้องแล้วไม่มีผู้ใช้']);
$expect(db()->query('SELECT checkout_method FROM room_visits WHERE id='.$id2)->fetchColumn()==='admin','Admin closure is explicitly distinguished from self checkout');
[, $bad]=$request('/?page=reports&tab=walkins&date_from=invalid');$expect(str_contains($bad,'role="alert"') && !str_contains($bad,'download=room-visits-csv'),'Invalid report filters do not allow exporting different data');
$cookies=[];[$status]=$request('/?page=room-checkin&token=bad');$expect($status===404,'Malformed room links have friendly 404');
$admin=(int)db()->query("SELECT id FROM users WHERE email='admin@example.invalid'")->fetchColumn();
$start=gmdate('Y-m-d\TH:i:s\Z',time()-300);$end=gmdate('Y-m-d\TH:i:s\Z',time()+3600);$classToken=bin2hex(random_bytes(16));
db()->prepare("INSERT INTO class_sessions(room_id,lecturer_user_id,course_code,course_name,starts_at,ends_at,status,checkin_mode,admission_lead_minutes,qr_token,created_at,updated_at) VALUES (?,?,'HTTP-ROOM-CLASS','ทดสอบคลาสปัจจุบัน',?,?,'open','scheduled',10,?,?,?)")->execute([$room,$admin,$start,$end,$classToken,$now,$now]);$class=(int)db()->lastInsertId();
[, $current]=$request($url);$expect(str_contains($current,'ลงชื่อเข้าเรียน') && str_contains($current,$classToken) && str_contains($current,'value="room_class_attendance"') && !str_contains($current,'value="room_visit_start"'),'Room displays the single current class and attendance form together');
$classInput=['action'=>'room_class_attendance','room_token'=>$token,'class_token'=>$classToken,'csrf_token'=>$csrf($current),'student_code'=>'HTTPCLASS01','student_name'=>'นิสิตตัวอย่าง','client_request_id'=>'http-room-class-001'];
$request($url,$classInput); [, $classDone]=$request($url); $expect(str_contains($classDone,'ลงชื่อเข้าเรียนเรียบร้อย'),'Class check-in reports success on the room page');
$request($url,$classInput);$expect((int)db()->query("SELECT COUNT(*) FROM attendance_records WHERE class_session_id=$class AND student_code='HTTPCLASS01'")->fetchColumn()===1,'Repeated class submission does not create duplicates');
$cookies=[];[, $exitPage]=$request($url.'&mode=exit');
$request($url,['action'=>'room_checkout_direct','token'=>$token,'csrf_token'=>$csrf($exitPage),'person_code'=>'HTTPCLASS01']);
$expect(db()->query("SELECT checkout_method FROM attendance_records WHERE class_session_id=$class AND student_code='HTTPCLASS01'")->fetchColumn()==='self','Class user can check out on another browser with their code');
[, $reentryPage]=$request($url);
$request($url,array_replace($classInput,['csrf_token'=>$csrf($reentryPage),'client_request_id'=>'http-room-reentry-001']));
[, $reentryResult]=$request($url);
$expect(str_contains($reentryResult,'ลงชื่อเข้าเรียนเรียบร้อย') && (int)db()->query('SELECT COUNT(*) FROM attendance_return_visits')->fetchColumn()===1,'Re-entry creates a separate usage interval');
$returnRows=find_active_checkout($token,'HTTPCLASS01');
$expect(count($returnRows)===1 && $returnRows[0]['kind']==='return','Re-entry is recognized as currently in the room');
$expect((int)(active_room_user_counts()[$room] ?? 0)===1,'A re-entered user is counted in the live room total');
[, $reentryExit]=$request($url.'&mode=exit');
$request($url,['action'=>'room_checkout_direct','token'=>$token,'csrf_token'=>$csrf($reentryExit),'person_code'=>'HTTPCLASS01']);
$expect(db()->query('SELECT checkout_method FROM attendance_return_visits')->fetchColumn()==='self','Re-entered user can check out normally');
$reportItems=list_attendance_records(['q'=>'HTTPCLASS01'],true)['items'];
$expect(count($reportItems)===2,'Attendance reports retain both entry and return intervals');
$cookies=[];[, $adminLogin]=$request('/?page=login');
$request('/?page=login',['action'=>'login','csrf_token'=>$csrf($adminLogin),'email'=>'admin@example.invalid','password'=>getenv('LUMS_ADMIN_PASSWORD')]);
$reportQuery=['q'=>'HTTPCLASS01','date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d')];
[, $classCsv]=$request('/?'.http_build_query(['download'=>'attendance-csv']+$reportQuery));
[, $classPrint]=$request('/?'.http_build_query(['page'=>'reports','tab'=>'attendance','print'=>1]+$reportQuery));
$expect(substr_count($classCsv,'HTTPCLASS01')>=2 && substr_count($classPrint,'HTTPCLASS01')>=2,'Attendance CSV and print include both entry intervals');
$cookies=[];
$otherToken=bin2hex(random_bytes(16));
db()->prepare("INSERT INTO class_sessions(room_id,lecturer_user_id,course_code,course_name,starts_at,ends_at,status,checkin_mode,admission_lead_minutes,qr_token,created_at,updated_at) VALUES (?,?,'HTTP-SECOND','คลาสทดสอบที่สอง',?,?,'open','scheduled',10,?,?,?)")->execute([$room,$admin,$start,$end,$otherToken,$now,$now]);
$otherClass=(int)db()->lastInsertId();
[, $multi]=$request($url);$expect(str_contains($multi,'เลือกคลาสที่เข้าเรียน') && str_contains($multi,'type="radio"'),'Multiple available classes require an explicit choice');
db()->prepare("UPDATE class_sessions SET status='cancelled' WHERE id=?")->execute([$otherClass]);
$request($url,array_replace($classInput,['csrf_token'=>$csrf($multi),'student_code'=>'FORGED01','class_token'=>$otherToken,'client_request_id'=>'forged-class-test-01']));
$expect((int)db()->query("SELECT COUNT(*) FROM attendance_records WHERE student_code='FORGED01'")->fetchColumn()===0,'A cancelled or unavailable class token cannot be submitted through the room');
$future=gmdate('Y-m-d\TH:i:s\Z',time()+3600); $futureEnd=gmdate('Y-m-d\TH:i:s\Z',time()+7200);
db()->prepare("UPDATE class_sessions SET starts_at=?,ends_at=?,checkin_mode='manual' WHERE id=?")->execute([$future,$futureEnd,$class]);
$expect(count(room_current_classes($room))===1 && class_checkin_status(room_current_classes($room)[0])==='open','A manually opened class appears more than ten minutes early');
[, $legacy]=$request('/?page=student-checkin&token='.$classToken);$expect(str_contains($legacy,'ทดสอบคลาสปัจจุบัน'),'Existing per-class links continue working');
db()->prepare("UPDATE class_sessions SET status='closed',starts_at=?,ends_at=? WHERE id=?")->execute([$start,$end,$class]);
[, $closed]=$request($url);$expect(str_contains($closed,'ยังไม่เปิดรับลงชื่อ') && !str_contains($closed,'value="room_visit_start"'),'Closed lesson cannot be bypassed by room QR');
db()->prepare("UPDATE class_sessions SET status='cancelled' WHERE id=?")->execute([$class]);
[, $cancelled]=$request($url);$expect(str_contains($cancelled,'value="room_visit_start"'),'Cancelled class releases room check-in');
echo "Room visit HTTP: $checks checks passed\n";
