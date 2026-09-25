<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'personal_team' => false,
        ];
    }

    /**
     * Indicate that the team is a user's personal team.
     */
    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'personal_team' => true,
        ]);
    }

    /**
     * Attach the given user to the team as its owner and make it their current team.
     */
    public function ownedBy(User $owner): static
    {
        return $this->afterCreating(function (Team $team) use ($owner) {
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

            $owner->forceFill(['current_team_id' => $team->id])->save();
        });
    }
}
