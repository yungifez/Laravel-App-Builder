<?php

namespace Database\Factories;

use App\Models\Runner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Runner>
 */
class RunnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::lower(Str::random(8)),
            'token_hash' => Runner::hashToken(Str::random(64)),
            'service_host' => fake()->localIpv4(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A runner that has not asked for work for a long time.
     */
    public function offline(): static
    {
        return $this->state(['last_seen_at' => now()->subDay()]);
    }
}
