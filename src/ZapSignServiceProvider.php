<?php

namespace JeffersonGoncalves\ZapSign;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use JeffersonGoncalves\ZapSign\Http\Controllers\WebhookController;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZapSignServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-zapsign')
            ->hasConfigFile()
            ->hasMigrations(['create_zapsign_tables'])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('jeffersongoncalves/laravel-zapsign');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ZapSign::class, function ($app): ZapSign {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('zapsign', []);

            return new ZapSign(
                $app->make(HttpFactory::class),
                new RateLimiter($app->make(CacheFactory::class)->store($config['rate_limit']['cache_store'] ?? null)),
                $config,
            );
        });
    }

    public function packageBooted(): void
    {
        $path = config('zapsign.webhook.path');

        if (blank(config('zapsign.webhook.secret')) || blank($path)) {
            return;
        }

        Route::post((string) $path, WebhookController::class)
            ->middleware((array) config('zapsign.webhook.middleware', []))
            ->name('zapsign.webhook');
    }
}
