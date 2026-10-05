<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

use OpenSwoole\Event;
use OpenSwoole\Timer;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class LobbyServer
{
    private Server $server;
    private GameRooms $games;
    /** @var array<int, array<string, mixed>> */
    private array $connections = [];
    /** @var array<string, int> */
    private array $playerSockets = [];
    /** @var array<int, array{code: int, reason: string}> */
    private array $requestedCloses = [];

    public function __construct(
        private readonly Config $config,
        private readonly LobbyRepository $repository,
        private readonly Protocol $protocol,
        private readonly Logger $logger,
    ) {
        $this->games = new GameRooms();
        $mode = defined('SWOOLE_PROCESS') ? SWOOLE_PROCESS : 3;
        $devMode = (bool) $config->get('devMode');
        $socketType = SWOOLE_SOCK_TCP;
        if (!$devMode) {
            $socketType |= constant('SWOOLE_SSL');
        }
        $this->server = new Server(
            (string) $config->get('host'),
            (int) $config->get('port'),
            $mode,
            $socketType,
        );
        $settings = [
            'worker_num' => 1,
            'max_request' => 0,
            'dispatch_mode' => 2,
            'package_max_length' => (int) $config->get('maxFrameBytes'),
            'heartbeat_check_interval' => (int) $config->get('pingIntervalSeconds'),
            'heartbeat_idle_time' => (int) $config->get('pongTimeoutSeconds') * 2,
            // Control-frame ping/pong remains internal to OpenSwoole; JSON heartbeat is handled below.
            'open_websocket_ping_frame' => false,
            'open_websocket_pong_frame' => false,
            'log_level' => 3,
        ];
        if (!$devMode) {
            $settings['ssl_cert_file'] = (string) $config->get('certFile');
            $settings['ssl_key_file'] = (string) $config->get('keyFile');
            if ((string) $config->get('keyPassphrase') !== '') {
                $settings['ssl_passphrase'] = (string) $config->get('keyPassphrase');
            }
        }
        $this->server->set($settings);
        $this->registerCallbacks();
    }

    public function start(): void
    {
        $this->logger->info('Starting lobby server', [
            'host' => $this->config->get('host'), 'port' => $this->config->get('port'),
            'scheme' => $this->config->get('devMode') ? 'ws' : 'wss',
            'dev_mode' => $this->config->get('devMode'),
            'openswoole' => phpversion('openswoole'),
        ]);
        $this->server->start();
    }

    private function registerCallbacks(): void
    {
        $this->server->on('handshake', function ($request, $response): bool {
            try {
                $uri = (string) ($request->server['request_uri'] ?? '');
                if ($uri !== '/lobby') {
                    $response->status(404);
                    $response->header('Content-Type', 'text/plain; charset=utf-8');
                    $response->end('Not found');
                    return false;
                }
                $key = (string) ($request->header['sec-websocket-key'] ?? '');
                $decodedKey = base64_decode($key, true);
                $version = (string) ($request->header['sec-websocket-version'] ?? '');
                if ($decodedKey === false || strlen($decodedKey) !== 16 || $version !== '13') {
                    $response->status(400);
                    $response->header('Content-Type', 'text/plain; charset=utf-8');
                    $response->end('Invalid WebSocket handshake');
                    return false;
                }

                $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $response->status(101);
                $response->header('Upgrade', 'websocket');
                $response->header('Connection', 'Upgrade');
                $response->header('Sec-WebSocket-Accept', $accept);
                $response->header('Sec-WebSocket-Version', '13');
                $response->end();

                $fd = (int) $request->fd;
                $remote = isset($request->server['remote_addr']) ? (string) $request->server['remote_addr'] : null;
                Event::defer(fn () => $this->safeCallback($fd, fn () => $this->onOpen($fd, $remote)));
                return true;
            } catch (Throwable $error) {
                $this->logger->error('Handshake failed unexpectedly', ['exception' => $error->getMessage()]);
                try {
                    $response->status(500);
                    $response->end('WebSocket handshake failed');
                } catch (Throwable) {
                    // The response may already have closed; there is nothing else to send.
                }
                return false;
            }
        });

        $this->server->on('request', static function ($request, $response): void {
            try {
                $response->status(404);
                $response->header('Content-Type', 'application/json');
                $response->end('{"error":"Not found"}');
            } catch (Throwable) {
                // Ignore an HTTP peer that closed before the 404 could be written.
            }
        });

        $this->server->on('message', function ($server, $frame): void {
            $fd = (int) $frame->fd;
            $this->safeCallback($fd, fn () => $this->onMessage($fd, (string) $frame->data, (int) $frame->opcode));
        });

        $this->server->on('close', function ($server, int $fd): void {
            $this->safeCallback($fd, fn () => $this->onClose($fd));
        });

        $this->server->on('workerStart', function (): void {
            // The repository was preflighted before start; reopen PDO in the forked worker.
            $this->repository->reconnect(
                (string) $this->config->get('databasePath'),
                dirname(__DIR__) . '/migrations',
            );
            $this->repository->recordEvent('info', 'SERVER_STARTED', null, null, [
                'pid' => getmypid(), 'openswoole' => phpversion('openswoole'),
            ]);
            Timer::tick((int) $this->config->get('pingIntervalSeconds') * 1000, fn () => $this->safeCallback(null, fn () => $this->pingConnections()));
            Timer::tick((int) $this->config->get('cleanupIntervalSeconds') * 1000, fn () => $this->safeCallback(null, function (): void {
                // Connected players retain their names even if they have been quietly idle.
                foreach ($this->playerSockets as $fd) {
                    if (isset($this->connections[$fd]['playerId'])) {
                        $this->repository->touchPlayer((string) $this->connections[$fd]['playerId']);
                    }
                }
                $released = $this->repository->releaseExpiredReservations();
                if ($released > 0) {
                    $this->logger->info('Released expired username reservations', ['count' => $released]);
                }
            }));
        });

        $this->server->on('workerStop', function (): void {
            $this->repository->recordEvent('info', 'SERVER_STOPPED', null, null, ['pid' => getmypid()]);
        });

        $this->server->on('shutdown', function (): void {
            // OpenSwoole owns SIGTERM/SIGINT handling and stops accepting connections first.
            $this->logger->info('OpenSwoole server shutdown complete');
        });
    }

    private function onOpen(int $fd, ?string $remoteAddress): void
    {
        if (!$this->isEstablished($fd)) {
            return;
        }
        $sessionId = $this->repository->openSession($fd, $remoteAddress);
        $this->connections[$fd] = [
            'sessionId' => $sessionId,
            'playerId' => null,
            'username' => null,
            'usernameNormalized' => null,
            'registeredAt' => null,
            'lastPong' => time(),
            'superseded' => false,
            'registerLimiter' => new RateLimiter(
                (int) $this->config->get('registrationRateCount'),
                (int) $this->config->get('registrationRateWindow'),
            ),
            'gameLimiter' => new RateLimiter(30, 10),
            'chatLimiter' => new RateLimiter(
                (int) $this->config->get('chatRateCount'),
                (int) $this->config->get('chatRateWindow'),
            ),
        ];
        $this->repository->recordEvent('info', 'CONNECTION_OPENED', $sessionId, null, ['remote_address' => $remoteAddress]);
        $this->logger->info('WebSocket opened', ['fd' => $fd, 'session_id' => $sessionId, 'remote_address' => $remoteAddress]);

        $timeoutMs = (int) $this->config->get('registrationTimeoutSeconds') * 1000;
        Timer::after($timeoutMs, fn () => $this->safeCallback($fd, function () use ($fd, $sessionId): void {
            if (($this->connections[$fd]['sessionId'] ?? null) !== $sessionId || $this->connections[$fd]['playerId'] !== null) {
                return;
            }
            $this->sendError($fd, new ProtocolException('REGISTRATION_TIMEOUT', 'Registration was not completed in time.', true, 'register'));
            $this->disconnect($fd, 4000, 'Registration timeout');
        }));
    }

    private function onMessage(int $fd, string $raw, int $opcode): void
    {
        if (!isset($this->connections[$fd])) {
            return;
        }
        if ($opcode !== 1) {
            $error = new ProtocolException('BINARY_NOT_SUPPORTED', 'Only text JSON messages are supported.', true);
            $this->protocolFailure($fd, $error);
            return;
        }

        try {
            $message = $this->protocol->decode($raw);
            match ($message['type']) {
                'register' => $this->handleRegistration($fd, $message),
                'message' => $this->handleChat($fd, $message),
                'pong' => $this->handlePong($fd),
                'game_create', 'game_join', 'game_leave', 'game_move' => $this->handleGame($fd, $message),
                default => throw new ProtocolException('UNKNOWN_TYPE', 'The message type is not supported.'),
            };
        } catch (ProtocolException $error) {
            $this->protocolFailure($fd, $error);
        }
    }

    /** @param array<string, mixed> $message */
    private function handleRegistration(int $fd, array $message): void
    {
        $connection = &$this->connections[$fd];
        if ($connection['playerId'] !== null) {
            throw new ProtocolException('ALREADY_REGISTERED', 'This connection is already registered.', false, 'register');
        }
        /** @var RateLimiter $limiter */
        $limiter = $connection['registerLimiter'];
        if (!$limiter->consume()) {
            throw new ProtocolException('RATE_LIMITED', 'Too many registration attempts. Try again later.', false, 'register');
        }

        $registration = $this->protocol->registration($message);
        // Refresh a matching online identity before the repository performs lazy expiry.
        foreach ($this->playerSockets as $onlineFd) {
            $online = $this->connections[$onlineFd] ?? null;
            if ($online !== null && $online['usernameNormalized'] === $registration['normalized']) {
                $this->repository->touchPlayer((string) $online['playerId']);
                break;
            }
        }
        $digest = $this->protocol->digestToken($registration['token'], (string) $this->config->get('tokenPepper'));
        $claim = $this->repository->claimUsername(
            $registration['username'],
            $registration['normalized'],
            $digest,
            (string) $connection['sessionId'],
        );
        unset($registration['token'], $digest);

        if (!$claim['available']) {
            $this->push($fd, [
                'type' => 'register', 'available' => false, 'registered' => false,
                'code' => 'USERNAME_TAKEN',
                'message' => 'That player name is reserved by another browser.',
            ]);
            return;
        }

        /** @var array<string, mixed> $player */
        $player = $claim['player'];
        $playerId = (string) $player['id'];
        $username = (string) $player['username'];
        $oldFd = $this->playerSockets[$playerId] ?? null;
        $wasOnline = $oldFd !== null && $oldFd !== $fd;
        if ($wasOnline && isset($this->connections[$oldFd])) {
            $this->connections[$oldFd]['superseded'] = true;
            $this->sendError($oldFd, new ProtocolException('SESSION_REPLACED', 'This player was opened in a newer connection.', true));
            $this->repository->recordEvent('warning', 'SESSION_REPLACED', (string) $connection['sessionId'], $playerId, [
                'old_session_id' => $this->connections[$oldFd]['sessionId'],
            ]);
            $this->disconnect($oldFd, 4001, 'Session replaced');
        }

        $connection['playerId'] = $playerId;
        $connection['username'] = $username;
        $connection['usernameNormalized'] = (string) $player['username_normalized'];
        $connection['registeredAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $connection['lastPong'] = time();
        $this->playerSockets[$playerId] = $fd;
        $this->repository->registerSession((string) $connection['sessionId'], $playerId);

        $this->push($fd, [
            'type' => 'register', 'available' => true, 'registered' => true,
            'code' => $claim['reclaimed'] ? 'IDENTITY_RECLAIMED' : 'IDENTITY_CREATED',
            'username' => $username, 'destination' => Protocol::ROOM,
            'reclaimed' => $claim['reclaimed'],
            'reservationExpiresAt' => $claim['reservationExpiresAt'],
            'protocolVersion' => Protocol::VERSION,
        ]);
        if ($this->games->expire(time())) {
            $this->broadcastGames();
        }
        $this->push($fd, $this->games->catalog());
        $this->push($fd, $this->games->snapshot($playerId));
        $this->push($fd, ['type' => 'players', 'destination' => Protocol::ROOM, 'players' => $this->onlinePlayers()]);
        $this->push($fd, [
            'type' => 'history', 'destination' => Protocol::ROOM,
            'messages' => $this->repository->recentMessages(Protocol::ROOM, (int) $this->config->get('historyCount')),
        ]);

        if (!$wasOnline) {
            $this->broadcast([
                'type' => 'player_joined', 'destination' => Protocol::ROOM,
                'name' => $username, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        $this->logger->info('Player registered', [
            'fd' => $fd, 'session_id' => $connection['sessionId'], 'player_id' => $playerId,
            'username' => $username, 'reclaimed' => $claim['reclaimed'],
        ]);
    }

    /** @param array<string, mixed> $message */
    private function handleChat(int $fd, array $message): void
    {
        $connection = &$this->connections[$fd];
        if ($connection['playerId'] === null) {
            throw new ProtocolException('NOT_REGISTERED', 'Register before sending chat messages.', false, 'message');
        }
        /** @var RateLimiter $limiter */
        $limiter = $connection['chatLimiter'];
        if (!$limiter->consume()) {
            throw new ProtocolException('RATE_LIMITED', 'You are sending messages too quickly.', false, 'message');
        }
        $chat = $this->protocol->chat($message);
        $stored = $this->repository->storeMessage(
            $chat['destination'],
            (string) $connection['playerId'],
            (string) $connection['username'],
            $chat['content'],
            $chat['requestId'],
        );
        $this->broadcast(['type' => 'message'] + $stored);
    }

    private function handleGame(int $fd, array $message): void
    {
        $connection = $this->connections[$fd];
        if ($connection['playerId'] === null || $connection['superseded']) {
            throw new ProtocolException('NOT_REGISTERED', 'Register before entering a game.', false, 'game');
        }
        if (!$connection['gameLimiter']->consume()) {
            throw new ProtocolException('RATE_LIMITED', 'Too many game actions. Try again shortly.', false, 'game');
        }
        if ($this->games->expire(time())) {
            $this->broadcastGames();
        }
        $this->games->apply((string) $connection['playerId'], (string) $connection['username'], $message, time());
        $this->broadcastGames();
    }

    private function broadcastGames(): void
    {
        $this->broadcast($this->games->catalog());
        foreach ($this->playerSockets as $playerId => $fd) {
            $this->push($fd, $this->games->snapshot((string) $playerId));
        }
    }

    private function handlePong(int $fd): void
    {
        $this->connections[$fd]['lastPong'] = time();
    }

    private function onClose(int $fd): void
    {
        $connection = $this->connections[$fd] ?? null;
        if ($connection === null) {
            return;
        }
        $requested = $this->requestedCloses[$fd] ?? null;
        unset($this->connections[$fd], $this->requestedCloses[$fd]);
        $code = $requested['code'] ?? null;
        $reason = $requested['reason'] ?? 'Peer disconnected';
        $playerId = $connection['playerId'] === null ? null : (string) $connection['playerId'];
        $this->repository->closeSession((string) $connection['sessionId'], $code, $reason);

        if ($playerId !== null) {
            $this->repository->touchPlayer($playerId);
            if (($this->playerSockets[$playerId] ?? null) === $fd) {
                unset($this->playerSockets[$playerId]);
                if (!$connection['superseded']) {
                    $this->broadcast([
                        'type' => 'player_left', 'destination' => Protocol::ROOM,
                        'name' => (string) $connection['username'], 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                }
            }
        }
        $this->repository->recordEvent('info', 'CONNECTION_CLOSED', (string) $connection['sessionId'], $playerId, [
            'close_code' => $code, 'close_reason' => $reason,
        ]);
        $this->logger->info('WebSocket closed', ['fd' => $fd, 'player_id' => $playerId, 'close_code' => $code]);
    }

    private function pingConnections(): void
    {
        $now = time();
        if ($this->games->expire($now)) {
            $this->broadcastGames();
        }
        foreach (array_keys($this->connections) as $fd) {
            if (!isset($this->connections[$fd]) || $this->connections[$fd]['playerId'] === null) {
                continue;
            }
            if ((int) $this->connections[$fd]['lastPong'] < $now - (int) $this->config->get('pongTimeoutSeconds')) {
                $this->sendError($fd, new ProtocolException('PONG_TIMEOUT', 'The connection stopped responding.', true));
                $this->disconnect($fd, 4002, 'Pong timeout');
                continue;
            }
            $this->push($fd, ['type' => 'ping', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]);
        }
    }

    /** @return list<array{name: string, joinedAt: string}> */
    private function onlinePlayers(): array
    {
        $players = [];
        foreach ($this->playerSockets as $fd) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection === null || $connection['playerId'] === null) {
                continue;
            }
            $players[] = ['name' => (string) $connection['username'], 'joinedAt' => (string) $connection['registeredAt']];
        }
        usort($players, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $players;
    }

    /** @param array<string, mixed> $payload */
    private function broadcast(array $payload): void
    {
        foreach ($this->playerSockets as $fd) {
            $this->push($fd, $payload);
        }
    }

    /** @param array<string, mixed> $payload */
    private function push(int $fd, array $payload): bool
    {
        if (!$this->isEstablished($fd)) {
            return false;
        }
        try {
            return $this->server->push($fd, $this->protocol->encode($payload));
        } catch (Throwable $error) {
            $this->logger->warning('Failed to push WebSocket message', ['fd' => $fd, 'exception' => $error->getMessage()]);
            return false;
        }
    }

    private function protocolFailure(int $fd, ProtocolException $error): void
    {
        $connection = $this->connections[$fd] ?? null;
        $this->sendError($fd, $error);
        $this->repository->recordEvent(
            'warning', 'PROTOCOL_' . $error->protocolCode,
            $connection['sessionId'] ?? null, $connection['playerId'] ?? null,
            ['request_type' => $error->requestType],
        );
        if ($error->fatal) {
            $this->disconnect($fd, $error->protocolCode === 'FRAME_TOO_LARGE' ? 1009 : 4003, $error->protocolCode);
        }
    }

    private function sendError(int $fd, ProtocolException $error): void
    {
        $this->push($fd, $this->protocol->error($error));
    }

    private function disconnect(int $fd, int $code, string $reason): void
    {
        if (!$this->isEstablished($fd)) {
            return;
        }
        $this->requestedCloses[$fd] = ['code' => $code, 'reason' => $reason];
        $this->server->disconnect($fd, $code, $reason);
    }

    private function isEstablished(int $fd): bool
    {
        return $this->server->isEstablished($fd);
    }

    private function safeCallback(?int $fd, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $error) {
            $connection = $fd === null ? null : ($this->connections[$fd] ?? null);
            $this->logger->error('Unhandled lobby callback exception', [
                'fd' => $fd, 'exception' => $error->getMessage(), 'class' => $error::class,
            ]);
            try {
                $this->repository->recordEvent(
                    'error', 'INTERNAL_ERROR', $connection['sessionId'] ?? null,
                    $connection['playerId'] ?? null, ['exception_class' => $error::class],
                );
            } catch (Throwable $persistenceError) {
                $this->logger->error('Could not persist internal error event', ['class' => $persistenceError::class]);
            }
            if ($fd !== null) {
                $this->sendError($fd, new ProtocolException('INTERNAL_ERROR', 'An internal server error occurred.', true));
                $this->disconnect($fd, 1011, 'Internal server error');
            }
        }
    }

}
