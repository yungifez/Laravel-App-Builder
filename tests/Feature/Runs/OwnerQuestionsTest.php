<?php

namespace Tests\Feature\Runs;

use App\Actions\Projects\CreateProject;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Jobs\StartPreview;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/*
| What the agent asked the owner to keep is the owner's to decide. It holds
| the change until they answer, but it never sends the change back to the
| agent, who can do nothing more about it, and keeping the change anyway
| answers it.
*/
class OwnerQuestionsTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const GATE = 'GET /teams while Laravel checked whether the person may act';

    protected const TEAM_WITH_DESCRIPTION = "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n\n    public ?string \$description = null;\n}\n";

    protected const DESCRIPTION_TEST = "<?php\n\ntest('teams have a nullable description', fn () => expect(true)->toBeTrue());\n";

    protected FakeCodingAgent $coder;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        Bus::fake([StartPreview::class]);
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
    }

    public function test_a_question_for_the_owner_waits_while_the_agent_fixes_what_it_can()
    {
        FeaturePlanner::fake([$this->plan()]);
        $this->coder(
            $this->writes(['app/Models/Team.php' => self::TEAM_WITH_DESCRIPTION, 'tests/Feature/TeamDescriptionTest.php' => self::DESCRIPTION_TEST]),
            $this->writes([], 'KEEP B1: The owner asked for every refused visit to be logged.'),
            $this->writes([], 'Cast the description.'),
        );
        $verify = [['criterion' => 1, 'test_file' => 'tests/Feature/TeamDescriptionTest.php', 'test_name' => 'teams have a nullable description']];
        $approve = ['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'verify' => $verify];
        $doubt = ['approved' => false, 'summary' => 'One gap.', 'findings' => [['severity' => 'blocking', 'summary' => 'The description is not cast.', 'file' => 'app/Models/Team.php']], 'verify' => $verify];
        ChangeReviewer::fake([$approve, $doubt, $approve]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);
        $this->passVerification($run);

        // Base: the agent asked, and the reviewer found one more gap. Only
        // the gap is sent back; the question waits, in plain words.
        $run->refresh();
        $this->assertSame([RunStatus::Verifying, 2], [$run->status, $run->repairs]);
        $prompt = $this->coder->tasks[2]->prompt;
        $this->assertSame("- app/Models/Team.php: The description is not cast.\n\n## Waiting for the owner", Str::between($prompt, "The files already contain your earlier changes.\n\n", "\n\nYou asked the owner"));
        $this->assertStringContainsString("## Waiting for the owner\n\nYou asked the owner to keep these.", $prompt);
        $this->assertStringContainsString('- '.self::GATE, $prompt);
        $this->assertStringNotContainsString('B1', $prompt);
        $this->assertStringNotContainsString('You asked the owner to keep this, so leave it', $prompt);

        // Alternate: with the gap fixed, only the question is left, so the
        // change waits for the owner's answer instead of being sent back.
        $this->passVerification($run);

        $run->refresh();
        $this->assertSame([RunStatus::NeedsUserDecision, StopReason::FindingProposed, 2], [$run->status, $run->stop_reason, $run->repairs]);
        $this->assertStringStartsWith(self::GATE, $run->feedback['details'][0]);
    }

    public function test_keeping_a_change_anyway_answers_its_open_questions_yes()
    {
        $repository = app(ProjectRepository::class);
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
        $change = FeatureRequest::factory()->generated()->for($project)->create([
            'patch' => "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n",
            'base_revision' => $repository->head($project),
        ]);
        $run = Run::factory()->for($change)->create([
            'status' => RunStatus::NeedsUserDecision,
            'stop_reason' => StopReason::ReviewFindings,
            'feedback' => ['reason' => 'review_findings', 'details' => ['app/A.php: The comment says nothing.']],
        ]);
        Verification::factory()->for($change)->create(['status' => VerificationStatus::Passed]);
        $identity = 'open_to_anyone|POST /bookings';
        $change->findingProposals()->create(['kind' => 'open_to_anyone', 'identity' => $identity, 'reason' => 'Customers book without an account.', 'run_id' => $run->id]);
        // Exception: one the owner already said no to stays a no.
        $change->findingProposals()->create(['kind' => 'no_longer_checked', 'identity' => 'no_longer_checked|GET /teams', 'reason' => 'It is public now.', 'agreed' => false]);

        $this->actingAs($owner)->post(route('feature-requests.acceptance.store', $change), ['despite_review' => '1'])->assertSessionHasNoErrors();

        $this->assertSame([true, false], $change->findingProposals()->orderBy('id')->pluck('agreed')->all());
        $this->assertSame($owner->id, $change->findingProposals()->where('identity', $identity)->sole()->answered_by);
        $this->assertSame([$identity], $change->acceptedFindings()->pluck('identity')->all());
        $this->assertSame([$identity], $run->events()->where('type', 'change_accepted')->sole()->data['agreed_findings']);
    }

    public function test_a_change_kept_as_usual_records_no_findings_agreed()
    {
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
        $change = FeatureRequest::factory()->generated()->for($project)->create([
            'patch' => "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n",
            'base_revision' => app(ProjectRepository::class)->head($project),
        ]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Completed]);
        Verification::factory()->for($change)->create(['status' => VerificationStatus::Passed]);

        $this->actingAs($owner)->post(route('feature-requests.acceptance.store', $change))->assertSessionHasNoErrors();

        $this->assertSame([], $run->events()->where('type', 'change_accepted')->sole()->data['agreed_findings']);
    }

    /**
     * Script the coding agent's attempts, one per build or repair.
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
     * An attempt that writes the given files.
     *
     * @param  array<string, string>  $files
     * @return Closure(Workspace): string
     */
    protected function writes(array $files, string $summary = 'Done.'): Closure
    {
        return function (Workspace $workspace) use ($files, $summary) {
            foreach ($files as $path => $contents) {
                $full = config('workspaces.drivers.local.root').DIRECTORY_SEPARATOR.$workspace->driver_id.DIRECTORY_SEPARATOR.$path;
                File::ensureDirectoryExists(dirname($full));
                File::put($full, $contents);
            }

            return $summary;
        };
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
            'cases' => [['base' => 'A team saved with a description keeps it.', 'alternate' => null, 'no_alternate' => 'A description is only set one way.', 'exception' => null, 'no_exception' => 'Nothing about a description is refused.']],
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

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource([])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);
    }

    /**
     * Record a passing verification whose policy was seen saving while the
     * app checked who may act, and carry it back.
     */
    protected function passVerification(Run $run): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Passed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'OK', 'tests' => [['file' => '/workspace/tests/Feature/TeamDescriptionTest.php', 'name' => 'teams have a nullable description', 'outcome' => 'passed']]],
        ], 'evidence' => ['boundaries' => ['phased' => 30, 'unknown' => 0, 'existing' => 0, 'findings' => [
            ['kind' => 'changed_while_authorizing', 'route' => 'GET /teams', 'what' => 'insert refusals', 'at' => 'app/Policies/TeamPolicy.php:9', 'in' => 'App\Policies\TeamPolicy::view', 'test' => null],
        ]]], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
