<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Api\Extensions\VerifiedExtension;
use Trianity\Vpos\Api\Extensions\VerifiedMaskedCardExtension;
use Trianity\Vpos\Api\Extensions\VerifiedTransactionDatesExtension;
use Trianity\Vpos\Exceptions\MalformedGatewayResponseException;
use Trianity\Vpos\Exceptions\UnsupportedExtensionException;

final class ExtensionVerifier
{
    private const MASKED_CARD_SCHEMA = [
        'extension', 'dttm', 'maskedCln', 'expiration', 'longMaskedCln',
    ];

    private const TRANSACTION_DATES_SCHEMA = [
        'extension', 'dttm', 'createdDate', 'authDate', 'settlementDate',
    ];

    public function __construct(private readonly SignedJsonTransport $transport) {}

    /** @param mixed $extensions @return list<VerifiedExtension> */
    public function verify(mixed $extensions): array
    {
        if (! is_array($extensions) || ! array_is_list($extensions)) {
            throw new MalformedGatewayResponseException('Gateway extensions must be a list.');
        }

        return array_map(fn (mixed $extension): VerifiedExtension => $this->verifyOne($extension), $extensions);
    }

    private function verifyOne(mixed $extension): VerifiedExtension
    {
        if (! is_array($extension) || array_is_list($extension) || ! isset($extension['extension']) || ! is_string($extension['extension'])) {
            throw new MalformedGatewayResponseException('A gateway extension has an invalid envelope.');
        }

        return match ($extension['extension']) {
            'maskCln', 'maskClnRP' => $this->maskedCard($extension),
            'trxDates' => $this->transactionDates($extension),
            default => throw new UnsupportedExtensionException(
                "Gateway extension [{$extension['extension']}] is not supported and was not exposed as verified.",
            ),
        };
    }

    /** @param array<string, mixed> $extension */
    private function maskedCard(array $extension): VerifiedMaskedCardExtension
    {
        $this->only($extension, [...self::MASKED_CARD_SCHEMA, 'signature']);
        $this->transport->verifyDetachedPayload($extension, self::MASKED_CARD_SCHEMA);
        $values = $this->strings($extension, ['extension', 'dttm', 'maskedCln', 'expiration', 'longMaskedCln']);

        return new VerifiedMaskedCardExtension(...$values);
    }

    /** @param array<string, mixed> $extension */
    private function transactionDates(array $extension): VerifiedTransactionDatesExtension
    {
        $this->only($extension, [...self::TRANSACTION_DATES_SCHEMA, 'signature']);
        $this->transport->verifyDetachedPayload($extension, self::TRANSACTION_DATES_SCHEMA);
        $required = $this->strings($extension, ['dttm', 'createdDate']);
        $authDate = $this->optionalString($extension, 'authDate');
        $settlementDate = $this->optionalString($extension, 'settlementDate');

        return new VerifiedTransactionDatesExtension($required[0], $required[1], $authDate, $settlementDate);
    }

    /** @param array<string, mixed> $payload @param list<string> $fields @return list<string> */
    private function strings(array $payload, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field]) || $payload[$field] === '') {
                throw new MalformedGatewayResponseException("Gateway extension field [{$field}] is invalid.");
            }
            $values[] = $payload[$field];
        }

        return $values;
    }

    /** @param array<string, mixed> $payload */
    private function optionalString(array $payload, string $field): ?string
    {
        if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
            return null;
        }
        if (! is_string($payload[$field])) {
            throw new MalformedGatewayResponseException("Gateway extension field [{$field}] is invalid.");
        }

        return $payload[$field];
    }

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private function only(array $payload, array $allowed): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new MalformedGatewayResponseException('Gateway extension contains an unsupported field.');
        }
    }
}
