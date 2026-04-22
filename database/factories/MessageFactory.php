<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'direction' => 'outbound',
            'to_number' => '+628'.fake()->numerify('##########'),
            'from_number' => '+628'.fake()->numerify('##########'),
            'type' => 'text',
            'payload' => ['body' => fake()->sentence()],
            'status' => 'pending',
            'attempts' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => 'qiscus-'.fake()->uuid()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed', 'failed_at' => now(), 'error_code' => 'provider_error']);
    }
}
