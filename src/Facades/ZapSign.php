<?php

namespace JeffersonGoncalves\ZapSign\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JeffersonGoncalves\ZapSign\ZapSign
 */
class ZapSign extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-zapsign';
    }
}
