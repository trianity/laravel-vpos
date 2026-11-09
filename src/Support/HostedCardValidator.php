<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;

final class HostedCardValidator
{
    private const LANGUAGES = ['hu', 'en', 'de', 'fr', 'cs', 'it', 'ja', 'pl', 'pt', 'ro', 'ru', 'sk', 'es', 'tr', 'vi', 'hr', 'sl', 'sv'];

    /** @param array<string, mixed> $payload */
    public function init(array $payload): void
    {
        foreach (['merchantId', 'orderNo', 'totalAmount', 'currency', 'returnUrl', 'returnMethod', 'cart', 'language'] as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                throw new InvalidPaymentRequestException("Missing required payment/init field [{$field}].");
            }
        }

        if (! is_string($payload['merchantId']) || trim($payload['merchantId']) === '') {
            throw new InvalidPaymentRequestException('merchantId must be a non-empty string.');
        }

        if (! is_string($payload['orderNo']) || preg_match('/^\d{1,10}$/D', $payload['orderNo']) !== 1) {
            throw new InvalidPaymentRequestException('orderNo must remain a string containing at most 10 digits.');
        }
        if (! is_int($payload['totalAmount']) || $payload['totalAmount'] <= 0) {
            throw new InvalidPaymentRequestException('totalAmount must be a positive integer in minor units.');
        }
        if (! is_string($payload['currency']) || ! in_array($payload['currency'], ['HUF', 'EUR', 'USD'], true)) {
            throw new InvalidPaymentRequestException('currency must be HUF, EUR or USD.');
        }
        if ($payload['currency'] === 'HUF' && $payload['totalAmount'] % 100 !== 0) {
            throw new InvalidPaymentRequestException('HUF amounts must be whole forints and end in 00 minor units.');
        }
        if (! is_array($payload['cart']) || ! array_is_list($payload['cart']) || count($payload['cart']) < 1 || count($payload['cart']) > 2) {
            throw new InvalidPaymentRequestException('cart must contain one or two line items.');
        }

        $cartTotal = 0;
        foreach ($payload['cart'] as $item) {
            if (! is_array($item)
                || ! isset($item['name'], $item['quantity'])
                || ! array_key_exists('amount', $item)
                || ! is_string($item['name'])
                || ! is_int($item['quantity'])
                || $item['quantity'] < 1
                || ! is_int($item['amount'])
                || $item['amount'] < 0) {
                throw new InvalidPaymentRequestException('Every cart item needs a name, positive integer quantity and non-negative integer amount.');
            }
            $cartTotal += $item['amount'];
        }
        if ($cartTotal !== $payload['totalAmount']) {
            throw new InvalidPaymentRequestException('The cart line totals must equal totalAmount.');
        }

        if (isset($payload['closePayment']) && ! is_bool($payload['closePayment'])) {
            throw new InvalidPaymentRequestException('closePayment must be Boolean.');
        }
        if (! in_array($payload['returnMethod'], ['GET', 'POST'], true)) {
            throw new InvalidPaymentRequestException('returnMethod must be GET or POST.');
        }
        if (! is_string($payload['returnUrl'])
            || strlen($payload['returnUrl']) > 300
            || filter_var($payload['returnUrl'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidPaymentRequestException('returnUrl must be a valid URL of at most 300 characters.');
        }
        if (! is_string($payload['language']) || ! in_array($payload['language'], self::LANGUAGES, true)) {
            throw new InvalidPaymentRequestException('language is not supported.');
        }
        if (isset($payload['ttlSec']) && (! is_int($payload['ttlSec']) || $payload['ttlSec'] < 300 || $payload['ttlSec'] > 1800)) {
            throw new InvalidPaymentRequestException('ttlSec must be between 300 and 1800 seconds.');
        }
        if (isset($payload['payOperation']) && ! in_array($payload['payOperation'], ['payment', 'oneclickPayment', 'customPayment'], true)) {
            throw new InvalidPaymentRequestException('payOperation is not supported by the gateway.');
        }
        if (isset($payload['payMethod']) && ! in_array($payload['payMethod'], ['card', 'card#LVP'], true)) {
            throw new InvalidPaymentRequestException('payMethod is not supported by the gateway.');
        }
    }

    public function optionalAmount(?int $amount, string $field): void
    {
        if ($amount !== null && $amount <= 0) {
            throw new InvalidPaymentRequestException("{$field} must be a positive integer in minor units when supplied.");
        }
    }

    public function reference(string $merchantId, string $payId): void
    {
        if (trim($merchantId) === '' || trim($payId) === '') {
            throw new InvalidPaymentRequestException('merchantId and payId must be non-empty strings.');
        }
    }
}
