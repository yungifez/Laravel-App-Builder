<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CancelRun;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeatureCoder;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\FeatureRequestStatus;
use App\Enums\OperationStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Concerns\PreparesRuns;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\TestCase;

class AgentDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase, UsesReferenceSolutions;

    protected const TEAM = "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n}\n";

    protected const TEAM_WITH_DESCRIPTION = "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n\n    public ?string \$description = null;\n}\n";

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'agent',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.coder' => ['provider' => 'anthropic', 'model' => 'coder-model'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model'],
        ]);
    }

    public function test_the_planner_coder_and_reviewer_build_and_accept_a_verified_change()
    {
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'read_file', ['path' => 'app/Models/Team.php']),
            new ToolCall('call-2', 'write_file', ['path' => 'app/Models/Team.php', 'contents' => self::TEAM_WITH_DESCRIPTION, 'expected_sha256' => hash('sha256', self::TEAM), 'expected_revision' => 0]),
            'I added a description to teams and every test passes.',
        ]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'The diff adds the field the plan asks for.', 'findings' => []]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame('Teams get an optional description.', $run->plan['summary']);
        $this->assertSame(['Teams have a nullable description.'], $run->plan['acceptance_criteria']);
        $this->assertSame([], $run->plan['acceptance']);
        $this->assertSame(1, $run->workspace_revision);
        $this->assertSame(['coder:0:call-1', 'coder:0:call-2'], $run->operations()->orderBy('id')->pluck('operation_key')->all());

        $featureRequest->refresh();
        $this->assertSame(FeatureRequestStatus::Generated, $featureRequest->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $featureRequest->patch);
        $this->assertSame('description-field', $featureRequest->steps[0]['key']);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Give teams a description')
            && str_contains($prompt->prompt, 'app/Models/Team.php')
            && $prompt->model === 'planner-model');
        FeatureCoder::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Add a nullable description property.')
            && $prompt->model === 'coder-model');

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'diff --git a/app/Models/Team.php')
            && str_contains($prompt->prompt, 'Teams have a nullable description.')
            && ! str_contains($prompt->prompt, 'every test passes')
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'reviewer-model');

        $calls = $run->events()->where('type', 'model_call')->get()->pluck('data');
        $this->assertSame(['planner', 'coder', 'reviewer'], $calls->pluck('role')->all());
        $this->assertSame(['planner-model', 'coder-model', 'reviewer-model'], $calls->pluck('model')->all());
        $this->assertSame('I added a description to teams and every test passes.', $run->events()->where('type', 'build_finished')->sole()->data['account']);
    }

    public function test_the_platform_not_the_planner_chooses_the_protected_suites_including_for_follow_ups()
    {
        $solutions = $this->useReferenceSolutions();
        config(['builder.generator' => 'reference']);
        FeaturePlanner::fake([
            [...$this->plan(), 'acceptance' => ['Invitations/AlwaysPasses.php']],
            $this->plan(),
        ]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Invitation.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done.',
            new ToolCall('call-1', 'write_file', ['path' => 'app/OwnerOnly.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done.',
        ]);
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

    public function test_an_invalid_plan_fails_the_run_with_a_reason()
    {
        FeaturePlanner::fake([['summary' => 'Something.', 'acceptance_criteria' => [], 'assumptions' => [], 'tasks' => [], 'steps' => []]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringStartsWith('The planner returned an invalid plan:', (string) $run->error);
        $this->assertSame(FeatureRequestStatus::Failed, $featureRequest->refresh()->status);
        FeatureCoder::assertNeverPrompted();
    }

    public function test_refused_and_unknown_tool_calls_go_back_to_the_model_and_a_claim_without_changes_is_not_accepted()
    {
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'tests/Acceptance/Contract.php', 'contents' => '<?php // passes', 'expected_sha256' => null, 'expected_revision' => 0]),
            new ToolCall('call-2', 'shell', ['command' => 'rm -rf /']),
            new ToolCall('call-3', 'write_file', ['path' => '../escape.php', 'contents' => 'x', 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done! The feature is complete and all tests pass.',
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The run finished without changing the project.', $run->error);
        $this->assertSame(
            [['coder:0:call-1', 'rejected'], ['coder:0:call-3', 'rejected']],
            $run->operations()->orderBy('id')->get()->map(fn ($operation) => [$operation->operation_key, $operation->status->value])->all(),
        );
        $this->assertSame(0, $run->workspace_revision);
        ChangeReviewer::assertNeverPrompted();
    }

    public function test_the_model_loop_stops_when_the_operation_budget_is_used()
    {
        config(['builder.construction.budgets.operations' => 2]);
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'list_files', []),
            new ToolCall('call-2', 'read_file', ['path' => 'app/Models/Team.php']),
            new ToolCall('call-3', 'read_file', ['path' => 'config/teams.php']),
            new ToolCall('call-4', 'read_file', ['path' => 'config/teams.php']),
            'Finished.',
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The run used all 2 of its tool operations.', $run->error);
        $this->assertSame(2, $run->operations()->count());
        $this->assertSame('budget_exhausted', $run->events()->get()->last()->data['reason']);
    }

    public function test_cancelling_during_the_model_loop_stops_it_before_the_next_model_call()
    {
        $steps = 0;
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake(function () use (&$steps) {
            $steps++;

            if ($steps === 2) {
                app(CancelRun::class)->handle(Run::query()->sole());
            }

            return new ToolCall("call-{$steps}", 'list_files', []);
        });

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame(2, $steps);
        $this->assertSame(['succeeded'], $run->operations()->pluck('status')->map(fn (OperationStatus $status) => $status->value)->all());
        $this->assertSame(FeatureRequestStatus::Cancelled, $featureRequest->refresh()->status);
    }

    public function test_a_failed_verification_sends_the_change_back_to_the_coder_with_the_failures()
    {
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Models/Team.php', 'contents' => self::TEAM_WITH_DESCRIPTION, 'expected_sha256' => hash('sha256', self::TEAM), 'expected_revision' => 0]),
            'Done.',
            new ToolCall('call-1', 'write_file', ['path' => 'app/Team.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 1]),
            'Fixed.',
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->failVerification($run, 'Tests: 1 failed. Expected description to be fillable.');

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertNull($run->feedback);
        $this->assertSame(['coder:0:call-1', 'coder:1:call-1'], $run->operations()->orderBy('id')->pluck('operation_key')->all());
        FeatureCoder::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Fix these problems')
            && str_contains($prompt->prompt, 'Expected description to be fillable.')
            && str_contains($prompt->prompt, 'revision 1'));
        $this->assertStringContainsString('app/Team.php', (string) $run->featureRequest->patch);
        FeaturePlanner::assertPromptedTimes(1);
    }

    public function test_blocking_review_findings_are_repaired_until_the_repair_budget_is_used()
    {
        config(['builder.construction.budgets.repairs' => 1]);
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Models/Team.php', 'contents' => self::TEAM_WITH_DESCRIPTION, 'expected_sha256' => hash('sha256', self::TEAM), 'expected_revision' => 0]),
            'Done.',
            new ToolCall('call-1', 'write_file', ['path' => 'app/Team.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 1]),
            'Fixed.',
        ]);
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
        FeatureCoder::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'app/Models/Team.php: Anyone can edit the description.'));

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The review found problems this run cannot fix: Still missing authorization.', $run->error);
    }

    public function test_the_reviewer_sees_tests_the_change_deletes()
    {
        FeaturePlanner::fake([$this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'read_file', ['path' => 'tests/Feature/TeamTest.php']),
            new ToolCall('call-2', 'apply_patch', ['patch' => "diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php\ndeleted file mode 100644\n--- a/tests/Feature/TeamTest.php\n+++ /dev/null\n@@ -1,3 +0,0 @@\n-<?php\n-\n-test('teams have names', fn () => expect(true)->toBeTrue());\n", 'expected_revision' => 0]),
            'Removed a slow test.',
        ]);
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
        FeaturePlanner::fake([[...$this->plan(), 'capabilities' => ['teams', 'unknown']]]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Models/Team.php', 'contents' => self::TEAM_WITH_DESCRIPTION, 'expected_sha256' => hash('sha256', self::TEAM), 'expected_revision' => 0]),
            new ToolCall('call-2', 'write_file', ['path' => 'config/billing.php', 'contents' => "<?php\n\nreturn [];\n", 'expected_sha256' => null, 'expected_revision' => 1]),
            new ToolCall('call-3', 'write_file', ['path' => 'config/teams.php', 'contents' => str_replace('members:invite', 'members:remove', $config), 'expected_sha256' => hash('sha256', $config), 'expected_revision' => 2]),
            new ToolCall('call-4', 'write_file', ['path' => 'app/Other.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 3]),
            'Done.',
        ]);
        ChangeReviewer::fake([[
            'approved' => true,
            'summary' => 'Adds the description.',
            'findings' => [],
            'changes' => [
                ['area' => 'teams', 'behavior' => 'Team details', 'before' => 'Teams had a name.', 'now' => 'Teams can also have a description.'],
                ['area' => 'settings', 'behavior' => 'Removing members', 'before' => 'Owners could invite.', 'now' => 'Owners can remove members instead.'],
                ['area' => 'made-up', 'behavior' => 'Something else', 'before' => 'Before.', 'now' => 'Now.'],
            ],
        ]]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request([
            '.builder/project.md' => "# Sparkle Cleaning\n\nWe call customers clients.\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: Clients belong to teams.\npaths: [app/Models/Team.php]\neffects:\n    - to: billing\n      strength: possible\n      reason: Each team is billed separately.\n      source: owner\n---\n# Teams\n\nA team always has a name.\n",
            '.builder/capabilities/billing.md' => "---\ncapability: billing\nsummary: Invoices for teams.\npaths: [config/billing.php]\n---\n# Billing\n\nOnly owners see invoices.\n",
            '.builder/capabilities/settings.md' => "---\ncapability: settings\npaths: [config/teams.php]\n---\n# Settings\n",
        ]))->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame('selective', $run->context['mode']);
        $this->assertSame(['teams'], $run->context['targets']);
        $this->assertSame(['.builder/project.md', '.builder/capabilities/teams.md', 'index'], array_column($run->context['included'], 'file'));
        $this->assertGreaterThan(0, $run->events()->where('type', 'context_compiled')->sole()->data['tokens']);

        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'We call customers clients.')
            && str_contains($prompt->prompt, '- teams: Teams. Clients belong to teams.')
            && ! str_contains($prompt->prompt, 'A team always has a name.'));
        FeatureCoder::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'A team always has a name.')
            && str_contains($prompt->prompt, '- Billing (possible): Each team is billed separately.')
            && str_contains($prompt->prompt, '(.builder/capabilities/billing.md)')
            && ! str_contains($prompt->prompt, 'Only owners see invoices.'));

        $this->passVerification($run);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame([
            'requested' => ['teams' => ['app/Models/Team.php']],
            'may_also_affect' => ['billing' => ['config/billing.php']],
            'unexpected' => ['settings' => ['config/teams.php']],
            'unclaimed' => ['app/Other.php'],
            'context_updates' => [],
            'targets' => ['teams'],
        ], $run->review['classification']);
        $this->assertSame(['requested', 'unexpected', 'other'], array_column($run->review['changes'], 'section'));
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '## Areas this change touched')
            && str_contains($prompt->prompt, '- settings (not expected): Settings; config/teams.php')
            && str_contains($prompt->prompt, 'Files no area claims: app/Other.php'));

        $this->actingAs($featureRequest->project->owner)
            ->get(route('feature-requests.show', $featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.context.mode', 'selective')
                ->where('run.review.areas.requested.0.name', 'Teams')
                ->where('run.review.areas.may_also_affect.0.files', ['config/billing.php'])
                ->where('run.review.areas.unexpected.0.name', 'Settings')
                ->where('run.review.changes.1.area_name', 'Settings')
                ->where('run.review.changes.1.section', 'unexpected')
                ->where('run.review.unclaimed', ['app/Other.php']));
    }

    public function test_a_context_file_that_cannot_be_read_is_reported_and_does_not_stop_the_run()
    {
        FeaturePlanner::fake([[...$this->plan(), 'capabilities' => ['teams']]]);
        FeatureCoder::fake(['Nothing to do.']);

        $run = app(StartRun::class)->handle($this->request([
            '.builder/capabilities/teams.md' => "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n# Teams\n",
            '.builder/capabilities/broken.md' => "---\ncapability: [not, a, key]\n---\n",
            '.builder/capabilities/copy.md' => "---\ncapability: teams\n---\n",
        ]))->refresh();

        $this->assertSame(['teams'], $run->context['targets']);
        $this->assertCount(2, $run->context['problems']);
        $this->assertStringStartsWith('.builder/capabilities/broken.md:', $run->context['problems'][0]);
        $this->assertSame('.builder/capabilities/copy.md: another file already describes "teams".', $run->context['problems'][1]);
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
     */
    protected function passVerification(Run $run): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Passed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'OK'],
        ], 'finished_at' => now()]);

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
