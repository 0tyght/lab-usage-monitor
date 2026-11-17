<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$file=tempnam(sys_get_temp_dir(),'lums-worker-');
if ($file===false) throw new RuntimeException('Could not create test database');
putenv('APP_ENV=local');
putenv('LUMS_DB_DSN=sqlite:'.$file);
putenv('LUMS_SESSION_PATH='.sys_get_temp_dir().'/lums-worker-session-'.bin2hex(random_bytes(6)));
require dirname(__DIR__).'/src/bootstrap.php';
try {
    initialize_database(db(),true);
    $room=(int)db()->query('SELECT id FROM rooms LIMIT 1')->fetchColumn();
    $now=utc_now(); $deadline=gmdate('Y-m-d\TH:i:s\Z',time()-60);
    db()->prepare("INSERT INTO room_visits(room_id,person_code,person_name,person_role,purpose,check_in_at,planned_end_at,receipt_hash,client_request_id,created_at) VALUES (?,'TEST123','Test','student','Test',?,?,?,?,?)")
        ->execute([$room,gmdate('Y-m-d\TH:i:s\Z',time()-3600),$deadline,hash('sha256',random_bytes(32)),bin2hex(random_bytes(16)),$now]);
    $id=(int)db()->lastInsertId();
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/scripts/close-expired.php');
    exec($cmd,$output,$status);
    $q=db()->prepare('SELECT check_out_at,auto_closed_at FROM room_visits WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if ($status!==0 || $row['check_out_at']!==$deadline || !$row['auto_closed_at']) throw new RuntimeException('Worker failed to close expired visit');
    exec($cmd,$output,$status);
    $q->execute([$id]);
    if ($status!==0 || $q->fetch()['check_out_at']!==$deadline) throw new RuntimeException('Worker is not idempotent');
    echo "PASS: background timeout worker closes expired visit and safely repeats\n";
} finally {
    // This file was created by tempnam above, solely for this test.
    @unlink($file);
}
