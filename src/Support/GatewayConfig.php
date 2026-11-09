<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Exceptions\CheckoutConfigurationException;

final class GatewayConfig
{
    public function merchantId(): string
    {
        return $this->optionalString(config('vpos.merchant_id'))
            ?? throw new CheckoutConfigurationException('Configure KH_VPOSID before using the payment client.');
    }

    public function currency(): string
    {
        $currency = strtoupper((string) config('vpos.currency', 'HUF'));
        if (! in_array($currency, ['HUF', 'EUR', 'USD'], true)) {
            throw new CheckoutConfigurationException("Unsupported checkout currency [{$currency}].");
        }

        return $currency;
    }

    public function language(): string
    {
        $language = strtolower((string) config('vpos.lang', 'hu'));
        $supported = ['hu', 'en', 'de', 'fr', 'cs', 'it', 'ja', 'pl', 'pt', 'ro', 'ru', 'sk', 'es', 'tr', 'vi', 'hr', 'sl', 'sv'];
        if (! in_array($language, $supported, true)) {
            throw new CheckoutConfigurationException("Unsupported checkout language [{$language}].");
        }

        return $language;
    }

    public function returnUrl(): string
    {
        $url = $this->optionalString(config('vpos.checkout.return_url'));
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new CheckoutConfigurationException('Configure vpos.checkout.return_url as an absolute HTTPS URL.');
        }

        return $url;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
