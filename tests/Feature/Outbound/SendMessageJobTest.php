<?php

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Exceptions\DriverException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\DispatchService;
use App\Waba\Outbound\SendMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
    $this->msg = Message::factory()->for($this->channel)->create();
});

it('marks message sent on success', function () {
    Waba::fake();

    (new SendMessageJob($this->msg->id))->handle(app(DispatchService::class));

    expect($this->msg->fresh()->status)->toBe('sent');
});

it('marks failed on PermanentSendException without retry', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new PermanentSendException('rejected');

    $job = new SendMessageJob($this->msg->id);
    try {
        $job->handle(app(DispatchService::class));
    } catch (Throwable) {
        // job swallows internally via $this->fail()
    }

    expect($this->msg->fresh()->status)->toBe('failed');
});

it('rethrows DriverException for queue retry', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new DriverException('transient');

    $job = new SendMessageJob($this->msg->id);

    expect(fn () => $job->handle(app(DispatchService::class)))
        ->toThrow(DriverException::class);

    expect($this->msg->fresh()->status)->toBe('sending');
    expect($this->msg->fresh()->attempts)->toBe(1);
});

it('failed callback marks pending row as failed terminally', function () {
    $job = new SendMessageJob($this->msg->id);
    $job->failed(new RuntimeException('boom'));

    expect($this->msg->fresh()->status)->toBe('failed');
});
