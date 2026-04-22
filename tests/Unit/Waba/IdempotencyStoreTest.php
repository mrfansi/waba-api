<?php

use App\Waba\Outbound\IdempotencyStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->store = new IdempotencyStore;
});

it('returns null when no record exists', function () {
    expect($this->store->find('apikey-1', 'k1'))->toBeNull();
});

it('remembers and returns record', function () {
    $this->store->remember('apikey-1', 'k1', 'msg-1', 'hash-a');

    expect($this->store->find('apikey-1', 'k1'))
        ->toBe(['message_id' => 'msg-1', 'request_hash' => 'hash-a']);
});

it('isolates by api key id', function () {
    $this->store->remember('apikey-1', 'k1', 'msg-1', 'hash-a');

    expect($this->store->find('apikey-2', 'k1'))->toBeNull();
});
