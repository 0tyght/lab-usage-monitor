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
$request = static function (string $path, ?array $data = null) use (&$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', $cookies);
    $context = stream_context_create(['http'=>[
        'method'=>$data === null ? 'GET' : 'POST', 'timeout'=>5,
        'ignore_errors'=>true, 'follow_location'=>0,
        'header'=>implode("\r\n", $headers),
        'content'=>$data === null ? '' : http_build_query($data),
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
$expect(str_contains($empty, 'ยังไม่มีภาคการศึกษา'), 'Fresh database shows the empty-term state');
$expect(!str_contains($empty, 'data-schedule-form'), 'No unusable schedule form before the first term');
$expect(str_contains($empty, 'data-open-term'), 'Empty-term state offers the popup action');
[, $fallback] = $request('/?page=schedule&new_term=1');
$expect((bool)preg_match('/<dialog[^>]+\bopen\b/', $fallback), 'Direct link opens the form without JavaScript');

$context = '/?page=schedule&room_id=2&week=2026-08-31&weekend=1&q=TEST';
[$status, , $location] = $request($context, ['action'=>'create_term', 'csrf_token'=>$csrf($fallback), 'term_name'=>'<script>test</script>', 'academic_year'=>'2499']);
$expect($status === 302 && str_contains($location, 'new_term=1'), 'Invalid form redirects back to the dialog');
$expect(str_contains($location, 'room_id=2') && str_contains($location, 'week=2026-08-31') && str_contains($location, 'weekend=1') && str_contains($location, 'q=TEST'), 'Validation preserves timetable filters');
[, $invalid] = $request('/' . $location);
$expect(str_contains($invalid, 'data-term-error-summary'), 'Server errors have a dedicated dialog summary');
$expect(str_contains($invalid, '&lt;script&gt;test&lt;/script&gt;') && !str_contains($invalid, '<script>test</script>'), 'Submitted draft is retained and safely escaped');
$expect(str_contains($invalid, 'value="2499"') && str_contains($invalid, 'aria-invalid="true"'), 'Invalid fields retain their values and accessible error state');

$term = ['action'=>'create_term', 'term_name'=>'ภาคการศึกษาทดสอบ 2/2569', 'academic_year'=>'2569', 'semester'=>'2', 'term_starts_on'=>'2026-11-01', 'term_ends_on'=>'2027-03-31'];
[$status, , $location] = $request($context, $term + ['csrf_token'=>$csrf($invalid)]);
$expect($status === 302 && str_contains($location, 'term_id=') && !str_contains($location, 'new_term'), 'Success selects the new term and closes the dialog');
[, $success] = $request('/' . $location);
$expect(str_contains($success, 'สร้างภาคการศึกษาแล้ว'), 'Success feedback is visible');
$expect(!preg_match('/<dialog[^>]+\bopen\b/', $success), 'Dialog is closed after a successful save');
[$status, , $location] = $request($context, $term + ['csrf_token'=>$csrf($success)]);
[, $duplicate] = $request('/' . $location);
$expect($status === 302 && str_contains($duplicate, 'มีปีและภาคการศึกษานี้อยู่แล้ว'), 'Repeated save returns a duplicate-term error');
$expect(!preg_match('/id="schedule-form".*?alert--error/s', strstr($duplicate, '<div class="schedule-admin-stack">', true)), 'Term errors do not leak into the schedule form');
require dirname(__DIR__) . '/src/bootstrap.php';
$expect((int)db()->query('SELECT COUNT(*) FROM academic_terms')->fetchColumn() === 1, 'Duplicate save does not create a second record');
echo "Term dialog HTTP checks passed: $checks\n";
