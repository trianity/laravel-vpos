<?php

declare(strict_types=1);

namespace Trianity\Vpos\Exceptions;

class GatewayHttpException extends VposException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $unverifiedResultCode = null,
    ) {
        parent::__construct("The payment gateway returned HTTP {$status}.");
    }

    public function isDefinitiveRejection(): bool
    {
        return $this->status >= 400
            && $this->status < 500
            && $this->unverifiedResultCode !== null;
    }
}
