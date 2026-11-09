<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Exceptions\KeyLoadingException;

final class SignedJsonTransportFactory
{
    public static function fromConfig(): SignedJsonTransport
    {
        $environment = (string) config('vpos.env', 'test');
        $baseUrl = (string) config("vpos.{$environment}.apiUrl", '');
        $privateKey = self::keyPath(config("vpos.{$environment}.privateKey"));
        $publicKey = self::keyPath(config("vpos.{$environment}.publicKey"));

        if ($privateKey === null || $publicKey === null) {
            throw new KeyLoadingException(
                "The payment gateway keys for environment [{$environment}] are not configured.",
            );
        }

        return new SignedJsonTransport(
            $baseUrl,
            new SignatureService($privateKey, $publicKey),
        );
    }

    public static function hostedCardsFromConfig(): HostedCardService
    {
        return new HostedCardService(self::fromConfig());
    }

    public static function browserReturnsFromConfig(): BrowserReturnVerifier
    {
        return new BrowserReturnVerifier(self::fromConfig());
    }

    public static function walletsFromConfig(): WalletService
    {
        return new WalletService(self::fromConfig());
    }

    private static function keyPath(mixed $configuredPath): ?string
    {
        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        $path = trim($configuredPath);
        $candidates = [
            $path,
            base_path('storage/app/'.ltrim($path, '/')),
            dirname(__DIR__, 2).'/'.ltrim($path, '/'),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
