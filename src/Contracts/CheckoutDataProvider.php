<?php

declare(strict_types=1);

namespace Trianity\Vpos\Contracts;

use Illuminate\Http\Request;
use Trianity\Vpos\Support\CheckoutData;

interface CheckoutDataProvider
{
    public function resolve(string $orderReference, Request $request): CheckoutData;

    public function persistPaymentReference(string $orderReference, string $payId): void;
}
