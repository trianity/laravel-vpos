<?php

declare(strict_types=1);

namespace Trianity\Vpos\Api\Extensions;

final readonly class VerifiedTransactionDatesExtension implements VerifiedExtension
{
    public function __construct(
        public string $dttm,
        public string $createdDate,
        public ?string $authDate,
        public ?string $settlementDate,
    ) {}

    public function extension(): string
    {
        return 'trxDates';
    }
}
