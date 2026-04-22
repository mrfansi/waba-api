<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\IdempotencyMismatchException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\DispatchService;
use App\Waba\Outbound\SendMessageJob;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
    $this->apiKey = ChannelApiKey::factory()->for($this->channel)->create();
    $this->svc = app(DispatchService::class);
    $this->msg = new OutboundMessage('+62811', 'text', new TextPayload('hi'));
});

it('persists pending row and queues job in queue mode', function () {
    Queue::fake();

    $row = $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'queue');

    expect($row->status)->toBe('pending')->and($row->channel_id)->toBe($this->channel->id);
    Queue::assertPushed(SendMessageJob::class);
});

it('returns sent row in sync mode on success', function () {
    Waba::fake();

    $row = $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'sync');

    expect($row->status)->toBe('sent')
        ->and($row->provider_message_id)->toBe('fake-id');
});

it('marks row failed on PermanentSendException sync', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new PermanentSendException('rejected');

    expect(fn () => $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'sync'))
        ->toThrow(PermanentSendException::class);

    $row = Message::where('channel_id', $this->channel->id)->first();
    expect($row->status)->toBe('failed');
});

it('returns existing row on idempotency hit', function () {
    Queue::fake();
    $msg = new OutboundMessage('+62811', 'text', new TextPayload('hi'), idempotencyKey: 'k1');

    $row1 = $this->svc->dispatch($this->channel, $this->apiKey, $msg, 'queue');
    $row2 = $this->svc->dispatch($this->channel, $this->apiKey, $msg, 'queue');

    expect($row2->id)->toBe($row1->id);
    Queue::assertPushed(SendMessageJob::class, 1);
});

it('throws on idempotency mismatch (same key, different body)', function () {
    Queue::fake();
    $a = new OutboundMessage('+62811', 'text', new TextPayload('hi'), idempotencyKey: 'k1');
    $b = new OutboundMessage('+62811', 'text', new TextPayload('different'), idempotencyKey: 'k1');

    $this->svc->dispatch($this->channel, $this->apiKey, $a, 'queue');

    expect(fn () => $this->svc->dispatch($this->channel, $this->apiKey, $b, 'queue'))
        ->toThrow(IdempotencyMismatchException::class);
});
