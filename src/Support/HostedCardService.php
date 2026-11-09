<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use BackedEnum;
use Trianity\Vpos\Exceptions\GatewayHttpException;
use Trianity\Vpos\Exceptions\GatewayIdentityMismatchException;
use Trianity\Vpos\Exceptions\GatewayTransportException;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;
use Trianity\Vpos\Exceptions\SignatureException;
use Trianity\Vpos\Exceptions\UnknownPaymentOutcomeException;

final class HostedCardService
{
    private readonly WalletService $walletResponses;

    public function __construct(
        private readonly SignedJsonTransport $transport,
        private readonly HostedCardValidator $validator = new HostedCardValidator,
        ?WalletService $walletResponses = null,
    ) {
        $this->walletResponses = $walletResponses ?? new WalletService($transport);
    }

    /** @param array<string, mixed>|object $request @return array<string, mixed> */
    public function init(array|object $request): array
    {
        $payload = $this->normalize($request);
        $this->validator->init($payload);
        $payload = $this->transport->signPayload($payload, PaymentInitSchema::REQUEST);

        return $this->transport->sendJson('POST', 'payment/init', $payload, PaymentSchema::INIT_RESPONSE);
    }

    /** @return array<string, mixed> */
    public function status(string $merchantId, string $payId): array
    {
        $payload = $this->referencePayload($merchantId, $payId);
        $url = $this->transport->url('payment/status', array_values($payload));
        $endpoint = substr($url, strpos($url, 'payment/status'));

        $response = $this->transport->sendJson('GET', $endpoint, [], PaymentSchema::PAYMENT_RESPONSE);
        if (($response['payId'] ?? null) !== $payId) {
            throw new GatewayIdentityMismatchException('The payment status response does not match the requested payId.');
        }

        return $this->walletResponses->operationResponse($response);
    }

    public function processUrl(string $merchantId, string $payId): string
    {
        return $this->transport->url('payment/process', array_values($this->referencePayload($merchantId, $payId)));
    }

    public function processLocation(string $merchantId, string $payId): string
    {
        return $this->transport->consumeRedirect($this->processUrl($merchantId, $payId));
    }

    /** @return array<string, mixed> */
    public function close(string $merchantId, string $payId, ?int $totalAmount = null): array
    {
        $this->validator->optionalAmount($totalAmount, 'totalAmount');

        return $this->mutate('close', $merchantId, $payId, 'totalAmount', $totalAmount, PaymentSchema::CLOSE_REQUEST);
    }

    /** @return array<string, mixed> */
    public function reverse(string $merchantId, string $payId): array
    {
        return $this->mutate('reverse', $merchantId, $payId, null, null, PaymentSchema::REFERENCE_REQUEST);
    }

    /** @return array<string, mixed> */
    public function refund(string $merchantId, string $payId, ?int $amount = null): array
    {
        $this->validator->optionalAmount($amount, 'amount');

        return $this->mutate('refund', $merchantId, $payId, 'amount', $amount, PaymentSchema::REFUND_REQUEST);
    }

    /** @return array<string, mixed> */
    private function referencePayload(string $merchantId, string $payId): array
    {
        $this->validator->reference($merchantId, $payId);

        return $this->transport->signPayload(
            ['merchantId' => $merchantId, 'payId' => $payId],
            PaymentSchema::REFERENCE_REQUEST,
        );
    }

    /** @return array<string, mixed> */
    private function mutate(
        string $operation,
        string $merchantId,
        string $payId,
        ?string $amountField,
        ?int $amount,
        array $schema,
    ): array {
        $payload = ['merchantId' => $merchantId, 'payId' => $payId];
        if ($amountField !== null && $amount !== null) {
            $payload[$amountField] = $amount;
        }
        $payload = $this->transport->signPayload($payload, $schema);

        try {
            $response = $this->transport->sendJson(
                'PUT',
                "payment/{$operation}",
                $payload,
                PaymentSchema::PAYMENT_RESPONSE,
            );
            if (($response['payId'] ?? null) !== $payId) {
                throw new GatewayIdentityMismatchException(
                    'The payment mutation response does not match the requested payId.',
                );
            }

            return $this->walletResponses->operationResponse($response);
        } catch (GatewayHttpException $exception) {
            if ($exception->isDefinitiveRejection()) {
                throw $exception;
            }

            throw new UnknownPaymentOutcomeException($operation, $payId, 'http_failure', $exception);
        } catch (GatewayTransportException $exception) {
            throw new UnknownPaymentOutcomeException($operation, $payId, $exception->category, $exception);
        } catch (SignatureException $exception) {
            throw new UnknownPaymentOutcomeException($operation, $payId, 'signature_failure', $exception);
        } catch (GatewayIdentityMismatchException $exception) {
            throw new UnknownPaymentOutcomeException($operation, $payId, 'identity_mismatch', $exception);
        } catch (MalformedGatewayResponseException $exception) {
            throw new UnknownPaymentOutcomeException($operation, $payId, 'malformed_response', $exception);
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
