<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Trianity\Vpos\Api\Extensions\VerifiedMaskedCardExtension;
use Trianity\Vpos\Api\Extensions\VerifiedTransactionDatesExtension;
use Trianity\Vpos\Api\ThreeDS\Actions;
use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;
use Trianity\Vpos\Exceptions\MalformedActionException;
use Trianity\Vpos\Exceptions\SignatureException;
use Trianity\Vpos\Exceptions\UnsupportedExtensionException;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\HostedCardService;
use Trianity\Vpos\Support\PaymentSchema;
use Trianity\Vpos\Support\SignatureService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Support\WalletSchema;
use Trianity\Vpos\Support\WalletService;
use Trianity\Vpos\Tests\Support\EapiFixtures;

it('covers active and unavailable OneClick template echo results with distinct origPayId identity', function (int $code): void {
    $response = walletSigned([
        'origPayId' => 'template@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => $code,
        'resultMessage' => $code === 0 ? 'OK' : 'Oneclick template not found',
    ], WalletSchema::ONECLICK_ECHO_RESPONSE);
    Http::fake(['gateway.test/api/v1.0/oneclick/echo' => Http::response($response)]);

    expect(walletService()->oneclickEcho(EapiFixtures::MERCHANT_ID, 'template@HA')['resultCode'])->toBe($code);
    Http::assertSent(fn (Request $request): bool => walletSignedRequest(
        $request,
        'oneclick/echo',
        WalletSchema::ONECLICK_ECHO_REQUEST,
    ) && $request->data()['origPayId'] === 'template@HA');
})->with([0, 700])->group('wallets', 'offline');

it('covers Apple Pay and Google Pay echo with typed provider-specific nested parameters', function (string $provider): void {
    $initParams = $provider === 'applepay' ? [
        'countryCode' => 'HU',
        'supportedNetworks' => ['visa', 'masterCard'],
        'merchantCapabilities' => ['supports3DS'],
    ] : [
        'apiVersion' => 2,
        'apiVersionMinor' => 0,
        'paymentMethodType' => 'CARD',
        'allowedCardNetworks' => ['VISA', 'MASTERCARD'],
        'allowedCardAuthMethods' => ['CRYPTOGRAM_3DS'],
        'assuranceDetailsRequired' => true,
        'billingAddressRequired' => true,
        'billingAddressParametersFormat' => 'FULL',
        'tokenizationSpecificationType' => 'PAYMENT_GATEWAY',
        'gateway' => 'khps',
        'gatewayMerchantId' => EapiFixtures::MERCHANT_ID,
        'googlepayMerchantId' => '01234567890123456789',
        'merchantName' => 'Example shop',
        'environment' => 'TEST',
        'totalPriceStatus' => 'FINAL',
        'countryCode' => 'HU',
    ];
    $schema = $provider === 'applepay' ? WalletSchema::APPLE_ECHO_RESPONSE : WalletSchema::GOOGLE_ECHO_RESPONSE;
    $response = walletSigned([
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'initParams' => $initParams,
    ], $schema);
    Http::fake(["gateway.test/api/v1.0/{$provider}/echo" => Http::response($response)]);

    $result = walletService()->providerEcho($provider, EapiFixtures::MERCHANT_ID);

    expect($result['initParams'])->toBe($initParams);
    Http::assertSent(fn (Request $request): bool => walletSignedRequest($request, "{$provider}/echo", ['merchantId', 'dttm']));
})->with(['applepay', 'googlepay'])->group('wallets', 'offline');

it('returns an authenticated unavailable wallet echo without init parameters', function (string $provider): void {
    $schema = $provider === 'applepay' ? WalletSchema::APPLE_ECHO_RESPONSE : WalletSchema::GOOGLE_ECHO_RESPONSE;
    Http::fake(['gateway.test/*' => Http::response(walletSigned([
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 160,
        'resultMessage' => 'Payment method disabled',
    ], $schema))]);

    expect(walletService()->providerEcho($provider, EapiFixtures::MERCHANT_ID))
        ->not->toHaveKey('initParams')
        ->toMatchArray(['resultCode' => 160]);
})->with(['applepay', 'googlepay'])->group('wallets', 'offline');

