<?php

declare(strict_types=1);

namespace Checkerboard\Lobby;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values) {}

    public static function fromEnvironment(bool $validateRuntime = true): self
    {
        $root = dirname(__DIR__);
        $devMode = self::envBool('LOBBY_DEV_MODE', false);
        $values = [
            'devMode' => $devMode,
            'host' => self::env('LOBBY_HOST', '0.0.0.0'),
            'port' => self::envInt('LOBBY_PORT', $devMode ? 9502 : 443, 1, 65535),
            'certFile' => self::env('LOBBY_TLS_CERT_FILE', ''),
            'keyFile' => self::env('LOBBY_TLS_KEY_FILE', ''),
            'keyPassphrase' => self::env('LOBBY_TLS_KEY_PASSPHRASE', ''),
            'databasePath' => self::env('LOBBY_DATABASE_PATH', $root . '/var/lobby.sqlite3'),
            'tokenPepper' => self::env('LOBBY_TOKEN_PEPPER', ''),
            'registrationTimeoutSeconds' => self::envInt('LOBBY_REGISTRATION_TIMEOUT', 10, 2, 120),
            'reservationSeconds' => self::envInt('LOBBY_RESERVATION_SECONDS', 172800, 60, 31536000),
            'historyCount' => self::envInt('LOBBY_HISTORY_COUNT', 20, 0, 100),
            'maxFrameBytes' => self::envInt('LOBBY_MAX_FRAME_BYTES', 16384, 1024, 1048576),
            'maxChatCharacters' => self::envInt('LOBBY_MAX_CHAT_CHARACTERS', 500, 1, 5000),
            'pingIntervalSeconds' => self::envInt('LOBBY_PING_INTERVAL', 30, 5, 300),
            'pongTimeoutSeconds' => self::envInt('LOBBY_PONG_TIMEOUT', 75, 10, 600),
            'registrationRateCount' => self::envInt('LOBBY_REGISTER_RATE_COUNT', 5, 1, 100),
            'registrationRateWindow' => self::envInt('LOBBY_REGISTER_RATE_WINDOW', 60, 1, 3600),
            'chatRateCount' => self::envInt('LOBBY_CHAT_RATE_COUNT', 12, 1, 1000),
            'chatRateWindow' => self::envInt('LOBBY_CHAT_RATE_WINDOW', 10, 1, 3600),
            'cleanupIntervalSeconds' => self::envInt('LOBBY_CLEANUP_INTERVAL', 300, 30, 86400),
            'logLevel' => strtolower(self::env('LOBBY_LOG_LEVEL', 'info')),
        ];

        if (!in_array($values['logLevel'], ['debug', 'info', 'warning', 'error'], true)) {
            throw new RuntimeException('LOBBY_LOG_LEVEL must be debug, info, warning, or error.');
        }
        if ($values['pongTimeoutSeconds'] <= $values['pingIntervalSeconds']) {
            throw new RuntimeException('LOBBY_PONG_TIMEOUT must be greater than LOBBY_PING_INTERVAL.');
        }

        $config = new self($values);
        if ($validateRuntime) {
            $config->validateRuntime();
        }
        return $config;
    }

    /** @param array<string, mixed> $overrides */
    public static function forTests(array $overrides = []): self
    {
        return new self(array_merge([
            'devMode' => true,
            'host' => '127.0.0.1',
            'port' => 9443,
            'certFile' => '',
            'keyFile' => '',
            'keyPassphrase' => '',
            'databasePath' => ':memory:',
            'tokenPepper' => str_repeat('test-pepper-', 4),
            'registrationTimeoutSeconds' => 10,
            'reservationSeconds' => 172800,
            'historyCount' => 20,
            'maxFrameBytes' => 16384,
            'maxChatCharacters' => 500,
            'pingIntervalSeconds' => 30,
            'pongTimeoutSeconds' => 75,
            'registrationRateCount' => 5,
            'registrationRateWindow' => 60,
            'chatRateCount' => 12,
            'chatRateWindow' => 10,
            'cleanupIntervalSeconds' => 300,
            'logLevel' => 'error',
        ], $overrides));
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new RuntimeException("Unknown configuration key: {$key}");
        }
        return $this->values[$key];
    }

    private function validateRuntime(): void
    {
        if (!extension_loaded('openswoole')) {
            throw new RuntimeException('The OpenSwoole extension is required.');
        }
        $version = phpversion('openswoole') ?: '0.0.0';
        $major = (int) explode('.', $version)[0];
        if ($major !== 26 || version_compare($version, '26.2.0', '<')) {
            throw new RuntimeException("OpenSwoole 26.2.x is required; found {$version}.");
        }
        $devMode = (bool) $this->get('devMode');
        if (!$devMode && !defined('SWOOLE_SSL')) {
            throw new RuntimeException('OpenSwoole must be built with SSL support for direct WSS termination. Set LOBBY_DEV_MODE=1 only for local plain-WS development.');
        }
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The PDO SQLite extension is required.');
        }
        if (strlen((string) $this->get('tokenPepper')) < 32) {
            throw new RuntimeException('LOBBY_TOKEN_PEPPER is required and must contain at least 32 characters.');
        }
        if (!$devMode) {
            foreach (['certFile' => 'TLS certificate', 'keyFile' => 'TLS private key'] as $key => $label) {
                $path = (string) $this->get($key);
                if ($path === '' || !is_file($path) || !is_readable($path)) {
                    throw new RuntimeException("{$label} file is missing or unreadable.");
                }
            }
        }
        $databasePath = (string) $this->get('databasePath');
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('SQLite database directory could not be created.');
            }
            if (!is_writable($directory)) {
                throw new RuntimeException('SQLite database directory is not writable.');
            }
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim($value);
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        return match (strtolower(trim($raw))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new RuntimeException("{$name} must be one of: 1, 0, true, false, yes, no, on, off."),
        };
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("{$name} must be an integer.");
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new RuntimeException("{$name} must be between {$min} and {$max}.");
        }
        return $value;
    }
}
