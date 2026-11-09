<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Trianity\Vpos\Exceptions\BrowserReturnMismatchException;
use Trianity\Vpos\Exceptions\GatewayHttpException;
use Trianity\Vpos\Exceptions\GatewayIdentityMismatchException;
use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;
use Trianity\Vpos\Exceptions\SignatureException;
use Trianity\Vpos\Exceptions\UnexpectedGatewayRedirectException;
use Trianity\Vpos\Exceptions\UnknownPaymentOutcomeException;
use Trianity\Vpos\Support\BrowserReturnVerifier;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\HostedCardService;
use Trianity\Vpos\Support\PaymentInitSchema;
use Trianity\Vpos\Support\PaymentSchema;
use Trianity\Vpos\Support\SignatureService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Tests\Support\EapiFixtures;

it('initializes standard extended and OneClick-template card payments with signed top-level JSON', function (string $variant): void {
    Http::fake(['gateway.test/api/v1.0/payment/init' => Http::response(cardResponse(1))]);
    $payload = initPayload();

    if ($variant === 'extended') {
        $payload['customer'] = [
            'name' => 'Jan Novák',
            'email' => 'jan@example.test',
            'mobilePhone' => '+420800300300',
            'account' => ['createdAt' => '2022-01-12T12:10:37+01:00'],
            'login' => ['auth' => 'account', 'authAt' => '2022-01-25T13:10:03+01:00'],
        ];
        $payload['order'] = [
            'type' => 'purchase',
            'availability' => 'now',
            'delivery' => 'shipping',
            'deliveryMode' => '1',
            'addressMatch' => true,
            'billing' => ['address1' => 'Karlova 1', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZE'],
        ];
    }
    if ($variant === 'oneclick') {
        $payload['payOperation'] = 'oneclickPayment';
        $payload['ttlSec'] = 600;
        $payload['closePayment'] = false;
    }

    $response = cardService()->init($payload);
    expect($response)->toMatchArray(['resultCode' => 0, 'paymentStatus' => 1]);

    Http::assertSent(function (Request $request) use ($variant): bool {
        $wire = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/v1.0/payment/init'
            && $wire['orderNo'] === '00005547'
            && is_int($wire['totalAmount'])
            && is_bool($wire['closePayment'])
            && ($variant !== 'oneclick' || ($wire['payOperation'] === 'oneclickPayment' && $wire['ttlSec'] === 600))
            && EapiFixtures::verify(
                (new Canonicalizer)->canonicalize($wire, PaymentInitSchema::REQUEST),
                $wire['signature'],
                EapiFixtures::merchantPublicKey(),
            );
    });
})->with(['standard', 'extended', 'oneclick'])->group('hosted-card', 'offline');

it('enforces minor-unit and cart invariants while allowing EUR and USD cents', function (array $changes, bool $valid): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse(1))]);
    $payload = array_replace_recursive(initPayload(), $changes);

    if ($valid) {
        expect(cardService()->init($payload)['resultCode'])->toBe(0);
    } else {
        expect(fn () => cardService()->init($payload))->toThrow(InvalidPaymentRequestException::class);
    }
})->with([
    'HUF whole forint' => [[], true],
    'HUF filler' => [['totalAmount' => 123450, 'cart' => [['amount' => 123450]]], false],
    'EUR cents' => [['currency' => 'EUR', 'totalAmount' => 10051, 'cart' => [['amount' => 10051]]], true],
    'USD cents' => [['currency' => 'USD', 'totalAmount' => 999, 'cart' => [['amount' => 999]]], true],
    'cart mismatch' => [['cart' => [['amount' => 123300]]], false],
    'numeric order reference' => [['orderNo' => 5547], false],
    'invalid ttl' => [['ttlSec' => 299], false],
    'integer close flag' => [['closePayment' => 1], false],
])->group('hosted-card', 'offline');

it('omits absent optional init fields from both wire and signature', function (): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse(1))]);
    cardService()->init(initPayload());

    Http::assertSent(function (Request $request): bool {
        $wire = $request->data();
        $canonical = (new Canonicalizer)->canonicalize($wire, PaymentInitSchema::REQUEST);

        return ! array_key_exists('ttlSec', $wire)
            && ! array_key_exists('customer', $wire)
            && ! str_contains($canonical, '||');
    });
})->group('hosted-card', 'offline');

it('generates an exactly-once encoded browser process URL without making a request', function (): void {
    $payId = 'card@token+/=';
    $url = cardService()->processUrl(EapiFixtures::MERCHANT_ID, $payId);

    expect($url)->toContain('/'.rawurlencode($payId).'/')
        ->and($url)->not->toContain('%2540')
        ->and(Http::recorded())->toBeEmpty();
})->group('hosted-card', 'offline');