it('matches the documented canonical strings for all nine wallet operations', function (): void {
    $oneclickInit = walletOneclickInit(true);
    $appleInit = walletProviderInit('applepay');
    $googleInit = walletProviderInit('googlepay');
    $browserProcess = walletProcessRequest('browser');
    $sdkProcess = walletProcessRequest('sdk');
    $oneclickInit['dttm'] = EapiFixtures::REQUEST_TIMESTAMP;
    $appleInit['dttm'] = EapiFixtures::REQUEST_TIMESTAMP;
    $googleInit['dttm'] = EapiFixtures::REQUEST_TIMESTAMP;
    $browserProcess['dttm'] = EapiFixtures::REQUEST_TIMESTAMP;
    $sdkProcess['dttm'] = EapiFixtures::REQUEST_TIMESTAMP;

    $cases = [
        'oneclick/echo' => [[
            'merchantId' => EapiFixtures::MERCHANT_ID,
            'origPayId' => 'template@HA',
            'dttm' => EapiFixtures::REQUEST_TIMESTAMP,
        ], WalletSchema::ONECLICK_ECHO_REQUEST, 'M1MIPS0000|template@HA|20220125131559'],
        'applepay/echo' => [[
            'merchantId' => EapiFixtures::MERCHANT_ID,
            'dttm' => EapiFixtures::REQUEST_TIMESTAMP,
        ], ['merchantId', 'dttm'], 'M1MIPS0000|20220125131559'],
        'googlepay/echo' => [[
            'merchantId' => EapiFixtures::MERCHANT_ID,
            'dttm' => EapiFixtures::REQUEST_TIMESTAMP,
        ], ['merchantId', 'dttm'], 'M1MIPS0000|20220125131559'],
        'oneclick/init' => [$oneclickInit, WalletSchema::ONECLICK_INIT_REQUEST,
            'M1MIPS0000|template@HA|51966|20220125131559|192.0.2.2|https://shop.example.test/return|POST|true|false|hu|600'],
        'applepay/init' => [$appleInit, WalletSchema::WALLET_INIT_REQUEST,
            'M1MIPS0000|51966|20220125131559|192.0.2.2|12300|HUF|true|c3ludGhldGljLWFwcGxlcGF5LXRva2Vu|https://shop.example.test/return|POST|true|b3JkZXItYXBwbGVwYXk=|hu|600'],
        'googlepay/init' => [$googleInit, WalletSchema::WALLET_INIT_REQUEST,
            'M1MIPS0000|51967|20220125131559|192.0.2.2|12300|HUF|true|c3ludGhldGljLWdvb2dsZXBheS10b2tlbg==|https://shop.example.test/return|POST|true|b3JkZXItZ29vZ2xlcGF5|hu|600'],
        'oneclick/process' => [$browserProcess, WalletSchema::PROCESS_REQUEST,
            'M1MIPS0000|wallet-pay@HA|20220125131559|Synthetic Browser/1.0|text/html|hu|false|05'],
        'applepay/process' => [$browserProcess, WalletSchema::PROCESS_REQUEST,
            'M1MIPS0000|wallet-pay@HA|20220125131559|Synthetic Browser/1.0|text/html|hu|false|05'],
        'googlepay/process' => [$sdkProcess, WalletSchema::PROCESS_REQUEST,
            'M1MIPS0000|wallet-pay@HA|20220125131559|app-id|encrypted-device-data|jwe-public-key|5|sdk-reference|sdk-transaction-id'],
    ];

    foreach ($cases as [$payload, $schema, $expected]) {
        expect((new Canonicalizer)->canonicalize($payload, $schema))->toBe($expected);
    }
})->group('wallets', 'offline');

it('initializes customer and merchant initiated OneClick payments while keeping template and payment IDs distinct', function (bool $clientInitiated): void {
    $response = walletOperationResponse('new-payment@HA', 1, [
        'fingerprint' => [
            'browserInit' => [
                'url' => 'https://acs.example.test/fingerprint',
                'method' => 'POST',
                'vars' => ['threeDSMethodData' => 'encoded-value'],
            ],
        ],
    ]);
    Http::fake(['gateway.test/api/v1.0/oneclick/init' => Http::response($response)]);
    $request = walletOneclickInit($clientInitiated);

    $result = walletService()->oneclickInit($request);

    expect($result['payId'])->toBe('new-payment@HA')
        ->and($result['actions'])->toBeInstanceOf(Actions::class)
        ->and($result['actions']->fingerprint?->browserInit?->vars)
        ->toBe(['threeDSMethodData' => 'encoded-value']);
    Http::assertSent(fn (Request $sent): bool => walletSignedRequest(
        $sent,
        'oneclick/init',
        WalletSchema::ONECLICK_INIT_REQUEST,
    ) && $sent->data()['origPayId'] === 'template@HA'
        && ($clientInitiated ? $sent->data()['clientIp'] === '192.0.2.2' : ! array_key_exists('clientIp', $sent->data())));
})->with([true, false])->group('wallets', 'offline');

