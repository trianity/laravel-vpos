<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use OpenSSLAsymmetricKey;
use Trianity\Vpos\Exceptions\KeyLoadingException;
use Trianity\Vpos\Exceptions\SignatureException;

final class SignatureService
{
    private OpenSSLAsymmetricKey $privateKey;

    private OpenSSLAsymmetricKey $publicKey;

    public function __construct(
        string $privateKeyPath,
        string $publicKeyPath,
        ?string $privateKeyPassword = null,
    ) {
        $private = $this->readKey($privateKeyPath, 'private');
        $public = $this->readKey($publicKeyPath, 'public');

        $privateKey = openssl_pkey_get_private($private, $privateKeyPassword);
        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new KeyLoadingException('The configured merchant private key is invalid.');
        }

        $publicKey = openssl_pkey_get_public($public);
        if (! $publicKey instanceof OpenSSLAsymmetricKey) {
            throw new KeyLoadingException('The configured gateway public key is invalid.');
        }

        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
    }

    public function sign(string $canonical): string
    {
        if (! openssl_sign($canonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new SignatureException('Unable to sign the payment gateway request.');
        }

        return base64_encode($signature);
    }

    public function verify(string $canonical, string $signature): void
    {
        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            throw new SignatureException('The payment gateway signature is not valid Base64.');
        }

        if (openssl_verify($canonical, $decoded, $this->publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SignatureException('The payment gateway signature verification failed.');
        }
    }

    private function readKey(string $path, string $kind): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new KeyLoadingException("The configured {$kind} key is not readable.");
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new KeyLoadingException("The configured {$kind} key is empty.");
        }

        return $contents;
    }
}
