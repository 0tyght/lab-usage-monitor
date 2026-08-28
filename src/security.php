<?php

declare(strict_types=1);

function start_lums_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (PHP_SAPI !== 'cli' && headers_sent()) {
        throw new RuntimeException('ไม่สามารถเริ่ม session ได้หลังจากมีการส่งข้อมูลออกแล้ว');
    }

    $secure = (bool) app_config('security.secure_cookies', false);
    if (!$secure && isset($_SERVER['HTTPS'])) {
        $secure = $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $sessionPath = (string) app_config('security.session_path', '');
    if ($sessionPath !== '') {
        if (!is_dir($sessionPath) && !mkdir($sessionPath, 0775, true) && !is_dir($sessionPath)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ session ได้');
        }
        session_save_path($sessionPath);
    }

    session_name((string) app_config('security.session_name', 'lums_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_lums_session();

    $ttl = (int) app_config('security.csrf_ttl', 7200);
    $issuedAt = (int) ($_SESSION['_csrf_issued_at'] ?? 0);
    $token = $_SESSION['_csrf_token'] ?? null;

    if (!is_string($token) || $token === '' || $issuedAt < time() - $ttl) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['_csrf_issued_at'] = time();
    }

    return $token;
}

function verify_csrf(?string $token = null): bool
{
    start_lums_session();

    if ($token === null) {
        $postedToken = $_POST['csrf_token'] ?? $_POST['_csrf'] ?? null;
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $token = is_string($postedToken) ? $postedToken : (is_string($headerToken) ? $headerToken : null);
    }

    $stored = $_SESSION['_csrf_token'] ?? null;
    $issuedAt = (int) ($_SESSION['_csrf_issued_at'] ?? 0);
    $ttl = (int) app_config('security.csrf_ttl', 7200);

    $valid = is_string($token)
        && is_string($stored)
        && $issuedAt >= time() - $ttl
        && hash_equals($stored, $token);

    if (!$valid) {
        set_flash('error', 'คำขอหมดอายุ', 'กรุณาลองทำรายการอีกครั้งเพื่อความปลอดภัย');
        if (!headers_sent()) {
            header('Location: ' . safe_return_path($_SERVER['REQUEST_URI'] ?? null, 'index.php'), true, 303);
            exit;
        }

        throw new RuntimeException('CSRF token validation failed');
    }

    return true;
}

function set_flash(string $type, string $title, string $message = ''): void
{
    start_lums_session();

    $allowed = ['success', 'error', 'warning', 'info'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    $_SESSION['_flash'][] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
    ];
}

/**
 * Consume flash messages. Supplying a type only consumes messages of that type.
 *
 * @return array<int, array{type: string, message: string}>
 */
function flash(?string $type = null): array
{
    start_lums_session();

    $messages = isset($_SESSION['_flash']) && is_array($_SESSION['_flash'])
        ? $_SESSION['_flash']
        : [];

    if ($type === null) {
        unset($_SESSION['_flash']);

        return $messages;
    }

    $matched = [];
    $remaining = [];
    foreach ($messages as $message) {
        if (($message['type'] ?? null) === $type) {
            $matched[] = $message;
        } else {
            $remaining[] = $message;
        }
    }
    $_SESSION['_flash'] = $remaining;

    return $matched;
}

/**
 * Consume the oldest flash message in the shape expected by the web shell.
 *
 * @return array{type: string, title: string, message: string}|null
 */
function pull_flash(): ?array
{
    start_lums_session();

    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash']) || $_SESSION['_flash'] === []) {
        return null;
    }

    $message = array_shift($_SESSION['_flash']);
    if ($_SESSION['_flash'] === []) {
        unset($_SESSION['_flash']);
    }

    return [
        'type' => (string) ($message['type'] ?? 'info'),
        'title' => (string) ($message['title'] ?? ''),
        'message' => (string) ($message['message'] ?? ''),
    ];
}

function is_post_request(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function safe_return_path(?string $path, string $fallback = 'index.php'): string
{
    if (!is_string($path) || $path === '' || str_contains($path, "\r") || str_contains($path, "\n")) {
        return $fallback;
    }

    $parts = parse_url($path);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($path, '//')) {
        return $fallback;
    }

    return $path;
}
