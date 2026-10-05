<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Checkerboard\\Lobby\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

date_default_timezone_set('UTC');

// bootstrap.php is included by commands; it does not start a server by itself.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    fwrite(STDOUT, "[OK] Checkerboard lobby bootstrap and autoloader loaded successfully.\n");
    fwrite(STDOUT, "[INFO] bootstrap.php is not the server entry point.\n");
    fwrite(STDOUT, "[INFO] Run diagnostics: " . PHP_BINARY . " " . __DIR__ . "/bin/diagnose.php\n");
    fwrite(STDOUT, "[INFO] Start server:    " . PHP_BINARY . " " . __DIR__ . "/bin/lobby-server.php\n");
}
