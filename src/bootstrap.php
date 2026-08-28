<?php

declare(strict_types=1);

$lumsConfig = require dirname(__DIR__) . '/config.php';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services.php';

date_default_timezone_set((string) app_config('app.timezone', 'Asia/Bangkok'));

if (PHP_SAPI !== 'cli') {
    start_lums_session();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=(self), geolocation=(), microphone=()");
}
