<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Exceptions\CheckoutConfigurationException;
use Trianity\Vpos\Facades\KHVpos;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\CheckoutData;
use Trianity\Vpos\Support\GatewayConfig;
use Trianity\Vpos\Support\PaymentSchema;
use Trianity\Vpos\Tests\Support\EapiFixtures;
use Trianity\Vpos\Tests\Support\FeatureCheckoutDataProvider;
use Trianity\Vpos\Vpos;

beforeEach(function (): void {
    config()->set('vpos.checkout.return_url', 'https://shop.example.test/vpos/return');
    config()->set('vpos.currency', 'HUF');
    config()->set('vpos.lang', 'HU');
});

it('resolves the package facade and container alias to the same verified client', function (): void {
    KHVpos::clearResolvedInstance('khvpos');

    expect(app('khvpos'))->toBe(app(Vpos::class))
        ->and(KHVpos::getFacadeRoot())->toBe(app(Vpos::class))
        ->and(KHVpos::merchantId())->toBe(EapiFixtures::MERCHANT_ID);
})->group('controller', 'offline');

it('boots routes and resolves published error views without payment credentials', function (): void {
    config()->set('vpos.test.privateKey');
    config()->set('vpos.test.publicKey');

    $this->get(route('vpos.error'))->assertOk()->assertSee('Payment could not be started');
    $this->get(route('vpos.apierror'))->assertOk()->assertSee('Payment service unavailable');
})->group('controller', 'offline');

it('handles missing payment credentials at the route boundary', function (): void {
    app()->instance(CheckoutDataProvider::class, new FeatureCheckoutDataProvider(new CheckoutData('5547', 123400)));
    config()->set('vpos.test.privateKey');
    config()->set('vpos.test.publicKey');

    $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42'])
        ->assertRedirect(route('vpos.apierror'));

    Http::assertNothingSent();
})->group('controller', 'offline');

it('requires the single canonical merchant configuration', function (): void {
    config()->set('vpos.merchant_id');

    expect(fn (): string => app(Vpos::class)->merchantId())
        ->toThrow(CheckoutConfigurationException::class);
})->group('controller', 'offline');

it('rejects invalid currency language and trusted return URL configuration', function (): void {
    $config = app(GatewayConfig::class);
    config()->set('vpos.currency', 'BTC');
    expect(fn (): string => $config->currency())->toThrow(CheckoutConfigurationException::class);

    config()->set('vpos.lang', 'xx');
    expect(fn (): string => $config->language())->toThrow(CheckoutConfigurationException::class);

    config()->set('vpos.checkout.return_url', 'http://shop.example.test/return');
    expect(fn (): string => $config->returnUrl())->toThrow(CheckoutConfigurationException::class);
})->group('controller', 'offline');

it('uses host-owned checkout data and redirects the browser to the signed handoff URL', function (): void {
    $provider = new FeatureCheckoutDataProvider(new CheckoutData(
        '5547',
        123400,
        [['name' => 'Server order', 'quantity' => 1, 'amount' => 123400]],
        'trusted-order-context',
    ));
    app()->instance(CheckoutDataProvider::class, $provider);
    Http::fake(['gateway.test/*' => Http::sequence()
        ->push(EapiFixtures::successfulEchoResponse())
        ->push(featureSignedResponse([
            'payId' => 'payment@HA',
            'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
            'resultCode' => 0,
            'resultMessage' => 'OK',
            'paymentStatus' => 1,
        ], PaymentSchema::INIT_RESPONSE))]);

    $response = $this->from('/checkout')->post(route('vpos.paymentinit'), [
        'orderReference' => 'host-order-42',
        'totalAmount' => 1,
        'returnUrl' => 'https://attacker.example/return',
        'cart' => [['name' => 'Attacker cart', 'amount' => 1]],
    ]);

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toBeString()
        ->and($location)->toStartWith('https://gateway.test/api/v1.0/payment/process/'.EapiFixtures::MERCHANT_ID.'/payment%40HA/');
    featureAssertSignedProcessUrl($location, 'payment@HA');
    expect($provider->persisted)->toBe([[
        'orderReference' => 'host-order-42',
        'payId' => 'payment@HA',
    ]])->and($provider->events)->toBe([
        'resolve:host-order-42',
        'persist:host-order-42',
    ]);

    Http::assertSent(function (HttpRequest $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'https://gateway.test/api/v1.0/payment/init') {
            return true;
        }
        $payload = $request->data();

        return $payload['orderNo'] === '5547'
            && $payload['totalAmount'] === 123400
            && $payload['returnUrl'] === 'https://shop.example.test/vpos/return'
            && $payload['cart'][0]['name'] === 'Server order'
            && $payload['merchantData'] === 'trusted-order-context'
            && $payload['currency'] === 'HUF'
            && $payload['language'] === 'hu';
    });
    Http::assertSentCount(2);
})->group('controller', 'offline');