it('initializes Apple Pay and Google Pay with integer minor units payload metadata and sdkUsed', function (string $provider): void {
    Http::fake(["gateway.test/api/v1.0/{$provider}/init" => Http::response(walletOperationResponse("{$provider}-pay@HA", 1, [
        'fingerprint' => ['sdkInit' => [
            'directoryServerID' => 'A000000003',
            'schemeId' => 'Visa',
            'messageVersion' => '2.2.0',
        ]],
    ]))]);
    $request = walletProviderInit($provider);

    $result = walletService()->providerInit($provider, $request);

    expect($result['actions'])->toBeInstanceOf(Actions::class)
        ->and($result['actions']->fingerprint?->sdkInit?->messageVersion)->toBe('2.2.0');
    Http::assertSent(function (Request $sent) use ($provider, $request): bool {
        $wire = $sent->data();

        return walletSignedRequest($sent, "{$provider}/init", WalletSchema::WALLET_INIT_REQUEST)
            && $wire['payload'] === $request['payload']
            && is_int($wire['totalAmount'])
            && $wire['sdkUsed'] === true
            && $wire['language'] === 'hu';
    });
})->with(['applepay', 'googlepay'])->group('wallets', 'offline');

it('processes browser and SDK fingerprints for all providers and hydrates frictionless or challenge results', function (
    string $provider,
    string $fingerprintType,
    string $resultType,
): void {
    $actions = match ($resultType) {
        'frictionless' => null,
        'browser' => ['authenticate' => ['browserChallenge' => ['url' => 'https://acs.example.test/challenge']]],
        'sdk' => ['authenticate' => ['sdkChallenge' => [
            'threeDSServerTransID' => 'server-trans-id',
            'acsReferenceNumber' => 'acs-reference',
            'acsTransID' => 'acs-trans-id',
            'acsSignedContent' => 'signed-content',
        ]]],
        default => throw new LogicException("Unknown wallet result fixture [{$resultType}]."),
    };
    Http::fake(["gateway.test/api/v1.0/{$provider}/process" => Http::response(
        walletOperationResponse('wallet-pay@HA', 2, $actions),
    )]);

    $result = walletService()->process($provider, walletProcessRequest($fingerprintType));

    if ($resultType === 'frictionless') {
        expect($result['actions'] ?? null)->toBeNull();
    } else {
        expect($result['actions'] ?? null)->toBeInstanceOf(Actions::class);
    }
    Http::assertSent(fn (Request $sent): bool => walletSignedRequest(
        $sent,
        "{$provider}/process",
        WalletSchema::PROCESS_REQUEST,
    ));
})->with([
    ['oneclick', 'browser', 'frictionless'],
    ['applepay', 'browser', 'browser'],
    ['googlepay', 'sdk', 'sdk'],
])->group('wallets', 'offline');

it('allows merchant-initiated OneClick processing without a fingerprint', function (): void {
    Http::fake(['gateway.test/*' => Http::response(walletOperationResponse('wallet-pay@HA', 2))]);
    $request = ['merchantId' => EapiFixtures::MERCHANT_ID, 'payId' => 'wallet-pay@HA'];

    expect(walletService()->process('oneclick', $request, false)['paymentStatus'])->toBe(2);
    Http::assertSent(fn (Request $sent): bool => ! array_key_exists('fingerprint', $sent->data()));
})->group('wallets', 'offline');

