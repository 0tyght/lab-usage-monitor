<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';

$path = '/var/www/html/storage/backups/handover-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create backup directory');
db()->exec('VACUUM INTO ' . db()->quote($path));
$copy = new PDO('sqlite:' . $path);
if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Backup integrity check failed');
echo $path . PHP_EOL;
