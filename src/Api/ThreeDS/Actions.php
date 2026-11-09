<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\ThreeDS;

use Trianity\Vpos\Api\Signable;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\WalletSchema;

final readonly class Actions implements Signable
{
    public function __construct(
        public ?FingerprintAction $fingerprint = null,
        public ?AuthenticateAction $authenticate = null,
    ) {}

    public function toSign(): string
    {
        return (new Canonicalizer)->canonicalize($this, WalletSchema::ACTIONS);
    }
}