it('enforces conditional OneClick and fingerprint fields', function (callable $operation): void {
    expect(fn () => $operation(walletService()))->toThrow(InvalidPaymentRequestException::class);
})->with([
    'customer init requires IP' => [fn (WalletService $service) => $service->oneclickInit(
        array_diff_key(walletOneclickInit(true), ['clientIp' => true]),
    )],
    'wallet process requires fingerprint' => [fn (WalletService $service) => $service->process('applepay', [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'payId' => 'wallet-pay@HA',
    ])],
    'JavaScript fingerprint requires screen fields' => [fn (WalletService $service) => $service->process('googlepay', [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'payId' => 'wallet-pay@HA',
        'fingerprint' => ['browser' => [
            'userAgent' => 'Browser',
            'acceptHeader' => 'text/html',
            'language' => 'hu',
            'javascriptEnabled' => true,
        ]],
    ])],
    'SDK timeout minimum' => [fn (WalletService $service) => $service->process('applepay', array_replace_recursive(
        walletProcessRequest('sdk'),
        ['fingerprint' => ['sdk' => ['maxTimeout' => 4]]],
    ))],
])->group('wallets', 'offline');

it('rejects a correctly signed but structurally ambiguous action', function (): void {
    $actions = ['fingerprint' => [
        'browserInit' => ['url' => 'https://acs.example.test/fingerprint'],
        'sdkInit' => ['directoryServerID' => 'A', 'schemeId' => 'V', 'messageVersion' => '2.2.0'],
    ]];
    Http::fake(['gateway.test/*' => Http::response(walletOperationResponse('applepay-pay@HA', 1, $actions))]);

    expect(fn () => walletService()->providerInit('applepay', walletProviderInit('applepay')))
        ->toThrow(MalformedActionException::class);
})->group('wallets', 'offline');

it('hydrates authentication actions from signed payment status responses', function (): void {
    $response = walletSigned([
        'payId' => 'wallet-pay@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 2,
        'actions' => ['authenticate' => ['browserChallenge' => [
            'url' => 'https://acs.example.test/challenge',
            'method' => 'GET',
        ]]],
    ], PaymentSchema::PAYMENT_RESPONSE);
    Http::fake(['gateway.test/*' => Http::response($response)]);

    $result = (new HostedCardService(walletTransport()))->status(EapiFixtures::MERCHANT_ID, 'wallet-pay@HA');

    expect($result['actions'])->toBeInstanceOf(Actions::class)
        ->and($result['actions']->authenticate?->browserChallenge?->url)
        ->toBe('https://acs.example.test/challenge');
})->group('wallets', 'offline');

it('verifies masked-card and transaction-date extensions independently from the parent response', function (): void {
    $extensions = [
        walletExtension([
            'extension' => 'maskClnRP',
            'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
            'maskedCln' => '****0209',
            'expiration' => '11/26',
            'longMaskedCln' => '415461****0209',
        ], ['extension', 'dttm', 'maskedCln', 'expiration', 'longMaskedCln']),
        walletExtension([
            'extension' => 'trxDates',
            'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
            'createdDate' => '2022-01-24T10:06:20.848Z',
            'authDate' => '220124100635',
            'settlementDate' => '20220124',
        ], ['extension', 'dttm', 'createdDate', 'authDate', 'settlementDate']),
    ];
    $response = walletSigned([
        'payId' => 'wallet-pay@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 7,
        'extensions' => $extensions,
    ], PaymentSchema::PAYMENT_RESPONSE);
    Http::fake(['gateway.test/*' => Http::response($response)]);

    $result = (new HostedCardService(walletTransport()))->status(EapiFixtures::MERCHANT_ID, 'wallet-pay@HA');

    expect($result['extensions'][0])->toBeInstanceOf(VerifiedMaskedCardExtension::class)
        ->and($result['extensions'][1])->toBeInstanceOf(VerifiedTransactionDatesExtension::class);
})->group('wallets', 'offline');

it('never exposes tampered or unknown extensions as verified', function (string $failure): void {
    $extension = walletExtension([
        'extension' => 'maskClnRP',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'maskedCln' => '****0209',
        'expiration' => '11/26',
        'longMaskedCln' => '415461****0209',
    ], ['extension', 'dttm', 'maskedCln', 'expiration', 'longMaskedCln']);
    if ($failure === 'tampered') {
        $extension['maskedCln'] = '****9999';
    } else {
        $extension['extension'] = 'futureExtension';
    }
    $response = walletSigned([
        'origPayId' => 'template@HA',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'extensions' => [$extension],
    ], WalletSchema::ONECLICK_ECHO_RESPONSE);
    Http::fake(['gateway.test/*' => Http::response($response)]);

    expect(fn () => walletService()->oneclickEcho(EapiFixtures::MERCHANT_ID, 'template@HA'))
        ->toThrow($failure === 'tampered' ? SignatureException::class : UnsupportedExtensionException::class);
})->with(['tampered', 'unknown'])->group('wallets', 'offline');

