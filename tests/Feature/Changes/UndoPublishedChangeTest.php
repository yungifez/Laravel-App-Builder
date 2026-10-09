<?php

namespace Tests\Feature\Changes;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class UndoPublishedChangeTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        $this->repository->import($this->project);
    }

    public function test_an_undone_change_that_is_online_says_so_and_offers_to_put_the_app_online_again(): void
    {
        [$change, $kept] = $this->keep('a.txt');
        $this->publish($kept, [$change]);
        $revert = $this->undo($change, 'a.txt');

        $this->assertStillOnline($change, ['head' => $revert, 'others' => 0]);

        // A change kept since goes online too, and the owner is told.
        $this->keep('b.txt');

        $this->assertStillOnline($change, ['head' => $this->repository->head($this->project), 'others' => 1]);
    }

    public function test_an_undone_change_that_was_never_online_says_only_that_it_was_undone(): void
    {
        [$change] = $this->keep('a.txt');
        $this->undo($change, 'a.txt');

        $this->assertStillOnline($change, null);
    }

    public function test_an_undone_change_a_later_publish_already_left_out_is_not_online(): void
    {
        [$change, $kept] = $this->keep('a.txt');
        $this->publish($kept, [$change]);
        $revert = $this->undo($change, 'a.txt');
        $this->publish($revert, []);

        $this->assertStillOnline($change, null);
    }

    /**
     * Keep a change that adds one file to the main app.
     *
     * @return array{FeatureRequest, string}
     */
    protected function keep(string $path): array
    {
        $commit = $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$path => "{$path}\n"], "Add {$path}", null);
        $change = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'commit_sha' => $commit, 'accepted_at' => now()]);

        return [$change, $commit];
    }

    /**
     * Undo a kept change with a new commit, as undoing it does.
     */
    protected function undo(FeatureRequest $change, string $path): string
    {
        $revert = $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$path => null], "Remove {$path}", null);
        $change->update(['revert_sha' => $revert, 'reverted_at' => now()]);

        return $revert;
    }

    /**
     * Put a version online holding the given changes.
     *
     * @param  list<FeatureRequest>  $changes
     */
    protected function publish(string $commit, array $changes): void
    {
        $deployment = Deployment::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'commit_sha' => $commit,
            'status' => DeploymentStatus::Published,
        ]);

        $deployment->featureRequests()->attach(array_map(fn (FeatureRequest $change) => $change->id, $changes));
    }

    /**
     * @param  array{head: string, others: int}|null  $expected
     */
    protected function assertStillOnline(FeatureRequest $change, ?array $expected): void
    {
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.still_online', $expected)->etc());
    }
}
