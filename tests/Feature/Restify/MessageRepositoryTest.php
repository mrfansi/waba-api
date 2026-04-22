<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $this->channel = Channel::factory()->create();
});

it('lists messages', function () {
    Message::factory()->for($this->channel)->count(2)->create();

    $this->getJson('/api/restify/messages')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('shows a message', function () {
    $m = Message::factory()->for($this->channel)->create();

    $this->getJson("/api/restify/messages/{$m->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $m->id);
});

it('matches by status', function () {
    Message::factory()->for($this->channel)->sent()->create();
    Message::factory()->for($this->channel)->failed()->create();

    $this->getJson('/api/restify/messages?status=failed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
