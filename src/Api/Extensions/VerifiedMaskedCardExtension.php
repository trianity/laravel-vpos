<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\Extensions;

final readonly class VerifiedMaskedCardExtension implements VerifiedExtension
{
    public function __construct(
        public string $identifier,
        public string $dttm,
        public string $maskedCln,
        public string $expiration,
        public string $longMaskedCln,
    ) {}

    public function extension(): string
    {
        return $this->identifier;
    }
}
