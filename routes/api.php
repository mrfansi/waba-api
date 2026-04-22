<?php

use App\Http\Webhooks\HandleInboundWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('channel.apikey')
    ->prefix('v1/channels/{channel}')
    ->group(function () {
        Route::get('/ping', fn () => response()->json(['ok' => true]));
    });

Route::post('/v1/webhooks/{provider}/{channel}', HandleInboundWebhook::class);
