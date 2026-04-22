<?php

use App\Waba\Drivers\QiscusDriver;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\DriverException;
use App\Waba\Exceptions\PermanentSendException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->creds = new ChannelCredentials(
        driver: 'qiscus',
        channelId: '01HZ',
        data: ['app_id' => 'app1', 'secret_key' => 'sec1', 'phone_number_id' => 'pni-1'],
        webhookSecret: 'wh-secret',
    );
    $this->driver = (new QiscusDriver)->bind($this->creds);
});

it('sends text and returns SendResult', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'qiscus-msg-1']], 200)]);

    $result = $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));

    expect($result->providerMessageId)->toBe('qiscus-msg-1')->and($result->status)->toBe('accepted');
    expect($this->driver->lastTransaction())->toHaveKeys(['request', 'response']);
});

it('throws PermanentSendException on 4xx', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid number'], 400)]);

    $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));
})->throws(PermanentSendException::class);

it('throws DriverException on 5xx', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));
})->throws(DriverException::class);

it('builds media payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-2']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'media',
        new MediaPayload(kind: 'image', url: 'https://x/y.jpg', caption: 'cap'),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'image')
        && str_contains(json_encode($req->data()), 'cap'));
});

it('builds template payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-3']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'template',
        new TemplatePayload(name: 'order_confirm', language: 'id', components: []),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'order_confirm'));
});

it('builds interactive payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-4']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'interactive',
        new InteractivePayload(kind: 'button', body: 'pick one', action: ['buttons' => []]),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'pick one'));
});

it('builds location payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-5']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'location',
        new LocationPayload(latitude: 1.23, longitude: 4.56, name: 'office'),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), '1.23'));
});

it('normalizes status webhook', function () {
    $event = $this->driver->normalizeStatus([
        'event' => 'status',
        'data' => ['message_id' => 'q-1', 'status' => 'delivered', 'timestamp' => '2026-04-22T10:00:00Z'],
    ]);

    expect($event)->not->toBeNull()
        ->and($event->providerMessageId)->toBe('q-1')
        ->and($event->status)->toBe('delivered');
});

it('returns null for non-status webhook', function () {
    expect($this->driver->normalizeStatus(['event' => 'message', 'data' => []]))->toBeNull();
});

it('verifies HMAC signature', function () {
    $payload = '{"hello":"world"}';
    $sig = hash_hmac('sha256', $payload, 'wh-secret');

    expect($this->driver->verifyWebhookSignature($payload, ['x-qiscus-signature' => [$sig]]))->toBeTrue();
    expect($this->driver->verifyWebhookSignature($payload, ['x-qiscus-signature' => ['bad']]))->toBeFalse();
});
