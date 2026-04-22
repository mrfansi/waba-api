<?php

use App\Models\Channel;
use App\Models\User;
use App\Waba\Facades\Waba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('invokes probe via action', function () {
    $channel = Channel::factory()->create();
    $fake = Waba::fake();
    $fake->probeResult = true;

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=probe-channel")
        ->assertOk()
        ->json();

    expect(data_get($response, 'data.ok'))->toBeTrue();
});

it('reports failure when probe returns false', function () {
    $channel = Channel::factory()->create();
    $fake = Waba::fake();
    $fake->probeResult = false;

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=probe-channel")
        ->assertOk()
        ->json();

    expect(data_get($response, 'data.ok'))->toBeFalse();
});
