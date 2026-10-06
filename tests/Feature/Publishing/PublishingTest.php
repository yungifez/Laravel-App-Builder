<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Jobs\ConfirmDeployment;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Publishing\Hosts\GitBranchHost;
use App\Publishing\PublishingHostManager;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use RuntimeException;
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

    public function test_a_check_that_needs_what_the_installs_add_still_runs()
    {
        config(['builder.verification.checks' => [
            ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan'], 'timeout' => 60, 'needs' => 'vendor/bin/phpstan'],
            ['name' => 'Type checks', 'command' => ['npm', 'run', 'types'], 'timeout' => 60, 'needs' => 'package.json'],
        ]]);
        // vendor/bin/phpstan is there only once the PHP packages are in.
        $installed = false;
        $this->driver->onExec = function (string $id, array $command) use (&$installed) {
            $installed = $installed || $command === ['composer', 'install'];
            $exists = $command[0] === 'test' ? ($command[2] === 'vendor/bin/phpstan' && $installed) : true;

            return new CommandResult(exitCode: $exists ? 0 : 1, output: '', errorOutput: '', durationMs: 5);
        };
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame([
            ['name' => 'Install PHP dependencies', 'passed' => true],
            ['name' => 'Static analysis', 'passed' => true],
        ], Deployment::sole()->checks);
    }

    public function test_publishing_checks_the_exact_commit_then_pushes_it_to_the_branch()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasNoErrors();

        // Without the app's address nobody can say it is online: only sent.
        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Sent, $deployment->status);
        $this->assertNotNull($deployment->pushed_at);
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

    public function test_a_publish_records_which_kept_changes_it_contains()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $kept = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['a.txt' => "a\n"], 'Add a', null);
        $undone = $this->repository->commitFiles($this->project, $kept, ['b.txt' => "b\n"], 'Add b', null);
        $revert = $this->repository->commitFiles($this->project, $undone, ['b.txt' => null], 'Remove b', null);
        $included = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'commit_sha' => $kept]);
        $reverted = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'commit_sha' => $undone, 'revert_sha' => $revert]);
        $elsewhere = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'commit_sha' => str_repeat('e', 40)]);

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasNoErrors();

        $this->assertSame([$included->id], Deployment::sole()->featureRequests()->pluck('feature_requests.id')->all());
        $this->assertTrue($reverted->deployments()->doesntExist());
        $this->assertTrue($elsewhere->deployments()->doesntExist());
    }

    public function test_an_app_without_a_health_route_is_online_once_its_home_page_answers()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake([
            'shop.example.com/up' => Http::response('Not Found', 404),
            'shop.example.com/' => Http::response('', 200),
            'shop.example.com/login' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame(DeploymentStatus::Published, Deployment::sole()->status);
    }

    public function test_an_app_whose_home_page_is_missing_is_not_online()
    {
        config(['builder.publishing.confirm.confirm_seconds' => 0]);
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake(['shop.example.com/*' => Http::response('Not Found', 404)]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->assertNotSame(DeploymentStatus::Published, $deployment->status);
        $this->assertContains(['path' => '/', 'status' => 404, 'passed' => false], $deployment->health);
    }

    public function test_a_publish_counts_as_online_only_once_the_app_answers_at_its_address()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake([
            // The hosting platform is still starting the app at first.
            'shop.example.com/up' => Http::sequence()->push('', 503)->push('', 200),
            'shop.example.com/' => Http::response('', 302),
            // Signing in with an account that cannot exist is turned down.
            'shop.example.com/login' => Http::sequence()->push('', 200, ['Set-Cookie' => 'XSRF-TOKEN=abc%3D; path=/'])->push(['errors' => ['email' => ['These credentials do not match our records.']]], 422),
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertNotNull($deployment->confirmed_at);
        $this->assertSame([
            ['path' => '/up', 'status' => 200, 'passed' => true],
            ['path' => '/', 'status' => 302, 'passed' => true],
            ['path' => '/login', 'status' => 422, 'passed' => true, 'key' => 'auth.sign-in'],
        ], $deployment->health);
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('X-XSRF-TOKEN', 'abc=') && $request['email'] === 'publish-check@example.invalid');

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.published_at', $deployment->finished_at?->toIso8601String())
                ->where('publishing.address', 'https://shop.example.com')
                ->where('publishing.deployments.0.status', 'published'));
    }

    public function test_a_pushed_app_that_does_not_answer_needs_attention_and_is_not_called_online()
    {
        config(['builder.publishing.confirm.confirm_seconds' => 0]);
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake(['shop.example.com/*' => Http::response('Server Error', 500)]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->status);
        $this->assertNotNull($deployment->pushed_at);
        $this->assertNull($deployment->confirmed_at);
        $this->assertSame('Your hosting has the new version, but the app is not answering properly at https://shop.example.com.', $deployment->error);
        // The host is done, so checking the same version again tells nothing new.
        $this->assertNull($deployment->error_cause);
        $this->actingAs($this->owner)
            ->post(route('deployment-checks.store', $this->project))
            ->assertSessionHasErrors(['check' => 'There is nothing to check right now.']);
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('project.published_at', null));
    }

    public function test_an_app_where_people_cannot_sign_in_needs_attention()
    {
        config(['builder.publishing.confirm.confirm_seconds' => 0]);
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake([
            'shop.example.com/login' => Http::sequence()->push('', 200)->push('Server Error', 500),
            'shop.example.com/*' => Http::response('', 200),
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->status);
        $this->assertSame('Your app is online at https://shop.example.com, but people cannot sign in.', $deployment->error);
    }

    public function test_an_app_without_a_sign_in_page_is_online_once_it_answers()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        Http::fake([
            'shop.example.com/login' => Http::response('Not Found', 404),
            'shop.example.com/*' => Http::response('', 200),
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertSame(['/up', '/'], array_column($deployment->health ?? [], 'path'));
    }

    public function test_the_app_address_must_be_a_public_https_address()
    {
        config(['builder.publishing.allow_local_remotes' => false]);

        foreach (['http://shop.example.com', 'https://10.0.0.5', 'https://localhost', 'https://db.internal', 'not a url'] as $address) {
            $this->actingAs($this->owner)
                ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'main', 'live_url' => $address])
                ->assertSessionHasErrors('live_url');
        }

        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://shop.example.com', $this->project->refresh()->live_url);
    }

    public function test_a_failing_check_stops_the_publish_and_nothing_is_pushed()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->driver->onExec = fn (string $workspace, array $command) => $command === ['php', 'artisan', 'test']
            ? new CommandResult(exitCode: 1, output: "FAILED  Tests\\Feature\\CartTest > it totals the cart\n", errorOutput: 'sk-ant-api03-'.str_repeat('a', 40), durationMs: 5)
            : new CommandResult(exitCode: 0, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('A check did not pass, so I did not publish. Your app online has not changed.', $deployment->error);
        $this->assertSame([true, false, true], array_column((array) $deployment->checks, 'passed'));

        // What the failed check said is kept for a fix, without secrets,
        // and stays off the owner's page.
        $failed = (array) $deployment->checks[1];
        $this->assertStringContainsString('CartTest > it totals the cart', $failed['output'] ?? '');
        $this->assertStringNotContainsString('sk-ant-api03', $failed['output'] ?? '');
        $this->assertArrayNotHasKey('output', (array) $deployment->checks[0]);
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.checks.1', ['name' => $failed['name'], 'passed' => false]));
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
        // Sending again is refused the same way, so a developer is the step, not the settings.
        $this->assertSame('conflict', $deployment->error_cause);
        $this->assertSame($theirs, trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output()));
    }

    public function test_push_errors_are_recorded_without_credentials()
    {
        $this->project->update(['deploy_remote' => 'https://builder:secret-token@127.0.0.1:1/acme/shop.git', 'deploy_branch' => 'main']);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        // The owner reads which setting to check; Git's words wait behind Details.
        $this->assertSame('I could not send it to the repository at https://127.0.0.1:1/acme/shop.git. Check the repository address and branch under "Change where to publish", then try again. Your app online has not changed.', $deployment->error);
        $this->assertSame('settings', $deployment->error_cause);
        $this->assertNotEmpty($deployment->error_details);
        $this->assertStringNotContainsString('secret-token', (string) $deployment->error_details);
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.deployments.0.error_cause', 'settings')
                ->where('publishing.deployments.0.error_details', $deployment->error_details));
    }

    public function test_an_unexpected_failure_says_it_is_our_fault_and_keeps_what_it_said_for_details()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $host = Mockery::mock(GitBranchHost::class, [app(ProjectRepository::class)])->makePartial();
        $host->shouldReceive('release')->andThrow(new LogicException('Undefined index: release_id with key sk-ant-'.str_repeat('a', 40)));
        $hosts = app(PublishingHostManager::class)->extend('git', fn () => $host);
        $this->app->instance(PublishingHostManager::class, $hosts);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('This is our fault: publishing stopped on our side. Your app online has not changed. Try again.', $deployment->error);
        $this->assertSame('ours', $deployment->error_cause);
        $this->assertStringContainsString('Undefined index: release_id', (string) $deployment->error_details);
        $this->assertStringNotContainsString('sk-ant-', (string) $deployment->error_details);
    }

    public function test_a_publish_that_dies_part_way_says_it_is_our_fault_and_to_try_again()
    {
        $deployment = Deployment::factory()->for($this->project)->create(['user_id' => $this->owner->id, 'status' => DeploymentStatus::Pushing]);

        (new PublishDeployment($deployment))->failed(new RuntimeException('The worker was killed after 3600 seconds.'));

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('This is our fault: publishing stopped before it finished. Your app online may not have changed. Try again.', $deployment->error);
        $this->assertSame('ours', $deployment->error_cause);
        $this->assertSame('The worker was killed after 3600 seconds.', $deployment->error_details);
    }

    public function test_giving_the_web_address_after_a_send_checks_it_is_online_without_sending_again()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        $sent = Deployment::sole();
        $this->assertSame(DeploymentStatus::Sent, $sent->status);
        Http::fake(['shop.example.com/*' => Http::response('', 200)]);
        $this->travel(2)->days();

        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Saved. I am checking that your app is online.');

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        // Checked, not sent again: the push is the one from two days ago.
        $this->assertTrue($sent->pushed_at?->equalTo($deployment->pushed_at));
        $this->assertNotNull($deployment->confirmed_at);
    }

    public function test_a_check_asked_for_by_hand_can_find_the_app_not_working()
    {
        config(['builder.publishing.confirm.confirm_seconds' => 0]);
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        // Newer work is waiting, so giving the address does not check the old version on its own.
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['app/Models/Plan.php' => "<?php\n"], 'Add plans', null);
        Http::fake(['shop.example.com/*' => Http::response('Server Error', 500)]);

        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com'])
            ->assertInertiaFlash('toast.message', 'Saved. You can publish now.');
        $this->assertSame(DeploymentStatus::Sent, Deployment::sole()->status);

        $this->actingAs($this->owner)->post(route('deployment-checks.store', $this->project))->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->status);
        $this->assertNull($deployment->error_cause);
        $this->assertContains(['path' => '/', 'status' => 500, 'passed' => false], $deployment->health);
    }

    public function test_there_is_nothing_to_check_without_an_address_or_once_the_app_is_online()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $this->actingAs($this->owner)
            ->post(route('deployment-checks.store', $this->project))
            ->assertSessionHasErrors(['check' => 'Add your app\'s web address first, so I know where to check.']);

        $this->project->update(['live_url' => 'https://shop.example.com']);
        Deployment::sole()->update(['status' => DeploymentStatus::Published]);

        $this->actingAs($this->owner)
            ->post(route('deployment-checks.store', $this->project))
            ->assertSessionHasErrors(['check' => 'There is nothing to check right now.']);
        $this->actingAs(User::factory()->create())
            ->post(route('deployment-checks.store', $this->project))
            ->assertForbidden();
        $this->assertSame(DeploymentStatus::Published, Deployment::sole()->status);
    }

    public function test_a_check_that_broke_on_our_side_says_so_and_can_be_checked_again()
    {
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main', 'live_url' => 'https://shop.example.com']);
        $deployment = Deployment::factory()->for($this->project)->create(['user_id' => $this->owner->id, 'status' => DeploymentStatus::Confirming, 'pushed_at' => now()]);

        (new ConfirmDeployment($deployment))->failed(new RuntimeException('cURL error 6 with key sk-ant-'.str_repeat('b', 40)));

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->status);
        $this->assertSame('This is our fault: your hosting has the new version, but I could not check that the app is online. Check again.', $deployment->error);
        $this->assertSame('ours', $deployment->error_cause);
        $this->assertStringContainsString('cURL error 6', (string) $deployment->error_details);
        $this->assertStringNotContainsString('sk-ant-', (string) $deployment->error_details);

        Http::fake(['shop.example.com/*' => Http::response('', 200)]);
        $this->actingAs($this->owner)->post(route('deployment-checks.store', $this->project))->assertSessionHasNoErrors();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertNull($deployment->error);
        $this->assertNull($deployment->error_cause);
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

    public function test_only_the_version_the_owner_looked_at_goes_online()
    {
        Queue::fake();
        $this->project->update(['deploy_remote' => $this->remote, 'deploy_branch' => 'main']);
        $seen = $this->repository->head($this->project);

        // A change is kept in another tab after the owner looked.
        $this->repository->commitFiles($this->project, $seen, ['late.txt' => "late\n"], 'Add late', null);

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $seen])
            ->assertSessionHasErrors(['publish' => 'Your app changed since you looked. Check what goes online now, then put it online.']);

        $this->assertSame(0, Deployment::count());

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $this->repository->head($this->project)])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->repository->head($this->project), Deployment::sole()->commit_sha);
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
