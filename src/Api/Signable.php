<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api;

interface Signable
{
    public function toSign(): string;
}
