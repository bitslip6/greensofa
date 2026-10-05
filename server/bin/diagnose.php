#!/usr/bin/env php
<?php
declare(strict_types=1);

$serverRoot = dirname(__DIR__);
$failures = 0;

function diagnostic(string $status, string $message): void
{
    fwrite(STDOUT, sprintf("[%-4s] %s\n", $status, $message));
}

function check(bool $condition, string $success, string $failure): void
{
    global $failures;
    if ($condition) {
        diagnostic('OK', $success);
        return;
    }
    $failures++;
    diagnostic('FAIL', $failure);
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

diagnostic('INFO', 'Checkerboard Chase lobby server diagnostics');
diagnostic('INFO', 'PHP binary: ' . PHP_BINARY);
diagnostic('INFO', 'PHP version: ' . PHP_VERSION);
diagnostic('INFO', 'Operating system: ' . PHP_OS_FAMILY . ' (' . PHP_OS . ')');
diagnostic('INFO', 'Server directory: ' . $serverRoot);

$devModeRaw = strtolower(envValue('LOBBY_DEV_MODE', '0'));
$validDevMode = in_array($devModeRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true);
check($validDevMode, 'LOBBY_DEV_MODE has a valid boolean value.', 'LOBBY_DEV_MODE must be one of: 1, 0, true, false, yes, no, on, off.');
$devMode = in_array($devModeRaw, ['1', 'true', 'yes', 'on'], true);
diagnostic('INFO', $devMode ? 'Mode: DEVELOPMENT (plain ws://)' : 'Mode: PRODUCTION (direct wss://)');

check(PHP_VERSION_ID >= 80100, 'PHP version supports the server code.', 'PHP 8.1 or newer is required.');

$openSwooleLoaded = extension_loaded('openswoole');
check(
    $openSwooleLoaded,
    'OpenSwoole extension is loaded.',
    'OpenSwoole is not loaded by this PHP binary. Compare CLI php.ini and extension directories.',
);

if ($openSwooleLoaded) {
    $version = phpversion('openswoole') ?: 'unknown';
    diagnostic('INFO', 'OpenSwoole version: ' . $version);
    $parts = explode('.', $version);
    $supported = (int) ($parts[0] ?? 0) === 26 && version_compare($version, '26.2.0', '>=');
    check(
        $supported,
        'OpenSwoole version satisfies the required 26.2.x range.',
        "OpenSwoole 26.2.x is required by Config.php; found {$version}.",
    );
    if ($devMode) {
        diagnostic('SKIP', 'OpenSwoole SSL support is not required in development mode.');
    } else {
        check(
            defined('SWOOLE_SSL'),
            'OpenSwoole SSL support is available (SWOOLE_SSL is defined).',
            'OpenSwoole was built without SSL support; direct wss:// termination cannot work.',
        );
    }
}

check(extension_loaded('pdo'), 'PDO extension is loaded.', 'PDO extension is not loaded.');
check(
    extension_loaded('pdo_sqlite'),
    'PDO SQLite extension is loaded.',
    'PDO SQLite is not loaded. Install/enable the pdo_sqlite extension for this PHP binary.',
);

if ($devMode) {
    diagnostic('SKIP', 'TLS certificate and private key checks are disabled in development mode.');
} else {
    $certificate = envValue('LOBBY_TLS_CERT_FILE');
    if ($certificate === '') {
        $failures++;
        diagnostic('FAIL', 'LOBBY_TLS_CERT_FILE is not set.');
    } else {
        diagnostic('INFO', 'TLS certificate: ' . $certificate);
        check(is_file($certificate), 'TLS certificate exists.', 'TLS certificate does not exist.');
        check(is_readable($certificate), 'TLS certificate is readable.', 'TLS certificate is not readable by this process.');
    }

    $privateKey = envValue('LOBBY_TLS_KEY_FILE');
    if ($privateKey === '') {
        $failures++;
        diagnostic('FAIL', 'LOBBY_TLS_KEY_FILE is not set.');
    } else {
        diagnostic('INFO', 'TLS private key: ' . $privateKey);
        check(is_file($privateKey), 'TLS private key exists.', 'TLS private key does not exist.');
        check(is_readable($privateKey), 'TLS private key is readable.', 'TLS private key is not readable by this process.');
    }
}

$pepper = envValue('LOBBY_TOKEN_PEPPER');
check(
    strlen($pepper) >= 32,
    'LOBBY_TOKEN_PEPPER is set and has an acceptable length (value hidden).',
    'LOBBY_TOKEN_PEPPER is missing or shorter than 32 characters.',
);

$databasePath = envValue('LOBBY_DATABASE_PATH', $serverRoot . '/var/lobby.sqlite3');
$databaseDirectory = dirname($databasePath);
diagnostic('INFO', 'SQLite database: ' . $databasePath);
if (!is_dir($databaseDirectory)) {
    diagnostic('WARN', 'Database directory does not exist yet; startup will attempt to create it: ' . $databaseDirectory);
    $parent = dirname($databaseDirectory);
    check(is_dir($parent) && is_writable($parent), 'Parent directory permits database-directory creation.', 'Parent directory is missing or not writable.');
} else {
    check(is_writable($databaseDirectory), 'Database directory is writable.', 'Database directory is not writable.');
}
if (is_file($databasePath)) {
    check(is_readable($databasePath) && is_writable($databasePath), 'Existing SQLite file is readable and writable.', 'Existing SQLite file is not readable and writable.');
}

$host = envValue('LOBBY_HOST', '0.0.0.0');
$port = envValue('LOBBY_PORT', $devMode ? '9502' : '443');
$scheme = $devMode ? 'ws' : 'wss';
diagnostic('INFO', "Configured endpoint: {$scheme}://{$host}:{$port}/lobby");
if ($devMode && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
    diagnostic('OK', 'Development listener is restricted to loopback.');
} elseif ($devMode) {
    diagnostic('WARN', 'Development listener is network-accessible and uses unencrypted WebSocket traffic.');
}
if ((int) $port < 1024 && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    diagnostic('WARN', "Port {$port} is privileged. Confirm the service account has permission to bind it.");
}

diagnostic('INFO', 'Server entry point: ' . $serverRoot . '/bin/lobby-server.php');
diagnostic('INFO', 'Migration entry point: ' . $serverRoot . '/bin/migrate.php');

if ($failures > 0) {
    diagnostic('FAIL', "Diagnostics found {$failures} blocking problem(s). The server will not start until they are resolved.");
    exit(1);
}

diagnostic('OK', 'All startup diagnostics passed.');
diagnostic('INFO', 'Start with: ' . PHP_BINARY . ' ' . $serverRoot . '/bin/lobby-server.php');
