# K&H VPOS for Laravel

Laravel 13 integration for the K&H eAPI v1.0 payment gateway. The package signs requests with RSA/SHA-256, verifies every signed gateway response, and supports hosted card payments, OneClick, Apple Pay and Google Pay through one client stack.

## Requirements

- PHP 8.4.27 or later in the PHP 8 series
- Laravel 13
- OpenSSL PHP extension

## Installation

```bash
composer require trianity/vpos
php artisan vendor:publish --provider="Trianity\Vpos\Providers\PackageServiceProvider" --tag=vpos-config
```

Package discovery registers the provider and `KHVpos` facade automatically.

## Configuration

Sandbox:

```dotenv
KH_ENV=test
KH_VPOSID=your-sandbox-vpos-id
KH_CURRENCY=HUF
KH_LANG=HU
KH_RETURN_URL=https://shop.example.test/payments/kh-return
KH_TEST_PRIVATE_KEY=key/merchant-private.pem
KH_TEST_PUBLIC_KEY=key/kh-sandbox-public.pem
```

Production uses the same VPOS ID and trusted return URL with production keys:

```dotenv
KH_ENV=live
KH_VPOSID=your-production-vpos-id
KH_PRIVATE_KEY=key/merchant-private.pem
KH_PUBLIC_KEY=key/kh-production-public.pem
```

Key paths may be absolute or relative to `storage/app`. Keep private keys and environment files outside version control. Response signature verification is mandatory and cannot be disabled.

## Server-owned checkout data and payment-reference persistence

The package route accepts only an opaque `orderReference`. The host must authorize the order, calculate the amount/cart from trusted persistence, and durably store the verified `payId` before the package redirects the payer to K&H.

Bind `CheckoutDataProvider` in an application service provider:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Support\CheckoutData;

$this->app->bind(CheckoutDataProvider::class, function () {
    return new class implements CheckoutDataProvider {
        public function resolve(string $orderReference, Request $request): CheckoutData
        {
            $order = Order::query()->whereKey($orderReference)->firstOrFail();
            Gate::forUser($request->user())->authorize('pay', $order);

            $merchantData = base64_encode($order->gatewayMerchantContext());

            if (strlen($merchantData) > 255) {
                throw new LogicException('Encoded merchantData exceeds 255 characters.');
            }

            return new CheckoutData(
                orderNo: (string) $order->gateway_order_no,
                totalAmount: $order->total_minor,
                cart: $order->gatewayCart(),
                merchantData: $merchantData,
            );
        }

        public function persistPaymentReference(string $orderReference, string $payId): void
        {
            DB::transaction(function () use ($orderReference, $payId): void {
                $order = Order::query()->whereKey($orderReference)->lockForUpdate()->firstOrFail();

                if ($order->vpos_pay_id === $payId) {
                    return;
                }
                if ($order->vpos_pay_id !== null) {
                    throw new LogicException('The order already has a different payment attempt.');
                }

                $order->forceFill(['vpos_pay_id' => $payId])->saveOrFail();
            });
        }
    };
});
```

`merchantData` is optional. When used, it must be Base64-encoded and no longer than 255 characters after encoding.

Start checkout with:

```http
POST /vpos/payment/init
Content-Type: application/x-www-form-urlencoded

orderReference=host-owned-order-id
```

The route performs verified Echo and Init calls, invokes `persistPaymentReference()` with the exact order reference and verified `payId`, then returns the browser redirect. Returning from `persistPaymentReference()` confirms durable storage. If persistence throws, no handoff or automatic re-init occurs; the exception remains visible to the host because a bank-side transaction may already exist.

The host should serialize concurrent submissions per order. Repeated submissions must either reuse the same durably stored attempt or stop and reconcile it; they must never create a second payment merely because the first browser redirect was lost.

## Callback verification and reconciliation

The host application owns the public return route, middleware, order lookup, replay protection, persistence and fulfillment. Verify the decoded query/form fields, correlate them with the stored payment attempt, then query signed status:

```php
use Illuminate\Http\Request;
use Trianity\Vpos\Facades\KHVpos;

public function returned(Request $request)
{
    $order = Order::query()
        ->where('payment_return_token', $request->route('returnToken'))
        ->firstOrFail();

    $verified = KHVpos::verifyReturn(
        $request->all(),
        expectedPayId: $order->vpos_pay_id,
        expectedMerchantData: $order->gatewayMerchantData(),
    );

    $status = KHVpos::status($verified->payId);

    $order->applyVerifiedPaymentStatusIdempotently($status);

    return redirect()->route('orders.show', $order);
}
```

The browser return is not fulfillment authority. Use verified, correlated `payment/status` and an idempotent host transition. If the browser never returns, reconcile the stored `payId` directly through `KHVpos::status()`.

For `close`, `reverse` and `refund`, never retry automatically after `UnknownPaymentOutcomeException`. Use its `operation`, `payId` and safe `category` properties to reconcile through signed status and require operator review when status cannot establish the mutation outcome. Refund completion is asynchronous.

## Sandbox acceptance

The default test suite is offline and denies stray HTTP. The opt-in Echo harness is documented in [docs/acceptance-and-sandbox.md](docs/acceptance-and-sandbox.md); payment scenarios require separate authorization and merchant provisioning.

## License

Released under the [MIT License](LICENSE).
