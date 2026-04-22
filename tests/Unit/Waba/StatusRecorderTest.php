<?php

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Outbound\StatusRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMsg(Channel $ch, array $state = []): Message
{
    return Message::factory()->for($ch)->create(array_merge([
        'provider_message_id' => 'p-1',
        'status' => 'sent',
    ], $state));
}

beforeEach(function () {
    $this->channel = Channel::factory()->create();
    $this->recorder = app(StatusRecorder::class);
});

it('updates status forward and appends event', function () {
    $m = makeMsg($this->channel);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, ['raw' => 1]);

    expect($m->fresh()->status)->toBe('delivered')
        ->and($m->fresh()->delivered_at)->not->toBeNull()
        ->and($m->fresh()->statusEvents()->count())->toBe(1);
});

it('does not downgrade read to delivered', function () {
    $m = makeMsg($this->channel, ['status' => 'read', 'read_at' => now()]);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect($m->fresh()->status)->toBe('read');
    expect($m->fresh()->statusEvents()->count())->toBe(1);
});

it('failed is terminal', function () {
    $m = makeMsg($this->channel, ['status' => 'failed', 'failed_at' => now()]);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect($m->fresh()->status)->toBe('failed');
});

it('no-ops on unknown provider id', function () {
    $event = new NormalizedStatusEvent('unknown', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect(true)->toBeTrue();
});
