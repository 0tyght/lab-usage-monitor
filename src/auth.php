<?php

declare(strict_types=1);

function current_user(): ?array
{
    start_lums_session();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null) {
        return null;
    }

    $lifetime = (int) app_config('security.session_lifetime', 7200);
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && $lastActivity < time() - $lifetime) {
        logout_user();

        return null;
    }

    $statement = db()->prepare(
        'SELECT id, email, full_name, role, student_code, department, last_login_at
         FROM users
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([':id' => (int) $userId]);
    $user = $statement->fetch();

    if (!$user) {
        logout_user();

        return null;
    }

    $_SESSION['last_activity'] = time();
    $user['id'] = (int) $user['id'];
    $user['name'] = $user['full_name'];

    return $user;
}

function require_auth(array|string|null $roles = null): array
{
    $user = current_user();
    if ($user === null) {
        start_lums_session();
        $_SESSION['intended_url'] = safe_return_path($_SERVER['REQUEST_URI'] ?? null, 'index.php');
        set_flash('warning', 'กรุณาเข้าสู่ระบบ', 'เข้าสู่ระบบก่อนใช้งานส่วนนี้');

        if (!headers_sent()) {
            header('Location: index.php?page=login', true, 302);
            exit;
        }

        throw new RuntimeException('ต้องเข้าสู่ระบบก่อนใช้งาน');
    }

    if ($roles !== null) {
        $allowedRoles = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role'], $allowedRoles, true)) {
            http_response_code(403);
            set_flash('error', 'ไม่มีสิทธิ์ใช้งาน', 'บัญชีนี้ไม่สามารถเปิดส่วนที่ร้องขอได้');

            if (!headers_sent()) {
                header('Location: index.php', true, 302);
                exit;
            }

            throw new RuntimeException('ไม่มีสิทธิ์ใช้งานส่วนนี้');
        }
    }

    return $user;
}

