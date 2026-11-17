<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    close_expired_room_usage(db());
} catch (Throwable $exception) {
    error_log('LUMS timeout worker failed: '.$exception->getMessage());
    exit(1);
}
