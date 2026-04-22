<?php

use App\Waba\Drivers\QiscusDriver;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->creds = new ChannelCredentials(
        driver: 'qiscus',
        channelId: '01HZ-test',
        data: ['app_id' => 'app123', 'secret_key' => 'sec123'],
        webhookSecret: 'whsec',
    );
});

it('returns name', function () {
    expect((new QiscusDriver)->name())->toBe('qiscus');
});

it('binds credentials immutably', function () {
    $a = new QiscusDriver;
    $b = $a->bind($this->creds);
    expect($b)->not->toBe($a);
});

it('probes successfully', function () {
    Http::fake([
        '*' => Http::response(['app' => ['id' => 'app123']], 200),
    ]);

    $driver = (new QiscusDriver)->bind($this->creds);
    expect($driver->probe())->toBeTrue();
});

it('probe returns false on http failure', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $driver = (new QiscusDriver)->bind($this->creds);
    expect($driver->probe())->toBeFalse();
});

it('throws not-implemented for unimplemented methods', function () {
    $driver = (new QiscusDriver)->bind($this->creds);
    $driver->send(new OutboundMessage('+62', 'text'));
})->throws(DriverException::class, 'not implemented');
