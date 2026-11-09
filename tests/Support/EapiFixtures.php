<?php

declare(strict_types=1);

namespace Trianity\Vpos\Tests\Support;

use RuntimeException;

final class EapiFixtures
{
    public const MERCHANT_ID = 'M1MIPS0000';

    public const REQUEST_TIMESTAMP = '20220125131559';

    public const RESPONSE_TIMESTAMP = '20220125133016';

    public const PAYMENT_INIT_CANONICAL = 'M1MIPS0000|5547|20220125131559|payment|card|123400|HUF|true|https://shop.example.com/return|POST|Wireless headphones|1|123400|Shipping|1|0|DPL|some-base64-encoded-merchant-data|cs';

    public static function merchantPrivateKey(): string
    {
        return self::fixturePath('merchant-private.pem');
    }

    public static function merchantPublicKey(): string
    {
        return self::fixturePath('merchant-public.pem');
    }

    public static function gatewayPrivateKey(): string
    {
        return self::fixturePath('gateway-private.pem');
    }

    public static function gatewayPublicKey(): string
    {
        return self::fixturePath('gateway-public.pem');
    }

    /** @return array{dttm: string, resultCode: int, resultMessage: string, signature: string} */
    public static function successfulEchoResponse(): array
    {
        return self::echoResponse(0, 'OK');
    }

    /** @return array{dttm: string, resultCode: int, resultMessage: string, signature: string} */
    public static function echoResponse(int $resultCode, string $resultMessage): array
    {
        $canonical = self::RESPONSE_TIMESTAMP."|{$resultCode}|{$resultMessage}";

        return [
            'dttm' => self::RESPONSE_TIMESTAMP,
            'resultCode' => $resultCode,
            'resultMessage' => $resultMessage,
            'signature' => self::sign($canonical, self::gatewayPrivateKey()),
        ];
    }

    public static function sign(string $canonical, string $privateKeyPath): string
    {
        $key = openssl_pkey_get_private(self::read($privateKeyPath));
        if ($key === false || ! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the deterministic eAPI fixture.');
        }

        return base64_encode($signature);
    }

    public static function verify(string $canonical, string $signature, string $publicKeyPath): bool
    {
        $key = openssl_pkey_get_public(self::read($publicKeyPath));
        $decoded = base64_decode($signature, true);

        return $key !== false
            && $decoded !== false
            && openssl_verify($canonical, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function fixturePath(string $filename): string
    {
        return dirname(__DIR__).'/Fixtures/'.$filename;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read eAPI fixture: {$path}");
        }

        return $contents;
    }
}
