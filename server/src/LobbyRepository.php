<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Throwable;

final class LobbyRepository
{
    private PDO $pdo;
    private bool $transactionActive = false;

    public function __construct(
        Database $database,
        private readonly Clock $clock,
        private readonly int $reservationSeconds,
    ) {
        $this->pdo = $database->pdo();
    }

    /** Close the preflight connection before OpenSwoole forks. */
    public function disconnectBeforeFork(): void
    {
        unset($this->pdo);
        $this->transactionActive = false;
    }

    /** Reopen SQLite after OpenSwoole forks the worker; PDO connections must not be shared across fork. */
    public function reconnect(string $databasePath, string $migrationDirectory): void
    {
        $database = new Database($databasePath);
        $database->migrate($migrationDirectory);
        $this->pdo = $database->pdo();
        $this->transactionActive = false;
    }

    /** @return array{available: bool, reclaimed: bool, player?: array<string, mixed>, reservationExpiresAt?: string} */
    public function claimUsername(string $username, string $normalized, string $tokenDigest, ?string $sessionId = null): array
    {
        $now = $this->clock->now();
        $nowText = $this->timestamp($now);
        $expires = $this->timestamp($now->add(new DateInterval('PT' . $this->reservationSeconds . 'S')));
        $this->beginImmediate();
        try {
            $this->releaseExpiredInsideTransaction($nowText);
            $select = $this->pdo->prepare(
                'SELECT id, username, username_normalized, token_digest, created_at, last_seen_at, reserved_until
                 FROM players WHERE username_normalized = :normalized AND released_at IS NULL LIMIT 1'
            );
            $select->execute(['normalized' => $normalized]);
            $player = $select->fetch();

            if ($player !== false) {
                if (!hash_equals((string) $player['token_digest'], $tokenDigest)) {
                    $this->insertEvent('warning', 'REGISTRATION_REJECTED', $sessionId, (string) $player['id'], [
                        'reason' => 'USERNAME_TAKEN', 'username_normalized' => $normalized,
                    ], $nowText);
                    $this->commit();
                    return ['available' => false, 'reclaimed' => false];
                }

                $update = $this->pdo->prepare(
                    'UPDATE players SET last_seen_at = :now, reserved_until = :expires WHERE id = :id'
                );
                $update->execute(['now' => $nowText, 'expires' => $expires, 'id' => $player['id']]);
                $player['last_seen_at'] = $nowText;
                $player['reserved_until'] = $expires;
                unset($player['token_digest']);
                $this->insertEvent('info', 'REGISTRATION_RECLAIMED', $sessionId, (string) $player['id'], [
                    'username_normalized' => $normalized,
                ], $nowText);
                $this->commit();
                return ['available' => true, 'reclaimed' => true, 'player' => $player, 'reservationExpiresAt' => $expires];
            }

            $id = Id::generate();
            $insert = $this->pdo->prepare(
                'INSERT INTO players(id, username, username_normalized, token_digest, created_at, last_seen_at, reserved_until, released_at)
                 VALUES(:id, :username, :normalized, :digest, :now, :now, :expires, NULL)'
            );
            $insert->execute([
                'id' => $id, 'username' => $username, 'normalized' => $normalized,
                'digest' => $tokenDigest, 'now' => $nowText, 'expires' => $expires,
            ]);
            $this->insertEvent('info', 'REGISTRATION_CREATED', $sessionId, $id, [
                'username_normalized' => $normalized,
            ], $nowText);
            $this->commit();
            return [
                'available' => true,
                'reclaimed' => false,
                'player' => [
                    'id' => $id, 'username' => $username, 'username_normalized' => $normalized,
                    'created_at' => $nowText, 'last_seen_at' => $nowText, 'reserved_until' => $expires,
                ],
                'reservationExpiresAt' => $expires,
            ];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function releaseExpiredReservations(): int
    {
        $now = $this->timestamp($this->clock->now());
        $this->beginImmediate();
        try {
            $count = $this->releaseExpiredInsideTransaction($now);
            $this->commit();
            return $count;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function touchPlayer(string $playerId): void
    {
        $now = $this->clock->now();
        $statement = $this->pdo->prepare(
            'UPDATE players SET last_seen_at = :now, reserved_until = :expires
             WHERE id = :id AND released_at IS NULL'
        );
        $statement->execute([
            'now' => $this->timestamp($now),
            'expires' => $this->timestamp($now->add(new DateInterval('PT' . $this->reservationSeconds . 'S'))),
            'id' => $playerId,
        ]);
    }

    public function openSession(int $fd, ?string $remoteAddress): string
    {
        $id = Id::generate();
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions(id, fd, remote_address, connected_at) VALUES(:id, :fd, :remote, :connected)'
        );
        $statement->execute([
            'id' => $id, 'fd' => $fd, 'remote' => $remoteAddress,
            'connected' => $this->timestamp($this->clock->now()),
        ]);
        return $id;
    }

    public function registerSession(string $sessionId, string $playerId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET player_id = :player_id, registered_at = :registered, registration_completed = 1 WHERE id = :id'
        );
        $statement->execute([
            'player_id' => $playerId,
            'registered' => $this->timestamp($this->clock->now()),
            'id' => $sessionId,
        ]);
    }

    public function closeSession(string $sessionId, ?int $closeCode, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET disconnected_at = :disconnected, close_code = :code, close_reason = :reason
             WHERE id = :id AND disconnected_at IS NULL'
        );
        $statement->execute([
            'disconnected' => $this->timestamp($this->clock->now()),
            'code' => $closeCode,
            'reason' => substr(str_replace(["\r", "\n"], ' ', $reason), 0, 200),
            'id' => $sessionId,
        ]);
    }

    /** @return array{id: string, destination: string, sender: string, content: string, requestId: ?string, timestamp: string} */
    public function storeMessage(string $room, string $playerId, string $sender, string $content, ?string $requestId): array
    {
        $id = Id::generate();
        $now = $this->clock->now();
        $created = $this->timestamp($now);
        $expires = $this->timestamp($now->add(new DateInterval('PT' . $this->reservationSeconds . 'S')));
        $this->beginImmediate();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO messages(id, room, player_id, sender_username, content, request_id, created_at)
                 VALUES(:id, :room, :player_id, :sender, :content, :request_id, :created_at)'
            );
            $insert->execute([
                'id' => $id, 'room' => $room, 'player_id' => $playerId, 'sender' => $sender,
                'content' => $content, 'request_id' => $requestId, 'created_at' => $created,
            ]);
            $touch = $this->pdo->prepare(
                'UPDATE players SET last_seen_at = :now, reserved_until = :expires WHERE id = :id AND released_at IS NULL'
            );
            $touch->execute(['now' => $created, 'expires' => $expires, 'id' => $playerId]);
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
        return [
            'id' => $id, 'destination' => $room, 'sender' => $sender, 'content' => $content,
            'requestId' => $requestId, 'timestamp' => $created,
        ];
    }

