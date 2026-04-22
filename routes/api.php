<?php

use App\Http\Actions\ListMessagesActionApi;
use App\Http\Actions\SendMessageActionApi;
use App\Http\Actions\ShowMessageActionApi;
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

        Route::post('/messages', SendMessageActionApi::class)
            ->middleware('assert.ability:messages:send');
        Route::get('/messages', ListMessagesActionApi::class)
            ->middleware('assert.ability:messages:read');
        Route::get('/messages/{id}', ShowMessageActionApi::class)
            ->middleware('assert.ability:messages:read');
    });

Route::post('/v1/webhooks/{provider}/{channel}', HandleInboundWebhook::class);
