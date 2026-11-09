<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\Extensions;

interface VerifiedExtension
{
    public function extension(): string;
}
