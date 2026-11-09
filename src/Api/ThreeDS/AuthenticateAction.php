<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class AuthenticateAction
{
    public function __construct(
        public ?Endpoint $browserChallenge = null,
        public ?SdkChallenge $sdkChallenge = null,
    ) {}
}
