<?php

declare(strict_types=1);

namespace Trianity\Vpos\Sandbox;

use Dotenv\Dotenv;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\EchoSchema;
use Trianity\Vpos\Support\SignatureService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Tests\PackageTestCase;

final class SandboxEchoTest extends PackageTestCase
{
    private const SANDBOX_URL = 'https://api.sandbox.khpos.hu/api/v1.0/';

    private SignedJsonTransport $transport;

    private Canonicalizer $canonicalizer;

    private string $merchantId;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = dirname(__DIR__);
        Dotenv::createImmutable($repository, 'tests/.env')->safeLoad();

        if ($this->requiredEnvironment('KH_SANDBOX_ACCEPTANCE') !== '1') {
            throw new RuntimeException('Sandbox acceptance requires KH_SANDBOX_ACCEPTANCE=1.');
        }

        $url = $this->optionalEnvironment('KH_SANDBOX_API_URL') ?? self::SANDBOX_URL;
        if ($url !== self::SANDBOX_URL) {
            throw new RuntimeException('Sandbox acceptance only permits the pinned K&H sandbox endpoint.');
        }

        $privateKey = $this->untrackedReadableKey('KH_TEST_PRIVATE_KEY');
        $publicKey = $this->untrackedReadableKey('KH_TEST_PUBLIC_KEY');
        $this->merchantId = $this->requiredEnvironment('KH_VPOSID');
        $this->canonicalizer = new Canonicalizer;
        $this->transport = new SignedJsonTransport(
            $url,
            new SignatureService($privateKey, $publicKey),
            $this->canonicalizer,
        );

        Http::allowStrayRequests([self::SANDBOX_URL.'*'])->record();
    }

    public function test_signed_post_echo(): void
    {
        $request = $this->transport->signEchoRequest($this->merchantId);
        $result = $this->transport->sendEchoPost($request);

        self::assertSame(0, $result['resultCode'], 'The sandbox POST Echo was not available.');
        self::assertNotSame('', $result['resultMessage']);
        $this->assertRequestTextToSign(
            $this->canonicalizer->canonicalize($request, EchoSchema::REQUEST),
        );
    }

    public function test_signed_get_echo(): void
    {
        $request = $this->transport->signEchoRequest($this->merchantId);
        $result = $this->transport->sendEchoGet($request);

        self::assertSame(0, $result['resultCode'], 'The sandbox GET Echo was not available.');
        self::assertNotSame('', $result['resultMessage']);
    }

    private function assertRequestTextToSign(string $localCanonical): void
    {
        $pair = Http::recorded()->last();
        if (! is_array($pair) || ! ($pair[1] ?? null) instanceof Response) {
            self::fail('The sandbox response was not recorded.');
        }

        $gatewayCanonical = $pair[1]->header('REQUEST_TEXT_TO_SIGN');
        self::assertNotSame('', $gatewayCanonical, 'The sandbox returned an empty REQUEST_TEXT_TO_SIGN.');
        self::assertTrue(
            hash_equals($localCanonical, $gatewayCanonical),
            'The local canonical string does not match the sandbox header.',
        );
    }

    private function untrackedReadableKey(string $name): string
    {
        $path = $this->requiredEnvironment($name);
        $repository = dirname(__DIR__);
        $candidates = [
            $path,
            $repository.'/storage/app/'.ltrim($path, '/'),
            $repository.'/'.ltrim($path, '/'),
        ];
        $realPath = false;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $realPath = realpath($candidate);

                break;
            }
        }
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException("{$name} must point to a readable key file.");
        }

        if (str_starts_with($realPath, $repository.'/')
            && ! str_starts_with($realPath, $repository.'/key/')) {
            throw new RuntimeException("{$name} must remain outside tracked repository paths.");
        }

        return $realPath;
    }

    private function requiredEnvironment(string $name): string
    {
        $value = $this->optionalEnvironment($name);
        if ($value === null) {
            throw new RuntimeException("Missing required sandbox variable: {$name}.");
        }

        return $value;
    }

    private function optionalEnvironment(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
