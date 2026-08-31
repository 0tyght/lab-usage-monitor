<?php
declare(strict_types=1);

/** A room token is a stable public locator, never authorization to see a roster. */
function public_room(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$token)) return null;
    $query=db()->prepare('SELECT id,code,name,building,floor,capacity,status,qr_token FROM rooms WHERE qr_token=?');
    $query->execute([$token]);
    return $query->fetch() ?: null;
}

function public_room_url(string $token): string
{
    $base=(string)app_config('app.base_url','');
    if ($base==='') $base=rtrim((string)app_config('app.url','http://localhost:8086'),'/');
    return rtrim($base,'/').'/?page=room-checkin&token='.rawurlencode($token);
}

/** Do not route by attendance-open status: a closed/draft lesson still exists. */
function room_current_classes(int $roomId, ?int $now=null): array
{
    $now ??= time();
    $query=db()->prepare("SELECT cs.id,cs.course_code,cs.course_name,cs.section,cs.starts_at,cs.ends_at,cs.status,cs.checkin_mode,cs.admission_lead_minutes,cs.qr_token,u.full_name AS lecturer_name FROM class_sessions cs JOIN users u ON u.id=cs.lecturer_user_id WHERE cs.room_id=? AND cs.status<>'cancelled' AND cs.starts_at<=? AND cs.ends_at>? ORDER BY cs.starts_at,cs.id");
    $query->execute([$roomId,gmdate('Y-m-d\TH:i:s\Z',$now+600),gmdate('Y-m-d\TH:i:s\Z',$now)]);
    return $query->fetchAll();
}

function room_has_legacy_plan(int $roomId, ?int $now=null): bool
{
    $now ??= time();
    $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone((string)app_config('app.timezone','Asia/Bangkok')));
    $query=db()->prepare("SELECT s.* FROM course_schedules s WHERE s.room_id=? AND s.status='active' AND s.day_of_week=? AND s.active_from<=? AND s.active_until>=? AND NOT EXISTS(SELECT 1 FROM class_sessions cs WHERE cs.schedule_id=s.id AND cs.scheduled_date=?)");
    foreach ([$local,$local->modify('+1 day')] as $date) {
    $query->execute([$roomId,(int)$date->format('N'),$date->format('Y-m-d'),$date->format('Y-m-d'),$date->format('Y-m-d')]);
    foreach ($query->fetchAll() as $row) {
        $start=strtotime(local_datetime_to_utc($date->format('Y-m-d').'T'.$row['starts_time']));
        $end=strtotime(local_datetime_to_utc($date->format('Y-m-d').'T'.$row['ends_time']));
        if ($now>=$start-600 && $now<$end) return true;
    }
    }
    return false;
}

function visit_error(string $message): array { return ['ok'=>false,'message'=>$message,'errors'=>['form'=>$message]]; }