it('consumes one process GET without following redirects and returns a validated HTTPS Location', function (): void {
    Http::fake(['gateway.test/*' => Http::response('', 303, ['Location' => 'https://pay.khpos.hu/pay/example'])]);

    expect(cardService()->processLocation(EapiFixtures::MERCHANT_ID, 'card@token+/='))
        ->toBe('https://pay.khpos.hu/pay/example');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->toPsrRequest()->getUri()->getPath() !== ''
        && $request->toPsrRequest()->getHeaderLine('Referer') === '');
})->group('hosted-card', 'offline');

it('rejects missing invalid and non-303 process redirects', function (int $status, ?string $location): void {
    Http::fake(['gateway.test/*' => Http::response('', $status, $location === null ? [] : ['Location' => $location])]);

    expect(fn () => cardService()->processLocation(EapiFixtures::MERCHANT_ID, 'pay@id'))
        ->toThrow(UnexpectedGatewayRedirectException::class);
})->with([
    'missing Location' => [303, null],
    'relative Location' => [303, '/pay/example'],
    'insecure Location' => [303, 'http://pay.example.test/path'],
    'ordinary success' => [200, 'https://pay.example.test/path'],
    'temporary redirect' => [302, 'https://pay.example.test/path'],
])->group('hosted-card', 'offline');

it('sends signed status close reverse and refund operations with exact amount field names', function (
    string $operation,
    ?int $amount,
    string $method,
): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse($operation === 'reverse' ? 5 : 7))]);
    $service = cardService();

    $result = match ($operation) {
        'status' => $service->status(EapiFixtures::MERCHANT_ID, 'pay@id'),
        'close' => $service->close(EapiFixtures::MERCHANT_ID, 'pay@id', $amount),
        'reverse' => $service->reverse(EapiFixtures::MERCHANT_ID, 'pay@id'),
        'refund' => $service->refund(EapiFixtures::MERCHANT_ID, 'pay@id', $amount),
        default => throw new InvalidArgumentException("Unknown operation [{$operation}]."),
    };
    expect($result['resultCode'])->toBe(0);

    Http::assertSent(function (Request $request) use ($operation, $amount, $method): bool {
        if ($method === 'GET') {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/payment/status/')
                && str_contains($request->url(), rawurlencode('pay@id'));
        }

        $wire = $request->data();
        $schema = match ($operation) {
            'close' => PaymentSchema::CLOSE_REQUEST,
            'refund' => PaymentSchema::REFUND_REQUEST,
            default => PaymentSchema::REFERENCE_REQUEST,
        };

        return $request->method() === 'PUT'
            && str_ends_with($request->url(), '/payment/'.$operation)
            && ($operation !== 'close' || (($amount === null) === ! array_key_exists('totalAmount', $wire)))
            && ($operation !== 'refund' || (($amount === null) === ! array_key_exists('amount', $wire)))
            && EapiFixtures::verify(
                (new Canonicalizer)->canonicalize($wire, $schema),
                $wire['signature'],
                EapiFixtures::merchantPublicKey(),
            );
    });
})->with([
    'status' => ['status', null, 'GET'],
    'full close' => ['close', null, 'PUT'],
    'lower close' => ['close', 10000, 'PUT'],
    'reverse' => ['reverse', null, 'PUT'],
    'full refund' => ['refund', null, 'PUT'],
    'partial refund' => ['refund', 1000, 'PUT'],
])->group('hosted-card', 'offline');

it('returns authenticated payment business errors but rejects tampering', function (bool $tampered): void {
    $response = cardResponse(null, 110, 'Invalid parameter');
    if ($tampered) {
        $response['resultMessage'] = 'Changed';
    }
    Http::fake(['gateway.test/*' => Http::response($response)]);

    if ($tampered) {
        expect(fn () => cardService()->status(EapiFixtures::MERCHANT_ID, 'pay@id'))
            ->toThrow(SignatureException::class);
    } else {
        expect(cardService()->status(EapiFixtures::MERCHANT_ID, 'pay@id'))
            ->toMatchArray(['resultCode' => 110, 'resultMessage' => 'Invalid parameter']);
    }
})->with([false, true])->group('hosted-card', 'offline');

it('marks mutating connection failures as unknown outcomes with reconciliation identifiers', function (): void {
    Http::fake(fn () => Http::failedConnection('timeout'));

    try {
        cardService()->refund(EapiFixtures::MERCHANT_ID, 'pay@reconcile', 1000);
        $this->fail('Expected an unknown payment outcome.');
    } catch (UnknownPaymentOutcomeException $exception) {
        expect($exception->operation)->toBe('refund')
            ->and($exception->payId)->toBe('pay@reconcile')
            ->and($exception->category)->toBe('connection')
            ->and($exception->getMessage())->toContain('payment/status');
    }
})->group('hosted-card', 'offline');

