<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

final readonly class Endpoint
{
    /** @param array<string, string> $vars */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public array $vars = [],
    ) {}
}
