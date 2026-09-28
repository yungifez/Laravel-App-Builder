<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ElementOriginTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const PLANS = "<template>\n    <div class=\"p-4\">\n        <h1 class=\"text-xl\">Plans</h1>\n    </div>\n</template>\n";

    protected const WITH_SAVINGS = "<template>\n    <div class=\"p-4\">\n        <h1 class=\"text-xl\">Plans</h1>\n        <p class=\"mt-2\">Yearly plans save 20%</p>\n    </div>\n</template>\n";

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/Plans.vue' => self::PLANS,
        ]), draftNotes: false);
        $this->repository->import($this->project);

        config(['builder.preview.workspace_driver' => 'fake', 'builder.preview.domain' => 'preview.test']);
    }

    public function test_a_selected_part_names_the_request_that_added_it_even_after_later_edits()
    {
        $added = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['resources/js/pages/Plans.vue' => self::WITH_SAVINGS], 'Show yearly savings', null);
        $featureRequest = $this->kept($added, 'Show how much people save on yearly plans');
        Run::factory()->create(['feature_request_id' => $featureRequest->id, 'answers' => [
            ['question' => 'How much do yearly plans save?', 'answer' => '20%', 'decided_by' => 'owner'],
            ['question' => 'Where should it go?', 'answer' => 'Under the title', 'decided_by' => 'builder'],
        ]]);

        // A later hand edit to the same line does not hide who added it.
        $this->repository->commitFiles($this->project, $added, ['resources/js/pages/Plans.vue' => str_replace('mt-2', 'mt-4', self::WITH_SAVINGS)], 'Edit the look', null);

        $this->inspect('resources/js/pages/Plans.vue:4:9', fn (Assert $page) => $page
            ->where('element.origin.id', $featureRequest->uuid)
            ->where('element.origin.how', 'added')
            ->where('element.origin.asked', 'Show how much people save on yearly plans')
            ->where('element.origin.decided', ['question' => 'How much do yearly plans save?', 'answer' => '20%']));
    }

    public function test_a_part_the_app_came_with_names_the_latest_request_that_changed_it()
    {
        $first = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['resources/js/pages/Plans.vue' => str_replace('Plans<', 'Our plans<', self::PLANS)], 'Rename', null);
        $this->kept($first, 'Call the page Our plans');
        $second = $this->repository->commitFiles($this->project, $first, ['resources/js/pages/Plans.vue' => str_replace('Plans<', 'Pick a plan<', self::PLANS)], 'Rename again', null);
        $latest = $this->kept($second, 'Call the page Pick a plan');

        $this->inspect('resources/js/pages/Plans.vue:3:9', fn (Assert $page) => $page
            ->where('element.origin.id', $latest->uuid)
            ->where('element.origin.how', 'changed')
            ->where('element.origin.decided', null));
    }

    public function test_parts_the_app_came_with_or_from_undone_requests_name_no_request()
    {
        $added = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['resources/js/pages/Plans.vue' => self::WITH_SAVINGS], 'Show yearly savings', null);
        $this->kept($added, 'Show yearly savings')->forceFill(['reverted_at' => now()])->save();

        $this->inspect('resources/js/pages/Plans.vue:4:9', fn (Assert $page) => $page->where('element.tag', 'p')->where('element.origin', null));
        $this->inspect('resources/js/pages/Plans.vue:3:9', fn (Assert $page) => $page->where('element.tag', 'h1')->where('element.origin', null));
    }

    protected function kept(string $commit, string $prompt): FeatureRequest
    {
        return FeatureRequest::factory()->create([
            'project_id' => $this->project->id,
            'prompt' => $prompt,
            'commit_sha' => $commit,
            'accepted_at' => now(),
        ]);
    }

    protected function inspect(string $target, Closure $assert): void
    {
        Preview::query()->delete();
        Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => $target]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', $assert));
    }
}