/** Opaque receipt survives session cleanup; never contains a name or a record ID. */
function remember_room_visit(string $roomToken,string $receipt): void
{
    if (PHP_SAPI==='cli') return;
    setcookie('lums_visit_'.$roomToken,$receipt,['expires'=>time()+7*86400,'path'=>'/','secure'=>(bool)app_config('security.secure_cookies',false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
}

function room_visit_report_query(array $filters): array
{
    return array_intersect_key($filters,array_flip(['date_from','date_to','room_id','time_from','time_to','unit','period_minutes','q','sort','visit_status']));
}

function record_room_visit(string $token, array $input, string $receipt): array
{
    $room=public_room($token);
    if (!$room) return visit_error('ไม่พบห้อง กรุณาสแกน QR หน้าห้องอีกครั้ง');
    $code=strtoupper(trim((string)($input['person_code'] ?? '')));
    $name=trim((string)($input['person_name'] ?? ''));
    $purpose=trim((string)($input['purpose'] ?? ''));
    $role=(string)($input['person_role'] ?? 'student');
    $request=(string)($input['client_request_id'] ?? '');
    if (!preg_match('/^[A-Z0-9._-]{4,30}$/D',$code) || text_length($name)<2 || text_length($name)>100 || text_length($purpose)<2 || text_length($purpose)>255 || !in_array($role,['student','lecturer','staff'],true)) return visit_error('กรอกรหัส ชื่อ ประเภทผู้ใช้ และวัตถุประสงค์ให้ครบและถูกต้อง');
    if (!preg_match('/^[a-f0-9]{32}$/D',$request) || !preg_match('/^[a-f0-9]{64}$/D',$receipt)) return visit_error('แบบฟอร์มหมดอายุ กรุณาสแกนหรือเปิดหน้าห้องใหม่');
    $connection=db(); $connection->beginTransaction();
    try {
        $connection->exec('UPDATE rooms SET id=id WHERE id='.(int)$room['id']);
        $existing=$connection->prepare('SELECT id,receipt_hash,room_id,person_code FROM room_visits WHERE client_request_id=?');
        $existing->execute([$request]);
        if ($row=$existing->fetch()) {
            if (!hash_equals($row['receipt_hash'],hash('sha256',$receipt)) || (int)$row['room_id']!==(int)$room['id'] || $row['person_code']!==$code) return visit_error('คำขอนี้ถูกใช้แล้ว กรุณาเปิดแบบฟอร์มใหม่');
            return ['ok'=>true,'id'=>(int)$row['id'],'duplicate'=>true];
        }
        $room=public_room($token);
        if ($room['status']!=='available') return visit_error('ห้องนี้งดรับการเข้าใช้ชั่วคราว กรุณาติดต่อผู้ดูแลห้อง');
        if (room_current_classes((int)$room['id']) || room_has_legacy_plan((int)$room['id'])) return visit_error('ขณะนี้มีคลาสหรือมีตารางเรียนแล้ว กรุณาตรวจสอบคลาสก่อนลงชื่อ ระบบยังไม่บันทึกเป็นการใช้นอกคลาส');
        $active=$connection->prepare('SELECT COUNT(*) FROM room_visits WHERE room_id=? AND check_out_at IS NULL');
        $active->execute([$room['id']]);
        if ((int)$active->fetchColumn()>=(int)$room['capacity']) return visit_error('จำนวนผู้ที่ยังไม่กดออกครบความจุห้องแล้ว กรุณาติดต่อผู้ดูแลห้อง');
        $same=$connection->prepare('SELECT id FROM room_visits WHERE person_code=? AND check_out_at IS NULL'); $same->execute([$code]);
        if ($same->fetchColumn()) return visit_error('รหัสนี้มีรายการที่ยังไม่กดออก กรุณาใช้เบราว์เซอร์ที่ลงชื่อไว้ หรือให้ผู้ดูแลตรวจสอบ');
        $now=utc_now();
        $connection->prepare('INSERT INTO room_visits(room_id,person_code,person_name,person_role,purpose,check_in_at,receipt_hash,client_request_id,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$room['id'],$code,$name,$role,$purpose,$now,hash('sha256',$receipt),$request,$now]);
        $id=(int)$connection->lastInsertId();
        audit_log('room_visit_started','room_visit',$id,['room_id'=>(int)$room['id']],null);
        $connection->commit();
        return ['ok'=>true,'id'=>$id,'duplicate'=>false];
    } catch (Throwable) { return visit_error('บันทึกไม่สำเร็จ กรุณาลองอีกครั้ง ระบบป้องกันการบันทึกซ้ำให้อัตโนมัติ'); }
    finally { if ($connection->inTransaction()) $connection->rollBack(); }
}

function room_visit_receipt(string $receipt): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$receipt)) return null;
    $query=db()->prepare('SELECT v.id,v.room_id,v.person_code,v.person_name,v.purpose,v.check_in_at,v.check_out_at,v.checkout_method,r.code AS room_code FROM room_visits v JOIN rooms r ON r.id=v.room_id WHERE receipt_hash=?');
    $query->execute([hash('sha256',$receipt)]); return $query->fetch() ?: null;
}

function checkout_room_visit(string $receipt, ?int $adminVisitId=null, string $note=''): array
{
    $admin=$adminVisitId!==null?current_user():null;
    if ($adminVisitId!==null && (!$admin || $admin['role']!=='admin' || text_length(trim($note))<2 || text_length(trim($note))>255)) return visit_error('เฉพาะผู้ดูแลระบบ และต้องระบุเหตุผลในการปิดรายการ');
    $connection=db(); $connection->beginTransaction();
    try {
        $connection->exec('UPDATE rooms SET id=id WHERE id IN (SELECT id FROM rooms)');
        $query=$connection->prepare($adminVisitId!==null?'SELECT * FROM room_visits WHERE id=?':'SELECT * FROM room_visits WHERE receipt_hash=?');
        $query->execute([$adminVisitId ?? hash('sha256',$receipt)]); $row=$query->fetch();
        if (!$row || ($adminVisitId===null && !preg_match('/^[a-f0-9]{64}$/D',$receipt))) return visit_error('ไม่พบรายการของเบราว์เซอร์นี้ กรุณาติดต่อผู้ดูแลห้อง');
        if ($row['check_out_at']) return ['ok'=>true,'duplicate'=>true];
        $now=utc_now();$method=$adminVisitId!==null?'admin':'self';
        $connection->prepare('UPDATE room_visits SET check_out_at=?,checkout_method=?,checkout_note=?,closed_by=? WHERE id=? AND check_out_at IS NULL')->execute([$now,$method,$adminVisitId!==null?trim($note):null,$admin['id'] ?? null,$row['id']]);
        audit_log('room_visit_finished','room_visit',(int)$row['id'],['method'=>$method,'check_out_at'=>$now,'note'=>$adminVisitId!==null?trim($note):null],$admin['id'] ?? null);
        $connection->commit(); return ['ok'=>true,'duplicate'=>false];
    } catch (Throwable) { return visit_error('บันทึกเวลาออกไม่สำเร็จ กรุณาลองอีกครั้ง'); }
    finally { if ($connection->inTransaction()) $connection->rollBack(); }
}

/** Individual visits stay separate from timetable hours: never sum incompatible units. */
function room_visit_report(array $input): array
{
    $filters=planning_filters(room_visit_report_query($input),true);
    $status=in_array($input['visit_status'] ?? '',['active','self','admin'],true)?$input['visit_status']:'';
    $filters['visit_status']=$status;
    $viewer=current_user();$rows=[];
    if (!$viewer || $viewer['role']!=='admin') $filters['errors'][]='เฉพาะผู้ดูแลระบบสามารถดูข้อมูลการเข้าใช้นอกคลาส';
    if (!$filters['errors']) {
        $from=local_datetime_to_utc($filters['date_from'].'T00:00');
        $to=local_datetime_to_utc((new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d').'T00:00');
        $query=db()->prepare('SELECT v.id,v.room_id,v.person_code,v.person_name,v.person_role,v.purpose,v.check_in_at,v.check_out_at,v.checkout_method,v.checkout_note,r.code AS room_code FROM room_visits v JOIN rooms r ON r.id=v.room_id WHERE v.check_in_at>=? AND v.check_in_at<? ORDER BY v.check_in_at,v.id');
        $query->execute([$from,$to]);
        while ($row=$query->fetch()) {
            if ($filters['room_id'] && (int)$row['room_id']!==$filters['room_id']) continue;
            $time=date('H:i',strtotime($row['check_in_at']));
            if ($time<$filters['time_from'] || $time>=$filters['time_to']) continue;
            if ($status && ($status==='active'?$row['check_out_at']!==null:$row['checkout_method']!==$status)) continue;
            if ($filters['q']!=='' && stripos(implode(' ',[$row['person_code'],$row['person_name'],$row['purpose'],$row['room_code']]),$filters['q'])===false) continue;
            $row['minutes']=$row['check_out_at'] && $row['checkout_method']==='self'?max(0,(strtotime($row['check_out_at'])-strtotime($row['check_in_at']))/60):null;
            $rows[]=$row;
            if (count($rows)>20000) { $filters['errors'][]='ข้อมูลเกิน 20,000 รายการ กรุณาลดช่วงวันที่หรือเลือกห้อง'; $rows=[]; break; }
        }
    }
    if ($filters['sort']==='desc') $rows=array_reverse($rows);
    return ['filters'=>$filters,'errors'=>$filters['errors'],'rows'=>$rows,'active'=>count(array_filter($rows,static fn($r)=>!$r['check_out_at']))];
}

function room_visit_table(array $report): array
{
    $rows=[];$periods=$report['filters']['unit']==='periods';$factor=$periods?$report['filters']['period_minutes']:60;
    foreach ($report['rows'] as $row) $rows[]=[date('d/m/Y H:i',strtotime($row['check_in_at'])),$row['check_out_at']?date('d/m/Y H:i',strtotime($row['check_out_at'])):'ยังไม่กดออก',$row['room_code'],$row['person_code'],$row['person_name'],['student'=>'นิสิต','lecturer'=>'อาจารย์','staff'=>'บุคลากร'][$row['person_role']],$row['purpose'],$row['minutes']!==null?number_format($row['minutes']/$factor,2,'.',''):'ไม่ทราบ',!$row['check_out_at']?'ยังไม่กดออก':($row['checkout_method']==='self'?'ผู้ใช้กดออกเอง':'ผู้ดูแลปิดรายการ (ไม่ใช่เวลาที่ผู้ใช้ยืนยัน)'),$row['checkout_note'] ?? ''];
    return ['headers'=>['เวลาเข้า','เวลาบันทึกออก / ปิดรายการ','ห้อง','รหัสผู้ใช้','ชื่อ–นามสกุล','ประเภท','วัตถุประสงค์',$periods?'คาบรายบุคคล':'ชั่วโมงรายบุคคล','สถานะ','เหตุผลปิดรายการ'],'rows'=>$rows];
}
