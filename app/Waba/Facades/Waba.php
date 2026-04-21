<?php

namespace App\Waba\Facades;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Support\WabaManager;
use App\Waba\Testing\FakeProvider;
use Illuminate\Support\Facades\Facade;

/**
 * @method static MessageProvider channel(string $slug)
 * @method static FakeProvider fake()
 */
class Waba extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WabaManager::class;
    }
}
