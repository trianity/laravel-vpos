<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use BackedEnum;
use Trianity\Vpos\Exceptions\GatewayIdentityMismatchException;
use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;
use Trianity\Vpos\Exceptions\MalformedActionException;

final class WalletService
{
    private readonly ExtensionVerifier $extensions;

    public function __construct(
        private readonly SignedJsonTransport $transport,
        private readonly WalletValidator $validator = new WalletValidator,
        private readonly ActionHydrator $actions = new ActionHydrator,
        ?ExtensionVerifier $extensions = null,
    ) {
        $this->extensions = $extensions ?? new ExtensionVerifier($transport);
    }

    /** @return array<string, mixed> */
    public function oneclickEcho(string $merchantId, string $origPayId): array
    {
        $this->validator->echo($merchantId, $origPayId);
        $payload = $this->transport->signPayload(
            ['merchantId' => $merchantId, 'origPayId' => $origPayId],
            WalletSchema::ONECLICK_ECHO_REQUEST,
        );
        $response = $this->transport->sendJson('POST', 'oneclick/echo', $payload, WalletSchema::ONECLICK_ECHO_RESPONSE);
        if (($response['origPayId'] ?? null) !== $origPayId) {
            throw new GatewayIdentityMismatchException('The OneClick echo response does not match the requested template.');
        }

        return $this->verifiedExtensions($response);
    }

    /** @return array<string, mixed> */
    public function providerEcho(string $provider, string $merchantId): array
    {
        $this->provider($provider);
        $this->validator->echo($merchantId);
        $payload = $this->transport->signPayload(['merchantId' => $merchantId], EchoSchema::REQUEST);
        $schema = $provider === 'applepay' ? WalletSchema::APPLE_ECHO_RESPONSE : WalletSchema::GOOGLE_ECHO_RESPONSE;

        return $this->transport->sendJson('POST', "{$provider}/echo", $payload, $schema);
    }

    /** @param array<string, mixed>|object $request @return array<string, mixed> */
    public function oneclickInit(array|object $request): array
    {
        $payload = $this->normalize($request);
        $this->validator->oneclickInit($payload);
        $payload = $this->transport->signPayload($payload, WalletSchema::ONECLICK_INIT_REQUEST);

        return $this->operationResponse(
            $this->transport->sendJson('POST', 'oneclick/init', $payload, WalletSchema::OPERATION_RESPONSE),
        );
    }

    /** @param array<string, mixed>|object $request @return array<string, mixed> */
    public function providerInit(string $provider, array|object $request): array
    {
        $this->provider($provider);
        $payload = $this->normalize($request);
        $this->validator->walletInit($payload);
        $payload = $this->transport->signPayload($payload, WalletSchema::WALLET_INIT_REQUEST);

        return $this->operationResponse(
            $this->transport->sendJson('POST', "{$provider}/init", $payload, WalletSchema::OPERATION_RESPONSE),
        );
    }

    /** @param array<string, mixed>|object $request @return array<string, mixed> */
    public function process(string $provider, array|object $request, bool $oneclickFingerprintRequired = true): array
    {
        $this->provider($provider, true);
        $payload = $this->normalize($request);
        $this->validator->process($payload, $provider !== 'oneclick' || $oneclickFingerprintRequired);
        $expectedPayId = $payload['payId'];
        $payload = $this->transport->signPayload($payload, WalletSchema::PROCESS_REQUEST);
        $response = $this->transport->sendJson('POST', "{$provider}/process", $payload, WalletSchema::OPERATION_RESPONSE);
        if (($response['payId'] ?? null) !== $expectedPayId) {
            throw new GatewayIdentityMismatchException('The payment process response does not match the requested payId.');
        }

        return $this->operationResponse($response);
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    public function operationResponse(array $response): array
    {
        if (isset($response['actions'])) {
            if (! is_array($response['actions']) || array_is_list($response['actions'])) {
                throw new MalformedActionException('The gateway actions value must be an object.');
            }
            $response['actions'] = $this->actions->hydrate($response['actions']);
        }

        return $this->verifiedExtensions($response);
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function verifiedExtensions(array $response): array
    {
        if (array_key_exists('extensions', $response)) {
            $response['extensions'] = $this->extensions->verify($response['extensions']);
        }

        return $response;
    }

    private function provider(string $provider, bool $includeOneclick = false): void
    {
        $allowed = $includeOneclick ? ['oneclick', 'applepay', 'googlepay'] : ['applepay', 'googlepay'];
        if (! in_array($provider, $allowed, true)) {
            throw new InvalidPaymentRequestException("Unsupported wallet provider [{$provider}].");
        }
    }

    /** @param array<string, mixed>|object $request @return array<string, mixed> */
    private function normalize(array|object $request): array
    {
        $values = is_array($request) ? $request : get_object_vars($request);

        return array_map(function (mixed $value): mixed {
            if ($value instanceof BackedEnum) {
                return $value->value;
            }
            if (is_object($value)) {
                return $this->normalize($value);
            }
            if (is_array($value)) {
                return array_map(function (mixed $item): mixed {
                    if ($item instanceof BackedEnum) {
                        return $item->value;
                    }

                    return is_object($item) || is_array($item) ? $this->normalize($item) : $item;
                }, $value);
            }

            return $value;
        }, $values);
    }
}
