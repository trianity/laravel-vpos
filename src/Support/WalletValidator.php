<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;

final class WalletValidator
{
    public function echo(string $merchantId, ?string $origPayId = null): void
    {
        if (trim($merchantId) === '' || ($origPayId !== null && trim($origPayId) === '')) {
            throw new InvalidPaymentRequestException('Wallet echo identifiers must be non-empty strings.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function oneclickInit(array $payload): void
    {
        $this->allowed($payload, [
            'merchantId', 'origPayId', 'orderNo', 'dttm', 'clientIp', 'totalAmount', 'currency',
            'closePayment', 'returnUrl', 'returnMethod', 'customer', 'order', 'clientInitiated',
            'sdkUsed', 'merchantData', 'language', 'ttlSec', 'signature',
        ]);
        $this->requiredStrings($payload, ['merchantId', 'origPayId', 'orderNo', 'returnUrl', 'returnMethod']);
        $this->orderNo($payload['orderNo']);
        $this->returnTarget($payload);
        $clientInitiated = $payload['clientInitiated'] ?? true;
        if (! is_bool($clientInitiated)) {
            throw new InvalidPaymentRequestException('clientInitiated must be Boolean.');
        }
        if ($clientInitiated) {
            $this->clientIp($payload['clientIp'] ?? null);
        }
        $this->amountPair($payload);
        $this->commonOptions($payload);
    }

    /** @param array<string, mixed> $payload */
    public function walletInit(array $payload): void
    {
        $this->allowed($payload, [
            'merchantId', 'orderNo', 'dttm', 'clientIp', 'totalAmount', 'currency', 'closePayment',
            'payload', 'returnUrl', 'returnMethod', 'customer', 'order', 'sdkUsed', 'merchantData',
            'language', 'ttlSec', 'signature',
        ]);
        $this->requiredStrings($payload, [
            'merchantId', 'orderNo', 'clientIp', 'currency', 'payload', 'returnUrl', 'returnMethod',
        ]);
        $this->orderNo($payload['orderNo']);
        $this->clientIp($payload['clientIp']);
        $this->amount($payload['totalAmount'] ?? null, $payload['currency']);
        $this->returnTarget($payload);
        $this->commonOptions($payload);
    }

    /** @param array<string, mixed> $payload */
    public function process(array $payload, bool $fingerprintRequired): void
    {
        $this->allowed($payload, ['merchantId', 'payId', 'dttm', 'fingerprint', 'signature']);
        $this->requiredStrings($payload, ['merchantId', 'payId']);
        if (! array_key_exists('fingerprint', $payload)) {
            if ($fingerprintRequired) {
                throw new InvalidPaymentRequestException('fingerprint is required for this payment process call.');
            }

            return;
        }

        $fingerprint = $payload['fingerprint'];
        if (! is_array($fingerprint) || array_is_list($fingerprint)) {
            throw new InvalidPaymentRequestException('fingerprint must be an object.');
        }
        $hasBrowser = isset($fingerprint['browser']);
        $hasSdk = isset($fingerprint['sdk']);
        if ($hasBrowser === $hasSdk) {
            throw new InvalidPaymentRequestException('fingerprint must contain exactly one browser or sdk object.');
        }
        $hasBrowser ? $this->browser($fingerprint['browser']) : $this->sdk($fingerprint['sdk']);
    }

    private function browser(mixed $browser): void
    {
        if (! is_array($browser) || array_is_list($browser)) {
            throw new InvalidPaymentRequestException('fingerprint.browser must be an object.');
        }
        $this->requiredStrings($browser, ['userAgent', 'acceptHeader', 'language']);
        if (! isset($browser['javascriptEnabled']) || ! is_bool($browser['javascriptEnabled'])) {
            throw new InvalidPaymentRequestException('fingerprint.browser.javascriptEnabled must be Boolean.');
        }
        if ($browser['javascriptEnabled']) {
            foreach (['colorDepth', 'screenHeight', 'screenWidth', 'timezone'] as $field) {
                if (! array_key_exists($field, $browser) || ! is_int($browser[$field])) {
                    throw new InvalidPaymentRequestException("fingerprint.browser.{$field} is required when JavaScript is enabled.");
                }
            }
            if (! array_key_exists('javaEnabled', $browser) || ! is_bool($browser['javaEnabled'])) {
                throw new InvalidPaymentRequestException('fingerprint.browser.javaEnabled is required when JavaScript is enabled.');
            }
        }
        if (isset($browser['challengeWindowSize'])
            && ! in_array($browser['challengeWindowSize'], ['01', '02', '03', '04', '05'], true)) {
            throw new InvalidPaymentRequestException('fingerprint.browser.challengeWindowSize is invalid.');
        }
    }

    private function sdk(mixed $sdk): void
    {
        if (! is_array($sdk) || array_is_list($sdk)) {
            throw new InvalidPaymentRequestException('fingerprint.sdk must be an object.');
        }
        $this->requiredStrings($sdk, ['appID', 'encData', 'ephemPubKey', 'referenceNumber', 'transID']);
        if (! isset($sdk['maxTimeout']) || ! is_int($sdk['maxTimeout']) || $sdk['maxTimeout'] < 5) {
            throw new InvalidPaymentRequestException('fingerprint.sdk.maxTimeout must be an integer of at least 5 minutes.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function amountPair(array $payload): void
    {
        $hasAmount = array_key_exists('totalAmount', $payload) && $payload['totalAmount'] !== null;
        $hasCurrency = array_key_exists('currency', $payload) && $payload['currency'] !== null && $payload['currency'] !== '';
        if ($hasAmount !== $hasCurrency) {
            throw new InvalidPaymentRequestException('totalAmount and currency must be supplied together.');
        }
        if ($hasAmount) {
            $this->amount($payload['totalAmount'], $payload['currency']);
        }
    }

    private function amount(mixed $amount, mixed $currency): void
    {
        if (! is_int($amount) || $amount <= 0 || ! is_string($currency)
            || ! in_array($currency, ['HUF', 'EUR', 'USD'], true)) {
            throw new InvalidPaymentRequestException('Wallet amount and currency are invalid.');
        }
        if ($currency === 'HUF' && $amount % 100 !== 0) {
            throw new InvalidPaymentRequestException('HUF wallet amounts must end in 00 minor units.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function commonOptions(array $payload): void
    {
        foreach (['closePayment', 'sdkUsed'] as $field) {
            if (isset($payload[$field]) && ! is_bool($payload[$field])) {
                throw new InvalidPaymentRequestException("{$field} must be Boolean.");
            }
        }
        if (isset($payload['ttlSec']) && (! is_int($payload['ttlSec']) || $payload['ttlSec'] < 300 || $payload['ttlSec'] > 1800)) {
            throw new InvalidPaymentRequestException('ttlSec must be between 300 and 1800 seconds.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function returnTarget(array $payload): void
    {
        if (! in_array($payload['returnMethod'], ['GET', 'POST'], true)
            || filter_var($payload['returnUrl'], FILTER_VALIDATE_URL) === false
            || strlen($payload['returnUrl']) > 300) {
            throw new InvalidPaymentRequestException('Wallet return target is invalid.');
        }
    }

    private function clientIp(mixed $ip): void
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidPaymentRequestException('clientIp must be a valid IPv4 or IPv6 address.');
        }
    }

    private function orderNo(mixed $orderNo): void
    {
        if (! is_string($orderNo) || preg_match('/^\d{1,10}$/D', $orderNo) !== 1) {
            throw new InvalidPaymentRequestException('orderNo must be a string containing at most 10 digits.');
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $fields */
    private function requiredStrings(array $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new InvalidPaymentRequestException("Missing or invalid field [{$field}].");
            }
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private function allowed(array $payload, array $allowed): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new InvalidPaymentRequestException('Unsupported request field ['.reset($unknown).'].');
        }
    }
}
