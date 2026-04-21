<?php

namespace App\Providers;

use Binaryk\LaravelRestify\RestifyApplicationServiceProvider;
use Illuminate\Support\Facades\Gate;

class RestifyServiceProvider extends RestifyApplicationServiceProvider
{
    /**
     * Register the Restify gate.
     *
     * This gate determines who can access Restify in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewRestify', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }
}
