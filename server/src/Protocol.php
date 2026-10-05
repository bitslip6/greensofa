<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

use JsonException;

final class Protocol
{
    public const VERSION = 1;
    public const ROOM = 'general';

    public function __construct(
        private readonly int $maxFrameBytes = 16384,
        private readonly int $maxChatCharacters = 500,
    ) {
    }

    /** @return array<string, mixed> */
    public function decode(string $payload): array
    {
        if (strlen($payload) > $this->maxFrameBytes) {
            throw new ProtocolException('FRAME_TOO_LARGE', 'The message frame is too large.', true);
        }
        try {
            $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProtocolException('INVALID_JSON', 'The message is not valid UTF-8 JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ProtocolException('INVALID_MESSAGE', 'Messages must be JSON objects.');
        }
        if (!isset($data['type']) || !is_string($data['type']) || $data['type'] === '') {
            throw new ProtocolException('INVALID_MESSAGE', 'A string message type is required.');
        }
        if (!in_array($data['type'], ['register', 'message', 'pong', 'game_create', 'game_join', 'game_leave', 'game_move'], true)) {
            throw new ProtocolException('UNKNOWN_TYPE', 'The message type is not supported.', false, $data['type']);
        }
        return $data;
    }

    /** @param array<string, mixed> $data
     *  @return array{username: string, normalized: string, token: string}
     */
    public function registration(array $data): array
    {
        $username = $data['username'] ?? null;
        if (!is_string($username) || preg_match('/^[A-Za-z0-9_-]{2,20}$/D', $username) !== 1) {
            throw new ProtocolException('INVALID_USERNAME', 'Username must be 2–20 letters, numbers, underscores, or hyphens.', false, 'register');
        }
        $token = $data['token'] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1) {
            throw new ProtocolException('INVALID_TOKEN', 'The browser identity token is invalid.', false, 'register');
        }
        return ['username' => $username, 'normalized' => strtolower($username), 'token' => $token];
    }

    /** @param array<string, mixed> $data
     *  @return array{destination: string, content: string, requestId: ?string}
     */
    public function chat(array $data): array
    {
        $destination = $data['destination'] ?? self::ROOM;
        if (!is_string($destination) || $destination !== self::ROOM) {
            throw new ProtocolException('UNKNOWN_DESTINATION', 'Only the general room is available.', false, 'message', $this->safeRequestId($data));
        }
        $content = $data['content'] ?? null;
        if (!is_string($content)) {
            throw new ProtocolException('INVALID_MESSAGE', 'Message content must be a string.', false, 'message', $this->safeRequestId($data));
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            throw new ProtocolException('EMPTY_MESSAGE', 'Message content cannot be empty.', false, 'message', $this->safeRequestId($data));
        }
        if ($this->characterLength($content) > $this->maxChatCharacters) {
            throw new ProtocolException('MESSAGE_TOO_LONG', "Messages may contain at most {$this->maxChatCharacters} characters.", false, 'message', $this->safeRequestId($data));
        }
        $requestId = $data['requestId'] ?? null;
        if ($requestId !== null && (!is_string($requestId) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $requestId) !== 1)) {
            throw new ProtocolException('INVALID_MESSAGE', 'requestId has an invalid format.', false, 'message');
        }
        return ['destination' => $destination, 'content' => $content, 'requestId' => $requestId];
    }

    /** @return array<string, mixed> */
    public function error(ProtocolException $error): array
    {
        $payload = [
            'type' => 'error',
            'code' => $error->protocolCode,
            'message' => $error->getMessage(),
            'fatal' => $error->fatal,
        ];
        if ($error->requestType !== null) {
            $payload['requestType'] = $error->requestType;
        }
        if ($error->requestId !== null) {
            $payload['requestId'] = $error->requestId;
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function digestToken(string $token, string $pepper): string
    {
        return hash_hmac('sha256', $token, $pepper);
    }

    /** @param array<string, mixed> $data */
    private function safeRequestId(array $data): ?string
    {
        $value = $data['requestId'] ?? null;
        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value) === 1 ? $value : null;
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $result = preg_match_all('/./us', $value, $matches);
        if ($result === false) {
            throw new ProtocolException('INVALID_JSON', 'Message content must be valid UTF-8.');
        }
        return $result;
    }
}