/** @return array<string, mixed> */
function walletOneclickInit(bool $clientInitiated): array
{
    $payload = [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'origPayId' => 'template@HA',
        'orderNo' => '51966',
        'returnUrl' => 'https://shop.example.test/return',
        'returnMethod' => 'POST',
        'clientInitiated' => $clientInitiated,
        'sdkUsed' => false,
        'language' => 'hu',
        'ttlSec' => 600,
    ];
    if ($clientInitiated) {
        $payload['clientIp'] = '192.0.2.2';
    }

    return $payload;
}

/** @return array<string, mixed> */
function walletProviderInit(string $provider): array
{
    return [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'orderNo' => $provider === 'applepay' ? '51966' : '51967',
        'clientIp' => '192.0.2.2',
        'totalAmount' => 12300,
        'currency' => 'HUF',
        'closePayment' => true,
        'payload' => base64_encode("synthetic-{$provider}-token"),
        'returnUrl' => 'https://shop.example.test/return',
        'returnMethod' => 'POST',
        'sdkUsed' => true,
        'merchantData' => base64_encode("order-{$provider}"),
        'language' => 'hu',
        'ttlSec' => 600,
    ];
}

/** @return array<string, mixed> */
function walletProcessRequest(string $type): array
{
    $fingerprint = $type === 'browser' ? ['browser' => [
        'userAgent' => 'Synthetic Browser/1.0',
        'acceptHeader' => 'text/html',
        'language' => 'hu',
        'javascriptEnabled' => false,
        'challengeWindowSize' => '05',
    ]] : ['sdk' => [
        'appID' => 'app-id',
        'encData' => 'encrypted-device-data',
        'ephemPubKey' => 'jwe-public-key',
        'maxTimeout' => 5,
        'referenceNumber' => 'sdk-reference',
        'transID' => 'sdk-transaction-id',
    ]];

    return [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'payId' => 'wallet-pay@HA',
        'fingerprint' => $fingerprint,
    ];
}

/** @param array<string, mixed>|null $actions @return array<string, mixed> */
function walletOperationResponse(string $payId, int $status, ?array $actions = null): array
{
    $payload = [
        'payId' => $payId,
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => $status,
    ];
    if ($actions !== null) {
        $payload['actions'] = $actions;
    }

    return walletSigned($payload, WalletSchema::OPERATION_RESPONSE);
}

/** @param array<string, mixed> $payload @param array<int|string, mixed> $schema @return array<string, mixed> */
function walletSigned(array $payload, array $schema): array
{
    $payload['signature'] = EapiFixtures::sign(
        (new Canonicalizer)->canonicalize($payload, $schema),
        EapiFixtures::gatewayPrivateKey(),
    );

    return $payload;
}

/** @param array<string, mixed> $payload @param array<int|string, mixed> $schema @return array<string, mixed> */
function walletExtension(array $payload, array $schema): array
{
    return walletSigned($payload, $schema);
}

/** @param array<int|string, mixed> $schema */
function walletSignedRequest(Request $request, string $endpoint, array $schema): bool
{
    $payload = $request->data();

    return $request->method() === 'POST'
        && $request->url() === "https://gateway.test/api/v1.0/{$endpoint}"
        && EapiFixtures::verify(
            (new Canonicalizer)->canonicalize($payload, $schema),
            $payload['signature'],
            EapiFixtures::merchantPublicKey(),
        );
}

function walletService(): WalletService
{
    return new WalletService(walletTransport());
}

function walletTransport(): SignedJsonTransport
{
    return new SignedJsonTransport(
        'https://gateway.test/api/v1.0/',
        new SignatureService(EapiFixtures::merchantPrivateKey(), EapiFixtures::gatewayPublicKey()),
        new Canonicalizer,
        static fn (): string => EapiFixtures::REQUEST_TIMESTAMP,
    );
}
