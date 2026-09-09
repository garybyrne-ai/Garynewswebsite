<?php
declare(strict_types=1);

/** Create a consistent SQLite backup: php scripts/backup.php /path/outside/public/menews-YYYYMMDD.sqlite */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Database;

try {
    $target = $argv[1] ?? '';
    if ($target === '' || is_file($target)) {
        throw new RuntimeException('Supply a new absolute backup filename outside public/.');
    }
    $parent = realpath(dirname($target));
    $public = realpath(ME_ROOT . '/public');
    if (!$parent || $parent === $public || str_starts_with($parent, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Backup directory must exist and be outside public/.');
    }
    Database::pdo()->exec('VACUUM INTO ' . Database::pdo()->quote($target));
    echo "Database backup saved to {$target}. Back up storage/uploads separately.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
