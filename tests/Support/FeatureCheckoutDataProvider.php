<?php

declare(strict_types=1);

namespace Trianity\Vpos\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Support\CheckoutData;

final class FeatureCheckoutDataProvider implements CheckoutDataProvider
{
    /** @var list<array{orderReference: string, payId: string}> */
    public array $persisted = [];

    /** @var list<string> */
    public array $events = [];

    /** @param (Closure(string, string): void)|null $onPersist */
    public function __construct(
        private readonly CheckoutData $checkout,
        private readonly ?Closure $onPersist = null,
    ) {}

    public function resolve(string $orderReference, Request $request): CheckoutData
    {
        $this->events[] = 'resolve:'.$orderReference;

        return $this->checkout;
    }

    public function persistPaymentReference(string $orderReference, string $payId): void
    {
        if ($this->onPersist !== null) {
            ($this->onPersist)($orderReference, $payId);
        }

        $this->persisted[] = compact('orderReference', 'payId');
        $this->events[] = 'persist:'.$orderReference;
    }
}
