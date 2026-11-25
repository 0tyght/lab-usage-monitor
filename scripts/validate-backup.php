<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !is_file($argv[1])) exit(1);
try {
    $db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') exit(1);
    foreach (['users','rooms','class_sessions','attendance_records','room_visits'] as $table) {
        $check = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
        $check->execute([$table]);
        if (!(int)$check->fetchColumn()) exit(1);
    }
} catch (Throwable) { exit(1); }
