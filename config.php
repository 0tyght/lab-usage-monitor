<?php

declare(strict_types=1);

/**
 * LUMS application configuration.
 *
 * Keep secrets out of this file. Every deployment-specific value can be
 * supplied as an environment variable, which also makes the application
 * straightforward to run in a Docker container later.
 */

$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
};

$envBool = static function (string $key, bool $default = false) use ($env): bool {
    $value = $env($key);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

$databasePath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lums.sqlite';
$defaultBaseUrl = $env('RENDER_EXTERNAL_URL', 'http://localhost/lab-usage-monitor');

return [
    'app' => [
        'name' => 'LUMS',
        'env' => $env('APP_ENV', 'local'),
        'debug' => $envBool('APP_DEBUG', true),
        'timezone' => $env('APP_TIMEZONE', 'Asia/Bangkok'),
        'base_url' => rtrim((string) $env('APP_URL', $defaultBaseUrl), '/'),
        'gateway_origin' => $env('LUMS_GATEWAY_ORIGIN', ''),
        'gateway_id' => $env('LUMS_GATEWAY_ID', ''),
    ],
    'database' => [
        'dsn' => $env('LUMS_DB_DSN', 'sqlite:' . $databasePath),
        'username' => $env('LUMS_DB_USER'),
        'password' => $env('LUMS_DB_PASSWORD'),
    ],
    'security' => [
        'session_name' => $env('LUMS_SESSION_NAME', 'lums_session'),
        'session_path' => $env('LUMS_SESSION_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions'),
        'session_lifetime' => max(900, (int) $env('LUMS_SESSION_LIFETIME', '7200')),
        'csrf_ttl' => max(900, (int) $env('LUMS_CSRF_TTL', '7200')),
        'login_max_attempts' => max(3, (int) $env('LUMS_LOGIN_MAX_ATTEMPTS', '5')),
        'login_window_seconds' => max(60, (int) $env('LUMS_LOGIN_WINDOW', '900')),
        'secure_cookies' => $envBool('LUMS_SECURE_COOKIES', false),
    ],
    'bootstrap' => [
        'admin_name' => $env('LUMS_ADMIN_NAME', 'ผู้ดูแลระบบ LUMS'),
        'admin_email' => $env('LUMS_ADMIN_EMAIL'),
        'admin_password' => $env('LUMS_ADMIN_PASSWORD'),
    ],
];
