#!/usr/bin/env php
<?php
declare(strict_types=1);

use Checkerboard\Lobby\Config;
use Checkerboard\Lobby\Database;
use Checkerboard\Lobby\FrozenClock;
use Checkerboard\Lobby\LobbyRepository;
use Checkerboard\Lobby\Logger;
use Checkerboard\Lobby\Protocol;
use Checkerboard\Lobby\ProtocolException;
use Checkerboard\Lobby\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $error) {
            $this->failed++;
            fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
        }
    }

    public function skip(string $name, string $reason): void
    {
        $this->skipped++;
        fwrite(STDOUT, "SKIP {$name}: {$reason}\n");
    }

    public function finish(): never
    {
        fwrite(STDOUT, "\n{$this->passed} passed, {$this->failed} failed, {$this->skipped} skipped\n");
        exit($this->failed === 0 ? 0 : 1);
    }
}

function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message !== '' ? $message : 'Values are not identical.';
        throw new RuntimeException($detail . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertProtocolCode(string $code, callable $callback): void
{
    try {
        $callback();
    } catch (ProtocolException $error) {
        assertSame($code, $error->protocolCode);
        return;
    }
    throw new RuntimeException("Expected ProtocolException {$code}.");
}

$runner = new TestRunner();
$protocol = new Protocol(1024, 500);
$tokenA = rtrim(strtr(base64_encode(str_repeat('a', 32)), '+/', '-_'), '=');
$tokenB = rtrim(strtr(base64_encode(str_repeat('b', 32)), '+/', '-_'), '=');

$runner->test('registration validation and case normalization', function () use ($protocol, $tokenA): void {
    $value = $protocol->registration(['type' => 'register', 'username' => 'Star_Chaser-7', 'token' => $tokenA]);
    assertSame('star_chaser-7', $value['normalized']);
    assertProtocolCode('INVALID_USERNAME', fn () => $protocol->registration(['type' => 'register', 'username' => 'bad name', 'token' => $tokenA]));
    assertProtocolCode('INVALID_TOKEN', fn () => $protocol->registration(['type' => 'register', 'username' => 'Valid', 'token' => 'short']));
});

$runner->test('token digest is stable and does not expose token', function () use ($protocol, $tokenA): void {
    $digest = $protocol->digestToken($tokenA, str_repeat('pepper', 8));
    assertSame(64, strlen($digest));
    assertTrue(!str_contains($digest, $tokenA));
    assertTrue(hash_equals($digest, $protocol->digestToken($tokenA, str_repeat('pepper', 8))));
});

$runner->test('malformed and unknown protocol messages are rejected', function () use ($protocol): void {
    assertProtocolCode('INVALID_JSON', fn () => $protocol->decode('{broken'));
    assertProtocolCode('INVALID_MESSAGE', fn () => $protocol->decode('[]'));
    assertProtocolCode('UNKNOWN_TYPE', fn () => $protocol->decode('{"type":"dance"}'));
    assertProtocolCode('FRAME_TOO_LARGE', fn () => $protocol->decode(str_repeat('x', 1025)));
});

$runner->test('chat validation and newline normalization', function () use ($protocol): void {
    $chat = $protocol->chat(['type' => 'message', 'destination' => 'general', 'content' => "hello\r\nworld", 'requestId' => 'web.1']);
    assertSame("hello\nworld", $chat['content']);
    assertProtocolCode('EMPTY_MESSAGE', fn () => $protocol->chat(['type' => 'message', 'content' => " \n "]));
    assertProtocolCode('UNKNOWN_DESTINATION', fn () => $protocol->chat(['type' => 'message', 'destination' => 'private', 'content' => 'x']));
    assertProtocolCode('MESSAGE_TOO_LONG', fn () => $protocol->chat(['type' => 'message', 'content' => str_repeat('x', 501)]));
});

$runner->test('rate limiter uses a sliding window', function (): void {
    $limiter = new RateLimiter(2, 10);
    assertTrue($limiter->consume(100.0));
    assertTrue($limiter->consume(101.0));
    assertTrue(!$limiter->consume(102.0));
    assertTrue($limiter->consume(111.1));
});

$runner->test('log redaction recursively removes credentials', function () use ($tokenA): void {
    $safe = Logger::redact(['token' => $tokenA, 'nested' => ['authorization' => 'Bearer secret'], 'message' => "line\nnext"]);
    assertSame('[REDACTED]', $safe['token']);
    assertSame('[REDACTED]', $safe['nested']['authorization']);
    assertTrue(!str_contains(json_encode($safe), $tokenA));
});

$runner->test('development mode selects network-accessible plain-WS defaults', function (): void {
    $names = ['LOBBY_DEV_MODE', 'LOBBY_HOST', 'LOBBY_PORT'];
    $previous = array_combine($names, array_map('getenv', $names));
    try {
        putenv('LOBBY_DEV_MODE=1');
        putenv('LOBBY_HOST');
        putenv('LOBBY_PORT');
        $config = Config::fromEnvironment(false);
        assertSame(true, $config->get('devMode'));
        assertSame('0.0.0.0', $config->get('host'));
        assertSame(9502, $config->get('port'));
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
});

require __DIR__ . '/games.php';

if (!extension_loaded('pdo_sqlite')) {
    $runner->skip('SQLite repository and migration suite', 'pdo_sqlite is not installed in this environment');
    $runner->finish();
}

$database = new Database(':memory:');
$database->migrate(dirname(__DIR__) . '/migrations');
$database->migrate(dirname(__DIR__) . '/migrations');
$clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
$config = Config::forTests();
$repository = new LobbyRepository($database, $clock, (int) $config->get('reservationSeconds'));
$pdo = $database->pdo();
$digestA = $protocol->digestToken($tokenA, (string) $config->get('tokenPepper'));
$digestB = $protocol->digestToken($tokenB, (string) $config->get('tokenPepper'));
$firstPlayerId = '';

$runner->test('migrations are rerunnable and create constraints/indexes', function () use ($pdo): void {
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('idx_players_active_username', $indexes, true));
    assertTrue(in_array('idx_messages_room_history', $indexes, true));
    assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
});

$runner->test('first claim succeeds without exposing credential fields', function () use ($repository, $digestA, &$firstPlayerId): void {
    $claim = $repository->claimUsername('StarChaser', 'starchaser', $digestA);
    assertTrue($claim['available']);
    assertTrue(!$claim['reclaimed']);
    assertTrue(!array_key_exists('token_digest', $claim['player']));
    $firstPlayerId = $claim['player']['id'];
});

$runner->test('same token reclaims case-insensitively and preserves display name', function () use ($repository, $digestA, &$firstPlayerId): void {
    $claim = $repository->claimUsername('starchaser', 'starchaser', $digestA);
    assertTrue($claim['available'] && $claim['reclaimed']);
    assertSame($firstPlayerId, $claim['player']['id']);
    assertSame('StarChaser', $claim['player']['username']);
});

$runner->test('different token is rejected during reservation', function () use ($repository, $digestB): void {
    $claim = $repository->claimUsername('STARCHASER', 'starchaser', $digestB);
    assertTrue(!$claim['available']);
});

$runner->test('sessions and latest twenty messages persist in chronological order', function () use ($repository, $clock, &$firstPlayerId): void {
    $session = $repository->openSession(42, '127.0.0.1');
    $repository->registerSession($session, $firstPlayerId);
    for ($i = 1; $i <= 25; $i++) {
        $repository->storeMessage('general', $firstPlayerId, 'StarChaser', "message {$i}", "req-{$i}");
        $clock->advance('+1 second');
    }
    $history = $repository->recentMessages('general', 20);
    assertSame(20, count($history));
    assertSame('message 6', $history[0]['content']);
    assertSame('message 25', $history[19]['content']);
    $repository->closeSession($session, 1000, 'Test complete');
});

$runner->test('expired name gets a new identity while history survives', function () use ($repository, $clock, $digestB, &$firstPlayerId, $pdo): void {
    $clock->advance('+48 hours +1 second');
    $claim = $repository->claimUsername('starCHASER', 'starchaser', $digestB);
    assertTrue($claim['available'] && !$claim['reclaimed']);
    assertTrue($claim['player']['id'] !== $firstPlayerId);
    assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM players WHERE username_normalized = 'starchaser'")->fetchColumn());
    assertSame(25, (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn());
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
});

$runner->test('audit context redacts raw token', function () use ($repository, $tokenA, $pdo): void {
    $repository->recordEvent('warning', 'TEST_REDACTION', null, null, ['token' => $tokenA]);
    $context = (string) $pdo->query("SELECT context_json FROM events WHERE code = 'TEST_REDACTION' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assertTrue(!str_contains($context, $tokenA));
    assertTrue(str_contains($context, '[REDACTED]'));
});

$runner->finish();
