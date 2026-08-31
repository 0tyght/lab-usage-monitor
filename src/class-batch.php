<?php
declare(strict_types=1);

/** Catalog choices exist without an administrator creating an empty term first. */
function class_term_choice(array $input): ?array
{
    $year = (int)($input['academic_year'] ?? 0);
    $semester = (string)($input['semester'] ?? '');
    if ($semester === '3') $semester = 'summer';
    $preset = nu_academic_presets()[$year]['terms'][$semester] ?? null;
    return $preset ? ['academic_year'=>$year,'semester'=>$semester,'name'=>academic_term_code($year,$semester),'starts_on'=>$preset['start'],'ends_on'=>$preset['end']] : null;
}

function class_batch_error(string $message): array
{
    return ['ok'=>false,'message'=>$message,'errors'=>['form'=>$message]];
}

function class_batch_preview(array $input): array
{
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'], ['admin','lecturer'], true)) return class_batch_error('กรุณาเข้าสู่ระบบด้วยบัญชีผู้สอนหรือผู้ดูแล');
    $mode = (string)($input['class_mode'] ?? '');
    if (!in_array($mode,['once','semester'],true)) return class_batch_error('เลือกรูปแบบครั้งเดียวหรือทั้งภาคเรียน');
    $term = $mode === 'semester' ? class_term_choice($input) : null;
    if ($mode === 'semester' && !$term) return class_batch_error('เลือกปีและภาคที่มีปฏิทินมหาวิทยาลัยในระบบ');
    $lecturerId = one_off_lecturer_id($input);
    $lecturer = db()->prepare("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('admin','lecturer')");
    $lecturer->execute([$lecturerId]);
    if (!$lecturer->fetchColumn()) return class_batch_error('เลือกผู้สอนที่ใช้งานได้');
    $code = strtoupper(trim((string)($input['course_code'] ?? '')));
    $name = trim((string)($input['course_name'] ?? ''));
    $section = trim((string)($input['section'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    if (!preg_match('/^[A-Z0-9._-]{2,30}$/D',$code) || text_length($name)<2 || text_length($name)>150 || text_length($section)>30 || text_length($notes)>500) return class_batch_error('กรอกรหัสวิชาและชื่อวิชาให้ครบ ตรวจความยาวของกลุ่มเรียนและหมายเหตุ');
    $json = $input['slots_json'] ?? '';
    if (!is_string($json) || strlen($json)>20000) return class_batch_error('รายการช่วงเวลาไม่ถูกต้อง');
    $slots = json_decode($json,true);
    if (!is_array($slots) || !array_is_list($slots) || !$slots || count($slots)>20) return class_batch_error('เลือกช่วงเวลาอย่างน้อย 1 ช่วง และไม่เกิน 20 ช่วงต่อการสร้าง');
    $rooms = array_column(list_rooms(),null,'id');
    $rows = [];
    foreach ($slots as $index=>$slot) {
        $label = 'ช่วงที่ '.($index+1).': ';
        if (!is_array($slot)) return class_batch_error($label.'ข้อมูลไม่ถูกต้อง');
        $room = $rooms[(int)($slot['room_id'] ?? 0)] ?? null;
        $start = (string)($slot['starts_time'] ?? '');
        $end = (string)($slot['ends_time'] ?? '');
        if (!$room || $room['status']!=='available') return class_batch_error($label.'เลือกห้องที่พร้อมใช้งาน');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end) || $start >= $end || strtotime($end)-strtotime($start)>43200) return class_batch_error($label.'เลือกเวลาเริ่ม–สิ้นสุดในวันเดียวกัน ไม่เกิน 12 ชั่วโมง');
        $dates = [];
        if ($term) {
            $weekday = filter_var($slot['day_of_week'] ?? null,FILTER_VALIDATE_INT);
            if (!$weekday || $weekday<1 || $weekday>7) return class_batch_error($label.'เลือกวันเรียน');
            $first = new DateTimeImmutable($term['starts_on']);
            $first = $first->modify('+'.(($weekday-(int)$first->format('N')+7)%7).' days');
            for ($day=$first;$day->format('Y-m-d')<=$term['ends_on'];$day=$day->modify('+7 days')) $dates[]=$day->format('Y-m-d');
        } else {
            $date = (string)($slot['class_date'] ?? $input['class_date'] ?? '');
            if (!valid_iso_date($date)) return class_batch_error($label.'เลือกวันที่ให้ถูกต้อง');
            $dates[] = $date;
        }
        foreach ($dates as $date) {
            $rows[] = ['room_id'=>$room['id'],'room_code'=>$room['code'],'lecturer_user_id'=>$lecturerId,'course_code'=>$code,'course_name'=>$name,'section'=>$section,'notes'=>$notes,'starts_at'=>local_datetime_to_utc($date.'T'.$start),'ends_at'=>local_datetime_to_utc($date.'T'.$end),'date'=>$date,'starts_time'=>$start,'ends_time'=>$end,'slot_index'=>$index+1];
        }
    }
    if (count($rows)>500) return class_batch_error('สร้างได้ไม่เกิน 500 คลาสต่อครั้ง กรุณาลดจำนวนช่วงเวลา');
    $conflict = class_candidate_conflict($rows);
    if ($conflict) return class_batch_error($conflict);
    usort($rows,static fn($a,$b)=>$a['starts_at']<=>$b['starts_at']);
    return ['ok'=>true,'rows'=>$rows,'term'=>$term,'count'=>count($rows),'message'=>'ตรวจแล้ว '.count($rows).' คลาส ไม่พบเวลาชน ห้องและผู้สอนพร้อมตามช่วงที่เลือก'];
}

/** Check candidates against each other, actual classes and unreconciled legacy plans. */
function class_candidate_conflict(array $rows, array $ignoreIds = []): ?string
{
    $cache = [];
    foreach ($rows as $i=>$row) {
        foreach (array_slice($rows,0,$i) as $other) {
            if (($row['room_id']===$other['room_id'] || $row['lecturer_user_id']===$other['lecturer_user_id']) && $row['starts_at']<$other['ends_at'] && $row['ends_at']>$other['starts_at']) return 'ช่วงเวลาที่เลือกชนกันเองในวันที่ '.thai_date_label($row['date']).' กรุณาแก้ช่วงเวลา';
        }
        $key = $row['room_id'].'|'.$row['lecturer_user_id'].'|'.$row['date'];
        $cache[$key] ??= one_off_busy_times($row['room_id'],$row['lecturer_user_id'],$row['date'],$ignoreIds);
        $start = (int)substr($row['starts_time'],0,2)*60+(int)substr($row['starts_time'],3,2);
        $end = (int)substr($row['ends_time'],0,2)*60+(int)substr($row['ends_time'],3,2);
        foreach ($cache[$key] as $busy) if ($start<$busy['end'] && $end>$busy['start']) return ($row['slot_index'] ?? $i+1).' · '.thai_date_label($row['date']).' '.$row['starts_time'].'–'.$row['ends_time'].' '.$busy['reason'].' กรุณาเปลี่ยนวัน ห้อง หรือเวลา';
    }
    return null;
}

function create_class_batch(array $input): array
{
    $connection = db();
    $connection->beginTransaction();
    try {
        // Acquire the SQLite write lock before reading availability. The same
        // transaction includes term creation, every lesson, unique QR and audit.
        $connection->exec('UPDATE rooms SET id=id WHERE id IN (SELECT id FROM rooms)');
        $preview = class_batch_preview($input);
        if (!$preview['ok']) return $preview;
        $termId = null;
        if ($term=$preview['term']) {
            $find = $connection->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?');
            $find->execute([$term['academic_year'],$term['semester']]);
            $termId = $find->fetchColumn();
            if (!$termId) {
                $connection->prepare("INSERT INTO academic_terms (name,academic_year,semester,starts_on,ends_on,status,created_at,updated_at) VALUES (?,?,?,?,?,'planned',?,?)")->execute([$term['name'],$term['academic_year'],$term['semester'],$term['starts_on'],$term['ends_on'],utc_now(),utc_now()]);
                $termId = (int)$connection->lastInsertId();
            }
        }
        $series = bin2hex(random_bytes(16));
        $ids = [];
        $insert = $connection->prepare("INSERT INTO class_sessions (room_id,lecturer_user_id,course_code,course_name,section,notes,starts_at,ends_at,status,checkin_mode,admission_lead_minutes,series_key,term_id,qr_token,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'open','scheduled',10,?,?,?,?,?)");
        foreach ($preview['rows'] as $row) {
            $insert->execute([$row['room_id'],$row['lecturer_user_id'],$row['course_code'],$row['course_name'],$row['section'],$row['notes'],$row['starts_at'],$row['ends_at'],$series,$termId ?: null,bin2hex(random_bytes(16)),utc_now(),utc_now()]);
            $ids[] = (int)$connection->lastInsertId();
        }
        audit_log('class_batch_created','class_session',$ids[0],['series_key'=>$series,'class_ids'=>$ids,'count'=>count($ids),'term_id'=>$termId,'admission_lead_minutes'=>10],(int)current_user()['id']);
        $connection->commit();
        return ['ok'=>true,'id'=>$ids[0],'ids'=>$ids,'count'=>count($ids),'series_key'=>$series,'term_id'=>$termId];
    } catch (Throwable) {
        return class_batch_error('บันทึกไม่สำเร็จ ไม่มีการบันทึกบางส่วน กรุณาตรวจเวลาแล้วลองอีกครั้ง');
    } finally {
        if ($connection->inTransaction()) $connection->rollBack();
    }
}

function class_change_preview(array $input): array
{
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'],['admin','lecturer'],true)) return class_batch_error('ไม่มีสิทธิ์จัดการคลาส');
    $selected = get_class_session((int)($input['class_id'] ?? 0));
    if (!$selected) return class_batch_error('ไม่พบคลาสหรือไม่มีสิทธิ์จัดการ');
    if (isset($input['revision']) && !hash_equals($selected['updated_at'],(string)$input['revision'])) return class_batch_error('มีการเปลี่ยนคลาสนี้จากอีกหน้าต่างแล้ว กรุณาเปิดใหม่ก่อนแก้ไข');
    $scope = (string)($input['scope'] ?? 'once');
    $operation = (string)($input['operation'] ?? 'edit');
    if (!in_array($scope,['once','following','all'],true) || !in_array($operation,['edit','cancel'],true)) return class_batch_error('เลือกขอบเขตและการดำเนินการให้ถูกต้อง');
    $sql = class_session_select_sql().' WHERE '.($scope==='once' || !$selected['series_key'] ? 'cs.id=:key' : 'cs.series_key=:key').' GROUP BY cs.id,r.id,u.id ORDER BY cs.starts_at';
    $query = db()->prepare($sql);
    $query->execute([':key'=>$scope==='once' || !$selected['series_key'] ? $selected['id'] : $selected['series_key']]);
    $targets = array_map('normalize_class_session',$query->fetchAll());
    if ($scope==='following') $targets = array_values(array_filter($targets,static fn($r)=>$r['starts_at'] >= $selected['starts_at']));
    $eligible = []; $protected = 0;
    foreach ($targets as $row) {
        if ($viewer['role']==='lecturer' && $row['lecturer_user_id']!==(int)$viewer['id']) return class_batch_error('ไม่มีสิทธิ์แก้ไขคลาสทั้งหมดในชุดนี้');
        // Preserve real history, even when changing/cancelling an entire series.
        if ($row['attendance_count']>0 || strtotime($row['starts_at'])<=time() || $row['status']==='cancelled') { $protected++; continue; }
        $eligible[] = $row;
    }
    if (!$eligible) return class_batch_error('ไม่มีคลาสที่เปลี่ยนได้ คลาสที่เริ่มแล้ว มีผู้ลงชื่อ หรือยกเลิกแล้วจะคงประวัติเดิม');
    $changed = [];
    $tz = new DateTimeZone((string)app_config('app.timezone','Asia/Bangkok'));
    if ($operation==='edit') {
        $date = (string)($input['class_date'] ?? '');
        $start = (string)($input['starts_time'] ?? '');
        $end = (string)($input['ends_time'] ?? '');
        if (!valid_iso_date($date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end) || $start >= $end) return class_batch_error('เลือกวันที่ เวลาเริ่ม และเวลาสิ้นสุดให้ถูกต้อง');
        $base = (new DateTimeImmutable($selected['starts_at']))->setTimezone($tz);
        $baseEnd = (new DateTimeImmutable($selected['ends_at']))->setTimezone($tz);
        if ($scope!=='once' && $date!==$base->format('Y-m-d')) return class_batch_error('การเปลี่ยนหลายคลาสคงวันเดิม หากต้องการย้ายวันให้เลือกเฉพาะครั้งนี้');
        $startDelta = strtotime($date.' '.$start)-strtotime($base->format('Y-m-d H:i'));
        $endDelta = strtotime($date.' '.$end)-strtotime($baseEnd->format('Y-m-d H:i'));
        $rooms = array_column(list_rooms(),null,'id');
        $roomId = (int)($input['room_id'] ?? 0);
        $notes = trim((string)($input['notes'] ?? ''));
        if (!isset($rooms[$roomId]) || $rooms[$roomId]['status']!=='available' || text_length($notes)>500) return class_batch_error('เลือกห้องที่พร้อมใช้งาน และหมายเหตุไม่เกิน 500 ตัวอักษร');
        foreach ($eligible as $row) {
            $a = (new DateTimeImmutable($row['starts_at']))->setTimezone($tz)->modify(sprintf('%+d seconds',$startDelta));
            $b = (new DateTimeImmutable($row['ends_at']))->setTimezone($tz)->modify(sprintf('%+d seconds',$endDelta));
            if ($a >= $b || $a->format('Y-m-d')!==$b->format('Y-m-d') || $b->getTimestamp()-$a->getTimestamp()>43200 || $a->getTimestamp()<=time()) return class_batch_error('เวลาใหม่ทำให้บางคลาสข้ามวัน ยาวเกิน 12 ชั่วโมง หรือย้อนหลัง กรุณาตรวจช่วงเวลา');
            $row['room_id'] = $roomId===$selected['room_id'] ? $row['room_id'] : $roomId;
            $row['room_code'] = $rooms[$row['room_id']]['code'];
            $row['notes'] = $notes===(string)$selected['notes'] ? $row['notes'] : $notes;
            $row['date'] = $a->format('Y-m-d'); $row['starts_time']=$a->format('H:i'); $row['ends_time']=$b->format('H:i');
            $row['starts_at']=$a->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $row['ends_at']=$b->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $changed[]=$row;
        }
        $conflict=class_candidate_conflict($changed,array_column($eligible,'id'));
        if ($conflict) return class_batch_error($conflict);
    } else {
        foreach ($eligible as $row) {
            $start=(new DateTimeImmutable($row['starts_at']))->setTimezone($tz);
            $row['date']=$start->format('Y-m-d');
            $row['starts_time']=$start->format('H:i');
            $row['ends_time']=(new DateTimeImmutable($row['ends_at']))->setTimezone($tz)->format('H:i');
            $row['status']='cancelled';
            $changed[]=$row;
        }
    }
    return ['ok'=>true,'rows'=>$changed,'before'=>$eligible,'count'=>count($changed),'protected'=>$protected,'operation'=>$operation,'scope'=>$scope,'message'=>($operation==='cancel'?'จะยกเลิก ':'จะเปลี่ยน ').count($changed).' คลาส · คงประวัติ '. $protected.' คลาสที่เริ่มแล้ว มีผู้ลงชื่อ หรือยกเลิกแล้ว'];
}

function apply_class_change(array $input): array
{
    $connection=db(); $connection->beginTransaction();
    try {
        $connection->exec('UPDATE rooms SET id=id WHERE id IN (SELECT id FROM rooms)');
        $preview=class_change_preview($input);
        if (!$preview['ok']) return $preview;
        foreach ($preview['rows'] as $row) {
            if ($preview['operation']==='cancel') $connection->prepare("UPDATE class_sessions SET status='cancelled',updated_at=? WHERE id=?")->execute([utc_now(),$row['id']]);
            else $connection->prepare('UPDATE class_sessions SET room_id=?,starts_at=?,ends_at=?,notes=?,updated_at=? WHERE id=?')->execute([$row['room_id'],$row['starts_at'],$row['ends_at'],$row['notes'],utc_now(),$row['id']]);
        }
        $snapshot=static fn($row)=>array_intersect_key($row,array_flip(['id','room_id','starts_at','ends_at','notes','status']));
        audit_log('class_series_'.$preview['operation'],'class_session',(int)$input['class_id'],['scope'=>$preview['scope'],'class_ids'=>array_column($preview['rows'],'id'),'protected'=>$preview['protected'],'before'=>array_map($snapshot,$preview['before']),'after'=>array_map($snapshot,$preview['rows'])],(int)current_user()['id']);
        $connection->commit();
        $preview['message']=($preview['operation']==='cancel'?'ยกเลิกแล้ว ':'แก้ไขแล้ว ').$preview['count'].' คลาส · คงประวัติ '.$preview['protected'].' คลาสที่เริ่มแล้ว มีผู้ลงชื่อ หรือยกเลิกแล้ว';
        return $preview;
    } catch (Throwable) { return class_batch_error('เปลี่ยนข้อมูลไม่สำเร็จ ไม่มีการเปลี่ยนบางส่วน กรุณาลองอีกครั้ง'); }
    finally { if ($connection->inTransaction()) $connection->rollBack(); }
}

function operational_class_list(array $input): array
{
    $viewer=current_user();
    if (!$viewer) return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
    $where=[]; $params=[];
    if ($viewer['role']==='lecturer') { $where[]='cs.lecturer_user_id=:viewer'; $params[':viewer']=$viewer['id']; }
    $range=in_array($input['range'] ?? '',['today','week','all'],true)?$input['range']:(empty($input['status'])?'today':'all');
    $date=valid_iso_date((string)($input['date'] ?? ''))?$input['date']:date('Y-m-d');
    if ($range!=='all') {
        $from=new DateTimeImmutable($date);
        if ($range==='week') $from=$from->modify('monday this week');
        $to=$from->modify($range==='week'?'+7 days':'+1 day');
        $where[]='cs.starts_at>=:from AND cs.starts_at<:to';
        $params[':from']=local_datetime_to_utc($from->format('Y-m-d').'T00:00');
        $params[':to']=local_datetime_to_utc($to->format('Y-m-d').'T00:00');
    }
    $search=trim((string)($input['q'] ?? ''));
    $status=(string)($input['status'] ?? '');
    if (in_array($status,['draft','closed','cancelled'],true)) { $where[]='cs.status=:status'; $params[':status']=$status; }
    elseif (in_array($status,['open','scheduled','overdue'],true)) {
        $where[]="cs.status='open'";
        $params[':now']=utc_now();
        if ($status==='overdue') $where[]="cs.checkin_mode='scheduled' AND cs.ends_at<=:now";
        else {
            $params[':early_now']=gmdate('Y-m-d\TH:i:s\Z',time()+600);
            $boundary='CASE WHEN cs.admission_lead_minutes=10 THEN :early_now ELSE :now END';
            $where[]=$status==='open'?"(cs.checkin_mode='manual' OR (cs.starts_at<=$boundary AND cs.ends_at>:now))":"cs.checkin_mode='scheduled' AND cs.starts_at>$boundary";
        }
    }
    if ($search!=='') { $where[]='(cs.course_code LIKE :q OR cs.course_name LIKE :q OR r.code LIKE :q)'; $params[':q']='%'.$search.'%'; }
    if ((int)($input['room_id'] ?? 0)) { $where[]='cs.room_id=:room'; $params[':room']=(int)$input['room_id']; }
    if (preg_match('/^[a-f0-9]{32}$/D',(string)($input['series'] ?? ''))) { $where[]='cs.series_key=:series'; $params[':series']=$input['series']; }
    $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
    $count=db()->prepare('SELECT COUNT(*) FROM class_sessions cs JOIN rooms r ON r.id=cs.room_id'.$whereSql); $count->execute($params);
    $total=(int)$count->fetchColumn(); $pages=max(1,(int)ceil($total/50)); $page=max(1,min($pages,(int)($input['p'] ?? 1)));
    $sql=class_session_select_sql().$whereSql.' GROUP BY cs.id,r.id,u.id ORDER BY cs.starts_at '.(($input['sort'] ?? '')==='desc'?'DESC':'ASC').',cs.id LIMIT 50 OFFSET '.(($page-1)*50);
    $query=db()->prepare($sql); $query->execute($params);
    return ['rows'=>array_map('normalize_class_session',$query->fetchAll()),'total'=>$total,'page'=>$page,'pages'=>$pages,'range'=>$range,'date'=>$date];
}
