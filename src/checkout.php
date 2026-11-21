<?php
declare(strict_types=1);

/** Close only new records with an explicit deadline; historical records stay intact. */
function close_expired_room_usage(PDO $connection, ?string $now = null): void
{
    $now ??= utc_now();
    $q=$connection->prepare("UPDATE attendance_records SET check_out_at=checkout_deadline_at, checkout_method='timeout' WHERE checkout_deadline_at IS NOT NULL AND check_out_at IS NULL AND checkout_deadline_at<=?");
    $q->execute([$now]);
    $q=$connection->prepare('UPDATE room_visits SET check_out_at=planned_end_at, auto_closed_at=? WHERE planned_end_at IS NOT NULL AND check_out_at IS NULL AND planned_end_at<=?');
    $q->execute([$now,$now]);
}

/** A room QR and identifier locate active records; no roster is disclosed. */
function find_active_checkout(string $token, string $code): array
{
    $room=public_room($token);
    $code=strtoupper(trim($code));
    if (!$room || !preg_match('/^[A-Z0-9._-]{4,30}$/D',$code)) return [];
    close_expired_room_usage(db());
    $q=db()->prepare("SELECT 'class' AS kind, ar.id, ar.student_name AS name, ar.check_in_at, ar.checkout_deadline_at AS deadline, cs.course_name AS purpose FROM attendance_records ar JOIN class_sessions cs ON cs.id=ar.class_session_id WHERE cs.room_id=? AND ar.student_code=? AND ar.checkout_deadline_at IS NOT NULL AND ar.check_out_at IS NULL");
    $q->execute([(int)$room['id'],$code]);
    $rows=$q->fetchAll();
    $q=db()->prepare("SELECT 'visit' AS kind, id, person_name AS name, check_in_at, planned_end_at AS deadline, purpose FROM room_visits WHERE room_id=? AND person_code=? AND planned_end_at IS NOT NULL AND check_out_at IS NULL");
    $q->execute([(int)$room['id'],$code]);
    return array_merge($rows,$q->fetchAll());
}

function confirm_room_checkout(string $token, string $code, string $kind, int $id): array
{
    $connection=db();
    $connection->beginTransaction();
    try {
        $room=public_room($token);
        if (!$room || !in_array($kind,['class','visit'],true) || !preg_match('/^[A-Z0-9._-]{4,30}$/D',strtoupper(trim($code)))) return visit_error('ข้อมูลไม่ถูกต้อง กรุณาสแกน QR และลองอีกครั้ง');
        $connection->prepare('UPDATE rooms SET id=id WHERE id=?')->execute([(int)$room['id']]);
        close_expired_room_usage($connection);
        $now=utc_now();
        if ($kind==='class') {
            $q=$connection->prepare("UPDATE attendance_records SET check_out_at=?,checkout_method='self' WHERE id=? AND student_code=? AND checkout_deadline_at>? AND check_out_at IS NULL AND class_session_id IN (SELECT id FROM class_sessions WHERE room_id=?)");
        } else {
            $q=$connection->prepare("UPDATE room_visits SET check_out_at=?,checkout_method='self' WHERE id=? AND person_code=? AND planned_end_at>? AND check_out_at IS NULL AND room_id=?");
        }
        $q->execute([$now,$id,strtoupper(trim($code)),$now,(int)$room['id']]);
        if ($q->rowCount()!==1) { $connection->commit(); return visit_error('ไม่พบรายการที่ยังเปิดอยู่ หรือถึงเวลาปิดอัตโนมัติแล้ว'); }
        audit_log('room_checkout_confirmed',$kind==='class'?'attendance_record':'room_visit',$id,['room_id'=>(int)$room['id']],null);
        $connection->commit();
        return ['ok'=>true,'at'=>$now];
    } catch (Throwable $e) {
        return visit_error('บันทึกเวลาออกไม่สำเร็จ กรุณาลองอีกครั้ง');
    } finally {
        if ($connection->inTransaction()) $connection->rollBack();
    }
}
