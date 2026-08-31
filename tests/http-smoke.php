<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('LUMS_HTTP_TEST') !== '1') {
    http_response_code(404);
    exit(1);
}

// Run only inside the disposable CI container, never against a real deployment.
$root = dirname(__DIR__);
$base = 'http://127.0.0.1';
$checks = 0;
$expect = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS: $message\n";
};
$request = static function (string $path, string $method = 'GET', string $origin = '') use ($base): array {
    $context = stream_context_create(['http' => [
        'method' => $method,
        'timeout' => 5,
        'ignore_errors' => true,
        'follow_location' => 0,
        'header' => $origin !== '' ? 'Origin: ' . $origin . "\r\n" : '',
    ]]);
    $body = @file_get_contents($base . $path, false, $context);
    $headers = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0] ?? '', $match);

    return [(int)($match[1] ?? 0), $body === false ? '' : $body, $headers];
};

// The entrypoint initializes a fresh production database before starting Apache.
for ($attempt = 0; $attempt < 30; $attempt++) {
    [$status, $body] = $request('/?health=1');
    if ($status === 200) {
        break;
    }
    usleep(500000);
}
$expect($status === 200 && (json_decode($body, true)['status'] ?? '') === 'ok', 'HTTP health check connects to the database');
if (getenv('LUMS_GATEWAY_ID')) {
    [$status, $body, $headers] = $request('/?health=1', 'GET', 'https://0tyght.github.io');
    $expect((json_decode($body, true)['gatewayId'] ?? '') === getenv('LUMS_GATEWAY_ID'), 'Gateway can verify the intended LUMS instance');
    $expect(in_array('Access-Control-Allow-Origin: https://0tyght.github.io', $headers, true), 'Health CORS permits the exact Pages origin');
    $expect(!preg_grep('/^Access-Control-Allow-Credentials:/i', $headers), 'Gateway health never grants credentialed CORS');
    [$status, $body, $headers] = $request('/?health=1', 'GET', 'https://untrusted.example');
    $expect(!preg_grep('/^Access-Control-Allow-Origin:/i', $headers), 'Health CORS rejects unrelated origins');
    [$status, $body, $headers] = $request('/', 'GET', 'https://0tyght.github.io');
    $expect(!preg_grep('/^Access-Control-Allow-Origin:/i', $headers), 'Login and application pages do not enable CORS');
}
[$status, $body] = $request('/');
$expect($status === 200 && str_contains($body, 'name="password"'), 'Public entry point renders the login form');
$expect(!str_contains($body, 'admin123') && !str_contains($body, 'admin@lums.local'), 'Production login does not expose demo credentials');
foreach (['app.css', 'app.js', 'planning.css', 'planning.js', 'one-off.js', 'class-panel.css', 'class-panel.js', 'timetable.css', 'timetable.js', 'favicon.svg', 'qrcode.min.js'] as $asset) {
    [$status, $body] = $request('/assets/' . $asset);
    $expect($status === 200 && strlen($body) > 0, "Static asset is available: $asset");
}

$privateFiles = [
    'storage/lums.sqlite',
    'config.php',
    'src/database.php',
    'src/planning.php',
    'views/calendar.php',
    'views/class-panel.php',
    'scripts/init.php',
    'tests/ux-regression.php',
    'tests/http-smoke.php',
    'docker/site.conf',
    'render.yaml',
    'start-local.ps1',
];
foreach ($privateFiles as $path) {
    $expect(is_file($root . '/' . $path), "Private file actually exists behind the public root: $path");
    foreach (['GET', 'HEAD'] as $method) {
        [$status] = $request('/' . $path, $method);
        $expect(in_array($status, [403, 404], true), "$method cannot access private file: $path");
    }
}
foreach (['/assets/', '/storage/', '/storage/sessions/', '/.env', '/.env.production', '/.git/config', '/assets/../storage/lums.sqlite', '/%2e%2e/storage/lums.sqlite', '/index.php/../storage/lums.sqlite'] as $path) {
    [$status] = $request($path);
    $expect(in_array($status, [400, 403, 404], true), "Internal path or directory listing is not exposed: $path");
}
$expect(!file_exists($root . '/.git'), 'Git metadata is excluded from the image');
$expect(glob($root . '/.env*') === [], 'Environment files are excluded from the image');
echo "HTTP deployment checks passed: $checks\n";
