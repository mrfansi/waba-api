<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChannelApiKey> */
class ChannelApiKeyFactory extends Factory
{
    protected $model = ChannelApiKey::class;

    public function definition(): array
    {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);

        return [
            'channel_id' => Channel::factory(),
            'name' => fake()->words(2, true),
            'prefix' => 'wba_'.$prefix,
            'key_hash' => hash('sha256', $secret),
            'abilities' => ['*'],
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
