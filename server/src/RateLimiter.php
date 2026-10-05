<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

final class RateLimiter
{
    /** @var list<float> */
    private array $attempts = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $windowSeconds,
    ) {
    }

    public function consume(?float $now = null): bool
    {
        $now ??= microtime(true);
        $cutoff = $now - $this->windowSeconds;
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (float $attempt): bool => $attempt > $cutoff,
        ));
        if (count($this->attempts) >= $this->limit) {
            return false;
        }
        $this->attempts[] = $now;
        return true;
    }
}
