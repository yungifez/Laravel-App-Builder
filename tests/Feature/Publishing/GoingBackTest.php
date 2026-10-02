<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/**
 * When a newer version goes wrong online, the owner puts the one before it
 * back, at once, while the app here keeps the newer work.
 */
class GoingBackTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected string $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        $this->repository->import($this->project);

        // A bare repository standing in for the one the hosting platform
        // deploys from. Nothing is ever pushed anywhere else.
        $this->remote = sys_get_temp_dir().'/builder-test-remote-'.Str::lower(Str::random(8)).'.git';
        Process::run(['git', 'init', '--quiet', '--bare', $this->remote])->throw();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->remote));

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60]],
            'builder.publishing.allow_local_remotes' => true,
        ]);

        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
    }

    public function test_the_owner_puts_the_earlier_version_back_online_without_forcing_or_checking_again()
    {
        $earlier = $this->publish();
        $newer = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['broken.txt' => "oops\n"], 'Break it', null);
        $this->publish();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.previous.id', $earlier->id)
                ->where('publishing.previous.stored', false));

        $checksBefore = count($this->driver->executed);

        $this->actingAs($this->owner)
            ->post(route('deployments.restoration.store', [$this->project, $earlier]))
            ->assertSessionHasNoErrors();

        $back = Deployment::query()->latest('id')->first();
        $this->assertTrue($back->restores->is($earlier));
        $this->assertSame($earlier->commit_sha, $back->commit_sha);
        $this->assertSame(DeploymentStatus::Sent, $back->status);
        $this->assertSame($checksBefore, count($this->driver->executed), 'A version that was online is not checked again.');
        $this->assertSame($earlier->checks, $back->checks);

        // The host has the earlier files, on top of the newer version.
        $this->assertSame($back->release_sha, $this->remoteHead());
        $this->assertSame($this->tree($earlier->commit_sha), trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main^{tree}'])->output()));
        $this->assertTrue(Process::run(['git', '--git-dir', $this->remote, 'merge-base', '--is-ancestor', $newer, 'refs/heads/main'])->successful());

        // The app here keeps the newer work.
        $this->assertSame($newer, $this->repository->head($this->project));

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.deployments.0.restores', $earlier->finished_at?->toIso8601String())
                ->where('publishing.previous', null));
    }

    public function test_publishing_again_after_going_back_puts_the_newer_files_online_without_forcing()
    {
        $earlier = $this->publish();
        $newer = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['fixed.txt' => "fine\n"], 'Fix it', null);
        $this->publish();

        $this->actingAs($this->owner)->post(route('deployments.restoration.store', [$this->project, $earlier]));
        $wentBack = $this->remoteHead();

        $again = $this->publish();

        $this->assertSame($newer, $again->commit_sha);
        $this->assertSame($again->release_sha, $this->remoteHead());
        $this->assertSame($this->tree($newer), trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main^{tree}'])->output()));
        $this->assertTrue(Process::run(['git', '--git-dir', $this->remote, 'merge-base', '--is-ancestor', $wentBack, 'refs/heads/main'])->successful());
    }

    public function test_the_owner_is_warned_when_the_newer_version_changed_how_information_is_stored()
    {
        $this->publish();
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['database/migrations/2026_01_01_000000_add_notes.php' => "<?php\n"], 'Add notes', null);
        $this->publish();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.previous.stored', true));
    }

    public function test_only_a_version_that_was_online_and_is_not_online_now_can_go_back()
    {
        $earlier = $this->publish();

        $this->actingAs($this->owner)
            ->post(route('deployments.restoration.store', [$this->project, $earlier]))
            ->assertSessionHasErrors(['restore' => 'This version is online now.']);

        $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['a.txt' => "a\n"], 'Add a', null);
        $earlier->update(['status' => DeploymentStatus::Failed]);
        $this->publish();

        $this->actingAs($this->owner)
            ->post(route('deployments.restoration.store', [$this->project, $earlier]))
            ->assertSessionHasErrors(['restore' => 'Only a version that was online can go back online.']);
    }

    public function test_others_cannot_go_back_and_a_version_of_another_app_is_not_found()
    {
        $earlier = $this->publish();
        $other = app(CreateProject::class)->handle($this->owner, 'Other', $this->makeProjectSource(), draftNotes: false);

        $this->actingAs(User::factory()->create())
            ->post(route('deployments.restoration.store', [$this->project, $earlier]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->post(route('deployments.restoration.store', [$other, $earlier]))
            ->assertNotFound();
    }

    /**
     * Publish the app as it is now, and count it as online.
     */
    protected function publish(): Deployment
    {
        $this->travel(1)->hour();

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasNoErrors();

        $deployment = Deployment::query()->latest('id')->first();
        $this->assertSame(DeploymentStatus::Sent, $deployment->status, (string) $deployment->error);

        // Without an address it can only be sent; the address checks are
        // tested in PublishingTest.
        $deployment->update(['status' => DeploymentStatus::Published, 'confirmed_at' => now()]);

        return $deployment;
    }

    protected function remoteHead(): string
    {
        return trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output());
    }

    protected function tree(string $commit): string
    {
        return trim($this->repository->git($this->project, ['rev-parse', "{$commit}^{tree}"])->output());
    }
}
