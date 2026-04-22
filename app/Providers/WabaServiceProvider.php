<?php

namespace App\Providers;

use App\Waba\Support\WabaManager;
use Illuminate\Support\ServiceProvider;

class WabaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WabaManager::class);
    }

    public function boot(): void
    {
        //
    }
}
