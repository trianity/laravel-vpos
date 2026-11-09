<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class SdkFingerprint
{
    public function __construct(
        public string $appID,
        public string $encData,
        public string $ephemPubKey,
        public int $maxTimeout,
        public string $referenceNumber,
        public string $transID,
    ) {}
}
