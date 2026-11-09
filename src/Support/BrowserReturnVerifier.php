<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Exceptions\BrowserReturnMismatchException;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;

final class BrowserReturnVerifier
{
    public function __construct(private readonly SignedJsonTransport $transport) {}

    /**
     * Accepts decoded query parameters or decoded application/x-www-form-urlencoded fields.
     * The caller must resolve the expected order from its own payId mapping.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function verify(
        array $parameters,
        string $expectedPayId,
        ?string $expectedMerchantData = null,
    ): VerifiedBrowserReturn {
        $payload = $this->normalize($parameters);
        $this->transport->verifyPayload($payload, PaymentSchema::BROWSER_RETURN);

        if ($payload['payId'] !== $expectedPayId) {
            throw new BrowserReturnMismatchException('The browser return payId does not match the expected payment.');
        }
        if ($expectedMerchantData !== null && ($payload['merchantData'] ?? null) !== $expectedMerchantData) {
            throw new BrowserReturnMismatchException('The browser return merchantData does not match the expected order context.');
        }

        return new VerifiedBrowserReturn(
            $payload['payId'],
            $payload['dttm'],
            $payload['resultCode'],
            $payload['resultMessage'],
            $payload['paymentStatus'] ?? null,
            $payload['authCode'] ?? null,
            $payload['merchantData'] ?? null,
            $payload['statusDetail'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{payId: string, dttm: string, resultCode: int, resultMessage: string, signature: string, paymentStatus?: int, authCode?: string, merchantData?: string, statusDetail?: string}
     */
    private function normalize(array $parameters): array
    {
        foreach (['payId', 'dttm', 'resultCode', 'resultMessage', 'signature'] as $field) {
            if (! array_key_exists($field, $parameters)) {
                throw new MalformedGatewayResponseException("The browser return is missing [{$field}].");
            }
        }
        foreach (['payId', 'dttm', 'resultMessage', 'signature', 'authCode', 'merchantData', 'statusDetail'] as $field) {
            if (isset($parameters[$field]) && ! is_string($parameters[$field])) {
                throw new MalformedGatewayResponseException("The browser return field [{$field}] must be a string.");
            }
        }
        if (! is_string($parameters['dttm']) || preg_match('/^\d{14}$/D', $parameters['dttm']) !== 1) {
            throw new MalformedGatewayResponseException('The browser return timestamp is invalid.');
        }

        foreach (['resultCode', 'paymentStatus'] as $field) {
            if (! array_key_exists($field, $parameters)) {
                continue;
            }
            if (is_string($parameters[$field]) && preg_match('/^-?\d+$/D', $parameters[$field]) === 1) {
                $parameters[$field] = (int) $parameters[$field];
            }
            if (! is_int($parameters[$field])) {
                throw new MalformedGatewayResponseException("The browser return field [{$field}] must be an integer.");
            }
        }

        /** @var array{payId: string, dttm: string, resultCode: int, resultMessage: string, signature: string, paymentStatus?: int, authCode?: string, merchantData?: string, statusDetail?: string} $parameters */
        return $parameters;
    }
}
