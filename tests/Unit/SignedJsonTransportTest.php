<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Trianity\Vpos\Exceptions\GatewayHttpException;
use Trianity\Vpos\Exceptions\GatewayTransportException;
use Trianity\Vpos\Exceptions\KeyLoadingException;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;
use Trianity\Vpos\Exceptions\SignatureException;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\SignatureService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Tests\Support\EapiFixtures;

it('posts top-level JSON and verifies the independently signed Echo response', function (): void {
    Http::fake([
        'gateway.test/api/v1.0/echo' => Http::response(
            EapiFixtures::successfulEchoResponse(),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $response = echoTransport()->echoPost(EapiFixtures::MERCHANT_ID);

    expect($response['signature'])->toBe(EapiFixtures::successfulEchoResponse()['signature']);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/v1.0/echo'
            && $request->hasHeader('Content-Type', 'application/json')
            && ! array_key_exists('body', $payload)
            && $payload['merchantId'] === EapiFixtures::MERCHANT_ID
            && $payload['dttm'] === EapiFixtures::REQUEST_TIMESTAMP
            && EapiFixtures::verify(
                EapiFixtures::MERCHANT_ID.'|'.EapiFixtures::REQUEST_TIMESTAMP,
                $payload['signature'],
                EapiFixtures::merchantPublicKey(),
            );
    });
})->group('echo', 'offline');

it('signs decoded GET values and percent-encodes every path segment once', function (): void {
    Http::fake([
        'gateway.test/api/v1.0/echo/*' => Http::response(EapiFixtures::successfulEchoResponse()),
    ]);

    $merchantId = 'Áruház/01';
    echoTransport()->echoGet($merchantId);

    Http::assertSent(function (Request $request) use ($merchantId): bool {
        $prefix = 'https://gateway.test/api/v1.0/echo/'.rawurlencode($merchantId).'/'.EapiFixtures::REQUEST_TIMESTAMP.'/';
        $signature = rawurldecode(substr($request->url(), strlen($prefix)));

        return $request->method() === 'GET'
            && str_starts_with($request->url(), $prefix)
            && ! str_contains($request->url(), '%252F')
            && EapiFixtures::verify(
                $merchantId.'|'.EapiFixtures::REQUEST_TIMESTAMP,
                $signature,
                EapiFixtures::merchantPublicKey(),
            );
    });
})->group('echo', 'offline');

it('logs GET Echo with a stable operation name and no signed path values', function (): void {
    $handler = new TestHandler;
    Log::swap(new Logger('vpos-test', [$handler]));
    Http::fake(['gateway.test/api/v1.0/echo/*' => Http::response(EapiFixtures::successfulEchoResponse())]);

    $transport = echoTransport();
    $request = $transport->signEchoRequest('merchant@sensitive');
    $transport->sendEchoGet($request);

    $records = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
    expect($records)->toContain('echo')
        ->and($records)->toContain('GET')
        ->and($records)->not->toContain('merchant@sensitive')
        ->and($records)->not->toContain($request['dttm'])
        ->and($records)->not->toContain($request['signature']);
})->group('echo', 'offline');

it('returns an authenticated nonzero Echo business result', function (): void {
    Http::fake([
        'gateway.test/api/v1.0/echo' => Http::response(EapiFixtures::echoResponse(110, 'Invalid parameter')),
    ]);

    expect(echoTransport()->echoPost(EapiFixtures::MERCHANT_ID))
        ->toMatchArray(['resultCode' => 110, 'resultMessage' => 'Invalid parameter']);
})->group('echo', 'offline');

it('rejects a tampered signed response', function (): void {
    $payload = EapiFixtures::successfulEchoResponse();
    $payload['resultMessage'] = 'TAMPERED';
    Http::fake(['gateway.test/*' => Http::response($payload)]);

    expect(fn () => echoTransport()->echoPost(EapiFixtures::MERCHANT_ID))
        ->toThrow(SignatureException::class);
})->group('echo', 'offline');

it('rejects a response signed by the wrong key', function (): void {
    Http::fake(['gateway.test/*' => Http::response(EapiFixtures::successfulEchoResponse())]);

    expect(fn () => echoTransport(EapiFixtures::merchantPublicKey())->echoPost(EapiFixtures::MERCHANT_ID))
        ->toThrow(SignatureException::class);
})->group('echo', 'offline');

it('rejects malformed Base64 and missing response signatures', function (array $payload): void {
    Http::fake(['gateway.test/*' => Http::response($payload)]);

    expect(fn () => echoTransport()->echoPost(EapiFixtures::MERCHANT_ID))
        ->toThrow($payload['signature'] ?? null
            ? SignatureException::class
            : MalformedGatewayResponseException::class);
})->with([
    'malformed Base64' => [[
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
        'signature' => '%%%not-base64%%%',
    ]],
    'missing signature' => [[
        'dttm' => EapiFixtures::RESPONSE_TIMESTAMP,
        'resultCode' => 0,
        'resultMessage' => 'OK',
    ]],
])->group('echo', 'offline');

it('separates malformed JSON unsigned HTTP errors and connection failures', function (
    string $failure,
    string $exception,
): void {
    Http::fake(function () use ($failure) {
        return match ($failure) {
            'json' => Http::response('{broken', 200, ['Content-Type' => 'application/json']),
            'content-type' => Http::response(
                json_encode(EapiFixtures::successfulEchoResponse(), JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'http' => Http::response(['resultCode' => 110, 'resultMessage' => 'Wrong signature'], 401),
            'connection' => Http::failedConnection('offline'),
            default => throw new InvalidArgumentException("Unknown failure fixture [{$failure}]."),
        };
    });

    expect(fn () => echoTransport()->echoPost(EapiFixtures::MERCHANT_ID))
        ->toThrow($exception);
})->with([
    'malformed JSON' => ['json', MalformedGatewayResponseException::class],
    'wrong Content-Type' => ['content-type', MalformedGatewayResponseException::class],
    'unsigned HTTP error' => ['http', GatewayHttpException::class],
    'connection failure' => ['connection', GatewayTransportException::class],
])->group('echo', 'offline');

it('does not write signatures or private key material to logs', function (): void {
    $handler = new TestHandler;
    Log::swap(new Logger('vpos-test', [$handler]));
    Http::fake(['gateway.test/*' => Http::response(EapiFixtures::successfulEchoResponse())]);

    $transport = echoTransport();
    $request = $transport->signEchoRequest(EapiFixtures::MERCHANT_ID);
    $transport->sendEchoPost($request);

    $records = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
    $privateKey = file_get_contents(EapiFixtures::merchantPrivateKey());

    expect($records)->not->toContain($request['signature'])
        ->and($privateKey)->toBeString()
        ->and($records)->not->toContain(trim($privateKey));
})->group('echo', 'offline');

it('preserves numeric unverified gateway codes without exposing gateway messages', function (int $resultCode): void {
    Http::fake(['gateway.test/*' => Http::response([
        'resultCode' => $resultCode,
        'resultMessage' => 'Untrusted diagnostic text',
    ], 400)]);

    try {
        echoTransport()->echoPost(EapiFixtures::MERCHANT_ID);
        $this->fail('Expected an HTTP gateway failure.');
    } catch (GatewayHttpException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->unverifiedResultCode)->toBe($resultCode)
            ->and($exception->getMessage())->not->toContain('Untrusted diagnostic text');
    }
})->with([110, 130])->group('echo', 'offline');

it('falls back to HTTP status when an unsigned error body is invalid', function (mixed $body): void {
    Http::fake(['gateway.test/*' => Http::response($body, 400)]);

    try {
        echoTransport()->echoPost(EapiFixtures::MERCHANT_ID);
        $this->fail('Expected an HTTP gateway failure.');
    } catch (GatewayHttpException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->unverifiedResultCode)->toBeNull();
    }
})->with([
    'malformed JSON' => ['{broken'],
    'wrong result type' => [['resultCode' => '110', 'resultMessage' => 'Untrusted']],
    'list root' => [[110, 'Untrusted']],
])->group('echo', 'offline');

it('rejects missing and invalid key material during construction', function (): void {
    expect(fn () => new SignatureService(
        '/definitely/missing/private.pem',
        EapiFixtures::gatewayPublicKey(),
    ))->toThrow(KeyLoadingException::class)
        ->and(fn () => new SignatureService(
            EapiFixtures::gatewayPublicKey(),
            EapiFixtures::gatewayPublicKey(),
        ))->toThrow(KeyLoadingException::class);
})->group('crypto', 'offline');

function echoTransport(?string $gatewayPublicKey = null): SignedJsonTransport
{
    return new SignedJsonTransport(
        'https://gateway.test/api/v1.0/',
        new SignatureService(
            EapiFixtures::merchantPrivateKey(),
            $gatewayPublicKey ?? EapiFixtures::gatewayPublicKey(),
        ),
        new Canonicalizer,
        static fn (): string => EapiFixtures::REQUEST_TIMESTAMP,
    );
}
