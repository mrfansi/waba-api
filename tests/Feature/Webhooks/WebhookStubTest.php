<?php

it('webhook stub returns 202', function () {
    $this->postJson('/api/v1/webhooks/qiscus/ch1', ['hello' => 'world'])
        ->assertStatus(202);
});
