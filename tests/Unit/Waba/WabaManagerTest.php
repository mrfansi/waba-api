<?php

use App\Models\Channel;
use App\Waba\Contracts\MessageProvider;
use App\Waba\Facades\Waba;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves channel via facade', function () {
    Channel::factory()->create(['name' => 'main']);

    expect(Waba::channel('main'))->toBeInstanceOf(MessageProvider::class);
});

it('fake replaces resolution', function () {
    Channel::factory()->create(['name' => 'main']);

    $fake = Waba::fake();

    expect(Waba::channel('main'))->toBe($fake);
});
