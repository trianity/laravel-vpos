<?php

declare(strict_types=1);

namespace Trianity\Vpos\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Trianity\Vpos\Providers\PackageServiceProvider;
use Trianity\Vpos\Tests\Support\EapiFixtures;

class PackageTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', '6rE9Nz59bGRbeMATftriyQjrpF7DcOQm');
        config()->set('vpos.env', 'test');
        config()->set('vpos.merchant_id', EapiFixtures::MERCHANT_ID);
        config()->set('vpos.test.apiUrl', 'https://gateway.test/api/v1.0/');
        config()->set('vpos.test.privateKey', '/tests/Fixtures/merchant-private.pem');
        config()->set('vpos.test.publicKey', '/tests/Fixtures/gateway-public.pem');

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PackageServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // perform environment setup
    }
}
