<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $this->channel = Channel::factory()->create();
});

it('creates api key and returns raw once', function () {
    $response = $this->postJson('/api/restify/channel-api-keys', [
        'channel_id' => $this->channel->id,
        'name' => 'default',
        'abilities' => ['messages:send', 'messages:read'],
    ])->assertCreated()->json();

    $raw = data_get($response, 'data.attributes.raw_key') ?? data_get($response, 'data.raw_key');
    expect($raw)->toStartWith('wba_')->and(substr_count((string) $raw, '_'))->toBe(2);

    $id = data_get($response, 'data.id');
    $show = $this->getJson("/api/restify/channel-api-keys/{$id}")->json();
    expect(data_get($show, 'data.attributes'))->not->toHaveKey('raw_key')
        ->and(data_get($show, 'data.attributes'))->not->toHaveKey('key_hash');
});

it('revoke sets revoked_at', function () {
    $key = ChannelApiKey::factory()->for($this->channel)->create();

    $this->patchJson("/api/restify/channel-api-keys/{$key->id}", [
        'revoked_at' => now()->toIso8601String(),
    ])->assertOk();

    expect($key->fresh()->revoked_at)->not->toBeNull();
});
