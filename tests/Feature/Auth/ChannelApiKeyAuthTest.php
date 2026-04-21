<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createKey(Channel $channel, array $state = []): array
{
    $prefix = 'wba_'.Str::lower(Str::random(8));
    $secret = Str::random(40);
    $key = ChannelApiKey::factory()->for($channel)->create(array_merge([
        'prefix' => $prefix,
        'key_hash' => hash('sha256', $secret),
    ], $state));

    return [$key, $prefix.'_'.$secret];
}

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'ch1']);
});

it('rejects missing token', function () {
    $this->getJson('/api/v1/channels/ch1/ping')->assertStatus(401);
});

it('accepts valid token', function () {
    [, $raw] = createKey($this->channel);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertOk();
});

it('rejects wrong channel', function () {
    $other = Channel::factory()->create(['name' => 'ch2']);
    [, $raw] = createKey($other);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects revoked', function () {
    [, $raw] = createKey($this->channel, ['revoked_at' => now()]);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects expired', function () {
    [, $raw] = createKey($this->channel, ['expires_at' => now()->subDay()]);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects bad secret', function () {
    [$key] = createKey($this->channel);

    $this->withHeader('Authorization', "Bearer {$key->prefix}_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});
