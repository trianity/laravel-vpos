<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Illuminate\Http\Request;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Exceptions\CheckoutConfigurationException;

final class UnconfiguredCheckoutDataProvider implements CheckoutDataProvider
{
    public function resolve(string $orderReference, Request $request): CheckoutData
    {
        throw new CheckoutConfigurationException(
            'Bind CheckoutDataProvider to a host-owned order lookup before using the payment route.',
        );
    }

    public function persistPaymentReference(string $orderReference, string $payId): void
    {
        throw new CheckoutConfigurationException(
            'Bind CheckoutDataProvider to a host-owned payment reference store before using the payment route.',
        );
    }
}
