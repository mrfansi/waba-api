<?php

use App\Http\Middleware\AssertAbility;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateChannelApiKey;
use App\Waba\Exceptions\WabaException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'channel.apikey' => AuthenticateChannelApiKey::class,
            'assert.ability' => AssertAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (WabaException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'details' => $e->details(),
                ],
                'request_id' => $request->attributes->get('request_id'),
            ], $e->httpStatus());
        });
    })->create();
