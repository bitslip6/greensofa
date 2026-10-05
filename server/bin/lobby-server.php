#!/usr/bin/env php
<?php
declare(strict_types=1);

use Checkerboard\Lobby\Config;
use Checkerboard\Lobby\Database;
use Checkerboard\Lobby\LobbyRepository;
use Checkerboard\Lobby\LobbyServer;
use Checkerboard\Lobby\Logger;
use Checkerboard\Lobby\Protocol;
use Checkerboard\Lobby\SystemClock;

require dirname(__DIR__) . '/bootstrap.php';

function startupStatus(string $message): void
{
    fwrite(STDOUT, '[STARTUP] ' . $message . PHP_EOL);
}

try {
    startupStatus('Using PHP ' . PHP_VERSION . ' at ' . PHP_BINARY);
    startupStatus('Loading and validating environment configuration...');
    $config = Config::fromEnvironment();
    $devMode = (bool) $config->get('devMode');
    startupStatus(
        'Configuration valid. OpenSwoole ' . (phpversion('openswoole') ?: 'unknown')
        . ', ' . ($devMode ? 'DEV MODE with SSL disabled' : 'production TLS enabled')
        . ', PDO SQLite enabled.'
    );
    if ($devMode) {
        startupStatus('WARNING: Plain ws:// development mode is active; traffic and identity tokens are not encrypted.');
        if (!in_array((string) $config->get('host'), ['127.0.0.1', '::1', 'localhost'], true)) {
            startupStatus('WARNING: The development server is exposed beyond loopback at ' . $config->get('host') . '.');
        }
    }
    $logger = new Logger((string) $config->get('logLevel'));
    startupStatus('Opening SQLite database and applying migrations...');
    $database = new Database((string) $config->get('databasePath'));
    $database->migrate(dirname(__DIR__) . '/migrations');
    startupStatus('SQLite database and migrations ready.');
    $clock = new SystemClock();
    $repository = new LobbyRepository($database, $clock, (int) $config->get('reservationSeconds'));
    $protocol = new Protocol(
        (int) $config->get('maxFrameBytes'),
        (int) $config->get('maxChatCharacters'),
    );
    // Migrations and database access were preflighted; do not carry a PDO handle across fork.
    $repository->disconnectBeforeFork();
    unset($database);
    startupStatus('Creating OpenSwoole ' . ($devMode ? 'plain-WS development' : 'direct-WSS') . ' server...');
    $server = new LobbyServer($config, $repository, $protocol, $logger);
    $scheme = $devMode ? 'ws' : 'wss';
    startupStatus('Starting event loop at ' . $scheme . '://' . $config->get('host') . ':' . $config->get('port') . '/lobby.');
    $server->start();
} catch (Throwable $error) {
    $message = str_replace(["\r", "\n"], ' ', $error->getMessage());
    fwrite(STDOUT, "[FAILED] {$message}\n");
    fwrite(STDERR, "Lobby server startup failed: {$message}\n");
    exit(1);
}