it('rejects signed mismatched mutation identifiers as uncertain outcomes', function (string $operation): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse(7, payId: 'different@pay'))]);

    try {
        invokeMutation(cardService(), $operation, 'pay@requested');
        $this->fail('Expected an unknown payment outcome.');
    } catch (UnknownPaymentOutcomeException $exception) {
        expect($exception->operation)->toBe($operation)
            ->and($exception->payId)->toBe('pay@requested')
            ->and($exception->category)->toBe('identity_mismatch')
            ->and($exception->getPrevious())->toBeInstanceOf(GatewayIdentityMismatchException::class)
            ->and($exception->getMessage())->not->toContain('pay@requested');
    }

    Http::assertSentCount(1);
})->with(['close', 'reverse', 'refund'])->group('hosted-card', 'offline');

it('classifies untrustworthy post-dispatch mutation responses without retrying', function (
    string $operation,
    string $failure,
    string $category,
): void {
    Http::fake(function () use ($failure) {
        return match ($failure) {
            'connection' => Http::failedConnection('signed-url-must-not-propagate'),
            'server' => Http::response(['resultCode' => 900, 'resultMessage' => 'Temporary failure'], 503),
            'malformed' => Http::response('{broken', 200, ['Content-Type' => 'application/json']),
            'signature' => Http::response(array_replace(cardResponse(7), ['signature' => 'invalid-signature'])),
            default => throw new InvalidArgumentException("Unknown failure fixture [{$failure}]."),
        };
    });

    try {
        invokeMutation(cardService(), $operation, 'pay@requested');
        $this->fail('Expected an unknown payment outcome.');
    } catch (UnknownPaymentOutcomeException $exception) {
        expect($exception->operation)->toBe($operation)
            ->and($exception->payId)->toBe('pay@requested')
            ->and($exception->category)->toBe($category)
            ->and($exception->getMessage())->not->toContain('pay@requested')
            ->and($exception->getMessage())->not->toContain('signed-url-must-not-propagate');
    }

    Http::assertSentCount(1);
})->with(['close', 'reverse', 'refund'])->with([
    'connection loss' => ['connection', 'connection'],
    'HTTP 5xx' => ['server', 'http_failure'],
    'malformed response' => ['malformed', 'malformed_response'],
    'signature failure' => ['signature', 'signature_failure'],
])->group('hosted-card', 'offline');

it('keeps documented unsigned mutation rejections distinguishable', function (string $operation): void {
    Http::fake(['gateway.test/*' => Http::response([
        'resultCode' => 110,
        'resultMessage' => 'Untrusted gateway detail',
    ], 400)]);

    try {
        invokeMutation(cardService(), $operation, 'pay@requested');
        $this->fail('Expected a definitive gateway rejection.');
    } catch (GatewayHttpException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->unverifiedResultCode)->toBe(110)
            ->and($exception->isDefinitiveRejection())->toBeTrue()
            ->and($exception->getMessage())->not->toContain('Untrusted gateway detail');
    }

    Http::assertSentCount(1);
})->with(['close', 'reverse', 'refund'])->group('hosted-card', 'offline');

it('returns verified mutation business results with matching identifiers', function (string $operation): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse(7, 150, 'Payment not in valid state', 'pay@requested'))]);

    expect(invokeMutation(cardService(), $operation, 'pay@requested'))
        ->toMatchArray(['payId' => 'pay@requested', 'resultCode' => 150]);

    Http::assertSentCount(1);
})->with(['close', 'reverse', 'refund'])->group('hosted-card', 'offline');

it('logs payment status with a stable operation name and no signed path values', function (): void {
    $handler = new TestHandler;
    Log::swap(new Logger('vpos-test', [$handler]));
    Http::fake(['gateway.test/*' => Http::response(cardResponse(7, payId: 'pay@sensitive'))]);

    cardService()->status(EapiFixtures::MERCHANT_ID, 'pay@sensitive');

    $records = $handler->getRecords();
    $serialized = json_encode($records, JSON_THROW_ON_ERROR);
    expect($records)->toHaveCount(1)
        ->and($records[0]->context)->toBe(['method' => 'GET', 'operation' => 'payment/status'])
        ->and($serialized)->not->toContain('pay@sensitive')
        ->and($serialized)->not->toContain(EapiFixtures::MERCHANT_ID)
        ->and($serialized)->not->toContain(EapiFixtures::REQUEST_TIMESTAMP);
})->group('hosted-card', 'offline');

