<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;
use Trianity\Vpos\Exceptions\GatewayHttpException;
use Trianity\Vpos\Exceptions\GatewayTransportException;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;
use Trianity\Vpos\Exceptions\UnexpectedGatewayRedirectException;

final class SignedJsonTransport
{
    private readonly string $baseUrl;

    /** @var Closure(): string */
    private readonly Closure $clock;

    /** @param (Closure(): string)|null $clock */
    public function __construct(
        string $baseUrl,
        private readonly SignatureService $signatures,
        private readonly Canonicalizer $canonicalizer = new Canonicalizer,
        ?Closure $clock = null,
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
        $this->clock = $clock ?? static fn (): string => (new DateTimeImmutable(
            'now',
            new DateTimeZone('Europe/Budapest'),
        ))->format('YmdHis');
    }

    /** @return array{dttm: string, resultCode: int, resultMessage: string, signature: string} */
    public function echoPost(string $merchantId): array
    {
        $payload = $this->signEchoRequest($merchantId);

        return $this->sendEchoPost($payload);
    }

    /** @return array{dttm: string, resultCode: int, resultMessage: string, signature: string} */
    public function echoGet(string $merchantId): array
    {
        $payload = $this->signEchoRequest($merchantId);

        return $this->sendEchoGet($payload);
    }

    /** @return array{merchantId: string, dttm: string, signature: string} */
    public function signEchoRequest(string $merchantId): array
    {
        return $this->signPayload([
            'merchantId' => $merchantId,
            'dttm' => ($this->clock)(),
        ], EchoSchema::REQUEST);
    }

    /**
     * @param  array{merchantId: string, dttm: string, signature: string}  $payload
     * @return array{dttm: string, resultCode: int, resultMessage: string, signature: string}
     */
    public function sendEchoPost(array $payload): array
    {
        return $this->send('POST', 'echo', $payload);
    }

