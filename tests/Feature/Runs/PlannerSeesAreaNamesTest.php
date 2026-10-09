<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * The planner sees the data the change's area already passes to its pages
 * and its models' relations, so the plan names them as the app does
 * ("can.deleteTeam", not a new "canDelete").
 */
class PlannerSeesAreaNamesTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const SECTION = '## Names this part of the app already uses';

    protected ?string $asked = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
        ]);

        // A question stops the run after planning; only the prompt matters.
        FeaturePlanner::fake(function (string $prompt) {
            $this->asked ??= $prompt;

            return [
                'summary' => 'Members leave a team.',
                'acceptance_criteria' => ['A member can leave a team.'],
                'cases' => [['base' => 'A member who leaves is no longer in the team.', 'alternate' => null, 'no_alternate' => 'There is one way to leave.', 'exception' => null, 'no_exception' => 'Anyone in the team may leave.']],
                'assumptions' => [],
                'tasks' => ['Let members leave.'],
                'steps' => [['key' => 'leave', 'kind' => 'data', 'label' => 'Leaving', 'file' => 'app/Actions/LeaveTeam.php', 'symbol' => 'leave', 'detail' => 'Removes the member.']],
                'question' => ['text' => 'Who may leave?', 'why' => 'It decides who keeps the team.', 'options' => ['Anyone', 'Members only'], 'recommended' => null, 'reversible' => true],
            ];
        });
    }

    public function test_the_planner_sees_the_page_data_and_relations_of_the_area_the_request_names(): void
    {
        app(StartRun::class)->handle($this->request('Let people leave a team.', [
            'app/Http/Controllers/Teams/TeamController.php' => "<?php\n\nclass TeamController\n{\n    public function index()\n    {\n        return 'teams';\n    }\n\n    public function show(Team \$team)\n    {\n        return Inertia::render('teams/Show', [\n            'team' => \$team->only('id', 'name'),\n            'members' => \$team->members->map(fn (\$member) => ['id' => \$member->id, 'name' => \$member->name]),\n            'can' => [\n                'deleteTeam' => Gate::allows('delete', [\$team, ',']),\n                'leaveTeam' => Gate::allows('leave', \$team),\n            ],\n        ]);\n    }\n}\n",
        ]));

        $this->assertStringContainsString(self::SECTION."\n\nThe data its pages get and its models' relations. Use these names in the plan; do not make up new ones for what is already there.\n\n- TeamController@show passes teams/Show: team, members, can.deleteTeam, can.leaveTeam\n- Team: members() is belongsToMany(User)\n\n", $this->asked);
        $this->assertStringNotContainsString('passes billing/Show', $this->asked);
    }

    public function test_a_blade_page_given_compact_data_is_named_and_a_request_naming_no_area_shows_no_names(): void
    {
        app(StartRun::class)->handle($this->request('Let people leave a team.', [
            'app/Http/Controllers/Teams/TeamController.php' => "<?php\n\nclass TeamController\n{\n    public function index()\n    {\n        \$teams = Team::all();\n\n        return view('teams.index', compact('teams', 'filters'));\n    }\n}\n",
        ]));

        $this->assertStringContainsString("- TeamController@index passes teams.index: teams, filters\n", $this->asked);

        $this->asked = null;
        app(StartRun::class)->handle($this->request('Make the logo bigger.'));

        $this->assertStringNotContainsString(self::SECTION, $this->asked);
    }

    public function test_code_that_cannot_be_read_leaves_its_names_out_and_the_plan_goes_on(): void
    {
        app(StartRun::class)->handle($this->request('Let people leave a team.', [
            'app/Http/Controllers/Teams/BrokenController.php' => "<?php\n\nclass BrokenController\n{\n    public function show()\n    {\n        return Inertia::render('teams/Broken', ['team' => [\$a, 'can' => \n",
            'app/Http/Controllers/Teams/StrangeController.php' => "\x00\xff Inertia::render('x', 42",
        ]));

        $this->assertStringContainsString("## Owner's request\n\nLet people leave a team.", $this->asked);
        $this->assertStringContainsString(self::SECTION, $this->asked);
        $this->assertStringContainsString('- Team: members() is belongsToMany(User)', $this->asked);
        $this->assertStringNotContainsString('teams/Broken', $this->asked);
        $this->assertStringNotContainsString('passes x', $this->asked);
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function request(string $prompt, array $files = []): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource([
            '.builder/project.md' => "# Project\n\nTeams of people.\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\nname: Teams\nsummary: People work in teams.\npaths:\n    - app/Models/Team.php\n    - app/Http/Controllers/Teams/*\n---\n\n# Teams\n",
            '.builder/capabilities/billing.md' => "---\ncapability: billing\nname: Billing\nsummary: Teams pay invoices.\npaths:\n    - app/Http/Controllers/BillingController.php\n---\n\n# Billing\n",
            'app/Models/Team.php' => "<?php\n\nnamespace App\\Models;\n\nclass Team extends Model\n{\n    public function members(): BelongsToMany\n    {\n        return \$this->belongsToMany(User::class);\n    }\n}\n",
            'app/Http/Controllers/BillingController.php' => "<?php\n\nclass BillingController\n{\n    public function show()\n    {\n        return Inertia::render('billing/Show', ['invoices' => []]);\n    }\n}\n",
            ...$files,
        ])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => $prompt]);
    }
}
