<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    if (app_config('app.env') !== 'production' || !app_config('security.secure_cookies')) {
        throw new RuntimeException('Public mode requires production settings and secure cookies.');
    }
    if (app_config('app.base_url') !== 'https://0tyght.github.io/lab-usage-monitor'
        || app_config('app.gateway_origin') !== 'https://0tyght.github.io'
        || !preg_match('/^[a-f0-9]{32}$/D', (string)app_config('app.gateway_id'))) {
        throw new RuntimeException('The permanent gateway URL or gateway ID is not configured correctly.');
    }
    $users = db()->query('SELECT role, password_hash FROM users WHERE is_active = 1')->fetchAll();
    if (!array_filter($users, static fn(array $user): bool => $user['role'] === 'admin')) {
        throw new RuntimeException('An active administrator is required.');
    }
    foreach ($users as $user) {
        foreach (['admin123', 'lecturer123', 'student123', 'password', '12345678'] as $demoPassword) {
            if (password_verify($demoPassword, $user['password_hash'])) {
                throw new RuntimeException('A demo password is still active. Do not expose this database.');
            }
        }
    }
    echo "Online preflight passed; no demo passwords, production mode, secure cookies, permanent QR gateway.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
