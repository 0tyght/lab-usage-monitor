<?php
declare(strict_types=1);
putenv('APP_ENV=local');
putenv('LUMS_DB_DSN=sqlite::memory:');
putenv('LUMS_SESSION_PATH='.sys_get_temp_dir().'/lums-reentry-migration-'.bin2hex(random_bytes(8)));
require dirname(__DIR__).'/src/bootstrap.php';
initialize_database(db(),true);
$before=(int)db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$roomBefore=(int)db()->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
db()->exec('DROP TABLE attendance_return_visits');
initialize_database(db(),false);
initialize_database(db(),false);
if ((int)db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn()!==$before
    || (int)db()->query('SELECT COUNT(*) FROM rooms')->fetchColumn()!==$roomBefore
    || db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='attendance_return_visits'")->fetchColumn()!=1
    || db()->query('PRAGMA foreign_key_check')->fetch()!==false) {
    throw new RuntimeException('Upgrade must preserve existing records and foreign keys and be repeatable.');
}
echo "PASS: additive re-entry migration preserves records and foreign keys on repeated initialization\n";
