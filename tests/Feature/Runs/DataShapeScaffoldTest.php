<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

class DataShapeScaffoldTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected FakeCodingAgent $agent;

    /** @var list<string> */
    protected array $found = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.agents.order' => ['claude'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);

        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);

        // The agent notes which files it found waiting, then adds a test.
        $agent = $this->agent = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            $root = config('workspaces.drivers.local.root').'/'.$workspace->driver_id;
            $this->found = array_values(array_filter(
                ['app/Models/Booking.php', 'app/Models/Team.php', 'app/Http/Requests/StoreBookingRequest.php', 'database/factories/BookingFactory.php'],
                fn (string $path) => File::exists("{$root}/{$path}") && ! str_contains(File::get("{$root}/{$path}"), 'class Team'),
            ));
            File::ensureDirectoryExists("{$root}/tests/Feature");
            File::put("{$root}/tests/Feature/BookingTest.php", "<?php\n\ntest('bookings', fn () => expect(true)->toBeTrue());\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.', null, null, turns: 2, inputTokens: 100, outputTokens: 50, costUsd: 0.01);
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $agent);
    }

    public function test_the_files_the_data_shape_fixes_are_written_before_the_agent_starts()
    {
        FeaturePlanner::fake([$this->plan([
            ['name' => 'Booking', 'fields' => [
                ['name' => 'team', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'Team'],
                ['name' => 'status', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'confirmed'], 'of' => null],
            ]],
        ])]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['app/Models/Booking.php', 'app/Http/Requests/StoreBookingRequest.php', 'database/factories/BookingFactory.php'], $this->found);

        $scaffolded = $run->events()->where('type', 'scaffolded')->sole()->data['files'];
        $this->assertCount(4, $scaffolded);
        $this->assertMatchesRegularExpression('#^database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_create_bookings_table\.php$#', $scaffolded[0]);

        // The scaffold is part of the change like any file the agent wrote.
        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertStringContainsString("+            \$table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();", $patch);
        $this->assertStringContainsString("+            'status' => ['required', 'in:pending,confirmed'],", $patch);
        $this->assertStringContainsString('+++ b/tests/Feature/BookingTest.php', $patch);

        $brief = $this->agent->tasks[0]->prompt;
        $this->assertStringContainsString('## Files already written from the data shape', $brief);
        $this->assertStringContainsString('- app/Models/Booking.php', $brief);
    }

    public function test_a_record_the_app_already_has_is_left_to_the_agent()
    {
        FeaturePlanner::fake([$this->plan([
            ['name' => 'Team', 'fields' => [['name' => 'motto', 'type' => 'string', 'required' => false, 'choices' => [], 'of' => null]]],
        ])]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame([], $this->found);
        $this->assertSame(0, $run->events()->where('type', 'scaffolded')->count());
        $this->assertStringNotContainsString('## Files already written', $this->agent->tasks[0]->prompt);
    }

    /**
     * @param  list<array<string, mixed>>  $shape
     * @return array<string, mixed>
     */
    protected function plan(array $shape): array
    {
        return [
            'summary' => 'Teams take bookings.',
            'acceptance_criteria' => ['A team can be booked.'],
            'assumptions' => [],
            'tasks' => ['Let people book a team.'],
            'capabilities' => [],
            'understood_as' => 'New feature',
            'current_behavior' => 'New',
            'preserve' => [],
            'data_shape' => $shape,
            'steps' => [[
                'key' => 'bookings',
                'kind' => 'data',
                'label' => 'Bookings',
                'file' => 'app/Models/Booking.php',
                'symbol' => 'Booking',
                'detail' => 'Keeps each booking.',
            ]],
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let people book a team.']);
    }
}
