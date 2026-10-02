<?php

namespace Tests\Feature\Projects;

use App\Actions\Changes\AcceptChange;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class BenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        config(['builder.benchmark.changes' => ['Let members book rooms.', 'A room cannot be booked twice.', 'Only the booker can cancel.']]);

        // Keeping a change commits it; here it only marks it kept.
        $accept = Mockery::mock(AcceptChange::class);
        $accept->shouldReceive('handle')->andReturnUsing(function (FeatureRequest $request) {
            $request->update(['commit_sha' => sha1($request->prompt), 'accepted_at' => now()]);

            return $request;
        });
        $this->app->instance(AcceptChange::class, $accept);
    }

    /**
     * Move the newest change's run on each time the command waits: it asks
     * a question first, then completes.
     */
    protected function work(RunStatus $end = RunStatus::Completed): void
    {
        Sleep::whenFakingSleep(function () use ($end) {
            $run = FeatureRequest::query()->latest('id')->firstOrFail()->latestRun;

            match ($run->status) {
                RunStatus::NeedsUserDecision => null,
                RunStatus::Queued => $run->update(['status' => RunStatus::NeedsUserDecision, 'question' => ['text' => 'Which rooms?', 'why' => 'Scope', 'options' => ['All', 'Some'], 'recommended' => 'Some']]),
                default => [$run->update(['status' => $end]), $run->featureRequest->update(['status' => FeatureRequestStatus::Generated])],
            };
        });
    }

    public function test_the_next_changes_are_asked_for_answered_and_kept_in_order(): void
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();
        $this->work();

        $this->artisan('builder:benchmark', ['project' => $project->id, '--changes' => 2])->assertSuccessful();

        $kept = $project->featureRequests()->whereNotNull('commit_sha')->oldest('id')->get();
        $this->assertSame(['Let members book rooms.', 'A room cannot be booked twice.'], $kept->pluck('prompt')->all());
        $this->assertSame('Some', $kept[0]->latestRun->answers[0]['answer'] ?? null, 'the recommended answer');

        // The next run starts after the kept ones.
        $this->artisan('builder:benchmark', ['project' => $project->id])->assertSuccessful();
        $this->assertSame('Only the booker can cancel.', $project->featureRequests()->latest('id')->firstOrFail()->prompt);
        $this->artisan('builder:benchmark', ['project' => $project->id])
            ->expectsOutputToContain('Every change of the benchmark is kept in this project.')
            ->assertSuccessful();
    }

    public function test_a_change_that_fails_stops_the_benchmark(): void
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();
        $this->work(RunStatus::Failed);

        $this->artisan('builder:benchmark', ['project' => $project->id, '--changes' => 3])
            ->expectsOutputToContain('It stopped: failed.')
            ->assertFailed();

        $this->assertSame(1, $project->featureRequests()->count());
    }

    public function test_a_run_that_gives_up_and_waits_for_the_owner_stops_the_benchmark(): void
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();
        Sleep::whenFakingSleep(fn () => FeatureRequest::query()->latest('id')->firstOrFail()->latestRun
            ->update(['status' => RunStatus::NeedsUserDecision, 'question' => null, 'error' => 'The checks did not pass.']));

        $this->artisan('builder:benchmark', ['project' => $project->id, '--changes' => 3])
            ->expectsOutputToContain('It stopped and waits for the owner. The checks did not pass.')
            ->assertFailed();

        $this->assertSame(1, $project->featureRequests()->count());
    }
}