    /**
     * @param  array{merchantId: string, dttm: string, signature: string}  $payload
     * @return array{dttm: string, resultCode: int, resultMessage: string, signature: string}
     */
    public function sendEchoGet(array $payload): array
    {
        $path = implode('/', array_map(
            rawurlencode(...),
            [$payload['merchantId'], $payload['dttm'], $payload['signature']],
        ));

        return $this->send('GET', 'echo/'.$path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int|string, string|array<mixed>>  $schema
     * @return array<string, mixed>
     */
    public function signPayload(array $payload, array $schema): array
    {
        $payload['dttm'] ??= ($this->clock)();
        unset($payload['signature']);
        $payload['signature'] = $this->signatures->sign(
            $this->canonicalizer->canonicalize($payload, $schema),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int|string, string|array<mixed>>  $responseSchema
     * @return array<string, mixed>
     */
    public function sendJson(
        string $method,
        string $endpoint,
        array $payload,
        array $responseSchema,
    ): array {
        return $this->send($method, $endpoint, $payload, $responseSchema);
    }

    /** @param list<string> $segments */
    public function url(string $endpoint, array $segments): string
    {
        $path = implode('/', array_map(rawurlencode(...), $segments));

        return $this->baseUrl.trim($endpoint, '/').'/'.$path;
    }

    public function consumeRedirect(string $url): string
    {
        Log::debug('K&H eAPI request', ['method' => 'GET', 'operation' => 'payment/process']);

        try {
            $response = Http::timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException) {
            throw new GatewayTransportException('Unable to connect to the payment gateway.', 'connection');
        } catch (Throwable) {
            throw new GatewayTransportException('The payment gateway transport failed.');
        }

        if ($response->status() !== 303) {
            throw new UnexpectedGatewayRedirectException('The payment gateway process endpoint did not return HTTP 303.');
        }

        $location = $response->header('Location');
        $parts = parse_url($location);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            throw new UnexpectedGatewayRedirectException('The payment gateway process redirect Location is missing or invalid.');
        }

        return $location;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>&array{dttm: string, resultCode: int, resultMessage: string, signature: string}
     */
    private function send(
        string $method,
        string $endpoint,
        ?array $payload = null,
        array $responseSchema = EchoSchema::RESPONSE,
    ): array {
        Log::debug('K&H eAPI request', [
            'method' => $method,
            'operation' => $this->operationName($endpoint),
        ]);

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->withOptions(['allow_redirects' => false]);

            $response = match ($method) {
                'POST' => $request->post($this->baseUrl.$endpoint, $payload ?? []),
                'PUT' => $request->put($this->baseUrl.$endpoint, $payload ?? []),
                'GET' => $request->get($this->baseUrl.$endpoint),
                default => throw new GatewayTransportException("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException) {
            throw new GatewayTransportException('Unable to connect to the payment gateway.', 'connection');
        } catch (Throwable) {
            throw new GatewayTransportException('The payment gateway transport failed.');
        }

        if ($response->status() !== 200) {
            throw new GatewayHttpException(
                $response->status(),
                $this->unverifiedResultCode($response->body()),
            );
        }

        if (! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')) {
            throw new MalformedGatewayResponseException(
                'The payment gateway response Content-Type is not JSON.',
            );
        }

        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MalformedGatewayResponseException('The payment gateway returned malformed JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MalformedGatewayResponseException('The payment gateway JSON root must be an object.');
        }

        if (in_array('payId', $responseSchema, true)
            && (! isset($decoded['payId']) || ! is_string($decoded['payId']) || $decoded['payId'] === '')) {
            throw new MalformedGatewayResponseException('The signed payment response payId is invalid.');
        }
        if (array_key_exists('paymentStatus', $decoded) && ! is_int($decoded['paymentStatus'])) {
            throw new MalformedGatewayResponseException('The signed payment response paymentStatus is invalid.');
        }
        $this->assertSignedShape($decoded);
        $this->signatures->verify(
            $this->canonicalizer->canonicalize($decoded, $responseSchema),
            $decoded['signature'],
        );

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    public function verifyPayload(array $payload, array $schema): void
    {
        $this->assertSignedShape($payload);
        $this->signatures->verify(
            $this->canonicalizer->canonicalize($payload, $schema),
            $payload['signature'],
        );
    }

    /** @param array<string, mixed> $payload */
    public function verifyDetachedPayload(array $payload, array $schema): void
    {
        if (! isset($payload['signature']) || ! is_string($payload['signature']) || $payload['signature'] === '') {
            throw new MalformedGatewayResponseException('The detached gateway payload signature is missing or invalid.');
        }

        $this->signatures->verify(
            $this->canonicalizer->canonicalize($payload, $schema),
            $payload['signature'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @phpstan-assert array<string, mixed>&array{dttm: string, resultCode: int, resultMessage: string, signature: string} $payload
     */
    private function assertSignedShape(array $payload): void
    {
        if (! isset($payload['dttm'], $payload['resultMessage'], $payload['signature'])
            || ! is_string($payload['dttm'])
            || preg_match('/^\d{14}$/D', $payload['dttm']) !== 1
            || ! array_key_exists('resultCode', $payload)
            || ! is_int($payload['resultCode'])
            || ! is_string($payload['resultMessage'])
            || $payload['resultMessage'] === ''
            || ! is_string($payload['signature'])
            || $payload['signature'] === '') {
            throw new MalformedGatewayResponseException('The signed payment gateway response shape is invalid.');
        }
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || ! isset($parts['host'])) {
            throw new GatewayTransportException('The configured payment gateway URL is invalid.');
        }

        $normalized = rtrim($baseUrl, '/').'/';
        $path = $parts['path'] ?? '';

        return $path === '' || $path === '/'
            ? $normalized.'api/v1.0/'
            : $normalized;
    }

    private function operationName(string $endpoint): string
    {
        $segments = explode('/', trim($endpoint, '/'));

        return isset($segments[1]) && in_array($segments[0], ['payment', 'oneclick', 'applepay', 'googlepay'], true)
            ? $segments[0].'/'.$segments[1]
            : $segments[0];
    }

    private function unverifiedResultCode(string $body): ?int
    {
        $decoded = json_decode($body, true);

        return is_array($decoded)
            && ! array_is_list($decoded)
            && isset($decoded['resultCode'])
            && is_int($decoded['resultCode'])
                ? $decoded['resultCode']
                : null;
    }
}
