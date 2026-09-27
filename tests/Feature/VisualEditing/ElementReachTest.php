<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ElementReachTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    public function test_a_selected_part_says_what_else_a_change_may_affect_and_whether_tests_check_it()
    {
        $this->fakeWorkspaces();
        $repository = app(ProjectRepository::class);
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/Plans.vue' => "<template>\n    <h1 class=\"text-xl\">Plans</h1>\n</template>\n",
            'tests/Feature/BillingTest.php' => "<?php\n",
            '.builder/capabilities/plans.md' => $this->notes('plans', 'Plans', ['resources/js/pages/*'], "effects:\n    - to: billing\n      strength: strong\n      reason: Each plan has a price.\n      source: owner\n    - to: reminders\n      strength: possible\n      reason: Reminders name the plan.\n      source: agent\n    - to: missing\n      strength: possible\n      reason: Gone.\n      source: agent\n"),
            '.builder/capabilities/billing.md' => $this->notes('billing', 'Billing', ['app/Billing/*', 'tests/Feature/BillingTest.php']),
            '.builder/capabilities/reminders.md' => $this->notes('reminders', 'Reminders', ['app/Reminders/*']),
        ]), draftNotes: false);
        $repository->import($project);
        config(['builder.preview.workspace_driver' => 'fake', 'builder.preview.domain' => 'preview.test']);

        Preview::factory()->editable($repository->head($project))->ready()->create([
            'project_id' => $project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $owner->id])->id,
        ]);

        $this->actingAs($owner)
            ->get(route('projects.show', ['project' => $project, 'target' => 'resources/js/pages/Plans.vue:2:5']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.area.name', 'Plans')
                ->where('element.area.affects', [
                    ['name' => 'Billing', 'tested' => true],
                    ['name' => 'Reminders', 'tested' => false],
                ])));
    }

    /**
     * @param  list<string>  $paths
     */
    protected function notes(string $key, string $name, array $paths, string $extra = ''): string
    {
        $paths = implode(', ', $paths);

        return "---\ncapability: {$key}\nsummary: {$name}.\npaths: [{$paths}]\n{$extra}---\n# {$name}\n";
    }
}
