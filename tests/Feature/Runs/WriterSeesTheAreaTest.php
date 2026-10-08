<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\TestWriter;
use App\Enums\AgentOutcomeStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * The test writer sees the code the change works on and the tests of its
 * area, so it uses the app's real names instead of guessing them.
 */
class WriterSeesTheAreaTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected ?string $asked = null;

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
            'builder.verification.written_first.enabled' => true,
            'builder.verification.written_first.beside' => false,
        ]);

        $coder = new FakeCodingAgent('anthropic', fn (Workspace $workspace) => new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.'));
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);

        TestWriter::fake(function (string $prompt) {
            $this->asked = $prompt;

            return [
                'files' => [['path' => 'tests/Feature/Teams/LeaveTeamTest.php', 'contents' => "<?php\n\ntest('a member leaves', fn () => expect(true)->toBeTrue());\n"]],
                'tests' => [['item' => 1, 'file' => 'tests/Feature/Teams/LeaveTeamTest.php', 'name' => 'a member leaves']],
            ];
        });
    }

    public function test_the_writer_sees_the_code_a_step_changes_and_its_areas_tests_first(): void
    {
        FeaturePlanner::fake([$this->plan('app/Models/Team.php', ['teams'])]);

        app(StartRun::class)->handle($this->request());

        $this->assertStringContainsString("## app/Models/Team.php (as it is now)\n\n```\n<?php\n\nclass Team", $this->asked);
        $this->assertStringContainsString('## tests/Feature/Teams/TeamTest.php', $this->asked);
        $this->assertStringContainsString('## tests/Feature/AaaTest.php', $this->asked);
        $this->assertLessThan(strpos($this->asked, '## tests/Feature/AaaTest.php'), strpos($this->asked, '## tests/Feature/Teams/TeamTest.php'));
        $this->assertStringNotContainsString('## tests/Feature/BbbTest.php', $this->asked);
        $this->assertSame(1, substr_count($this->asked, '## app/Models/Team.php'), 'A step file that is also the area\'s model is shown once.');
    }

    public function test_the_writer_sees_the_owners_request_and_the_model_of_the_area_a_new_file_belongs_to(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/LeaveTeam.php', ['teams'])]);

        app(StartRun::class)->handle($this->request());

        $this->assertStringContainsString("## The owner's request\n\nLet people leave a team. Use the DELETE route named team-membership.destroy.", $this->asked);
        $this->assertStringContainsString("## app/Models/Team.php (as it is now)\n\n```\n<?php\n\nclass Team", $this->asked);
        $this->assertStringNotContainsString('## app/Models/Invoice.php', $this->asked);
    }

    public function test_an_area_with_no_model_shows_no_model(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/PayInvoice.php', ['billing'])]);

        app(StartRun::class)->handle($this->request());

        $this->assertStringNotContainsString('(as it is now)', $this->asked);
    }

    public function test_an_area_is_found_from_the_file_a_step_changes_when_the_plan_names_none(): void
    {
        FeaturePlanner::fake([$this->plan('app/Models/Team.php', [])]);

        app(StartRun::class)->handle($this->request());

        $this->assertStringContainsString('## tests/Feature/Teams/TeamTest.php', $this->asked);
        $this->assertLessThan(strpos($this->asked, '## tests/Feature/AaaTest.php'), strpos($this->asked, '## tests/Feature/Teams/TeamTest.php'));
    }

    public function test_a_new_file_has_nothing_to_show_and_with_no_area_the_first_tests_are_samples(): void
    {
        FeaturePlanner::fake([$this->plan('app/Models/Membership.php', [])]);

        app(StartRun::class)->handle($this->request());

        $this->assertStringNotContainsString('(as it is now)', $this->asked);
        $this->assertStringContainsString('## tests/Feature/AaaTest.php', $this->asked);
        $this->assertStringContainsString('## tests/Feature/BbbTest.php', $this->asked);
        $this->assertStringNotContainsString('## tests/Feature/Teams/TeamTest.php', $this->asked);
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    protected function plan(string $file, array $capabilities): array
    {
        return [
            'summary' => 'Members leave a team.',
            'acceptance_criteria' => ['A member can leave a team.'],
            'cases' => [['base' => 'A member who leaves is no longer in the team.', 'alternate' => null, 'no_alternate' => 'There is one way to leave.', 'exception' => null, 'no_exception' => 'Anyone in the team may leave.']],
            'assumptions' => [],
            'tasks' => ['Let members leave.'],
            'capabilities' => $capabilities,
            'steps' => [[
                'key' => 'leave',
                'kind' => 'data',
                'label' => 'Leaving',
                'file' => $file,
                'symbol' => 'leave',
                'detail' => 'Removes the member.',
            ]],
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource([
            '.builder/project.md' => "# Project\n\nTeams of people.\n",
            '.builder/capabilities/billing.md' => "---\ncapability: billing\nsummary: Teams pay invoices.\npaths:\n    - app/Http/Controllers/BillingController.php\n---\n\n# Billing\n",
            'app/Models/Invoice.php' => "<?php\n\nclass Invoice\n{\n}\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: People work in teams.\npaths:\n    - app/Models/Team.php\n    - tests/Feature/Teams/TeamTest.php\n---\n\n# Teams\n",
            'tests/Feature/AaaTest.php' => "<?php\n\ntest('aaa', fn () => expect(true)->toBeTrue());\n",
            'tests/Feature/BbbTest.php' => "<?php\n\ntest('bbb', fn () => expect(true)->toBeTrue());\n",
            'tests/Feature/Teams/TeamTest.php' => "<?php\n\ntest('a team has members', fn () => expect(true)->toBeTrue());\n",
        ])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let people leave a team. Use the DELETE route named team-membership.destroy.']);
    }
}
