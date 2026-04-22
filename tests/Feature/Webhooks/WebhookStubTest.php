<?php

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('webhook stub returns 202', function () {
    Channel::factory()->create(['name' => 'ch1']);

    $this->postJson('/api/v1/webhooks/qiscus/ch1', ['hello' => 'world'])
        ->assertStatus(202);
});
