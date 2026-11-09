# Laravel entry points and host integration

Prompt 06 connects the package route, facade and Laravel container to the same verified `Vpos` client. Service construction is lazy: application boot, route registration and error views do not load payment keys. Keys become mandatory when the payment client is resolved.

## Configuration

Publish the package assets and configure the selected environment:

```bash
php artisan vendor:publish --tag=vpos-config
```

```dotenv
KH_ENV=test
KH_VPOSID=M1...
KH_CURRENCY=HUF
KH_LANG=HU
KH_RETURN_URL=https://shop.example.test/payments/return
KH_TEST_PRIVATE_KEY=key/merchant-private.pem
KH_TEST_PUBLIC_KEY=key/gateway-public.pem
```

`KH_VPOSID` is the only eAPI merchant identifier setting. It must contain the VPOS identifier used as the signed `merchantId`, not a distinct terminal/MID value. Currency is normalized to uppercase and limited to HUF/EUR/USD. Language is normalized to the lowercase gateway value. The trusted return URL must be an absolute HTTPS URL and is never accepted from request input.

The Composer alias is `KHVpos => Trianity\Vpos\Facades\KHVpos`. The facade, `app('khvpos')` and `app(Trianity\Vpos\Vpos::class)` resolve the same singleton.

## Required host checkout provider

Real applications must bind `CheckoutDataProvider` to a server-owned, authorized order lookup and durable payment-reference store. The browser supplies only an opaque `orderReference`; price, cart, gateway order number and merchant context come from trusted persistence.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Support\CheckoutData;

$this->app->bind(CheckoutDataProvider::class, function ($app) {
    return new class implements CheckoutDataProvider {
        public function resolve(string $orderReference, Request $request): CheckoutData
        {
            $order = Order::query()->whereKey($orderReference)->firstOrFail();
            Gate::forUser($request->user())->authorize('pay', $order);

            return new CheckoutData(
                orderNo: (string) $order->gateway_order_no,
                totalAmount: $order->total_minor,
                cart: $order->gatewayCart(),
                merchantData: $order->signedContext(),
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

`merchantData`, when present, must be Base64-encoded and at most 255 characters after encoding.

`POST /vpos/payment/init` retains the `vpos.paymentinit` route name and requires `orderReference`. The controller resolves trusted checkout data before gateway I/O, verifies Echo, sends a signed init request, requires `resultCode=0`, `resultMessage=OK` and `paymentStatus=1`, then synchronously calls `persistPaymentReference()` with the exact order reference and verified `payId`. Only a successful return from that method permits the signed browser redirect.

The host must make persistence atomic and durable, serialize concurrent starts for the same order, and treat the same stored `payId` idempotently. A different existing attempt must stop a new handoff and be reconciled instead. A persistence exception deliberately remains visible to the host: Init may already have created a bank transaction, so the package neither redirects nor retries automatically.

## Return and reconciliation boundary

No public callback route is registered by the package. The host owns its route middleware, order lookup, replay/idempotency policy, persistence and fulfillment. Verify the browser payload first, then query authoritative status using the order's stored `payId`:

```php
use Trianity\Vpos\Facades\KHVpos;

$verified = KHVpos::verifyReturn(
    $request->all(),
    expectedPayId: $order->vpos_pay_id,
    expectedMerchantData: $order->gateway_context,
);

$status = KHVpos::status($order->vpos_pay_id);
```

Repeated or out-of-order returns must be safe in the host. A browser return alone must not fulfill an order; use verified, correlated status and an idempotent state transition. If the browser never returns, query status using the `payId` stored before handoff.

## Supported surface

- `Vpos`, `KHVpos` and the package route are the only Laravel client entry points.
- The abandoned `ApiService`, `BaseApiService`, duplicate DTO/crypto layers and raw cURL client are not shipped.
- The package route does not accept browser-owned amount, cart, merchant data or return URL.
- The package ships no transaction table or order model; storage and fulfillment policy belong to the host.
- Error routes and names remain `vpos.error` and `vpos.apierror`; their package views are loaded and publishable.
