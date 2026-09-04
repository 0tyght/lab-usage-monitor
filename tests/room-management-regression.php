<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

putenv('APP_ENV=local');
putenv('LUMS_DB_DSN=sqlite::memory:');
putenv('LUMS_SESSION_PATH='.sys_get_temp_dir().'/lums-room-management-'.bin2hex(random_bytes(8)));

require dirname(__DIR__).'/src/bootstrap.php';
initialize_database(db(),true);
start_lums_session();

$admin=(int)db()->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$lecturer=(int)db()->query("SELECT id FROM users WHERE role='lecturer' LIMIT 1")->fetchColumn();
$_SESSION['user_id']=$admin;
$_SESSION['last_activity']=time();

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};

$created=create_room([
    'code'=>'LAB-X1',
    'name'=>'ห้องทดสอบการจัดการ',
    'building'=>'อาคารทดสอบ',
    'floor'=>'5',
    'status'=>'available',
    'description'=>'ข้อมูลสมมติสำหรับ regression test',
]);
$expect($created['ok'],'Admin can add a room without entering capacity');
$room=get_room_by_id((int)$created['id']);
$expect($room && $room['code']==='LAB-X1','New room can be read back');
$expect((int)$room['capacity']===1,'Legacy capacity column remains internal compatibility metadata only');

$duplicate=create_room([
    'code'=>'lab-x1',
    'name'=>'ห้องรหัสซ้ำ',
    'building'=>'อาคารทดสอบ',
    'status'=>'available',
]);
$expect(!$duplicate['ok'] && isset($duplicate['errors']['code']),'Duplicate room code is rejected case-insensitively');

$updated=update_room((int)$created['id'],[
    'code'=>'LAB-X2',
    'name'=>'ห้องทดสอบหลังแก้ไข',
    'building'=>'อาคารใหม่',
    'floor'=>'6',
    'status'=>'maintenance',
    'description'=>'แก้ไขแล้ว',
]);
$expect($updated['ok'],'Admin can edit room details');
$room=get_room_by_id((int)$created['id']);
$expect($room['code']==='LAB-X2' && $room['status']==='maintenance','Edited room values are persisted');

$_SESSION['user_id']=$lecturer;
$blocked=create_room([
    'code'=>'LECT-1',
    'name'=>'ห้องที่อาจารย์พยายามเพิ่ม',
    'building'=>'อาคารทดสอบ',
    'status'=>'available',
]);
$expect(!$blocked['ok'],'Lecturer cannot add rooms');

$_SESSION['user_id']=$admin;
$removed=remove_room((int)$created['id']);
$expect($removed['ok'] && empty($removed['archived']),'Unused room can be deleted permanently');
$expect(get_room_by_id((int)$created['id'])===null,'Deleted unused room is gone');

$referenced=create_room([
    'code'=>'LAB-HISTORY',
    'name'=>'ห้องมีประวัติ',
    'building'=>'อาคารทดสอบ',
    'floor'=>'1',
    'status'=>'available',
]);
$expect($referenced['ok'],'Create a room for history-preservation test');
$roomId=(int)$referenced['id'];
$now=utc_now();
$insert=db()->prepare(
    "INSERT INTO class_sessions
     (room_id, lecturer_user_id, course_code, course_name, section, starts_at, ends_at, status, qr_token, notes, created_at, updated_at)
     VALUES (:room_id,:lecturer,'OLD101','คลาสย้อนหลัง',NULL,'2020-01-01T01:00:00Z','2020-01-01T02:00:00Z','cancelled',:token,NULL,:created,:updated)"
);
$insert->execute([
    ':room_id'=>$roomId,
    ':lecturer'=>$admin,
    ':token'=>bin2hex(random_bytes(16)),
    ':created'=>$now,
    ':updated'=>$now,
]);

$archived=remove_room($roomId);
$expect($archived['ok'] && !empty($archived['archived']),'Room with historical references is archived instead of destroying history');
$expect(get_room_by_id($roomId)['status']==='inactive','Archived room is marked inactive');

session_write_close();
echo "PASS: $checks room-management checks\n";
