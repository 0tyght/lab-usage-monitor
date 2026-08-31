<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

// Every worker inherits this test's newly-created disposable database, never production.
if (($argv[1] ?? '') === '--worker') {
    $fixture=(string)getenv('LUMS_ROOM_CONCURRENCY_FIXTURE');
    if (!$fixture || !str_starts_with($fixture,sys_get_temp_dir().'/lums-room-concurrency-') || !is_file($fixture.'/test-only')) exit(1);
    require dirname(__DIR__).'/src/bootstrap.php';
    for ($i=0;$i<500 && !is_file($fixture.'/go');$i++) usleep(10000);
    $result=record_room_visit($argv[2],['person_code'=>'RACE'.str_pad($argv[3],4,'0',STR_PAD_LEFT),'person_name'=>'นิสิตสมมติพร้อมกัน','person_role'=>'student','purpose'=>'ทดสอบพร้อมกัน','client_request_id'=>bin2hex(random_bytes(16))],bin2hex(random_bytes(32)));
    echo json_encode($result,JSON_UNESCAPED_UNICODE);
    exit;
}

$fixture=sys_get_temp_dir().'/lums-room-concurrency-'.bin2hex(random_bytes(8));
mkdir($fixture,0700); touch($fixture.'/test-only');
putenv('APP_ENV=local'); putenv('LUMS_DB_DSN=sqlite:'.$fixture.'/test.sqlite');
putenv('LUMS_SESSION_PATH='.$fixture.'/sessions'); putenv('LUMS_ROOM_CONCURRENCY_FIXTURE='.$fixture);
require dirname(__DIR__).'/src/bootstrap.php';
initialize_database(db(),true); start_lums_session();
$admin=(int)db()->query("SELECT id FROM users WHERE role='admin'")->fetchColumn();
$_SESSION['user_id']=$admin; $_SESSION['last_activity']=time();
$room=(int)db()->query("SELECT id FROM rooms WHERE status='available' LIMIT 1")->fetchColumn();
$roomToken=bin2hex(random_bytes(16));
db()->prepare("UPDATE rooms SET capacity=2,qr_token=? WHERE id=?")->execute([$roomToken,$room]);
// Remove reservations only in this fresh synthetic fixture.
db()->exec('DELETE FROM attendance_records');db()->exec('DELETE FROM class_sessions');db()->exec('DELETE FROM course_schedules');
session_write_close();
$workers=[];
for ($i=1;$i<=8;$i++) {
    $process=proc_open([PHP_BINARY,__FILE__,'--worker',$roomToken,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
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
$count=(int)db()->query('SELECT COUNT(*) FROM room_visits WHERE room_id='.$room)->fetchColumn();
if ($accepted!==2 || $full!==6 || $count!==2) throw new RuntimeException("Capacity race failed: accepted=$accepted full=$full saved=$count");
echo "PASS: 8 simultaneous outside-class visitors, exactly 2 accepted and 6 capacity rejections; no overbooking or lost success\n";
