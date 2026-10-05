<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

use RuntimeException;

final class ProtocolException extends RuntimeException
{
    public function __construct(
        public readonly string $protocolCode,
        string $message,
        public readonly bool $fatal = false,
        public readonly ?string $requestType = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
