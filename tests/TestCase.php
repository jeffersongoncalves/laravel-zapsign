<?php

namespace JeffersonGoncalves\ZapSign\Tests;

use JeffersonGoncalves\ZapSign\ZapSignServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ZapSignServiceProvider::class,
        ];
    }
}
