<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'name' => 'ch-'.Str::random(8),
            'display_name' => fake()->company(),
            'driver' => 'qiscus',
            'phone_number' => '+628'.fake()->numerify('##########'),
            'phone_number_id' => null,
            'credentials' => ['app_id' => Str::random(16), 'secret_key' => Str::random(32)],
            'webhook_secret' => Str::random(32),
            'settings' => [],
            'status' => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }
}
