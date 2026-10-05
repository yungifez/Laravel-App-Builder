<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Projects\Exceptions\RepositoryMissing;
use App\Projects\ProjectRepository;
use App\Publishing\GitHubRepositories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * On a host whose disks are wiped on each deploy and not shared between
 * servers, every project's repository is kept in the project store. Two
 * folders stand in for two servers' disks.
 */
class ProjectStoreTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected string $web;

    protected string $worker;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('project-store');
        $this->web = sys_get_temp_dir().'/builder-store-web-'.Str::lower(Str::random(8));
        $this->worker = sys_get_temp_dir().'/builder-store-worker-'.Str::lower(Str::random(8));
        $this->beforeApplicationDestroyed(fn () => [File::deleteDirectory($this->web), File::deleteDirectory($this->worker)]);

        config(['builder.projects.store.driver' => 'disk', 'builder.projects.store.disk' => 'project-store', 'builder.projects.store.prefix' => 'projects']);
        $this->onServer($this->web);
    }

    public function test_a_change_made_on_one_server_is_seen_on_another(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);
        Storage::disk('project-store')->assertExists("projects/{$project->id}.bundle");

        $this->onServer($this->worker);
        $change = $repository->commitFiles($project->refresh(), $repository->head($project), ['a.txt' => "a\n"], 'Add a', null);

        $this->onServer($this->web);
        $this->assertSame($change, $repository->head($project->refresh()));
        $this->assertSame("a\n", $repository->show($project, $change, 'a.txt'));
        $this->assertSame(2, $project->refresh()->repository_version);
    }

    public function test_a_server_with_an_older_copy_does_not_drop_newer_commits_when_it_writes(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);

        $this->onServer($this->worker);
        $first = $repository->commitFiles($project->refresh(), $import, ['a.txt' => "a\n"], 'Add a', null);

        // The web server's copy still ends at the import.
        $this->onServer($this->web);
        $second = $repository->commitFiles($project->refresh(), $first, ['b.txt' => "b\n"], 'Add b', null);

        $this->onServer($this->worker);
        $this->assertSame($second, $repository->head($project->refresh()));
        $this->assertTrue($repository->isAncestor($project, $first, $second));
    }

    public function test_a_wiped_disk_gets_every_project_back_from_the_store(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);
        $repository->createBranch($project->refresh(), 'idea', $import);
        $release = $repository->releaseCommit($project, $import, [$import], 'Release', null, 'refs/releases/1');

        // A deploy replaces the server and its disk.
        File::deleteDirectory($this->web);

        $this->assertTrue($repository->exists($project->refresh()));
        $this->assertSame($import, $repository->head($project));
        $this->assertTrue($repository->hasBranch($project, 'idea'));
        $this->assertSame($release, trim($repository->git($project, ['rev-parse', 'refs/releases/1'])->output()));
        // The working tree is back as well, for commands that read files.
        $this->assertFileExists($repository->path($project).'/config/teams.php');
    }

    public function test_a_project_whose_saved_copy_is_gone_says_it_is_missing(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);
        File::deleteDirectory($this->web);
        Storage::disk('project-store')->delete("projects/{$project->id}.bundle");

        $this->expectException(RepositoryMissing::class);

        $repository->head($project->refresh());
    }

    public function test_a_change_the_store_did_not_take_is_not_kept(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);
        config(['filesystems.disks.project-store.root' => '/proc/not-writable']);
        Storage::forgetDisk('project-store');

        try {
            $repository->commitFiles($project->refresh(), $import, ['a.txt' => "a\n"], 'Add a', null);
            $this->fail('A change the store did not take was reported as kept.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('This is our fault', $exception->getMessage());
        }

        Storage::fake('project-store');
        $this->assertSame(1, $project->refresh()->repository_version);
    }

    public function test_a_server_removes_copies_it_did_not_use_for_a_while_and_gets_them_back_when_needed(): void
    {
        config(['builder.projects.store.idle_minutes' => 60]);
        $repository = app(ProjectRepository::class);
        $idle = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $busy = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($idle);
        $repository->import($busy);
        $repository->head($idle->refresh());
        $repository->head($busy->refresh());

        $this->travel(2)->hours();
        $repository->head($busy);

        $this->assertDirectoryDoesNotExist($repository->path($idle));
        $this->assertDirectoryExists($repository->path($busy));
        $this->assertSame($import, $repository->head($idle), 'The copy comes back from the store.');

        // At most one sweep every ten minutes.
        config(['builder.projects.store.idle_minutes' => 1]);
        $this->travel(5)->minutes();
        $repository->head($busy);
        $this->assertDirectoryExists($repository->path($idle));
    }

    public function test_a_forgotten_project_leaves_no_copy_here_or_in_the_store(): void
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);

        $repository->forget($project->id);

        $this->assertDirectoryDoesNotExist($repository->path($project));
        Storage::disk('project-store')->assertMissing("projects/{$project->id}.bundle");
    }

    public function test_a_server_drops_its_copy_of_a_project_deleted_elsewhere_at_once(): void
    {
        $repository = app(ProjectRepository::class);
        $deleted = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $kept = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($deleted);
        $repository->import($kept);

        $this->onServer($this->worker);
        $repository->head($deleted->refresh());
        $repository->head($kept->refresh());
        $deleted->delete();

        // Both were used a moment ago; only the deleted one goes.
        $this->travel(11)->minutes();
        $repository->head($kept);

        $this->assertDirectoryDoesNotExist($repository->path($deleted));
        $this->assertDirectoryExists($repository->path($kept));
    }

    public function test_on_github_a_forgotten_project_deletes_its_store_repository(): void
    {
        $remotes = $this->storeOnGitHub();
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);

        $repository->forget($project->id);

        $this->assertDirectoryDoesNotExist($repository->path($project));
        $this->assertDirectoryDoesNotExist("{$remotes}/acme-code/code-{$project->id}.git");
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === "https://api.github.test/repos/acme-code/code-{$project->id}");
    }

    public function test_without_the_store_copies_stay_on_the_disk(): void
    {
        config(['builder.projects.store.driver' => null]);
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);

        $this->travel(30)->days();
        $repository->head($project);

        $this->assertDirectoryExists($repository->path($project));
        $this->assertFileDoesNotExist($this->web.'/.swept');
    }

    public function test_on_github_a_change_made_on_one_server_is_seen_on_another(): void
    {
        $remotes = $this->storeOnGitHub();
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);

        $this->onServer($this->worker);
        $change = $repository->commitFiles($project->refresh(), $import, ['a.txt' => "a\n"], 'Add a', null);

        $this->onServer($this->web);
        $this->assertSame($change, $repository->head($project->refresh()));
        $this->assertSame($change, trim(Process::path("{$remotes}/acme-code/code-{$project->id}.git")->run(['git', 'rev-parse', 'refs/heads/main'])->throw()->output()));
        // The repository is made once, on the first save, by the app's ID.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.test/orgs/acme-code/repos' && $request['name'] === "code-{$project->id}" && $request['private'] === true);
    }

    public function test_on_github_a_wiped_disk_gets_the_project_back(): void
    {
        $this->storeOnGitHub();
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);
        $repository->createBranch($project->refresh(), 'idea', $import);

        File::deleteDirectory($this->web);

        $this->assertSame($import, $repository->head($project->refresh()));
        $this->assertTrue($repository->hasBranch($project, 'idea'));
        $this->assertFileExists($repository->path($project).'/config/teams.php');
    }

    public function test_on_github_a_missing_store_repository_says_the_code_is_missing(): void
    {
        $remotes = $this->storeOnGitHub();
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);
        File::deleteDirectory($this->web);
        File::deleteDirectory("{$remotes}/acme-code/code-{$project->id}.git");

        $this->expectException(RepositoryMissing::class);

        $repository->head($project->refresh());
    }

    public function test_on_github_a_change_github_did_not_take_is_not_kept(): void
    {
        $remotes = $this->storeOnGitHub();
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $import = $repository->import($project);
        File::deleteDirectory("{$remotes}/acme-code/code-{$project->id}.git");

        try {
            $repository->commitFiles($project->refresh(), $import, ['a.txt' => "a\n"], 'Add a', null);
            $this->fail('A change GitHub did not take was reported as kept.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('This is our fault', $exception->getMessage());
        }

        $this->assertSame(1, $project->refresh()->repository_version);
    }

    /**
     * Keep repositories in a GitHub organization. Bare repositories in a
     * temporary folder stand in for GitHub's, made when the API is asked.
     */
    protected function storeOnGitHub(): string
    {
        $remotes = sys_get_temp_dir().'/builder-store-github-'.Str::lower(Str::random(8));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($remotes));

        config([
            'builder.projects.store.driver' => 'github',
            'builder.projects.store.organization' => 'acme-code',
            'builder.projects.store.prefix' => 'code',
            'builder.publishing.github.url' => 'https://api.github.test',
            'builder.publishing.github.organization' => 'acme-apps',
            'builder.publishing.github.token' => 'github-token',
        ]);

        Http::fake(function ($request) use ($remotes) {
            if ($request->method() === 'DELETE') {
                File::deleteDirectory($remotes.'/'.Str::after($request->url(), '/repos/').'.git');

                return Http::response(status: 204);
            }

            $path = "{$remotes}/acme-code/{$request['name']}.git";
            File::ensureDirectoryExists($path);
            Process::path($path)->run(['git', 'init', '--quiet', '--bare'])->throw();

            return Http::response(['full_name' => "acme-code/{$request['name']}"], 201);
        });

        $this->app->instance(GitHubRepositories::class, new class($remotes) extends GitHubRepositories
        {
            public function __construct(private string $remotes) {}

            public function remote(string $repository): string
            {
                return "{$this->remotes}/{$repository}.git";
            }
        });

        return $remotes;
    }

    /**
     * Point the builder at another server's disk.
     */
    protected function onServer(string $root): void
    {
        config(['builder.projects.root' => $root]);
    }
}
