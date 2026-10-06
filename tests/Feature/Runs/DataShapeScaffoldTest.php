<?php

namespace Tests\Feature\Runs;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\ShapePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

class DataShapeScaffoldTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    /**
     * The routes of a new app from the Laravel starter kits.
     */
    protected const ROUTES = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;

        Route::inertia('/', 'Welcome')->name('home');

        Route::middleware(['auth', 'verified'])->group(function () {
            Route::inertia('dashboard', 'Dashboard')->name('dashboard');
        });

        require __DIR__.'/settings.php';

        PHP;

    /**
     * A new record with a link and a choice.
     */
    protected const BOOKING = ['name' => 'Booking', 'label' => 'booking', 'fields' => [
        ['name' => 'team', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'Team', 'label' => 'the team booked'],
        ['name' => 'status', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'confirmed'], 'of' => '', 'label' => 'whether it is settled'],
    ], 'access' => null];

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
            // The shape is not asked about here; ShapeQuestionTest covers that.
            'builder.construction.questions.before_building' => 0,
        ]);

        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);

        // The agent notes which files it found waiting, then adds a test.
        $agent = $this->agent = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            $root = config('workspaces.drivers.local.root').'/'.$workspace->driver_id;
            $this->found = array_values(array_filter(
                ['app/Models/Booking.php', 'app/Models/Team.php', 'app/Http/Requests/StoreBookingRequest.php', 'database/factories/BookingFactory.php', 'app/Http/Controllers/BookingController.php'],
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
            ['name' => 'Booking', 'label' => 'booking', 'fields' => [
                ['name' => 'team', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'Team', 'label' => 'the team booked'],
                ['name' => 'status', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'confirmed'], 'of' => null, 'label' => 'whether it is settled'],
            ], 'access' => null],
        ])]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['app/Models/Booking.php', 'app/Http/Requests/StoreBookingRequest.php', 'database/factories/BookingFactory.php', 'app/Http/Controllers/BookingController.php'], $this->found);

        $scaffolded = $run->events()->where('type', 'scaffolded')->sole()->data;
        $this->assertCount(7, $scaffolded['files']);
        $this->assertSame([], $scaffolded['notes']);
        $this->assertMatchesRegularExpression('#^database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_create_bookings_table\.php$#', $scaffolded['files'][0]);
        $this->assertSame(['app/Http/Controllers/BookingController.php', 'app/Http/Requests/UpdateBookingRequest.php', 'routes/web.php'], array_slice($scaffolded['files'], 4));

        // The scaffold is part of the change like any file the agent wrote.
        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertStringContainsString("+            \$table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();", $patch);
        $this->assertStringContainsString("+            'status' => ['required', 'in:pending,confirmed'],", $patch);
        $this->assertStringContainsString('+++ b/tests/Feature/BookingTest.php', $patch);
        // The routes go with the app's routes for signed-in people.
        $this->assertStringContainsString("     Route::inertia('dashboard', 'Dashboard')->name('dashboard');\n+    Route::resource('bookings', BookingController::class)->only(['store', 'update', 'destroy']);\n });", $patch);
        $this->assertStringContainsString("+use App\\Http\\Controllers\\BookingController;\n use Illuminate\\Support\\Facades\\Route;", $patch);

        $brief = $this->agent->tasks[0]->prompt;
        $this->assertStringContainsString('## Files already written from the data shape', $brief);
        $this->assertStringContainsString('- app/Models/Booking.php', $brief);

        // The owner sees what is kept in their words, first among the
        // decisions made for them.
        $this->assertSame(
            ['text' => 'For each booking I keep: the team booked and whether it is settled (pending or confirmed).', 'level' => 'glance'],
            app(DescribeFeatureRequest::class)->handle($featureRequest)['run']['plan']['assumptions'][0],
        );
    }

    public function test_a_route_the_app_already_has_is_not_written_over_and_the_agent_is_told()
    {
        FeaturePlanner::fake([$this->plan([
            ['name' => 'Booking', 'fields' => [['name' => 'starts_at', 'type' => 'datetime', 'required' => true, 'choices' => [], 'of' => null]], 'access' => null],
        ])]);

        $routes = self::ROUTES."\nRoute::post('book', fn () => 'booked')->name('bookings.store');\n";
        $run = app(StartRun::class)->handle($featureRequest = $this->request(['routes/web.php' => $routes]))->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['app/Models/Booking.php', 'app/Http/Requests/StoreBookingRequest.php', 'database/factories/BookingFactory.php'], $this->found);

        $scaffolded = $run->events()->where('type', 'scaffolded')->sole()->data;
        $this->assertNotContains('routes/web.php', $scaffolded['files']);
        $this->assertSame(['Booking: the app already has a route bookings.store in routes/web.php, so no controller or routes were written for it. Add its actions beside that route.'], $scaffolded['notes']);
        $this->assertStringNotContainsString('+++ b/routes/web.php', (string) $featureRequest->refresh()->patch);
        $this->assertStringContainsString("## Left for you to write from the data shape\n\n- Booking: the app already has a route bookings.store", $this->agent->tasks[0]->prompt);
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

    public function test_the_shape_is_asked_for_with_the_plan_and_its_time_is_kept()
    {
        FeaturePlanner::fake([$this->plan([self::BOOKING])]);

        $run = app(StartRun::class)->handle($this->request(['app/Models/Team.php' => "<?php\n\nclass Team {}\n"]))->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        ShapePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, "## The plan\n\nTeams take bookings.")
            && str_contains($prompt->prompt, '- Let people book a team.')
            && str_contains($prompt->prompt, "## Models the app has\n\nTeam"));
        $planned = $run->events()->where('type', 'shape_planned')->sole()->data;
        $this->assertSame(['Booking'], $planned['records']);
        $this->assertIsInt($planned['duration_ms']);
        $this->assertSame('Booking', $run->plan['data_shape'][0]['name']);
    }

    public function test_a_change_that_stores_nothing_new_asks_for_no_shape()
    {
        FeaturePlanner::fake([[...$this->plan([]), 'new_records' => false]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        ShapePlanner::assertNeverPrompted();
        $this->assertSame(0, $run->events()->where('type', 'shape_planned')->count());
        $this->assertSame([], $run->plan['data_shape']);
    }

    public function test_an_answer_or_a_question_for_the_owner_asks_for_no_shape()
    {
        FeaturePlanner::fake([[...$this->plan([self::BOOKING]), 'answer' => 'Teams cannot be booked yet.', 'acceptance_criteria' => [], 'cases' => [], 'tasks' => [], 'steps' => []]]);
        app(StartRun::class)->handle($this->request());

        config(['builder.construction.questions.before_building' => 1, 'builder.construction.questions.ask_about' => ['money']]);
        FeaturePlanner::fake([[...$this->plan([self::BOOKING]), 'question' => [
            'text' => 'Do bookings cost money?', 'why' => 'It decides whether people pay.', 'options' => ['Yes', 'No'], 'recommended' => 'No',
            'touches' => ['money'], 'reversible' => false, 'easier_after_seeing' => false,
        ]]]);
        $asked = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $asked->status);
        ShapePlanner::assertNeverPrompted();
    }

    public function test_a_new_record_with_a_choice_reaches_the_shape_question_and_waits_for_the_owner()
    {
        config(['builder.construction.questions.before_building' => 1, 'builder.construction.questions.ask_about' => ['data_shape']]);
        FeaturePlanner::fake([$this->plan([self::BOOKING])]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame(StopReason::Question, $run->stop_reason);
        $this->assertNotEmpty($run->question['text']);
        ShapePlanner::assertPrompted(fn () => true);
    }

    public function test_a_shape_that_does_not_hold_together_is_dropped_and_the_change_goes_on()
    {
        FeaturePlanner::fake([$this->plan([['name' => 'booking', 'label' => 'booking', 'fields' => [], 'access' => null]])]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame([], $run->plan['data_shape']);
        $this->assertSame(['records' => 1], $run->events()->where('type', 'shape_dropped')->sole()->data);
        $this->assertSame(0, $run->events()->where('type', 'scaffolded')->count());
    }

    public function test_an_ai_service_out_of_credit_stops_the_change_at_the_shape()
    {
        FeaturePlanner::fake([$this->plan([])]);
        ShapePlanner::fake(fn () => throw new RequestException(new Response(new Psr7Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['message' => 'Your credit balance is too low', 'type' => 'invalid_request_error']])))));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(StopReason::OutOfCredit, $run->stop_reason);
        $this->assertSame(StopReason::OutOfCredit->said(), $run->error);
    }

    /**
     * The planner's answer for a change that stores new records. The shape
     * planner answers with the records.
     *
     * @param  list<array<string, mixed>>  $shape
     * @return array<string, mixed>
     */
    protected function plan(array $shape): array
    {
        ShapePlanner::fake([['data_shape' => $shape]]);

        return [
            'summary' => 'Teams take bookings.',
            'acceptance_criteria' => ['A team can be booked.'],
            'cases' => [['base' => 'A team is booked for a free time.', 'alternate' => null, 'no_alternate' => 'There is one way to book a team.', 'exception' => 'Booking a team you cannot see is refused.', 'no_exception' => null]],
            'assumptions' => [],
            'tasks' => ['Let people book a team.'],
            'capabilities' => [],
            'understood_as' => 'New feature',
            'current_behavior' => 'New',
            'preserve' => [],
            'new_records' => true,
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

    /**
     * @param  array<string, string>  $files
     */
    protected function request(array $files = []): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource($files + ['routes/web.php' => self::ROUTES])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let people book a team.']);
    }
}
