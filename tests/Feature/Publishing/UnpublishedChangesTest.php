<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class UnpublishedChangesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        $this->repository->import($this->project);
    }

    public function test_the_owner_sees_which_requests_going_online_adds_and_takes_back()
    {
        $online = $this->commit('a.txt', 'Add a');
        $taken = $this->kept($online, 'Show the opening hours');
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $online, 'status' => DeploymentStatus::Published]);

        $added = $this->kept($this->commit('b.txt', 'Add b'), 'Show prices next to each item');
        $taken->forceFill(['revert_sha' => $this->commit('a.txt', 'Undo a', null), 'reverted_at' => now()])->save();
        $both = $this->kept($this->commit('c.txt', 'Add c'), 'Try a darker page');
        $both->forceFill(['revert_sha' => $this->commit('c.txt', 'Undo c', null), 'reverted_at' => now()])->save();
        VisualEdit::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->commit('d.txt', 'Edit the look')]);
        VisualEdit::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $online]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', [
                'added' => [['id' => $added->id, 'asked' => 'Show prices next to each item']],
                'undone' => [['id' => $taken->id, 'asked' => 'Show the opening hours']],
                'edits' => 1,
            ]));

        // The apps list counts the same: one kept, one undone, one edit.
        $this->actingAs($this->owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.offline', 3));
    }

    public function test_nothing_is_listed_before_the_first_publish_or_when_online_is_newest()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', null));
        $this->actingAs($this->owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.offline', 0));

        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', null));
    }

    protected function commit(string $file, string $message, ?string $contents = "x\n"): string
    {
        return $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => $contents], $message, null);
    }

    protected function kept(string $commit, string $prompt): FeatureRequest
    {
        return FeatureRequest::factory()->create(['project_id' => $this->project->id, 'prompt' => $prompt, 'commit_sha' => $commit, 'accepted_at' => now()]);
    }
}
