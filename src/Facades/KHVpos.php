<?php

namespace Trianity\Vpos\Facades;

use Illuminate\Support\Facades\Facade;
use Trianity\Vpos\Vpos;

/** @mixin Vpos */
class KHVpos extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'khvpos';
    }
}
