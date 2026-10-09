<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\MeasureEvolution;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ask for a change at an hour of the day, and keep it later when given.
     */
    protected function change(Project $project, int $askedAt, ?int $keptAt, array $attributes = []): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->for($project)->create([
            'created_at' => now()->startOfDay()->addHours($askedAt),
            'commit_sha' => $keptAt === null ? null : sha1((string) $askedAt),
            'accepted_at' => $keptAt === null ? null : now()->startOfDay()->addHours($keptAt),
            ...$attributes,
        ]);
    }

    /**
     * Give a change a run that cost something and was verified.
     */
    protected function attempt(FeatureRequest $change, float $cost, VerificationStatus $status, array $results = [], array $evidence = [], int $repairs = 0): void
    {
        $run = Run::factory()->for($change)->create(['repairs' => $repairs]);
        $run->recordEvent('model_call', ['input_tokens' => 1000, 'output_tokens' => 500, 'cost_usd' => $cost]);
        $change->verifications()->create(['run_id' => $run->id, 'status' => $status, 'results' => $results, 'evidence' => $evidence]);
    }

    public function test_each_window_counts_the_work_that_led_to_its_kept_changes(): void
    {
        $project = Project::factory()->create();

        // Change 1 went through first time.
        $this->attempt($this->change($project, 1, 2), 0.50, VerificationStatus::Passed);

        // Change 2: a first try was given up on, then a retry broke two
        // tests that passed before, and was kept after a repair.
        $abandoned = $this->change($project, 3, null);
        $this->attempt($abandoned, 0.25, VerificationStatus::Failed);
        $kept = $this->change($project, 4, 8, ['retry_of_id' => $abandoned->id]);
        $this->attempt($kept, 1.00, VerificationStatus::Failed, results: [[
            'name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'at_start' => 'passed',
            'tests' => [['file' => 'a', 'name' => 'x', 'outcome' => 'failed'], ['file' => 'a', 'name' => 'y', 'outcome' => 'failed'], ['file' => 'a', 'name' => 'z', 'outcome' => 'passed']],
        ]], repairs: 1);

        // Change 3 broke an earlier rule, and was undone after keeping.
        $this->attempt($this->change($project, 9, 10, ['reverted_at' => now()]), 0.75, VerificationStatus::Passed, evidence: ['earlier_rules' => ['Booking delete']]);

        // Asked, not kept yet: not counted.
        $this->attempt($this->change($project, 11, null), 9.00, VerificationStatus::Passed);

        $windows = (new MeasureEvolution)->handle($project, [1, 5]);

        $this->assertSame([
            ['from' => 1, 'to' => 1, 'kept' => 1, 'requests' => 1, 'cost_usd' => 0.5, 'unpriced_calls' => 0, 'tokens' => 1500, 'first_attempt_passed' => 1, 'first_attempts' => 1, 'repairs' => 0, 'owner_steps' => 0, 'broken_tests' => 0, 'earlier_rules_broken' => 0, 'undone' => 0, 'hours_to_keep' => 1.0],
            ['from' => 2, 'to' => 3, 'kept' => 2, 'requests' => 3, 'cost_usd' => 2.0, 'unpriced_calls' => 0, 'tokens' => 4500, 'first_attempt_passed' => 1, 'first_attempts' => 3, 'repairs' => 1, 'owner_steps' => 1, 'broken_tests' => 2, 'earlier_rules_broken' => 1, 'undone' => 1, 'hours_to_keep' => 2.5],
        ], $windows);
    }

    public function test_the_command_shows_a_row_per_window(): void
    {
        $project = Project::factory()->create();
        $this->attempt($this->change($project, 1, 2), 0.50, VerificationStatus::Passed);

        $this->artisan('builder:evolution', ['project' => $project->id])
            ->expectsOutputToContain('$0.50')
            ->assertSuccessful();

        $this->artisan('builder:evolution', ['project' => Project::factory()->create()->id])
            ->expectsOutputToContain('The project has no kept changes yet.')
            ->assertSuccessful();
    }
}
