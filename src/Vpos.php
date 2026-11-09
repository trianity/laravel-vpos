<?php

declare(strict_types=1);

namespace Trianity\Vpos;

use Trianity\Vpos\Support\BrowserReturnVerifier;
use Trianity\Vpos\Support\CheckoutData;
use Trianity\Vpos\Support\GatewayConfig;
use Trianity\Vpos\Support\HostedCardService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Support\VerifiedBrowserReturn;
use Trianity\Vpos\Support\WalletService;

class Vpos
{
    public function __construct(
        private readonly SignedJsonTransport $transport,
        private readonly HostedCardService $hostedCards,
        private readonly WalletService $wallets,
        private readonly BrowserReturnVerifier $browserReturns,
        private readonly GatewayConfig $config,
    ) {}

    /** @return array<string, mixed> */
    public function echo(): array
    {
        return $this->transport->sendEchoGet(
            $this->transport->signEchoRequest($this->merchantId()),
        );
    }

    /** @param CheckoutData|array<string, mixed>|object $checkout @return array<string, mixed> */
    public function init(array|object $checkout): array
    {
        $payload = $checkout instanceof CheckoutData
            ? $this->checkoutPayload($checkout)
            : (is_array($checkout) ? $checkout : get_object_vars($checkout));
        $payload['merchantId'] = $this->merchantId();

        return $this->hostedCards->init($payload);
    }

    public function processUrl(string $payId): string
    {
        return $this->hostedCards->processUrl($this->merchantId(), $payId);
    }

    /** @return array<string, mixed> */
    public function status(string $payId): array
    {
        return $this->hostedCards->status($this->merchantId(), $payId);
    }

    /** @param array<string, mixed> $parameters */
    public function verifyReturn(
        array $parameters,
        string $expectedPayId,
        ?string $expectedMerchantData = null,
    ): VerifiedBrowserReturn {
        return $this->browserReturns->verify($parameters, $expectedPayId, $expectedMerchantData);
    }

    public function wallets(): WalletService
    {
        return $this->wallets;
    }

    public function merchantId(): string
    {
        return $this->config->merchantId();
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(CheckoutData $checkout): array
    {
        return array_filter([
            'orderNo' => $checkout->orderNo,
            'totalAmount' => $checkout->totalAmount,
            'currency' => $this->config->currency(),
            'returnUrl' => $this->config->returnUrl(),
            'returnMethod' => 'POST',
            'cart' => $checkout->cart,
            'customer' => $checkout->customer,
            'order' => $checkout->order,
            'merchantData' => $checkout->merchantData,
            'language' => $this->config->language(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