function attempt_login(string $email, string $password): array
{
    start_lums_session();

    $email = strtolower(trim($email));
    $errors = [];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'กรุณากรอกอีเมลให้ถูกต้อง';
    }
    if ($password === '') {
        $errors['password'] = 'กรุณากรอกรหัสผ่าน';
    }
    if ($errors !== []) {
        return [
            'ok' => false,
            'success' => false,
            'message' => implode(' ', array_values($errors)),
            'errors' => $errors,
        ];
    }

    $connection = db();
    $attemptKey = hash('sha256', $email . '|' . (client_ip() ?? 'local'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $attempt = login_attempt($connection, $attemptKey);
    $lockedUntil = isset($attempt['locked_until']) && is_string($attempt['locked_until'])
        ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $attempt['locked_until'], new DateTimeZone('UTC'))
        : false;

    if ($lockedUntil instanceof DateTimeImmutable && $lockedUntil > $now) {
        return [
            'ok' => false,
            'success' => false,
            'message' => 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอสักครู่แล้วลองใหม่',
            'errors' => ['form' => 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอสักครู่แล้วลองใหม่'],
            'retry_after' => max(1, $lockedUntil->getTimestamp() - $now->getTimestamp()),
        ];
    }

    $statement = $connection->prepare(
        'SELECT id, email, password_hash, full_name, role, student_code, department, is_active, last_login_at
         FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();

    static $dummyHash = null;
    $dummyHash ??= password_hash('lums-invalid-password', PASSWORD_DEFAULT);
    $passwordHash = $user['password_hash'] ?? $dummyHash;
    $validPassword = is_string($passwordHash) && password_verify($password, $passwordHash);
    $isActive = $user && (int) $user['is_active'] === 1;
    $isBackOfficeUser = $user && in_array($user['role'], ['admin', 'lecturer'], true);

    if (!$user || !$validPassword || !$isActive || !$isBackOfficeUser) {
        $retryAfter = record_failed_login($connection, $attemptKey, $attempt, $now);
        usleep(random_int(100000, 220000));

        $result = [
            'ok' => false,
            'success' => false,
            'message' => $user && $validPassword && $isActive && !$isBackOfficeUser
                ? 'บัญชีนี้ไม่มีสิทธิ์เข้าใช้ระบบสำหรับอาจารย์และผู้ดูแล'
                : 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            'errors' => ['form' => $user && $validPassword && $isActive && !$isBackOfficeUser
                ? 'บัญชีนี้ไม่มีสิทธิ์เข้าใช้ระบบสำหรับอาจารย์และผู้ดูแล'
                : 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'],
        ];
        if ($retryAfter !== null) {
            $result['retry_after'] = $retryAfter;
        }

        return $result;
    }

    $connection->prepare('DELETE FROM login_attempts WHERE attempt_key = :attempt_key')
        ->execute([':attempt_key' => $attemptKey]);

    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
        $connection->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':updated_at' => utc_now(),
                ':id' => $user['id'],
            ]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['last_activity'] = time();
    unset($_SESSION['_csrf_token'], $_SESSION['_csrf_issued_at']);

    $loggedInAt = utc_now();
    $connection->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id')
        ->execute([
            ':last_login_at' => $loggedInAt,
            ':updated_at' => $loggedInAt,
            ':id' => $user['id'],
        ]);

    audit_log('login', 'user', (int) $user['id'], [], (int) $user['id']);
    unset($user['password_hash'], $user['is_active']);
    $user['id'] = (int) $user['id'];
    $user['last_login_at'] = $loggedInAt;

    $user['name'] = $user['full_name'];

    return ['ok' => true, 'success' => true, 'user' => $user];
}

function logout_user(): array
{
    start_lums_session();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId !== false && $userId !== null) {
        try {
            audit_log('logout', 'user', (int) $userId, [], (int) $userId);
        } catch (Throwable) {
            // Logout must still succeed if audit persistence is unavailable.
        }
    }

    $_SESSION = [];
    session_regenerate_id(true);

    return ['ok' => true, 'success' => true];
}

function login_attempt(PDO $connection, string $attemptKey): ?array
{
    $statement = $connection->prepare('SELECT * FROM login_attempts WHERE attempt_key = :attempt_key LIMIT 1');
    $statement->execute([':attempt_key' => $attemptKey]);
    $attempt = $statement->fetch();

    return $attempt ?: null;
}

function record_failed_login(
    PDO $connection,
    string $attemptKey,
    ?array $attempt,
    DateTimeImmutable $now
): ?int {
    $window = (int) app_config('security.login_window_seconds', 900);
    $maximum = (int) app_config('security.login_max_attempts', 5);
    $windowStarted = isset($attempt['window_started_at'])
        ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', (string) $attempt['window_started_at'], new DateTimeZone('UTC'))
        : false;

    if (!$windowStarted instanceof DateTimeImmutable || $windowStarted < $now->modify(sprintf('-%d seconds', $window))) {
        $attempts = 1;
        $windowStarted = $now;
    } else {
        $attempts = (int) ($attempt['attempts'] ?? 0) + 1;
    }

    $lockedUntil = $attempts >= $maximum ? $now->modify(sprintf('+%d seconds', $window)) : null;
    if ($attempt === null) {
        $statement = $connection->prepare(
            'INSERT INTO login_attempts (attempt_key, attempts, window_started_at, locked_until, last_attempt_at)
             VALUES (:attempt_key, :attempts, :window_started_at, :locked_until, :last_attempt_at)'
        );
    } else {
        $statement = $connection->prepare(
            'UPDATE login_attempts
             SET attempts = :attempts, window_started_at = :window_started_at,
                 locked_until = :locked_until, last_attempt_at = :last_attempt_at
             WHERE attempt_key = :attempt_key'
        );
    }
    $statement->execute([
        ':attempt_key' => $attemptKey,
        ':attempts' => $attempts,
        ':window_started_at' => $windowStarted->format('Y-m-d\TH:i:s\Z'),
        ':locked_until' => $lockedUntil?->format('Y-m-d\TH:i:s\Z'),
        ':last_attempt_at' => $now->format('Y-m-d\TH:i:s\Z'),
    ]);

    return $lockedUntil ? $window : null;
}
