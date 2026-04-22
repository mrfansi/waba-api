<?php

use App\Models\Channel;
use App\Models\User;
use App\Waba\Facades\Waba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    Waba::fake();
});

it('admin sends via restify action sync mode', function () {
    $channel = Channel::factory()->create();

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=send-message", [
        'to' => '+62811',
        'type' => 'text',
        'text' => ['body' => 'hi'],
    ])->assertOk()->json();

    expect(data_get($response, 'data.status'))->toBe('sent');
});
