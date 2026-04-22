<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Waba\Facades\Waba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function mkAuthHeader(Channel $channel, array $abilities = ['*']): array
{
    $prefix = 'wba_'.Str::lower(Str::random(8));
    $secret = Str::random(40);
    ChannelApiKey::factory()->for($channel)->create([
        'prefix' => $prefix,
        'key_hash' => hash('sha256', $secret),
        'abilities' => $abilities,
    ]);

    return ['Authorization' => "Bearer {$prefix}_{$secret}"];
}

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
});

it('queues a text send', function () {
    Queue::fake();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->postJson('/api/v1/channels/main/messages', [
            'to' => '+62811',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending');
});

it('returns 200 sync with sent status', function () {
    Waba::fake();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers + ['X-Send-Mode' => 'sync'])
        ->postJson('/api/v1/channels/main/messages', [
            'to' => '+62811',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'sent');
});

it('rejects without messages:send ability', function () {
    $headers = mkAuthHeader($this->channel, ['messages:read']);

    $this->withHeaders($headers)
        ->postJson('/api/v1/channels/main/messages', ['to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi']])
        ->assertStatus(403);
});

it('replays idempotent send', function () {
    Waba::fake();
    $headers = mkAuthHeader($this->channel) + ['X-Send-Mode' => 'sync', 'Idempotency-Key' => 'k1'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/channels/main/messages', [
        'to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi'],
    ])->assertStatus(200)->json('data.id');

    $second = $this->withHeaders($headers)->postJson('/api/v1/channels/main/messages', [
        'to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi'],
    ])->assertStatus(200)
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->json('data.id');

    expect($second)->toBe($first);
});

it('lists messages', function () {
    Message::factory()->for($this->channel)->count(3)->create();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->getJson('/api/v1/channels/main/messages')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('shows a single message', function () {
    $m = Message::factory()->for($this->channel)->create();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->getJson("/api/v1/channels/main/messages/{$m->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $m->id);
});
