<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class BrowserFingerprint
{
    public function __construct(
        public string $userAgent,
        public string $acceptHeader,
        public string $language,
        public bool $javascriptEnabled,
        public ?int $colorDepth = null,
        public ?int $screenHeight = null,
        public ?int $screenWidth = null,
        public ?int $timezone = null,
        public ?bool $javaEnabled = null,
        public ?string $challengeWindowSize = null,
    ) {}
}
