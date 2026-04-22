<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageStatusEvent> */
class MessageStatusEventFactory extends Factory
{
    protected $model = MessageStatusEvent::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'status' => 'sent',
            'occurred_at' => now(),
            'raw_payload' => [],
        ];
    }
}
