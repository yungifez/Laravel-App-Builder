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
 * The test writer sees the named routes and the model relations of the
 * part of the app a change works on, so it stops guessing their names.
 */
class WriterSeesAreaRoutesTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const SECTION = '## Named routes of this part of the app';

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

    public function test_the_writer_sees_the_named_routes_of_the_area_as_laravel_lists_them_and_its_models_relations(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/LeaveTeam.php', ['teams'])]);

        app(StartRun::class)->handle($this->request([
            'artisan' => "<?php\n\necho json_encode(".var_export([
                ['method' => 'DELETE', 'uri' => 'teams/{team}/members/{user}', 'name' => 'team-membership.destroy', 'action' => 'App\Http\Controllers\Teams\TeamMemberController@destroy', 'middleware' => ['web', 'auth']],
                ['method' => 'GET|HEAD', 'uri' => 'teams/{team}/members', 'name' => null, 'action' => 'App\Http\Controllers\Teams\TeamMemberController@index', 'middleware' => ['web']],
                ['method' => 'POST', 'uri' => 'invoices/{invoice}/pay', 'name' => 'invoices.pay', 'action' => 'App\Http\Controllers\BillingController', 'middleware' => ['web', 'auth']],
                ['method' => 'GET|HEAD', 'uri' => 'up', 'name' => null, 'action' => 'Closure', 'middleware' => []],
            ], true).");\n",
        ]));

        $this->assertStringContainsString(self::SECTION."\n\nUse these names and addresses; do not make up others.\n\n- DELETE /teams/{team}/members/{user} is named team-membership.destroy (middleware: web, auth)\n\n", $this->asked);
        $this->assertStringNotContainsString('is named invoices.pay', $this->asked);
        $this->assertStringNotContainsString('is named teams.show', $this->asked, 'The route files are read only when Laravel cannot list the routes.');
        $this->assertStringContainsString("## Relations on this part's models\n\n- Team: members() is belongsToMany(User)\n- Team: owner() is belongsTo(User)\n\n", $this->asked);
    }

    public function test_an_app_that_cannot_list_its_routes_has_them_read_from_its_route_files(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/LeaveTeam.php', ['teams'])]);

        app(StartRun::class)->handle($this->request([
            'artisan' => "<?php\n\necho 'Could not boot.';\nexit(1);\n",
        ]));

        $this->assertStringContainsString(self::SECTION, $this->asked);
        $this->assertStringContainsString('- DELETE /teams/{team}/members/{user} is named team-membership.destroy (middleware: auth)', $this->asked);
        $this->assertStringContainsString('- GET /teams/{team} is named teams.show', $this->asked);
        $this->assertStringNotContainsString('is named invoices.pay', $this->asked);
        $this->assertStringNotContainsString('/up ', $this->asked);
    }

    public function test_an_area_with_no_routes_shows_no_routes(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/ExportReport.php', ['reports'])]);

        app(StartRun::class)->handle($this->request());

        $this->assertNotNull($this->asked);
        $this->assertStringNotContainsString(self::SECTION, $this->asked);
        $this->assertStringNotContainsString("## Relations on this part's models", $this->asked);
    }

    public function test_a_listing_and_route_files_that_cannot_be_read_leave_the_prompt_whole(): void
    {
        FeaturePlanner::fake([$this->plan('app/Actions/LeaveTeam.php', ['teams'])]);

        app(StartRun::class)->handle($this->request([
            'artisan' => "<?php\n\necho '{not json';\n",
            'routes/web.php' => "<?php\n\nRoute::delete(\n\x00\xff\xfe Route::get(",
            'routes/teams.php' => "\x00\x01\x02\xff",
        ]));

        $this->assertStringContainsString("## The owner's request", $this->asked);
        $this->assertStringContainsString('## What the tests must check', $this->asked);
        $this->assertStringNotContainsString(self::SECTION, $this->asked);
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

    /**
     * @param  array<string, string>  $files
     */
    protected function request(array $files = []): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource([
            ...[
                '.builder/project.md' => "# Project\n\nTeams of people.\n",
                '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: People work in teams.\npaths:\n    - app/Models/Team.php\n    - app/Http/Controllers/Teams/*\n---\n\n# Teams\n",
                '.builder/capabilities/billing.md' => "---\ncapability: billing\nsummary: Teams pay invoices.\npaths:\n    - app/Http/Controllers/BillingController.php\n---\n\n# Billing\n",
                '.builder/capabilities/reports.md' => "---\ncapability: reports\nsummary: Owners export reports.\npaths:\n    - app/Actions/ExportReport.php\n---\n\n# Reports\n",
                'app/Models/Team.php' => "<?php\n\nnamespace App\\Models;\n\nclass Team extends Model\n{\n    public function members(): BelongsToMany\n    {\n        return \$this->belongsToMany(User::class);\n    }\n\n    public function owner(): BelongsTo\n    {\n        return \$this->belongsTo(\\App\\Models\\User::class, 'owner_id');\n    }\n\n    public function label(): string\n    {\n        return \$this->name;\n    }\n}\n",
                'routes/web.php' => "<?php\n\nuse App\\Http\\Controllers\\BillingController;\nuse App\\Http\\Controllers\\Teams\\TeamMemberController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('up', fn () => 'ok');\n\nRoute::middleware('auth')->group(function () {\n    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])\n        ->middleware('auth')\n        ->name('team-membership.destroy');\n    Route::post('invoices/{invoice}/pay', BillingController::class)->name('invoices.pay');\n});\n",
                'routes/teams.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('teams/{team}', [App\\Http\\Controllers\\Teams\\TeamController::class, 'show'])->name('teams.show');\n",
            ],
            ...$files,
        ])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let people leave a team.']);
    }
}
