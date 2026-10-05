<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];
    private const SENSITIVE_KEYS = ['token', 'token_digest', 'tokenDigest', 'pepper', 'authorization', 'cookie'];

    public function __construct(private readonly string $minimumLevel = 'info')
    {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void { $this->write('debug', $message, $context); }
    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void { $this->write('info', $message, $context); }
    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void { $this->write('warning', $message, $context); }
    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::LEVELS[$this->minimumLevel]) {
            return;
        }
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => self::cleanString($message),
            'context' => self::redact($context),
        ];
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents('php://stderr', ($json ?: '{"level":"error","message":"Log encoding failed"}') . PHP_EOL);
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), array_map('strtolower', self::SENSITIVE_KEYS), true)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $child) {
                $safe[$childKey] = self::redact($child, (string) $childKey);
            }
            return $safe;
        }
        if (is_string($value)) {
            return self::cleanString($value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        return '[UNSERIALIZABLE]';
    }

    private static function cleanString(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
    }
}
