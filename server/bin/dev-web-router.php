<?php
declare(strict_types=1);

// Run from the repository root: php -S 0.0.0.0:8080 server/bin/dev-web-router.php
// Never expose server/var (identity data/chat) via an unrestricted repository document root.
$files = [
    '/' => ['index.html', 'text/html'],
    '/index.html' => ['index.html', 'text/html'],
    '/app.js' => ['app.js', 'text/javascript'],
    '/styles.css' => ['styles.css', 'text/css'],
    '/games/lobby.css' => ['games/lobby.css', 'text/css'],
    '/games/tic-tac-toe.html' => ['games/tic-tac-toe.html', 'text/html'],
    '/games/tic-tac-toe.js' => ['games/tic-tac-toe.js', 'text/javascript'],
];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!isset($files[$path]) || !in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return;
}
[$file, $type] = $files[$path];
header('Content-Type: ' . $type . '; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if ($method === 'GET') {
    readfile(dirname(__DIR__, 2) . '/' . $file);
}
