<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Publishing\GitHubRepositories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class LaravelCloudPublishingTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected string $remote;

    /**
     * Cloud's answers to the release's progress, in order.
     *
     * @var list<string>
     */
    protected array $releaseStatuses = ['build.running', 'deployment.succeeded'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme Shop', $this->makeProjectSource(), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);

        // A bare repository stands in for the app's repository in our GitHub
        // organization. Nothing is pushed anywhere else.
        $this->remote = sys_get_temp_dir().'/builder-test-github-'.Str::lower(Str::random(8)).'.git';
        Process::run(['git', 'init', '--quiet', '--bare', $this->remote])->throw();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->remote));
        $remote = $this->remote;
        $this->app->instance(GitHubRepositories::class, new class($remote) extends GitHubRepositories
        {
            public function __construct(private string $bare) {}

            public function remote(string $repository): string
            {
                return $this->bare;
            }
        });

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60]],
            'builder.publishing.host' => 'laravel_cloud',
            'builder.publishing.laravel_cloud.token' => 'cloud-token',
            'builder.publishing.laravel_cloud.region' => 'eu-central-1',
            'builder.publishing.laravel_cloud.database_cluster' => 'cluster-1',
            'builder.publishing.github.organization' => 'acme-apps',
            'builder.publishing.github.token' => 'github-token',
        ]);
    }

    /**
     * Answer as GitHub, Laravel Cloud and the published app would.
     */
    protected function fakeHosts(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/orgs/acme-apps/repos' => Http::response(['full_name' => 'acme-apps/acme-shop-'.$this->project->id], 201),
                $path === '/api/applications' => Http::response(['data' => ['id' => 'app-1', 'relationships' => ['defaultEnvironment' => ['data' => ['id' => 'env-1']]]]], 201),
                $path === '/api/databases/clusters/cluster-1/databases' => Http::response(['data' => ['id' => 'db-1']], 201),
                $path === '/api/environments/env-1' => Http::response(['data' => ['id' => 'env-1', 'attributes' => ['vanity_domain' => 'acme-shop.laravel.cloud']]]),
                $path === '/api/environments/env-1/deployments' => Http::response(['data' => ['id' => 'release-'.Deployment::query()->count(), 'attributes' => ['status' => 'pending']]], 201),
                str_starts_with($path, '/api/deployments/') => Http::response(['data' => ['attributes' => ['status' => array_shift($this->releaseStatuses) ?? 'deployment.succeeded']]]),
                $request->url() === 'https://acme-shop.laravel.cloud/up', $request->url() === 'https://acme-shop.laravel.cloud/' => Http::response('', 200),
                $request->url() === 'https://acme-shop.laravel.cloud/login' => Http::response('', $request->method() === 'POST' ? 422 : 200),
                default => Http::response('Unexpected request', 500),
            };
        });
    }

    public function test_an_owner_publishes_to_laravel_cloud_with_one_click()
    {
        $this->fakeHosts();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.connected', true)
                ->where('publishing.managed', true));

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->project->refresh();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertSame('laravel_cloud', $deployment->host);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame('release-1', $deployment->host_release_id);
        $this->assertSame('deployment.succeeded', $deployment->host_status);
        $this->assertSame('https://acme-shop.laravel.cloud', $this->project->live_url);
        $this->assertSame(['repository' => 'acme-apps/acme-shop-'.$this->project->id, 'application' => 'app-1', 'environment' => 'env-1', 'database' => 'db-1'], $this->project->host_state);
        $this->assertSame($deployment->commit_sha, trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output()));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/acme-apps/repos' && $request['private'] === true && $request->hasHeader('Authorization', 'Bearer github-token'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://cloud.laravel.com/api/applications' && $request['region'] === 'eu-central-1' && $request->hasHeader('Authorization', 'Bearer cloud-token'));
        // Cloud deploys only the versions whose checks passed, never on push.
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['uses_push_to_deploy'] === false && $request['database_schema_id'] === 'db-1');
        // While Cloud builds, the old version still answers, so the address
        // is checked only once Cloud says the new one is live.
        Http::assertSentCount(11);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.address', 'https://acme-shop.laravel.cloud'));
    }

    public function test_a_second_publish_reuses_the_app_on_cloud()
    {
        $this->fakeHosts();
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        app(ProjectRepository::class)->commitFiles($this->project, app(ProjectRepository::class)->head($this->project), ['a.txt' => "a\n"], 'Add a', null);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame(DeploymentStatus::Published, Deployment::query()->latest('id')->firstOrFail()->status);
        Http::assertSentCount(11 + 6);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/applications')));
    }

    public function test_a_version_cloud_cannot_start_leaves_the_app_online_unchanged()
    {
        $this->releaseStatuses = ['build.failed'];
        $this->fakeHosts();

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('build.failed', $deployment->host_status);
        $this->assertSame('Your hosting could not start the new version, so your app online has not changed.', $deployment->error);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'acme-shop.laravel.cloud'));
    }

    public function test_when_cloud_refuses_the_owner_is_told_plainly_and_nothing_leaks()
    {
        Http::fake([
            'api.github.com/*' => Http::response(['full_name' => 'acme-apps/acme-shop-1'], 201),
            'cloud.laravel.com/*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('Your hosting did not take the new version. Your app online has not changed.', $deployment->error);
    }

    public function test_without_cloud_settings_the_owner_is_asked_where_to_publish()
    {
        config(['builder.publishing.laravel_cloud.token' => null]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.connected', false));

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project))
            ->assertSessionHasErrors(['publish' => 'Choose where to publish first.']);
    }

    public function test_an_owner_can_use_their_own_hosting_instead()
    {
        config(['builder.publishing.allow_local_remotes' => true]);

        $this->actingAs($this->owner)
            ->put(route('projects.publishing.update', $this->project), ['deploy_remote' => $this->remote, 'deploy_branch' => 'live'])
            ->assertSessionHasNoErrors();

        $this->project->refresh();
        $this->assertSame('git', $this->project->publishingHost());
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.managed', false));
    }
}
