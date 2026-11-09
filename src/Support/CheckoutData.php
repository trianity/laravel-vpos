<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final readonly class CheckoutData
{
    /**
     * @param  list<array<string, mixed>|object>  $cart
     * @param  array<string, mixed>|object|null  $customer
     * @param  array<string, mixed>|object|null  $order
     */
    public function __construct(
        public string $orderNo,
        public int $totalAmount,
        public array $cart = [],
        public ?string $merchantData = null,
        public array|object|null $customer = null,
        public array|object|null $order = null,
    ) {}
}
