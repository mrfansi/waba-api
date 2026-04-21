<?php

use App\Models\Channel;
use App\Waba\Drivers\QiscusDriver;
use App\Waba\Exceptions\ChannelNotFoundException;
use App\Waba\Support\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves slug to bound driver', function () {
    Channel::factory()->create(['name' => 'sales']);

    $driver = app(ChannelResolver::class)->resolve('sales');

    expect($driver)->toBeInstanceOf(QiscusDriver::class);
});

it('throws when channel not found', function () {
    app(ChannelResolver::class)->resolve('missing');
})->throws(ChannelNotFoundException::class);

it('throws when channel is disabled', function () {
    Channel::factory()->create(['name' => 'off', 'status' => 'disabled']);

    app(ChannelResolver::class)->resolve('off');
})->throws(ChannelNotFoundException::class);
