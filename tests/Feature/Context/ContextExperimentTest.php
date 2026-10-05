<?php

namespace Tests\Feature\Context;

use App\Actions\Changes\AcceptChange;
use App\Actions\Context\CompileContext;
use App\Actions\Runs\StartRun;
use App\Context\ProjectContext;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\ContextTrial;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class ContextExperimentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The mode each run was started with, as the queued work would carry it.
     *
     * @var array<int, string|null>
     */
    private array $carried = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        config([
            'builder.benchmark.changes' => ['Let members book rooms.', 'A room cannot be booked twice.'],
            'builder.context.experiment.min_pairs' => 2,
        ]);

        // Keeping a change commits it; here it only marks it kept.
        $accept = Mockery::mock(AcceptChange::class);
        $accept->shouldReceive('handle')->andReturnUsing(function (FeatureRequest $request) {
            $request->update(['commit_sha' => sha1($request->prompt), 'accepted_at' => now()]);

            return $request;
        });
        $this->app->instance(AcceptChange::class, $accept);

        // Note the mode the experiment sets while the run is started.
        $start = app(StartRun::class);
        $spy = Mockery::mock(StartRun::class);
        $spy->shouldReceive('handle')->andReturnUsing(function (FeatureRequest $request) use ($start) {
            $run = $start->handle($request);
            $this->carried[$run->id] = Context::getHidden(CompileContext::TRIAL_MODE);

            return $run;
        });
        $this->app->instance(StartRun::class, $spy);
    }

    /**
     * Build the newest change each time the command waits, as the queue
     * would: its context is compiled with the mode it carried, and it
     * spends tokens by mode. "Fails" lists the modes whose change fails.
     *
     * @param  list<string>  $fails
     */
    private function work(array $fails = [], ?string $compiledAs = null): void
    {
        Sleep::whenFakingSleep(function () use ($fails, $compiledAs) {
            $run = FeatureRequest::query()->latest('id')->firstOrFail()->latestRun;

            if ($run->status->finished()) {
                return;
            }

            Context::addHidden(CompileContext::TRIAL_MODE, $this->carried[$run->id]);
            $mode = app(CompileContext::class)->handle(new ProjectContext, [])->mode->value;
            Context::forgetHidden(CompileContext::TRIAL_MODE);

            $run->recordEvent('context_compiled', ['mode' => $compiledAs ?? $mode]);
            $run->recordEvent('model_call', ['input_tokens' => $mode === 'flat' ? 9000 : 6000, 'output_tokens' => 1000, 'cost_usd' => 0.25, 'tool_calls' => 12]);
            Verification::factory()->for($run->featureRequest)->create(['run_id' => $run->id, 'status' => VerificationStatus::Passed, 'finished_at' => now()]);
            $run->update(['status' => in_array($mode, $fails, true) ? RunStatus::Failed : RunStatus::Completed, 'started_at' => now()->subMinutes(5), 'finished_at' => now()]);
            $run->featureRequest->update(['status' => FeatureRequestStatus::Generated]);
        });
    }

    public function test_each_mode_makes_the_same_change_and_only_selective_is_kept()
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();
        $this->work();

        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'flat,selective'])->assertSuccessful();

        $trials = ContextTrial::query()->oldest('id')->get();
        $this->assertSame(['flat', 'selective'], $trials->map(fn (ContextTrial $trial) => $trial->mode->value)->all());
        $this->assertSame([1, 1], $trials->pluck('round')->all());
        $this->assertSame(['flat', 'selective'], array_values($this->carried), 'Each run carried its own mode.');
        $this->assertSame(['Let members book rooms.'], $trials->pluck('prompt')->unique()->values()->all());
        $this->assertSame(10000, $trials[0]->measures['tokens']);
        $this->assertTrue($trials[1]->measures['first_attempt_passed']);
        $this->assertSame(5.0, (float) $trials[1]->measures['minutes']);

        // Only selective is kept, so the next round builds on it; flat is put away.
        $this->assertNotNull($trials[1]->featureRequest->commit_sha);
        $this->assertNull($trials[0]->featureRequest->commit_sha);
        $this->assertNotNull($trials[0]->featureRequest->dismissed_at);

        // One pair is not a trend.
        $this->artisan('builder:context-results', ['project' => $project->id])
            ->expectsOutputToContain('too few pairs')
            ->assertSuccessful();

        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'flat,selective'])->assertSuccessful();
        $results = $this->resultsFor($project);
        $this->assertSame(2, $results['pairs']['flat']['pairs']);
        $this->assertTrue($results['pairs']['flat']['enough']);
        $this->assertEquals(3000, $results['pairs']['flat']['tokens'], 'Flat spends more tokens than selective, pair by pair.');
    }

    public function test_a_mode_whose_change_fails_is_left_out_of_the_pairs()
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();
        $this->work(fails: ['flat']);

        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'flat,selective'])->assertSuccessful();

        $this->assertSame(ContextTrial::OUTCOME_STOPPED, ContextTrial::query()->where('mode', 'flat')->sole()->outcome);
        $results = $this->resultsFor($project);
        $this->assertSame(0, $results['pairs']['flat']['pairs']);
        $this->assertSame(1, $results['modes']['flat']['trials']);
        $this->assertSame(0, $results['modes']['flat']['completed']);
    }

    public function test_unknown_modes_and_a_run_given_another_mode_measure_nothing()
    {
        $project = Project::factory()->for(User::factory(), 'owner')->create();

        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'flat,everything'])->assertFailed();
        // Selective is kept to move on, so it must be one of them.
        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'flat'])->assertFailed();
        $this->assertSame(0, $project->featureRequests()->count());

        // The agent got selective whatever the trial asked for.
        $this->work(compiledAs: 'selective');
        $this->artisan('builder:context-experiment', ['project' => $project->id, '--modes' => 'none,selective'])->assertSuccessful();

        $this->assertSame(ContextTrial::OUTCOME_WRONG_MODE, ContextTrial::query()->where('mode', 'none')->sole()->outcome);
        $results = $this->resultsFor($project);
        $this->assertSame(1, $results['wrong_mode']);
        $this->assertArrayNotHasKey('none', $results['modes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultsFor(Project $project): array
    {
        Artisan::call('builder:context-results', ['project' => $project->id, '--json' => true]);

        return json_decode(Artisan::output(), true);
    }
}
