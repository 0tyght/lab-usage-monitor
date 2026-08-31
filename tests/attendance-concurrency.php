<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

// Every worker inherits this test's newly-created disposable database, never production.
if (($argv[1] ?? '') === '--worker') {
    $fixture=(string)getenv('LUMS_CONCURRENCY_FIXTURE');
    if (!$fixture || !str_starts_with($fixture,sys_get_temp_dir().'/lums-concurrency-') || !is_file($fixture.'/test-only')) exit(1);
    require dirname(__DIR__).'/src/bootstrap.php';
    for ($i=0;$i<500 && !is_file($fixture.'/go');$i++) usleep(10000);
    $result=register_student_attendance($argv[2],['student_code'=>'9999'.str_pad($argv[3],4,'0',STR_PAD_LEFT),'student_name'=>'นิสิตสมมติพร้อมกัน','client_request_id'=>'parallel-request-'.$argv[3]]);
    echo json_encode($result,JSON_UNESCAPED_UNICODE);
    exit;
}

$fixture=sys_get_temp_dir().'/lums-concurrency-'.bin2hex(random_bytes(8));
mkdir($fixture,0700); touch($fixture.'/test-only');
putenv('APP_ENV=local'); putenv('LUMS_DB_DSN=sqlite:'.$fixture.'/test.sqlite');
putenv('LUMS_SESSION_PATH='.$fixture.'/sessions'); putenv('LUMS_CONCURRENCY_FIXTURE='.$fixture);
require dirname(__DIR__).'/src/bootstrap.php';
initialize_database(db(),true); start_lums_session();
$admin=(int)db()->query("SELECT id FROM users WHERE role='admin'")->fetchColumn();
$_SESSION['user_id']=$admin; $_SESSION['last_activity']=time();
$room=(int)db()->query("SELECT id FROM rooms WHERE status='available' LIMIT 1")->fetchColumn();
db()->exec('UPDATE rooms SET capacity=2 WHERE id='.$room);
$created=create_class_session(['room_id'=>$room,'lecturer_user_id'=>$admin,'course_code'=>'RACE101','course_name'=>'ทดสอบพร้อมกัน','starts_at'=>'2035-05-06T09:00','ends_at'=>'2035-05-06T13:00','checkin_mode'=>'manual']);
if (!$created['ok']) throw new RuntimeException('Cannot create isolated fixture');
$class=get_class_session($created['id']); session_write_close();
$workers=[];
for ($i=1;$i<=8;$i++) {
    $process=proc_open([PHP_BINARY,__FILE__,'--worker',$class['qr_token'],(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch test worker');
    fclose($pipes[0]); $workers[]=[$process,$pipes];
}
touch($fixture.'/go'); $accepted=0; $full=0;
foreach ($workers as [$process,$pipes]) {
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    $result=json_decode($output,true);
    if ($exit!==0 || !is_array($result)) throw new RuntimeException('Worker failed: '.$error);
    if ($result['ok']) $accepted++;
    elseif (str_contains(implode(' ',$result['errors'] ?? []),'ครบความจุห้อง')) $full++;
}
$count=(int)db()->query('SELECT COUNT(*) FROM attendance_records WHERE class_session_id='.$class['id'])->fetchColumn();
if ($accepted!==2 || $full!==6 || $count!==2) throw new RuntimeException("Capacity race failed: accepted=$accepted full=$full saved=$count");
echo "PASS: 8 simultaneous students, exactly 2 accepted and 6 capacity rejections; no overbooking or lost success\n";
