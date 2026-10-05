<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
