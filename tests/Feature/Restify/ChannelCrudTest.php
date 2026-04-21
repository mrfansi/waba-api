<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists channels', function () {
    Channel::factory()->count(2)->create();

    $this->getJson('/api/restify/channels')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('creates a channel', function () {
    $payload = [
        'name' => 'promo',
        'display_name' => 'Promo Channel',
        'driver' => 'qiscus',
        'phone_number' => '+628111111111',
        'credentials' => ['app_id' => 'a', 'secret_key' => 's'],
        'webhook_secret' => str_repeat('x', 32),
        'status' => 'active',
    ];

    $this->postJson('/api/restify/channels', $payload)->assertCreated();

    expect(Channel::where('name', 'promo')->exists())->toBeTrue();
});

it('hides credentials in response', function () {
    $channel = Channel::factory()->create();

    $response = $this->getJson("/api/restify/channels/{$channel->id}")->assertOk()->json();
    expect(data_get($response, 'data.attributes'))->not->toHaveKey('credentials');
});
