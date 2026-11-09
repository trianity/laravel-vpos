<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final readonly class VerifiedBrowserReturn
{
    public function __construct(
        public string $payId,
        public string $dttm,
        public int $resultCode,
        public string $resultMessage,
        public ?int $paymentStatus,
        public ?string $authCode,
        public ?string $merchantData,
        public ?string $statusDetail,
    ) {}
}
