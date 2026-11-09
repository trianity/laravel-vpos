<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class FingerprintAction
{
    public function __construct(
        public ?Endpoint $browserInit = null,
        public ?SdkInitialization $sdkInit = null,
    ) {}
}
