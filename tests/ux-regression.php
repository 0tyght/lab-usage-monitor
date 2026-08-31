<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests never connect to the application's existing database or session store.
$fixtureDirectory = getenv('LUMS_UX_FIXTURE_DIR') ?: null;
$testDirectory = $fixtureDirectory ?: sys_get_temp_dir() . '/lums-ux-' . bin2hex(random_bytes(8));
if ($fixtureDirectory && (!is_dir($fixtureDirectory) || count(scandir($fixtureDirectory)) !== 2)) {
    throw new RuntimeException('UI fixture directory must exist and be empty.');
}
putenv('APP_ENV=local');
putenv('LUMS_DB_DSN=' . ($fixtureDirectory ? 'sqlite:' . $fixtureDirectory . '/fixture.sqlite' : 'sqlite::memory:'));
putenv('LUMS_SESSION_PATH=' . $testDirectory . '/sessions');
require dirname(__DIR__) . '/src/bootstrap.php';

initialize_database(db(), true);
start_lums_session();
$adminId = (int)db()->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$lecturerId = (int)db()->query("SELECT id FROM users WHERE role='lecturer' LIMIT 1")->fetchColumn();
$roomId = (int)db()->query("SELECT id FROM rooms WHERE status='available' LIMIT 1")->fetchColumn();
$_SESSION['user_id'] = $adminId;
$_SESSION['last_activity'] = time();

$insert = db()->prepare('INSERT INTO class_sessions (room_id, lecturer_user_id, course_code, course_name, section, starts_at, ends_at, status, qr_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$fixtures = [
    'open' => ['open', -3600, 3600],
    'scheduled' => ['open', 7200, 10800],
    'overdue' => ['open', -10800, -7200],
    'draft' => ['draft', 14400, 18000],
    'closed' => ['closed', -18000, -14400],
    'cancelled' => ['cancelled', 21600, 25200],
];
$ids = [];
foreach ($fixtures as $displayStatus => [$storedStatus, $startOffset, $endOffset]) {
    $insert->execute([$roomId, $adminId, 'UX-' . strtoupper($displayStatus), 'ปฏิบัติการทดสอบระบบ — ข้อมูลสมมติ', 'ทดสอบ', gmdate('Y-m-d\TH:i:s\Z', time() + $startOffset), gmdate('Y-m-d\TH:i:s\Z', time() + $endOffset), $storedStatus, bin2hex(random_bytes(16)), utc_now(), utc_now()]);
    $ids[$displayStatus] = (int)db()->lastInsertId();
}

$checks = 0;
$expect = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
};
foreach ($fixtures as $displayStatus => $_) {
    $rows = list_class_sessions(['q'=>'UX-', 'status'=>$displayStatus]);
    $expect(count($rows) === 1, 'Filter must return exactly one ' . $displayStatus . ' class.');
    $expect($rows[0]['id'] === $ids[$displayStatus], 'Filter returned the wrong class.');
    $expect($rows[0]['display_status'] === $displayStatus, 'Filter and visible status disagree.');
}
$expect(count(list_class_sessions(['q'=>'UX-'])) === 6, 'Unfiltered result must preserve all statuses.');
$expect(list_class_sessions(['q'=>'no-matching-ux-class']) === [], 'Unmatched search must be empty.');
$_SESSION['user_id'] = $lecturerId;
$expect(list_class_sessions(['q'=>'UX-', 'status'=>'overdue']) === [], 'Lecturers must not see another lecturer\'s expired classes.');
$openToken = get_class_session($ids['open'], false)['qr_token'];
$attendanceInput = ['student_code'=>'99000001', 'student_name'=>'นักศึกษาทดสอบ ระบบสมมติ', 'client_request_id'=>'ux-attendance-0001'];
$expect(!register_student_attendance($openToken, ['student_code'=>'', 'student_name'=>''])['ok'], 'Empty student form must be rejected.');
$expect(register_student_attendance($openToken, $attendanceInput)['ok'], 'Valid check-in must succeed.');
$duplicate = register_student_attendance($openToken, $attendanceInput);
$expect($duplicate['ok'] && !empty($duplicate['duplicate']), 'Repeated submission must be identified as a duplicate.');
$expect((int)db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn() === 1, 'Duplicate submission must not add a second attendance row.');
foreach (['scheduled', 'overdue', 'draft', 'closed', 'cancelled'] as $notOpen) {
    $closedToken = get_class_session($ids[$notOpen], false)['qr_token'];
    $expect(!register_student_attendance($closedToken, $attendanceInput)['ok'], $notOpen . ' class must reject check-in.');
}
$termInput = ['academic_year'=>'2569', 'semester'=>'2'];
$expect(!create_academic_term($termInput)['ok'], 'Lecturers cannot create academic terms.');
$_SESSION['user_id'] = $adminId;
$termCount = count(list_academic_terms());
$expect(!create_academic_term([])['ok'], 'Empty term form is rejected.');
foreach (['academic_year'=>'2499', 'semester'=>'4'] as $field=>$invalid) {
    $result = create_academic_term(array_replace($termInput, [$field=>$invalid]));
    $expect(!$result['ok'] && isset($result['errors'][$field]), 'Term validation identifies invalid field: ' . $field);
}
$expect(count(list_academic_terms()) === $termCount, 'Failed term validation must not create records.');
$termResult = create_academic_term($termInput);
$expect($termResult['ok'], 'Valid term is saved.');
$expect(get_academic_term($termResult['id'])['name'] === '2569/2', 'Term code is generated from year and semester.');
$expect(get_academic_term($termResult['id'])['starts_on'] === '2026-11-16', 'Official start date is saved without any date field.');
$expect(get_academic_term($termResult['id'])['ends_on'] === '2027-03-21', 'Official inclusive end date is saved without any date field.');
$duplicateTerm = create_academic_term($termInput);
$expect(!$duplicateTerm['ok'] && isset($duplicateTerm['errors']['academic_year']), 'Duplicate term returns an actionable field error.');
$expect(count(list_academic_terms()) === $termCount + 1, 'Duplicate submit does not create another term.');
session_write_close();
echo 'PASS: ' . $checks . " UX regression checks\n";
if ($fixtureDirectory) echo 'UI fixture: ' . $fixtureDirectory . "\n";
