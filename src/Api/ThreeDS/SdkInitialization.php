<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class SdkInitialization
{
    public function __construct(
        public string $directoryServerID,
        public string $schemeId,
        public string $messageVersion,
    ) {}
}
