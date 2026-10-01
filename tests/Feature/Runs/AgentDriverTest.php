<?php

namespace Tests\Feature\Runs;

use App\Actions\Features\RequestFollowUp;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Tests\Concerns\PreparesRuns;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * How a change is planned, repaired and reviewed around the coding agent,
 * which each test scripts attempt by attempt.
 */
class AgentDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase, UsesReferenceSolutions;

    protected FakeCodingAgent $coder;

    protected const TEAM = "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n}\n";

    protected const DESCRIPTION_TEST = "<?php\n\ntest('teams have a nullable description', fn () => expect(true)->toBeTrue());\n";

    protected const TEAM_WITH_DESCRIPTION = "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n\n    public ?string \$description = null;\n}\n";

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.agents.order' => ['claude'],
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model'],
            'builder.agents.reviewers.anthropic' => ['provider' => 'openai', 'model' => 'reviewer-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
            'ai.providers.openai.key' => 'openai-test-key',
        ]);

        $this->coder();
    }

    public function test_the_plan_is_made_on_the_next_provider_when_the_planners_is_out_of_credit()
    {
        config([
            'builder.models.failover' => ['openai'],
            'ai.providers.openai.key' => 'openai-test-key',
        ]);
        FeaturePlanner::fake(fn (string $prompt, $attachments, Provider $provider) => $provider->name() === 'anthropic'
            ? throw InsufficientCreditsException::forProvider('anthropic')
            : $this->plan());

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame('Teams get an optional description.', $run->plan['summary']);
        $this->assertSame('openai', $run->events()->where('type', 'model_call')->where('data->role', 'planner')->sole()->data['provider']);
    }

    public function test_a_planner_that_turns_every_request_away_stops_the_run_and_says_why()
    {
        FeaturePlanner::fake(fn () => throw RateLimitedException::forProvider('anthropic', 429));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        // The owner reads why and decides; the job is not retried as if it
        // had crashed, and nothing is built.
        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('This is our fault: the AI service we use is turning requests away because we sent too many. Nothing in your app changed. Try again in a few minutes.', $run->error);
        $this->assertSame([], $this->coder->tasks);
    }

    public function test_the_planner_is_told_each_address_and_the_code_that_handles_it()
    {
        FeaturePlanner::fake([$this->plan()]);
        $routes = json_encode([
            ['method' => 'GET|HEAD', 'uri' => 'teams/{team}', 'name' => 'teams.show', 'action' => 'App\\Http\\Controllers\\TeamController@show', 'middleware' => ['web']],
            ['method' => 'POST', 'uri' => '/', 'name' => null, 'action' => 'Closure', 'middleware' => []],
        ]);

        $routes = base64_encode((string) $routes);

        app(StartRun::class)->handle($this->request(['artisan' => "<?php\n\necho base64_decode('{$routes}');\n"]));

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, "## Addresses in the app\n\nEach address and the code that handles it.\n\n- GET /teams/{team} → TeamController@show (teams.show)\n- POST / → Closure\n"));
    }

    public function test_the_planner_gets_no_addresses_from_an_app_that_cannot_list_them()
    {
        FeaturePlanner::fake([$this->plan()]);

        app(StartRun::class)->handle($this->request(['artisan' => "<?php\n\nexit(1);\n"]));

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '## Project files')
            && ! str_contains($prompt->prompt, '## Addresses in the app'));
    }

    public function test_the_planner_coder_and_reviewer_build_and_accept_a_verified_change()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder($this->writes([
            'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
            'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST,
        ], 'I added a description to teams and every test passes.'));
        ChangeReviewer::fake([['approved' => true, 'summary' => 'The diff adds the field the plan asks for.', 'findings' => [], 'verify' => [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description'],
        ]]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame('Teams get an optional description.', $run->plan['summary']);
        $this->assertSame(['Teams have a nullable description.'], $run->plan['acceptance_criteria']);
        $this->assertSame([], $run->plan['acceptance']);

        $featureRequest->refresh();
        $this->assertSame(FeatureRequestStatus::Generated, $featureRequest->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $featureRequest->patch);
        $this->assertSame('description-field', $featureRequest->steps[0]['key']);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Give teams a description')
            && str_contains($prompt->prompt, 'app/Models/: Team.php')
            && $prompt->model === 'planner-model');
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Add a nullable description property.')
            && str_contains($prompt, "## Write as the app's own developer")
            && preg_match('/platform|control plane|inspector/i', $prompt) === 0);

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame([[
            'criterion' => 'Teams have a nullable description.',
            'test_file' => 'tests/Feature/TeamDescriptionTest.php',
            'test_name' => 'teams have a nullable description',
            'evidence' => 'tested',
            'named_in_diff' => true,
        ]], $run->review['verified']);
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'diff --git a/app/Models/Team.php')
            && str_contains($prompt->prompt, '1. Teams have a nullable description.')
            && ! str_contains($prompt->prompt, 'every test passes')
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'reviewer-model');

        $calls = $run->events()->where('type', 'model_call')->get()->pluck('data');
        $this->assertSame(['planner', 'coder', 'reviewer'], $calls->pluck('role')->all());
        $this->assertSame(['planner-model', 'reviewer-model'], $calls->where('role', '!=', 'coder')->pluck('model')->values()->all());
        $this->assertSame('I added a description to teams and every test passes.', $run->events()->where('type', 'build_finished')->sole()->data['account']);
    }

    public function test_a_question_about_the_app_is_answered_without_building_anything()
    {
        FeaturePlanner::fake([[
            'summary' => 'Only team owners can invite people.',
            'answer' => "Only a team's owner can invite people. Members cannot.",
            'understood_as' => 'Question',
            'current_behavior' => 'Owners invite members.',
            'commit_subject' => '',
            'acceptance_criteria' => [],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'preserve' => [],
            'capabilities' => [],
            'question' => null,
        ]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame("Only a team's owner can invite people. Members cannot.", $run->plan['answer']);
        $this->assertSame(WorkspaceStatus::Destroyed, $run->workspace->status);
        $this->assertSame([], $this->coder->tasks);
        ChangeReviewer::assertNeverPrompted();

        $featureRequest->refresh();
        $this->assertSame(FeatureRequestStatus::Answered, $featureRequest->status);
        $this->assertNull($featureRequest->patch);
        $this->assertSame('I answered your question', $featureRequest->user->notifications()->sole()->data['title']);

        $this->actingAs($featureRequest->user)
            ->get(route('projects.show', ['project' => $featureRequest->project, 'change' => $featureRequest->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'answered')
                ->where('change.run.plan.answer', "Only a team's owner can invite people. Members cannot.")
                ->where('change.featureRequest.can_accept', false));

        $this->actingAs($featureRequest->user)
            ->post(route('feature-requests.acceptance.store', $featureRequest))
            ->assertSessionHasErrors('change');
    }

    public function test_a_message_after_an_answer_continues_the_chat_and_builds_on_the_app_as_it_is()
    {
        $answer = [
            'summary' => 'Teams have only a name.',
            'answer' => 'Teams have only a name.',
            'understood_as' => 'Question',
            'current_behavior' => 'Teams have a name.',
            'commit_subject' => '',
            'acceptance_criteria' => [],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'preserve' => [],
            'capabilities' => [],
            'question' => null,
        ];
        FeaturePlanner::fake([$answer, $answer]);
        $question = $this->request();
        app(StartRun::class)->handle($question);

        $followUp = app(RequestFollowUp::class)->handle($question->refresh(), $question->user, 'Then give teams a description.');

        $this->assertSame(FeatureRequestStatus::Answered, $followUp->refresh()->status);
        $this->assertSame([$followUp->id], array_map(fn (FeatureRequest $request) => $request->id, $followUp->lineage()));
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, "## This follows an earlier question\n\nEarlier question: Give teams a description.\n\nThe answer given: Teams have only a name.")
            && ! str_contains($prompt->prompt, 'This changes an earlier feature'));
    }

    public function test_the_platform_not_the_planner_chooses_the_protected_suites_including_for_follow_ups()
    {
        $solutions = $this->useReferenceSolutions();
        config(['builder.generator' => 'reference']);
        FeaturePlanner::fake([
            [...$this->plan(), 'acceptance' => ['Invitations/AlwaysPasses.php']],
            $this->plan(),
        ]);
        $this->coder(
            $this->writes(['app/Invitation.php' => "<?php\n"]),
            $this->writes(['app/OwnerOnly.php' => "<?php\n"]),
        );
        $project = Project::factory()->create(['source_path' => "{$solutions}/source"]);
        $parent = FeatureRequest::factory()->for($project)->create(['prompt' => 'Let owners invite people.']);

        $parentRun = app(StartRun::class)->handle($parent)->refresh();

        $this->assertSame(['Invitations/ContractTest.php'], $parentRun->plan['acceptance']);
        $this->assertSame('team-invitations', $parent->refresh()->solution_key);

        // The planner named its own step, which the manifest does not know.
        $followUp = FeatureRequest::factory()->for($project)->create([
            'parent_id' => $parent->id,
            'target_step' => 'description-field',
            'prompt' => 'Only the owner may do this.',
        ]);

        $followUpRun = app(StartRun::class)->handle($followUp)->refresh();

        $this->assertSame('owner-only-invitations', $followUpRun->plan['solution_key']);
    }

    public function test_a_plan_that_is_invalid_twice_fails_the_run_with_a_reason()
    {
        $invalid = ['summary' => 'Something.', 'acceptance_criteria' => [], 'assumptions' => [], 'tasks' => [], 'steps' => []];
        FeaturePlanner::fake([$invalid, $invalid]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringStartsWith('The planner returned an invalid plan:', (string) $run->error);
        $this->assertSame(FeatureRequestStatus::Failed, $featureRequest->refresh()->status);
        $this->assertSame([], $this->coder->tasks);
        FeaturePlanner::assertPrompted(fn ($prompt) => $prompt->contains('Your previous plan was rejected'));
    }

    public function test_an_invalid_plan_is_asked_for_once_more_with_what_was_wrong()
    {
        FeaturePlanner::fake([['summary' => 'Something.', 'acceptance_criteria' => [], 'assumptions' => [], 'tasks' => [], 'steps' => []], $this->plan()]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertNotNull($run->plan);
        $this->assertSame(1, $run->events()->where('type', 'plan_rejected')->count());
        $this->assertSame(2, $run->events()->where('type', 'model_call')->where('data->role', 'planner')->count());
        FeaturePlanner::assertPrompted(fn ($prompt) => $prompt->contains('The steps field is required.'));
    }

    public function test_a_failed_verification_sends_the_change_back_to_the_coder_with_the_failures()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION]),
            $this->writes(['app/Team.php' => "<?php\n"], 'Fixed.'),
        );

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->failVerification($run, 'Tests: 1 failed. Expected description to be fillable.');

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertNull($run->feedback);
        $this->assertCount(2, $this->coder->tasks);
        $this->assertStringNotContainsString('Fix these problems', $this->coder->tasks[0]->prompt);
        $this->assertStringContainsString('Fix these problems', $this->coder->tasks[1]->prompt);
        $this->assertStringContainsString('Expected description to be fillable.', $this->coder->tasks[1]->prompt);
        $this->assertStringContainsString('app/Team.php', (string) $run->featureRequest->patch);
        $this->assertStringContainsString('app/Models/Team.php', (string) $run->featureRequest->patch);
        FeaturePlanner::assertPromptedTimes(1);
    }

    public function test_blocking_review_findings_are_repaired_until_the_repair_budget_is_used()
    {
        config(['builder.construction.budgets.repairs' => 1]);
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION]),
            $this->writes(['app/Team.php' => "<?php\n"], 'Fixed.'),
        );
        $finding = ['severity' => 'blocking', 'summary' => 'Anyone can edit the description.', 'file' => 'app/Models/Team.php'];
        ChangeReviewer::fake([
            ['approved' => true, 'summary' => 'Looks fine apart from authorization.', 'findings' => [$finding]],
            ['approved' => false, 'summary' => 'Still missing authorization.', 'findings' => [$finding]],
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status, 'An approval with a blocking finding must not complete the run.');
        $this->assertSame(1, $run->repairs);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'app/Models/Team.php: Anyone can edit the description.'));

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The review found problems this run cannot fix: Still missing authorization.', $run->error);
    }

    public function test_a_verify_item_without_a_test_in_the_change_sends_the_change_back_even_when_the_reviewer_approves()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION]),
            $this->writes(['tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST], 'Added the test.'),
        );
        ChangeReviewer::fake([
            ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'it has a description']]],
            ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]],
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertSame('no_test', $run->review['verified'][0]['evidence']);
        $this->assertFalse($run->review['approved']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'No test in the change checks: Teams have a nullable description.'));

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('tested', $run->review['verified'][0]['evidence']);
    }

    public function test_the_coder_sees_the_pictures_the_owner_attached_and_they_never_enter_the_change()
    {
        Storage::fake('local');
        Storage::disk('local')->put('request-images/1/sketch.png', 'png bytes');
        FeaturePlanner::fake([$this->plan()]);
        $seen = null;
        $this->coder(function (Workspace $workspace) use (&$seen) {
            $seen = File::get(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/.git/attachments/1.png");

            return $this->writes([
                'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
                'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST,
            ])($workspace);
        });
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => []]]);
        $featureRequest = $this->request();
        $featureRequest->update(['images' => [['path' => 'request-images/1/sketch.png', 'name' => 'Sketch.png']]]);

        $run = app(StartRun::class)->handle($featureRequest)->refresh();
        $this->passVerification($run);

        $this->assertSame('png bytes', $seen);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, "## Pictures the owner attached\n\nThe owner attached these to show what they mean.")
            && str_contains($prompt, '- .git/attachments/1.png'));
        // The planner and reviewer see the picture too.
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'The owner attached a picture that shows what they mean. Match what it shows')
            && $prompt->attachments->sole() instanceof StoredImage
            && $prompt->attachments->sole()->path === 'request-images/1/sketch.png');
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1);
        $this->assertStringNotContainsString('attachments', (string) $featureRequest->refresh()->patch);
    }

    public function test_a_safety_mistake_the_change_adds_sends_it_back_even_when_the_reviewer_approves()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes([
                'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
                'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST,
                'resources/views/team.blade.php' => "<h1>{{ \$team->name }}</h1>\n<p>{!! \$team->description !!}</p>\n",
            ]),
            $this->writes(['resources/views/team.blade.php' => "<h1>{{ \$team->name }}</h1>\n<p>{{ \$team->description }}</p>\n"], 'Escaped it.'),
        );
        $approve = ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]];
        ChangeReviewer::fake([$approve, $approve]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(1, $run->repairs);
        $this->assertFalse($run->review['approved']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Line 2 of resources/views/team.blade.php shows text on a page without escaping it'));

        $this->passVerification($run);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
    }

    public function test_a_made_up_colour_the_change_adds_sends_it_back_to_use_the_theme()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes([
                'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
                'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST,
                'resources/js/pages/Team.vue' => "<template>\n    <p class=\"text-[#6b7280]\">{{ team.description }}</p>\n</template>\n",
            ]),
            $this->writes(['resources/js/pages/Team.vue' => "<template>\n    <p class=\"text-muted-foreground\">{{ team.description }}</p>\n</template>\n"], 'Used the theme.'),
        );
        $approve = ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]];
        ChangeReviewer::fake([$approve, $approve]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(1, $run->repairs);
        $this->assertFalse($run->review['approved']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Line 2 of resources/js/pages/Team.vue makes up a colour'));

        $this->passVerification($run);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
    }

    public function test_words_cut_off_on_a_phone_send_the_change_back_to_fit_the_screen()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes([
                'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
                'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST,
                'resources/js/pages/Team.vue' => "<template>\n    <p class=\"w-[700px]\">{{ team.description }}</p>\n</template>\n",
            ]),
            $this->writes(['resources/js/pages/Team.vue' => "<template>\n    <p class=\"max-w-full\">{{ team.description }}</p>\n</template>\n"], 'Let it wrap.'),
        );
        $approve = ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]];
        ChangeReviewer::fake([$approve, $approve]);
        $page = fn (array $cut) => ['pages' => [['path' => '/team', 'status' => 200, 'final' => '/team', 'screen' => 'Team', 'widths' => [
            ['width' => 390, 'overflow' => 0, 'cut_off' => count($cut), 'cut' => $cut, 'small_targets' => 0, 'small' => [], 'errors' => []],
        ]]], 'signed_in' => true];

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run, screens: $page([['text' => 'A team for designers', 'width' => 700, 'past' => 310]]));

        $run->refresh();
        $this->assertSame(1, $run->repairs);
        $this->assertFalse($run->review['approved']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'At 390 px wide, "A team for designers" on /team (resources/js/pages/Team.vue) runs 310 px past the edge of the screen'));

        $this->passVerification($run, screens: $page([]));

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
    }

    public function test_a_shortcut_is_kept_for_later_and_never_holds_the_change_back()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder($this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST]));
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run, shortcuts: [['rule' => 'SL107', 'path' => 'app/Models/Team.php', 'line' => 7]]);

        // The owner is not kept waiting for it: it is dealt with after.
        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(0, $run->repairs);
        $this->assertSame([['rule' => 'SL107', 'path' => 'app/Models/Team.php', 'line' => 7]], $run->verifications()->latest('id')->first()->shortcuts);
    }

    public function test_the_reviewer_reads_what_running_the_app_with_and_without_the_change_showed()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder($this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST]));
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run, evidence: [
            'new_tests' => [
                ['file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'teams have a nullable description', 'without_change' => 'failed'],
                ['file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'the team page loads', 'without_change' => 'passed'],
            ],
            'routes' => [
                'added' => [['route' => 'POST /teams/{team}/archive', 'middleware' => ['web']]],
                'removed' => ['GET /old'],
                'changed' => [['route' => 'GET /teams', 'lost' => ['auth'], 'gained' => ['throttle:6,1']]],
            ],
            'new_code' => ['lines' => 10, 'run' => 8, 'own_tests_only' => 5, 'unrun' => ['app/Models/Team.php' => [12, 13]]],
            'traces' => ['requests' => 40, 'reached' => 6, 'unseen' => 2, 'existing' => 1, 'repeats' => [], 'findings' => [
                ['kind' => 'saved_on_read', 'route' => 'GET /teams', 'what' => 'update teams', 'at' => 'app/Models/Team.php:12', 'test' => 'Tests\Feature\TeamDescriptionTest::test_the_team_page_loads'],
                ['kind' => 'sent_before_saved', 'route' => 'POST /teams', 'what' => 'mail App\Mail\TeamCreated', 'at' => null, 'test' => null],
            ]],
            'faults' => ['points' => 5, 'run' => 3, 'missed' => 1, 'existing' => 1, 'findings' => [
                ['kind' => 'saved_then_failed', 'route' => 'POST /teams', 'failed' => 'mail App\Mail\TeamCreated', 'what' => 'insert teams, insert team_user', 'at' => 'app/Models/Team.php:13', 'test' => 'Tests\Feature\TeamDescriptionTest::test_owners_create_teams'],
                ['kind' => 'sent_then_lost', 'route' => 'POST /teams', 'failed' => 'insert team_user', 'what' => 'job App\Jobs\SyncSeats', 'at' => null, 'test' => 'Tests\Feature\TeamDescriptionTest::test_owners_create_teams'],
            ]],
        ]);

        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, implode("\n\n", [
            '## What running the app with and without the change showed',
            "The change added 2 tests. Tests that fail without its code, as a test of new behaviour must: 1. These pass without it, so they do not check what it does:\n- tests/Feature/TeamDescriptionTest.php: the team page loads",
            "Routes it added, with their middleware:\n- POST /teams/{team}/archive [web]",
            "Routes whose middleware it changed:\n- GET /teams lost auth gained throttle:6,1",
            "Routes it removed:\n- GET /old",
            "Of its 10 new lines of PHP that can run, tests ran 8; 5 of those only its own tests ran. No test ran:\n- app/Models/Team.php: line 12, 13",
            'While the tests ran, 40 requests to the app were recorded: their queries, transactions and what they sent. 6 ran code the change added. Recorded from the code the change added:'
                ."\n- GET /teams saved data on a request that only reads: update teams at app/Models/Team.php:12 (seen in Tests\Feature\TeamDescriptionTest::test_the_team_page_loads)"
                ."\n- POST /teams sent this while a database transaction was still open, so it goes out even when the transaction is rolled back: mail App\Mail\TeamCreated"
                ."\nOf the requests that ran the change's code, 2 opened a transaction in a test that fakes mail, jobs or notifications, so what they sent, and when, was not seen.",
            "One failure at a time was caused in requests that ran the change's code: an email that could not be sent, an outside call that got no answer, or a save the database refused. Of 5 places where those requests send or save, 4 were tried and the failure happened in 3. What the app left behind:"
                ."\n- POST /teams: when mail App\Mail\TeamCreated failed at app/Models/Team.php:13, the request ended in a server error but had already saved: insert teams, insert team_user (caused in Tests\Feature\TeamDescriptionTest::test_owners_create_teams)"
                ."\n- POST /teams: when insert team_user failed, the save was rolled back but the request had already sent: job App\Jobs\SyncSeats (caused in Tests\Feature\TeamDescriptionTest::test_owners_create_teams)",
        ])));
    }

    public function test_a_named_test_that_did_not_run_is_not_evidence_and_sends_the_change_back()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST]),
            $this->writes([], 'Nothing to change; the test is there.'),
        );
        ChangeReviewer::fake([
            ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams keep their colour']]],
            ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']]],
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(1, $run->repairs);
        $this->assertSame('not_run_by_checks', $run->review['verified'][0]['evidence']);
        $this->assertFalse($run->review['approved']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'The test "teams keep their colour" for "Teams have a nullable description." did not run in the test suite'));

        $this->passVerification($run);

        $this->assertSame('tested', $run->refresh()->review['verified'][0]['evidence']);
    }

    public function test_a_test_the_checks_do_not_run_is_sent_back_before_verification_and_review()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'resources/js/pages/Team.test.ts' => "it('has a description', () => {});\n"]),
            $this->writes(['tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST], 'Moved the test.'),
        );
        ChangeReviewer::fake([]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertSame(1, $run->verifications()->count(), 'Only the repaired change is verified.');
        $this->assertSame(['resources/js/pages/Team.test.ts'], $run->events()->where('type', 'status')->where('data->reason', 'tests_not_run')->sole()->data['files']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'The checks do not run resources/js/pages/Team.test.ts'));
        ChangeReviewer::assertNeverPrompted();
    }

    public function test_a_script_test_under_the_test_folder_is_not_run_by_the_checks_either()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'tests/Frontend/Team.test.ts' => "it('has a description', () => {});\n"]),
            $this->writes(['tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST], 'Moved the test.'),
        );
        ChangeReviewer::fake([]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['tests/Frontend/Team.test.ts'], $run->events()->where('type', 'status')->where('data->reason', 'tests_not_run')->sole()->data['files']);
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Only tests under tests/, in a file whose name ends in Test.php are run by the checks'));
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'The checks do not run tests/Frontend/Team.test.ts'));
    }

    public function test_the_coder_is_asked_to_keep_what_the_app_does_easy_to_see()
    {
        FeaturePlanner::fake([$this->plan()]);

        app(StartRun::class)->handle($this->request());

        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Make it easy to see what the app does')
            && str_contains($prompt, 'never class, table or route names'));
    }

    public function test_an_app_nobody_uses_yet_is_changed_in_place_without_keeping_the_old_way()
    {
        FeaturePlanner::fake([$this->plan()]);
        $request = $this->request();
        $request->project->forceFill(['started_here' => true])->save();

        app(StartRun::class)->handle($request);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '## No need to keep the old way working'));
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'Do not add fallbacks, aliases, old names or support for the old way.')
            && ! str_contains($prompt, "## Keep the app's information and links working"));
    }

    public function test_a_published_or_imported_app_moves_its_data_forward_before_keeping_an_old_way()
    {
        FeaturePlanner::fake([$this->plan(), $this->plan()]);
        $published = $this->request();
        $published->project->forceFill(['started_here' => true])->save();
        Deployment::factory()->for($published->project)->create(['status' => DeploymentStatus::Published, 'finished_at' => now()]);

        app(StartRun::class)->handle($published);
        // An app brought in from outside may already serve people.
        app(StartRun::class)->handle($this->request());

        $this->assertSame(2, collect($this->coder->tasks)->filter(fn (AgentTask $task) => str_contains($task->prompt, 'prefer a migration that carries the existing data to the new shape')
            && ! str_contains($task->prompt, '## No need to keep the old way working'))->count());
    }

    public function test_the_agent_is_told_how_to_use_the_services_the_app_is_connected_to()
    {
        FeaturePlanner::fake([$this->plan()]);
        $request = $this->request();
        $request->project->forceFill(['service_keys' => ['payments' => ['STRIPE_KEY' => 'pk_test_a', 'STRIPE_SECRET' => 'sk_test_b']]])->save();

        app(StartRun::class)->handle($request);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '## Outside services the app uses')
            && str_contains($prompt->prompt, 'Laravel Cashier'));
        // The names of the keys, never the keys.
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, 'STRIPE_SECRET environment variables')
            && ! str_contains($prompt, 'sk_test_b')
            && ! str_contains($prompt, 'Resend'));
    }

    public function test_the_owners_choice_about_the_old_way_outranks_whether_the_app_is_used()
    {
        FeaturePlanner::fake([$this->plan()]);
        $request = $this->request();
        // Imported, so it may be in use, but the owner says nothing depends on it.
        $request->project->forceFill(['keep_old_working' => false])->save();

        $run = app(StartRun::class)->handle($request);

        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, '## No need to keep the old way working'));
        $this->assertSame(['keep_old_working' => false, 'chosen_by_owner' => true], $run->events()->where('type', 'compatibility')->sole()->data);
    }

    public function test_the_reviewer_sees_tests_the_change_deletes()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder($this->writes(['tests/Feature/TeamTest.php' => null], 'Removed a slow test.'));
        ChangeReviewer::fake([['approved' => false, 'summary' => 'Deletes a test.', 'findings' => [['severity' => 'blocking', 'summary' => 'A test was deleted.', 'file' => 'tests/Feature/TeamTest.php']]]]);
        config(['builder.construction.budgets.repairs' => 0]);

        $run = app(StartRun::class)->handle($this->request([
            'tests/Feature/TeamTest.php' => "<?php\n\ntest('teams have names', fn () => expect(true)->toBeTrue());\n",
        ]))->refresh();
        $this->passVerification($run);

        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"path": "tests/Feature/TeamTest.php"')
            && str_contains($prompt->prompt, '"deleted": true'));
        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
    }

    public function test_the_agents_get_the_selected_project_context_and_the_review_sorts_changes_by_area()
    {
        $config = "<?php\n\nreturn [\n    'owner' => ['members:invite'],\n];\n";
        FeaturePlanner::fake([[...$this->plan(), 'capabilities' => ['teams', 'unknown'], 'understood_as' => 'Data change', 'current_behavior' => 'Teams have only a name.', 'preserve' => [
            ['area' => 'teams', 'statement' => 'A team always has a name.'],
            ['area' => 'account', 'statement' => 'People can still sign up.'],
            ['area' => 'settings', 'statement' => 'Owners can still invite.'],
            ['area' => null, 'statement' => 'Nothing else changes.'],
        ]]]);
        $this->coder($this->writes([
            'app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION,
            'config/billing.php' => "<?php\n\nreturn [];\n",
            'config/teams.php' => str_replace('members:invite', 'members:remove', $config),
            'app/Other.php' => "<?php\n",
            'tests/Feature/TeamTest.php' => self::DESCRIPTION_TEST,
        ]));
        ChangeReviewer::fake([[
            'approved' => true,
            'summary' => 'Adds the description.',
            'findings' => [],
            'verify' => [['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a nullable description']],
            'changes' => [
                ['area' => 'teams', 'behavior' => 'Team details', 'before' => 'Teams had a name.', 'now' => 'Teams can also have a description.'],
                ['area' => 'settings', 'behavior' => 'Removing members', 'before' => 'Owners could invite.', 'now' => 'Owners can remove members instead.'],
                ['area' => 'made-up', 'behavior' => 'Something else', 'before' => 'Before.', 'now' => 'Now.'],
            ],
        ]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request([
            '.builder/project.md' => "# Sparkle Cleaning\n\nWe call customers clients.\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: Clients belong to teams.\npaths: [app/Models/Team.php, tests/Feature/TeamTest.php]\neffects:\n    - to: billing\n      strength: possible\n      reason: Each team is billed separately.\n      source: owner\n---\n# Teams\n\nA team always has a name.\n",
            '.builder/capabilities/billing.md' => "---\ncapability: billing\nsummary: Invoices for teams.\npaths: [config/billing.php]\n---\n# Billing\n\nOnly owners see invoices.\n",
            '.builder/capabilities/settings.md' => "---\ncapability: settings\npaths: [config/teams.php]\n---\n# Settings\n",
            '.builder/capabilities/account.md' => "---\ncapability: account\npaths: [app/Account.php]\n---\n# Account\n",
            'app/Account.php' => "<?php\n",
            'tests/Feature/TeamTest.php' => "<?php\n",
        ]))->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame('selective', $run->context['mode']);
        $this->assertSame(['teams'], $run->context['targets']);
        $this->assertSame(['project.md', 'capabilities/teams.md', 'index'], array_column($run->context['included'], 'file'));
        $this->assertGreaterThan(0, $run->events()->where('type', 'context_compiled')->sole()->data['tokens']);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'We call customers clients.')
            && str_contains($prompt->prompt, '- teams: Teams. Clients belong to teams.')
            && ! str_contains($prompt->prompt, 'A team always has a name.'));
        $this->assertCoderPrompted(fn (string $prompt) => str_contains($prompt, "## What it does now\n\nTeams have only a name.")
            && str_contains($prompt, "## Keep as it is\n\nDo not change these.")
            && str_contains($prompt, '- People can still sign up.')
            && str_contains($prompt, 'A team always has a name.')
            && str_contains($prompt, '- Billing (possible): Each team is billed separately.')
            && str_contains($prompt, '(capabilities/billing.md)')
            && ! str_contains($prompt, 'Only owners see invoices.'));

        $this->passVerification($run, [['file' => 'tests/Feature/TeamTest.php', 'name' => 'teams have a nullable description', 'outcome' => 'passed']]);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame([
            'requested' => ['teams' => ['app/Models/Team.php', 'tests/Feature/TeamTest.php']],
            'may_also_affect' => ['billing' => ['config/billing.php']],
            'unexpected' => ['settings' => ['config/teams.php']],
            'unclaimed' => ['app/Other.php'],
            'context_updates' => [],
            'targets' => ['teams'],
            // No test map was made for this project.
            'observed' => null,
        ], $run->review['classification']);
        $this->assertSame(['requested', 'unexpected', 'other'], array_column($run->review['changes'], 'section'));
        $this->assertSame([
            ['area' => 'teams', 'statement' => 'A team always has a name.', 'evidence' => 'verified', 'unchanged' => false, 'tests' => 1],
            ['area' => 'account', 'statement' => 'People can still sign up.', 'evidence' => 'untouched', 'unchanged' => true, 'tests' => 0],
            ['area' => 'settings', 'statement' => 'Owners can still invite.', 'evidence' => 'not_checked', 'unchanged' => false, 'tests' => 0],
            ['area' => null, 'statement' => 'Nothing else changes.', 'evidence' => 'not_checked', 'unchanged' => false, 'tests' => 0],
        ], $run->review['preserved']);
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '## Areas this change touched')
            && str_contains($prompt->prompt, '- settings (not expected): Settings; config/teams.php')
            && str_contains($prompt->prompt, 'Files no area claims: app/Other.php')
            && str_contains($prompt->prompt, "## Must stay as it is\n\n- A team always has a name."));

        $this->actingAs($featureRequest->project->owner)
            ->get(route('feature-requests.show', $featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('run.context')
                ->where('run.review.areas.requested.0.name', 'Teams')
                ->where('run.review.areas.may_also_affect.0.files', ['config/billing.php'])
                ->where('run.review.areas.unexpected.0.name', 'Settings')
                ->where('run.review.changes.1.area_name', 'Settings')
                ->where('run.review.changes.1.section', 'unexpected')
                ->where('run.review.unclaimed', ['app/Other.php'])
                ->where('run.plan.understood_as', 'Data change')
                ->where('run.plan.preserve.1', 'People can still sign up.')
                ->where('run.review.preserved.0.area_name', 'Teams')
                ->where('run.review.preserved.1.evidence', 'untouched'));
    }

    public function test_a_context_file_that_cannot_be_read_is_reported_and_does_not_stop_the_run()
    {
        FeaturePlanner::fake([[...$this->plan(), 'capabilities' => ['teams']]]);

        $run = app(StartRun::class)->handle($this->request([
            '.builder/capabilities/teams.md' => "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n# Teams\n",
            '.builder/capabilities/broken.md' => "---\ncapability: [not, a, key]\n---\n",
            '.builder/capabilities/copy.md' => "---\ncapability: teams\n---\n",
        ]))->refresh();

        $this->assertSame(['teams'], $run->context['targets']);
        $this->assertCount(2, $run->context['problems']);
        $this->assertStringStartsWith('capabilities/broken.md:', $run->context['problems'][0]);
        $this->assertSame('capabilities/copy.md: another file already describes "teams".', $run->context['problems'][1]);
    }

    /**
     * Script the coding agent's attempts, one per build or repair. Without
     * any, the agent changes nothing.
     *
     * @param  Closure(Workspace): string  ...$attempts
     */
    protected function coder(Closure ...$attempts): void
    {
        $coder = $this->coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) use (&$attempts) {
            $summary = $attempts === [] ? 'Nothing to do.' : array_shift($attempts)($workspace);

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, $summary, turns: 2, inputTokens: 100, outputTokens: 50, costUsd: 0.01);
        });

        app(CodingAgentManager::class)->extend('claude', fn () => $coder);
    }

    /**
     * An attempt that writes the given files, or deletes those set to null.
     *
     * @param  array<string, string|null>  $files
     * @return Closure(Workspace): string
     */
    protected function writes(array $files, string $summary = 'Done.'): Closure
    {
        return function (Workspace $workspace) use ($files, $summary) {
            foreach ($files as $path => $contents) {
                $full = config('workspaces.drivers.local.root').DIRECTORY_SEPARATOR.$workspace->driver_id.DIRECTORY_SEPARATOR.$path;

                if ($contents === null) {
                    File::delete($full);
                } else {
                    File::ensureDirectoryExists(dirname($full));
                    File::put($full, $contents);
                }
            }

            return $summary;
        };
    }

    /**
     * Assert that one of the coding agent's tasks matches.
     *
     * @param  Closure(string): bool  $matches
     */
    protected function assertCoderPrompted(Closure $matches): void
    {
        $this->assertTrue(
            collect($this->coder->tasks)->contains(fn (AgentTask $task) => $matches($task->prompt)),
            'No task given to the coding agent matches.',
        );
    }

    /**
     * A valid planner response.
     *
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return [
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a nullable description.'],
            'assumptions' => ['The description is optional.'],
            'tasks' => ['Add a nullable description property.'],
            'steps' => [[
                'key' => 'description-field',
                'kind' => 'data',
                'label' => 'Team description',
                'file' => 'app/Models/Team.php',
                'symbol' => 'Team::$description',
                'detail' => 'Holds an optional description.',
            ]],
        ];
    }

    /**
     * Create a request for a project built from a small source.
     *
     * @param  array<string, string>  $files
     */
    protected function request(array $files = []): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource($files)]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);
    }

    /**
     * Record a passing verification for the run's change and carry it back.
     * By default the suite's report shows the change's own test passing.
     *
     * @param  list<array{file: string, name: string, outcome: string}>|null  $tests
     * @param  list<array{rule: string, path: string, line: int}>|null  $shortcuts
     * @param  array<string, mixed>|null  $evidence
     */
    protected function passVerification(Run $run, ?array $tests = null, ?array $screens = null, ?array $shortcuts = null, ?array $evidence = null): void
    {
        $tests ??= [['file' => '/workspace/tests/Feature/TeamDescriptionTest.php', 'name' => 'teams have a nullable description', 'outcome' => 'passed']];
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Passed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'OK', 'tests' => $tests],
        ], 'screens' => $screens, 'shortcuts' => $shortcuts, 'evidence' => $evidence, 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }

    /**
     * Record a failing verification for the run's change and carry it back.
     */
    protected function failVerification(Run $run, string $output): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Failed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => $output],
        ], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
