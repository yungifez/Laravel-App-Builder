<?php

namespace Database\Factories;

use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Preview>
 */
class PreviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_request_id' => FeatureRequest::factory()->generated(),
            'project_id' => fn (array $attributes) => $attributes['feature_request_id'] === null
                ? Project::factory()
                : FeatureRequest::query()->whereKey($attributes['feature_request_id'])->value('project_id'),
            'host' => 'p'.Str::lower(Str::random(24)),
            'status' => PreviewStatus::Starting,
            'expires_at' => now()->addHour(),
        ];
    }

    /**
     * Indicate that the preview runs the project at a revision, marked for
     * point-and-edit, rather than a feature request's change.
     */
    public function editable(?string $revision = null): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_request_id' => null,
            'editable' => true,
            'revision' => $revision,
        ]);
    }

    /**
     * Indicate that the preview is running.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PreviewStatus::Ready,
            'port' => 20001,
            'upstream_url' => 'http://127.0.0.1:20001',
            'ready_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
