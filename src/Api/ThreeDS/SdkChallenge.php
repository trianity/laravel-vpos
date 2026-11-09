<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class SdkChallenge
{
    public function __construct(
        public string $threeDSServerTransID,
        public string $acsReferenceNumber,
        public string $acsTransID,
        public string $acsSignedContent,
    ) {}
}
