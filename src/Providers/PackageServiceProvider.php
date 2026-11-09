<?php

declare(strict_types=1);

namespace Trianity\Vpos\Providers;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Support\BrowserReturnVerifier;
use Trianity\Vpos\Support\GatewayConfig;
use Trianity\Vpos\Support\HostedCardService;
use Trianity\Vpos\Support\SignedJsonTransport;
use Trianity\Vpos\Support\SignedJsonTransportFactory;
use Trianity\Vpos\Support\UnconfiguredCheckoutDataProvider;
use Trianity\Vpos\Support\WalletService;
use Trianity\Vpos\Vpos;

class PackageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        AboutCommand::add(
            'Payment Gateway for K&H VPOS service',
            fn () => ['Version' => InstalledVersions::getPrettyVersion('trianity/laravel-vpos') ?? 'development']
        );

        $this->loadTranslationsFrom($this->packageBasePath().'/lang', 'vpos');
        $this->loadViewsFrom($this->packageBasePath().'/resources/views', 'vpos');

        $this->loadRoutesFrom($this->packageBasePath().'/routes/web.php');

        $this->publishes([
            $this->packageBasePath().'/config/vpos.php' => base_path('config/vpos.php'),
            $this->packageBasePath().'/lang' => lang_path('vendor/vpos'),
            $this->packageBasePath().'/resources/views' => resource_path('views/vendor/vpos'),
        ], 'vpos-config');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->packageBasePath().'/config/vpos.php',
            'vpos'
        );

        $this->app->singleton(GatewayConfig::class);
        $this->app->singleton(SignedJsonTransport::class, fn (): SignedJsonTransport => SignedJsonTransportFactory::fromConfig());
        $this->app->singleton(HostedCardService::class, fn ($app): HostedCardService => new HostedCardService(
            $app->make(SignedJsonTransport::class),
        ));
        $this->app->singleton(WalletService::class, fn ($app): WalletService => new WalletService(
            $app->make(SignedJsonTransport::class),
        ));
        $this->app->singleton(BrowserReturnVerifier::class, fn ($app): BrowserReturnVerifier => new BrowserReturnVerifier(
            $app->make(SignedJsonTransport::class),
        ));
        $this->app->singleton(Vpos::class);
        $this->app->alias(Vpos::class, 'khvpos');

        $this->app->bind(CheckoutDataProvider::class, UnconfiguredCheckoutDataProvider::class);
    }

    /**
     * Get the base path of the package.
     */
    protected function packageBasePath(string $path = ''): string
    {
        return dirname(__FILE__, 3).$path;
    }
}
