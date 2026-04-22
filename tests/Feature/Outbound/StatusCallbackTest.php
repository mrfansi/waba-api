<?php

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
});

it('accepts and applies status callback', function () {
    $m = Message::factory()->for($this->channel)->create([
        'provider_message_id' => 'q-1',
        'status' => 'sent',
    ]);

    $this->postJson('/api/v1/webhooks/qiscus/main', [
        'event' => 'status',
        'data' => ['message_id' => 'q-1', 'status' => 'delivered', 'timestamp' => now()->toIso8601String()],
    ])->assertStatus(202);

    expect($m->fresh()->status)->toBe('delivered');
    expect($m->statusEvents()->count())->toBe(1);
});

it('returns 202 for inbound message (P3 stub)', function () {
    $this->postJson('/api/v1/webhooks/qiscus/main', [
        'event' => 'message',
        'data' => ['from' => '+62811', 'text' => 'hi'],
    ])->assertStatus(202)
        ->assertJsonPath('note', 'inbound_p3_pending');
});
