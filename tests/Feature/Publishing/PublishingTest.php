<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PublishingTest extends TestCase
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
            'builder.verification.setup' => [['name' => 'Install PHP dependencies', 'command' => ['composer', 'install'], 'timeout' => 60]],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60],
                ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan'], 'timeout' => 60],
            ],
            'builder.publishing.allow_local_remotes' => true,
        ]);
    }

    public function test_the_owner_chooses_where_to_publish_and_the_address_is_stored_encrypted()
    {
        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), [
                'deploy_remote' => 'https://builder:secret-token@github.com/acme/shop.git',
                'deploy_branch' => 'production',
            ])
            ->assertSessionHasNoErrors();

        $this->project->refresh();
        $this->assertSame('https://builder:secret-token@github.com/acme/shop.git', $this->project->deploy_remote);
        $this->assertStringNotContainsString('secret-token', (string) DB::table('projects')->where('id', $this->project->id)->value('deploy_remote'));

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.connected', true)
                ->where('publishing.target', 'https://github.com/acme/shop.git')
                ->where('publishing.branch', 'production'))
            ->assertDontSee('secret-token');
    }

    public function test_only_https_and_ssh_remotes_and_safe_branch_names_are_accepted()
    {
        config(['builder.publishing.allow_local_remotes' => false]);

        foreach (['--upload-pack=touch /tmp/x', 'ext::sh -c touch% /tmp/x', 'file:///etc', $this->remote, 'https://github.com/a b.git'] as $remote) {
            $this->actingAs($this->owner)
                ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => $remote, 'deploy_branch' => 'main'])
                ->assertSessionHasErrors('deploy_remote');
        }

        foreach (['-f', 'a..b', 'main/', 'a b'] as $branch) {
            $this->actingAs($this->owner)
                ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => $branch])
                ->assertSessionHasErrors('deploy_branch');
        }

        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'release/v1'])
            ->assertSessionHasNoErrors();
    }

    public function test_publishing_checks_the_exact_commit_then_pushes_it_to_the_branch()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertSame($head, $deployment->commit_sha);
        $this->assertSame([
            ['name' => 'Install PHP dependencies', 'passed' => true],
            ['name' => 'Tests', 'passed' => true],
            ['name' => 'Static analysis', 'passed' => true],
        ], $deployment->checks);
        $this->assertSame([['composer', 'install'], ['php', 'artisan', 'test'], ['vendor/bin/phpstan']], array_column($this->driver->executed, 'command'));
        $this->assertCount(1, $this->driver->destroyed);
        $this->assertSame($head, trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output()));
    }

    public function test_a_failing_check_stops_the_publish_and_nothing_is_pushed()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: $command === ['php', 'artisan', 'test'] ? 1 : 0, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('A check did not pass, so I did not publish. Your app online has not changed.', $deployment->error);
        $this->assertSame([true, false, true], array_column((array) $deployment->checks, 'passed'));
        $this->assertTrue(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', '--verify', '--quiet', 'refs/heads/main'])->failed());
    }

    public function test_a_branch_with_commits_the_project_does_not_have_is_never_overwritten()
    {
        $other = app(CreateProject::class)->handle($this->owner, 'Other', $this->makeProjectSource(['README.md' => "Someone else's work\n"]), draftNotes: false);
        $this->repository->import($other);
        $this->repository->push($other, $this->repository->head($other), $this->remote, 'main');
        $theirs = $this->repository->head($other);

        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = $this->project->deployments()->sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringStartsWith('The published app has changes that are not in this project', (string) $deployment->error);
        $this->assertSame($theirs, trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output()));
    }

    public function test_push_errors_are_recorded_without_credentials()
    {
        $this->project->update(['deploy_remote' => 'https://builder:secret-token@127.0.0.1:1/acme/shop.git', 'deploy_branch' => 'main']);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertNotEmpty($deployment->error);
        $this->assertStringNotContainsString('secret-token', (string) $deployment->error);
    }

    public function test_publishing_needs_a_destination_and_runs_one_at_a_time()
    {
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasErrors(['publish' => 'Choose where to publish first.']);

        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasErrors(['publish' => 'Your app is already being published.']);

        Queue::assertPushed(PublishDeployment::class, 1);
    }

    public function test_other_people_cannot_publish_or_change_where_to()
    {
        $stranger = User::factory()->create();
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);

        $this->actingAs($stranger)->post(route('deployments.store', $this->project))->assertForbidden();
        $this->actingAs($stranger)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => 'git@github.com:evil/x.git', 'deploy_branch' => 'main'])
            ->assertForbidden();

        $this->assertSame(0, Deployment::count());
    }
}
