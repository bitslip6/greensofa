<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $time)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $interval): void
    {
        $this->time = $this->time->modify($interval);
    }
}