it('fails visibly after verified init when the host cannot durably persist the payment reference', function (): void {
    $provider = new FeatureCheckoutDataProvider(
        new CheckoutData('5547', 123400, [
            ['name' => 'Server order', 'quantity' => 1, 'amount' => 123400],
        ]),
        static function (string $orderReference, string $payId): void {
            expect($orderReference)->toBe('host-order-42')
                ->and($payId)->toBe('payment@HA');

            throw new RuntimeException('Synthetic persistence failure.');
        },
    );
    app()->instance(CheckoutDataProvider::class, $provider);
    Http::fake(['gateway.test/*' => Http::sequence()
        ->push(EapiFixtures::successfulEchoResponse())
        ->push(featureSignedResponse([
            'payId' => 'payment@HA',
            'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
            'resultCode' => 0,
            'resultMessage' => 'OK',
            'paymentStatus' => 1,
        ], PaymentSchema::INIT_RESPONSE))]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42']))
        ->toThrow(RuntimeException::class, 'Synthetic persistence failure.');

    expect($provider->persisted)->toBeEmpty();
    Http::assertSentCount(2);
})->group('controller', 'offline');

it('validates the required host order reference before contacting the gateway', function (): void {
    $this->from('/checkout')->post(route('vpos.paymentinit'))
        ->assertRedirect('/checkout')
        ->assertSessionHasErrors('orderReference');

    Http::assertNothingSent();
})->group('controller', 'offline');

it('fails safely before gateway I/O when the host checkout provider is not configured', function (): void {
    $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42'])
        ->assertRedirect(route('vpos.error'));

    Http::assertNothingSent();
})->group('controller', 'offline');

it('handles an unavailable authenticated echo without attempting initialization', function (): void {
    app()->instance(CheckoutDataProvider::class, new FeatureCheckoutDataProvider(new CheckoutData('5547', 123400)));
    Http::fake(['gateway.test/*' => Http::response(EapiFixtures::echoResponse(160, 'Payment method disabled'))]);

    $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42'])
        ->assertRedirect(route('vpos.apierror'));

    Http::assertSentCount(1);
})->group('controller', 'offline');

it('handles a signed failed initialization without a null dereference', function (): void {
    $provider = new FeatureCheckoutDataProvider(new CheckoutData('5547', 123400));
    app()->instance(CheckoutDataProvider::class, $provider);
    Http::fake(['gateway.test/*' => Http::sequence()
        ->push(EapiFixtures::successfulEchoResponse())
        ->push(featureSignedResponse([
            'payId' => 'payment@HA',
            'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
            'resultCode' => 150,
            'resultMessage' => 'Payment not in valid state',
            'paymentStatus' => 1,
        ], PaymentSchema::INIT_RESPONSE))]);

    $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42'])
        ->assertRedirect(route('vpos.error'));

    expect($provider->persisted)->toBeEmpty();
})->group('controller', 'offline');

it('rejects a tampered gateway initialization signature', function (): void {
    $provider = new FeatureCheckoutDataProvider(new CheckoutData('5547', 123400));
    app()->instance(CheckoutDataProvider::class, $provider);
    $init = featureSignedResponse([
        'payId' => 'payment@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 1,
    ], PaymentSchema::INIT_RESPONSE);
    $init['payId'] = 'tampered@HA';
    Http::fake(['gateway.test/*' => Http::sequence()
        ->push(EapiFixtures::successfulEchoResponse())
        ->push($init)]);

    $this->post(route('vpos.paymentinit'), ['orderReference' => 'host-order-42'])
        ->assertRedirect(route('vpos.error'));

    expect($provider->persisted)->toBeEmpty();
})->group('controller', 'offline');

it('exposes verified browser return and status boundaries through the facade client', function (): void {
    KHVpos::clearResolvedInstance('khvpos');
    $browserReturn = featureSignedResponse([
        'payId' => 'payment@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 2,
        'merchantData' => 'trusted-order-context',
    ], PaymentSchema::BROWSER_RETURN);
    Http::fake(['gateway.test/*' => Http::response(featureSignedResponse([
        'payId' => 'payment@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 2,
    ], PaymentSchema::PAYMENT_RESPONSE))]);

    $verified = KHVpos::verifyReturn($browserReturn, 'payment@HA', 'trusted-order-context');
    $status = KHVpos::status('payment@HA');

    expect($verified->payId)->toBe('payment@HA')
        ->and($verified->paymentStatus)->toBe(2)
        ->and($status['paymentStatus'])->toBe(2);
})->group('controller', 'offline');

/** @param array<string, mixed> $payload @param array<int|string, mixed> $schema @return array<string, mixed> */
function featureSignedResponse(array $payload, array $schema): array
{
    $payload['signature'] = EapiFixtures::sign(
        (new Canonicalizer)->canonicalize($payload, $schema),
        EapiFixtures::gatewayPrivateKey(),
    );

    return $payload;
}

function featureAssertSignedProcessUrl(?string $url, string $payId): void
{
    expect($url)->toBeString();
    $segments = explode('/', (string) parse_url($url, PHP_URL_PATH));
    $merchantId = rawurldecode($segments[count($segments) - 4]);
    $actualPayId = rawurldecode($segments[count($segments) - 3]);
    $dttm = rawurldecode($segments[count($segments) - 2]);
    $signature = rawurldecode($segments[count($segments) - 1]);

    expect($merchantId)->toBe(EapiFixtures::MERCHANT_ID)
        ->and($actualPayId)->toBe($payId)
        ->and(EapiFixtures::verify(
            "{$merchantId}|{$actualPayId}|{$dttm}",
            $signature,
            EapiFixtures::merchantPublicKey(),
        ))->toBeTrue();
}
