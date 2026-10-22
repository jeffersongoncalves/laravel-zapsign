<?php

namespace JeffersonGoncalves\ZapSign;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZapSignServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-zapsign')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations();
    }
}