    /** @return list<array{id: string, destination: string, sender: string, content: string, requestId: ?string, timestamp: string}> */
    public function recentMessages(string $room, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT id, room, sender_username, content, request_id, created_at
             FROM messages WHERE room = :room ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':room', $room, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = array_reverse($statement->fetchAll());
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'destination' => (string) $row['room'],
            'sender' => (string) $row['sender_username'],
            'content' => (string) $row['content'],
            'requestId' => $row['request_id'] === null ? null : (string) $row['request_id'],
            'timestamp' => (string) $row['created_at'],
        ], $rows);
    }

    /** @param array<string, mixed> $context */
    public function recordEvent(
        string $severity,
        string $code,
        ?string $sessionId = null,
        ?string $playerId = null,
        array $context = [],
    ): void {
        $this->insertEvent(
            $severity,
            $code,
            $sessionId,
            $playerId,
            $context,
            $this->timestamp($this->clock->now()),
        );
    }

    private function releaseExpiredInsideTransaction(string $now): int
    {
        $select = $this->pdo->prepare(
            'SELECT id, username_normalized FROM players WHERE released_at IS NULL AND reserved_until <= :now'
        );
        $select->execute(['now' => $now]);
        $expired = $select->fetchAll();
        if ($expired === []) {
            return 0;
        }
        $update = $this->pdo->prepare(
            'UPDATE players SET released_at = :now WHERE released_at IS NULL AND reserved_until <= :now'
        );
        $update->execute(['now' => $now]);
        foreach ($expired as $player) {
            $this->insertEvent('info', 'USERNAME_RELEASED', null, (string) $player['id'], [
                'username_normalized' => (string) $player['username_normalized'],
            ], $now);
        }
        return count($expired);
    }

    /** @param array<string, mixed> $context */
    private function insertEvent(
        string $severity,
        string $code,
        ?string $sessionId,
        ?string $playerId,
        array $context,
        string $occurredAt,
    ): void {
        $safeContext = Logger::redact($context);
        $json = json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $statement = $this->pdo->prepare(
            'INSERT INTO events(occurred_at, severity, code, session_id, player_id, context_json)
             VALUES(:occurred_at, :severity, :code, :session_id, :player_id, :context_json)'
        );
        $statement->execute([
            'occurred_at' => $occurredAt, 'severity' => $severity, 'code' => $code,
            'session_id' => $sessionId, 'player_id' => $playerId,
            'context_json' => $json ?: '{}',
        ]);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function beginImmediate(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        $this->transactionActive = true;
    }

    private function commit(): void
    {
        $this->pdo->exec('COMMIT');
        $this->transactionActive = false;
    }

    private function rollback(): void
    {
        if (!$this->transactionActive) {
            return;
        }
        try {
            $this->pdo->exec('ROLLBACK');
        } finally {
            $this->transactionActive = false;
        }
    }
}
