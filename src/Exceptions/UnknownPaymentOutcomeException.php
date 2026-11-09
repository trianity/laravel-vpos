<?php

declare(strict_types=1);

namespace Trianity\Vpos\Exceptions;

class UnknownPaymentOutcomeException extends GatewayTransportException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $payId,
        string $category,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "The outcome of payment/{$operation} is unknown. Check payment/status and obtain operator review before retrying.",
            $category,
            $previous,
        );
    }
}
