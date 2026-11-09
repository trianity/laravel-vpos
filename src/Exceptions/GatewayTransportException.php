<?php

declare(strict_types=1);

namespace Trianity\Vpos\Exceptions;

class GatewayTransportException extends VposException
{
    public function __construct(
        string $message,
        public readonly string $category = 'transport_failure',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
