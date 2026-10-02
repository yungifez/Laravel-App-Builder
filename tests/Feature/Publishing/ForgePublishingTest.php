<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Publishing\GitHubRepositories;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Forge\ForgeManager;
use Psr\Http\Message\RequestInterface;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ForgePublishingTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected string $remote;

    /**
     * Our servers in Forge, by ID, with how many sites each has.
     *
     * @var array<int, array{name: string, sites: int}>
     */
    protected array $servers = [7 => ['name' => 'apps-one', 'sites' => 2]];

    /**
     * The site's settings file as Forge keeps it.
     */
    protected string $environment = "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=base64:abc\nDB_CONNECTION=sqlite\n";

    /**
     * Forge's answers to the release's progress, in order.
     *
     * @var list<string>
     */
    protected array $releaseStatuses = ['deploying', 'finished'];

    /**
     * How many times Forge refuses to make the site before it does.
     */
    protected int $siteRefusals = 0;

    /**
     * The app's backup settings and copies in Forge.
     *
     * @var list<array<string, mixed>>
     */
    protected array $backupConfigurations = [];

    /**
     * @var list<array<string, mixed>>
     */
    protected array $copies = [];

    /**
     * How Forge's next copy of the database ends.
     */
    protected string $copyStatus = 'success';

    /**
     * What was asked of Forge: method, path and body.
     *
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected array $forgeRequests = [];

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

        // Forge's SDK talks through its own HTTP client, so a handler stands
        // in for Forge. No request leaves the test.
        $this->app->instance(ForgeManager::class, new ForgeManager('forge-token', new Client([
            'base_uri' => 'https://forge.laravel.com/api/',
            'http_errors' => false,
            'handler' => fn (RequestInterface $request) => $this->answerAsForge($request),
        ])));

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60]],
            'builder.publishing.host' => 'forge',
            'services.forge.token' => 'forge-token',
            'builder.publishing.forge.organization' => 'acme',
            'builder.publishing.forge.server_prefix' => 'apps',
            'builder.publishing.forge.sites_per_server' => 3,
            'builder.publishing.forge.credential' => null,
            'builder.publishing.forge.network' => null,
            'builder.publishing.forge.region' => null,
            'builder.publishing.forge.size' => null,
            'builder.publishing.forge.database_type' => 'postgres18',
            'builder.publishing.forge.backup_storage' => '5',
            'builder.publishing.forge.backup_retention' => 7,
            'builder.publishing.github.organization' => 'acme-apps',
            'builder.publishing.github.token' => 'github-token',
        ]);

        // GitHub, and the published app at its on-forge.com address.
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);

            return match (true) {
                $request->url() === 'https://api.github.com/orgs/acme-apps/repos' => Http::response(['full_name' => 'acme-apps/acme-shop-'.$this->project->id], 201),
                $host === $this->address() && str_ends_with($request->url(), '/login') => Http::response('', $request->method() === 'POST' ? 422 : 200),
                $host === $this->address() => Http::response('', 200),
                default => Http::response('Unexpected request', 500),
            };
        });
    }

    public function test_an_owner_publishes_to_a_forge_server_with_room_with_one_click()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.connected', true)
                ->where('publishing.managed', true));

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $deployment = Deployment::sole();
        $this->project->refresh();
        $this->assertSame(DeploymentStatus::Published, $deployment->status);
        $this->assertSame('forge', $deployment->host);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame('51', $deployment->host_release_id);
        $this->assertSame('finished', $deployment->host_status);
        $this->assertSame('https://'.$this->address(), $this->project->live_url);
        // The database password is not kept once it is in the app's settings.
        $this->assertSame(['repository' => 'acme-apps/acme-shop-'.$this->project->id, 'server' => '7', 'database' => '31', 'site' => '41'], $this->project->host_state);
        $this->assertSame($deployment->commit_sha, trim(Process::run(['git', '--git-dir', $this->remote, 'rev-parse', 'refs/heads/main'])->output()));

        $database = $this->sent('POST', 'orgs/acme/servers/7/database/schemas');
        $this->assertSame('app_'.$this->project->id, $database['name']);
        $this->assertSame('app_'.$this->project->id, $database['user']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $database['password']);

        $site = $this->sent('POST', 'orgs/acme/servers/7/sites');
        $this->assertSame('on-forge', $site['domain_mode']);
        $this->assertSame('acme-apps/acme-shop-'.$this->project->id, $site['repository']);
        $this->assertSame('main', $site['branch']);
        $this->assertSame(31, $site['database_id']);
        // Forge deploys only the versions whose checks passed, never on push.
        $this->assertFalse($site['push_to_deploy']);

        $settings = Dotenv::parse($this->environment);
        $this->assertSame('base64:abc', $settings['APP_KEY']);
        $this->assertSame('production', $settings['APP_ENV']);
        $this->assertSame('https://'.$this->address(), $settings['APP_URL']);
        $this->assertSame('pgsql', $settings['DB_CONNECTION']);
        $this->assertSame('app_'.$this->project->id, $settings['DB_DATABASE']);
        $this->assertSame($database['password'], $settings['DB_PASSWORD']);

        // The settings are written before the release starts.
        $this->assertLessThan($this->position('POST', 'orgs/acme/servers/7/sites/41/deployments'), $this->position('PUT', 'orgs/acme/servers/7/sites/41/environment'));
        $this->assertSame(0, $this->sentCount('POST', 'orgs/acme/servers'));
    }

    public function test_a_second_publish_reuses_the_site_and_sends_the_service_keys()
    {
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        $this->project->refresh()->forceFill(['service_keys' => ['email' => ['RESEND_API_KEY' => 're_c', 'MAIL_FROM_NAME' => 'Acme $hop "One"']]])->save();
        app(ProjectRepository::class)->commitFiles($this->project, app(ProjectRepository::class)->head($this->project), ['a.txt' => "a\n"], 'Add a', null);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame(DeploymentStatus::Published, Deployment::query()->latest('id')->firstOrFail()->status);
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/sites'));
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/database/schemas'));
        $this->assertSame(2, $this->sentCount('POST', 'orgs/acme/servers/7/sites/41/deployments'));

        // A value with spaces, quotes or a dollar sign reads back unchanged.
        $settings = Dotenv::parse($this->environment);
        $this->assertSame('re_c', $settings['RESEND_API_KEY']);
        $this->assertSame('Acme $hop "One"', $settings['MAIL_FROM_NAME']);
        $this->assertSame('pgsql', $settings['DB_CONNECTION']);
    }

    public function test_when_every_server_is_full_a_new_one_is_made_on_hetzner()
    {
        $this->servers = [7 => ['name' => 'apps-one', 'sites' => 3], 8 => ['name' => 'someone-elses', 'sites' => 0]];
        config([
            'builder.publishing.forge.credential' => '11',
            'builder.publishing.forge.network' => '12',
            'builder.publishing.forge.region' => 'fsn1',
            'builder.publishing.forge.size' => 'cx23',
        ]);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame(DeploymentStatus::Published, Deployment::sole()->status);
        $server = $this->sent('POST', 'orgs/acme/servers');
        $this->assertStringStartsWith('apps-', $server['name']);
        $this->assertLessThanOrEqual(30, strlen($server['name']));
        $this->assertSame('hetzner', $server['provider']);
        $this->assertSame(11, $server['credential_id']);
        $this->assertSame('app', $server['type']);
        $this->assertSame('postgres18', $server['database_type']);
        $this->assertSame(['region_id' => 'fsn1', 'size_id' => 'cx23', 'network_id' => 12], $server['hetzner']);
        // A server not named for our apps is never used.
        $this->assertSame(0, $this->sentCount('GET', 'orgs/acme/servers/8/sites'));
        $this->assertSame('9', $this->project->refresh()->host_state['server']);
    }

    public function test_when_every_server_is_full_and_none_can_be_made_the_owner_is_told_plainly()
    {
        $this->servers = [7 => ['name' => 'apps-one', 'sites' => 3]];

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('Your hosting did not take the new version. Your app online has not changed.', $deployment->error);
        $this->assertSame(0, $this->sentCount('POST', 'orgs/acme/servers'));
        $this->assertNull($this->project->refresh()->live_url);
    }

    public function test_a_publish_that_stopped_halfway_picks_up_without_making_things_twice()
    {
        $this->siteRefusals = 1;

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $this->assertSame(DeploymentStatus::Failed, Deployment::sole()->status);
        $this->assertArrayHasKey('database_password', $this->project->refresh()->host_state);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame(DeploymentStatus::Published, Deployment::query()->latest('id')->firstOrFail()->status);
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/database/schemas'));
        $this->assertSame(2, $this->sentCount('POST', 'orgs/acme/servers/7/sites'));
        $this->assertSame($this->sent('POST', 'orgs/acme/servers/7/database/schemas')['password'], Dotenv::parse($this->environment)['DB_PASSWORD']);
        $this->assertArrayNotHasKey('database_password', $this->project->refresh()->host_state);
    }

    public function test_a_version_forge_cannot_start_leaves_the_app_online_unchanged()
    {
        $this->releaseStatuses = ['failed-build'];

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));

        $deployment = Deployment::sole();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('failed-build', $deployment->host_status);
        $this->assertSame('Your hosting could not start the new version, so your app online has not changed.', $deployment->error);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'on-forge.com'));
    }

    public function test_without_forge_settings_the_owner_is_asked_where_to_publish()
    {
        config(['builder.publishing.forge.organization' => null]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.connected', false));
    }

    public function test_a_copy_of_the_database_is_saved_before_a_release_that_changes_how_it_is_stored()
    {
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        $this->publishMigration('2026_01_01_000000_add_notes');
        $this->publishMigration('2026_01_02_000000_add_tags');

        [$first, $notes, $tags] = Deployment::query()->oldest('id')->get()->all();
        // Nothing was online before the first publish, so nothing to copy.
        $this->assertNull($first->backup_id);
        $this->assertSame('61/71', $notes->backup_id);
        $this->assertSame('61/72', $tags->backup_id);
        $this->assertSame(DeploymentStatus::Published, $tags->status);

        $settings = $this->sent('POST', 'orgs/acme/servers/7/database/backups');
        $this->assertSame(5, $settings['storage_provider_id']);
        $this->assertSame([31], $settings['database_ids']);
        $this->assertSame('daily', $settings['frequency']);
        $this->assertSame(7, $settings['retention']);
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/database/backups'));
        $this->assertSame('61', $this->project->refresh()->host_state['backups']);

        // The copy is finished before the release starts.
        $this->assertLessThan($this->lastPosition('POST', 'orgs/acme/servers/7/sites/41/deployments'), $this->lastPosition('POST', 'orgs/acme/servers/7/database/backups/61/instances'));
    }

    public function test_without_a_copy_the_release_does_not_go_online()
    {
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        $this->copyStatus = 'failed';

        $this->publishMigration('2026_01_01_000000_add_notes');

        $deployment = Deployment::query()->latest('id')->firstOrFail();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertNull($deployment->backup_id);
        $this->assertSame("I could not save a copy of your app's information first, so I did not publish. Your app online has not changed.", $deployment->error);
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/sites/41/deployments'));
    }

    public function test_without_storage_for_copies_a_release_that_changes_storage_does_not_go_online()
    {
        $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
        config(['builder.publishing.forge.backup_storage' => null]);

        $this->publishMigration('2026_01_01_000000_add_notes');

        $this->assertSame(DeploymentStatus::Failed, Deployment::query()->latest('id')->firstOrFail()->status);
        $this->assertSame(0, $this->sentCount('POST', 'orgs/acme/servers/7/database/backups'));
        $this->assertSame(1, $this->sentCount('POST', 'orgs/acme/servers/7/sites/41/deployments'));
    }

    /**
     * Commit a new migration and publish it.
     */
    protected function publishMigration(string $name): void
    {
        $repository = app(ProjectRepository::class);
        $repository->commitFiles($this->project, $repository->head($this->project), ["database/migrations/{$name}.php" => "<?php\n"], "Add {$name}", null);

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();
    }

    /**
     * Answer as Forge's API does, as its published description says.
     */
    protected function answerAsForge(RequestInterface $request): PromiseInterface
    {
        $method = $request->getMethod();
        $path = Str::after($request->getUri()->getPath(), '/api/');
        $body = json_decode((string) $request->getBody(), true) ?: [];
        $this->forgeRequests[] = [$method, $path, $body];

        $server = fn (int $id, array $server) => ['id' => (string) $id, 'type' => 'servers', 'attributes' => ['name' => $server['name'], 'is_ready' => true, 'revoked' => false]];
        $site = ['id' => '41', 'type' => 'sites', 'attributes' => ['name' => 'acme-shop', 'status' => 'installed', 'url' => $this->address()]];

        $answer = match (true) {
            $method === 'GET' && $path === 'orgs/acme/servers' => ['data' => array_map($server, array_keys($this->servers), $this->servers), 'meta' => ['next_cursor' => null]],
            $method === 'POST' && $path === 'orgs/acme/servers' => [202, ['data' => ['id' => '9', 'type' => 'servers', 'attributes' => ['name' => $body['name'], 'is_ready' => false]]]],
            $method === 'GET' && $path === 'orgs/acme/servers/9' => ['data' => $server(9, ['name' => 'apps-new'])],
            $method === 'GET' && preg_match('#^orgs/acme/servers/(\d+)/sites$#', $path, $match) === 1 => [
                'data' => array_fill(0, $this->servers[(int) $match[1]]['sites'] ?? 0, ['id' => '1', 'type' => 'sites', 'attributes' => ['name' => 'other']]),
                'meta' => ['next_cursor' => null],
            ],
            $method === 'POST' && str_ends_with($path, '/database/schemas') => [202, ['data' => ['id' => '31', 'type' => 'databases', 'attributes' => ['name' => $body['name'], 'status' => 'installing']]]],
            $method === 'GET' && str_ends_with($path, '/database/schemas/31') => ['data' => ['id' => '31', 'type' => 'databases', 'attributes' => ['status' => 'installed']]],
            $method === 'POST' && preg_match('#^orgs/acme/servers/\d+/sites$#', $path) === 1 => $this->siteRefusals-- > 0
                ? [500, ['message' => 'Server Error']]
                : [202, ['data' => ['status' => 'creating'] + $site]],
            $method === 'GET' && $path === 'orgs/acme/sites/41' => ['data' => $site],
            $method === 'GET' && str_ends_with($path, '/sites/41/environment') => ['data' => ['id' => '41', 'type' => 'environments', 'attributes' => ['content' => $this->environment]]],
            $method === 'PUT' && str_ends_with($path, '/sites/41/environment') => [202, $this->environment = $body['environment']],
            $method === 'POST' && str_ends_with($path, '/sites/41/deployments') => [202, ['data' => ['id' => '51', 'type' => 'deployments', 'attributes' => ['status' => 'queued']]]],
            $method === 'GET' && str_ends_with($path, '/sites/41/deployments/51') => ['data' => ['id' => '51', 'type' => 'deployments', 'attributes' => ['status' => array_shift($this->releaseStatuses) ?? 'finished']]],
            $method === 'GET' && $path === 'orgs/acme/servers/7/database/backups' => ['data' => $this->backupConfigurations, 'meta' => ['next_cursor' => null]],
            $method === 'POST' && $path === 'orgs/acme/servers/7/database/backups' => [202, $this->backupConfigurations[] = ['id' => '61', 'type' => 'backupConfigurations', 'attributes' => ['name' => $body['name']]]],
            $method === 'GET' && $path === 'orgs/acme/servers/7/database/backups/61/instances' => ['data' => $this->copies, 'meta' => ['next_cursor' => null]],
            $method === 'POST' && $path === 'orgs/acme/servers/7/database/backups/61/instances' => [202, $this->copies[] = [
                'id' => (string) (71 + count($this->copies)),
                'type' => 'backups',
                'attributes' => ['status' => $this->copyStatus, 'finished_at' => 1790000000],
            ]],
            default => [500, ['message' => "Unexpected request: {$method} {$path}"]],
        };

        [$status, $json] = is_int($answer[0] ?? null) ? $answer : [200, $answer];

        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/vnd.api+json'], is_array($json) ? (string) json_encode($json) : ''));
    }

    protected function address(): string
    {
        return 'acme-shop-'.$this->project->id.'.on-forge.com';
    }

    /**
     * Get the body of the one request sent to Forge with the method and path.
     *
     * @return array<string, mixed>
     */
    protected function sent(string $method, string $path): array
    {
        $bodies = array_values(array_filter($this->forgeRequests, fn (array $request) => $request[0] === $method && $request[1] === $path));
        $this->assertNotEmpty($bodies, "Nothing was sent: {$method} {$path}");

        return $bodies[0][2];
    }

    protected function sentCount(string $method, string $path): int
    {
        return count(array_filter($this->forgeRequests, fn (array $request) => $request[0] === $method && $request[1] === $path));
    }

    protected function lastPosition(string $method, string $path): int
    {
        return max(array_keys(array_filter($this->forgeRequests, fn (array $request) => $request[0] === $method && $request[1] === $path)) ?: [-1]);
    }

    protected function position(string $method, string $path): int
    {
        foreach ($this->forgeRequests as $index => $request) {
            if ($request[0] === $method && $request[1] === $path) {
                return $index;
            }
        }

        $this->fail("Nothing was sent: {$method} {$path}");
    }
}
