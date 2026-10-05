#!/usr/bin/env php
<?php
declare(strict_types=1);

use Checkerboard\Lobby\Database;

require dirname(__DIR__) . '/bootstrap.php';

try {
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The PDO SQLite extension is required.');
    }
    $path = getenv('LOBBY_DATABASE_PATH');
    $path = $path === false || trim($path) === '' ? dirname(__DIR__) . '/var/lobby.sqlite3' : trim($path);
    if ($path !== ':memory:' && !is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    $database = new Database($path);
    $database->migrate(dirname(__DIR__) . '/migrations');
    fwrite(STDOUT, "SQLite migrations are current.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . str_replace(["\r", "\n"], ' ', $error->getMessage()) . "\n");
    exit(1);
}