it('reconciles through signed payment status when the payer browser never returns', function (): void {
    Http::fake(['gateway.test/*' => Http::response(cardResponse(8))]);

    $status = cardService()->status(EapiFixtures::MERCHANT_ID, 'pay@id');

    expect($status)->toMatchArray(['resultCode' => 0, 'paymentStatus' => 8]);
    Http::assertSentCount(1);
})->group('hosted-card', 'offline');

it('verifies typed GET or form POST browser returns including optional fields', function (): void {
    $payload = browserReturn();
    $verified = (new BrowserReturnVerifier(cardTransport()))->verify(
        array_map(static fn (mixed $value): string => (string) $value, $payload),
        'pay@return',
        'b3JkZXItNTU0Nw==',
    );

    expect($verified->payId)->toBe('pay@return')
        ->and($verified->paymentStatus)->toBe(4)
        ->and($verified->authCode)->toBe('F7A23E')
        ->and($verified->merchantData)->toBe('b3JkZXItNTU0Nw==')
        ->and($verified->statusDetail)->toBe('approved');
})->group('hosted-card', 'offline');

it('rejects tampered malformed and mismatched browser returns', function (string $failure): void {
    $payload = browserReturn();
    if ($failure === 'tampered') {
        $payload['paymentStatus'] = 8;
    } elseif ($failure === 'malformed') {
        unset($payload['dttm']);
    }

    $expected = $failure === 'mismatch' ? 'different@pay' : 'pay@return';
    $exception = match ($failure) {
        'tampered' => SignatureException::class,
        'mismatch' => BrowserReturnMismatchException::class,
        default => MalformedGatewayResponseException::class,
    };

    expect(fn () => (new BrowserReturnVerifier(cardTransport()))->verify($payload, $expected))
        ->toThrow($exception);
})->with(['tampered', 'malformed', 'mismatch'])->group('hosted-card', 'offline');

/** @return array<string, mixed> */
function initPayload(): array
{
    return [
        'merchantId' => EapiFixtures::MERCHANT_ID,
        'orderNo' => '00005547',
        'payOperation' => 'payment',
        'payMethod' => 'card',
        'totalAmount' => 123400,
        'currency' => 'HUF',
        'closePayment' => true,
        'returnUrl' => 'https://shop.example.test/return',
        'returnMethod' => 'POST',
        'cart' => [['name' => 'Headphones', 'quantity' => 1, 'amount' => 123400]],
        'merchantData' => 'b3JkZXItNTU0Nw==',
        'language' => 'hu',
    ];
}

/** @return array<string, mixed> */
function cardResponse(
    ?int $paymentStatus,
    int $resultCode = 0,
    string $message = 'OK',
    string $payId = 'pay@id',
): array {
    $payload = [
        'payId' => $payId,
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => $resultCode,
        'resultMessage' => $message,
    ];
    if ($paymentStatus !== null) {
        $payload['paymentStatus'] = $paymentStatus;
    }
    $payload['signature'] = EapiFixtures::sign(
        (new Canonicalizer)->canonicalize($payload, PaymentSchema::PAYMENT_RESPONSE),
        EapiFixtures::gatewayPrivateKey(),
    );

    return $payload;
}

/** @return array<string, mixed> */
function invokeMutation(HostedCardService $service, string $operation, string $payId): array
{
    return match ($operation) {
        'close' => $service->close(EapiFixtures::MERCHANT_ID, $payId),
        'reverse' => $service->reverse(EapiFixtures::MERCHANT_ID, $payId),
        'refund' => $service->refund(EapiFixtures::MERCHANT_ID, $payId),
        default => throw new InvalidArgumentException("Unknown operation [{$operation}]."),
    };
}

/** @return array<string, mixed> */
function browserReturn(): array
{
    $payload = [
        'payId' => 'pay@return',
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'paymentStatus' => 4,
        'authCode' => 'F7A23E',
        'merchantData' => 'b3JkZXItNTU0Nw==',
        'statusDetail' => 'approved',
    ];
    $payload['signature'] = EapiFixtures::sign(
        (new Canonicalizer)->canonicalize($payload, PaymentSchema::BROWSER_RETURN),
        EapiFixtures::gatewayPrivateKey(),
    );

    return $payload;
}

function cardService(): HostedCardService
{
    return new HostedCardService(cardTransport());
}

function cardTransport(): SignedJsonTransport
{
    return new SignedJsonTransport(
        'https://gateway.test/api/v1.0/',
        new SignatureService(EapiFixtures::merchantPrivateKey(), EapiFixtures::gatewayPublicKey()),
        new Canonicalizer,
        static fn (): string => EapiFixtures::REQUEST_TIMESTAMP,
    );
}
