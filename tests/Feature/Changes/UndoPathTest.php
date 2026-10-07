<?php

namespace Tests\Feature\Changes;

use App\Actions\Changes\RevertChange;
use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Enums\RunStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/**
 * Every kept change can be undone with a commit of its own: newest first,
 * once, with the owner told what stays, and gone from the live app once it
 * is put online again.
 */
class UndoPathTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const MIGRATION = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n";

    protected ProjectRepository $repository;

    protected FakeWorkspaceDriver $driver;

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

    public function test_the_newest_kept_change_is_undone(): void
    {
        $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $newest = $this->keep('Add a plans page', ['app/Plan.php' => "<?php\n"]);

        $this->undo($newest)->assertSessionHasNoErrors();

        $this->assertNotNull($newest->refresh()->reverted_at);
        $this->assertFalse($this->exists('app/Plan.php'));
        $this->assertTrue($this->exists('app/Team.php'));
    }

    public function test_a_change_a_later_change_may_rely_on_is_undone_only_after_it(): void
    {
        $team = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        // Other files, so git sees no conflict, yet it may call the team code.
        $plans = $this->keep('Add a plans page', ['app/Plan.php' => "<?php\n"]);
        $head = $this->repository->head($this->project);

        $this->undo($team)->assertSessionHasErrors(['change' => 'A later change may rely on this one, so it cannot be undone by itself. Undo “Add a plans page” first, then undo this one.']);

        $this->assertNull($team->refresh()->reverted_at);
        $this->assertSame($head, $this->repository->head($this->project));

        // The step it names works.
        $this->undo($plans)->assertSessionHasNoErrors();
        $this->undo($team)->assertSessionHasNoErrors();

        $this->assertFalse($this->exists('app/Team.php'));
        $this->assertFalse($this->exists('app/Plan.php'));
    }

    public function test_a_later_change_that_was_undone_or_lives_on_another_line_does_not_block(): void
    {
        $team = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $plans = $this->keep('Add a plans page', ['app/Plan.php' => "<?php\n"]);
        $this->undo($plans)->assertSessionHasNoErrors();
        // A change whose commit does not come after this one cannot rely on it.
        FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'commit_sha' => str_repeat('e', 40), 'accepted_at' => now()]);

        $this->undo($team)->assertSessionHasNoErrors();

        $this->assertNotNull($team->refresh()->reverted_at);
    }

    public function test_undoing_a_change_shuts_out_the_owners_tool_at_once(): void
    {
        $change = $this->keep('Add a plans page', ['app/Plan.php' => "<?php\n"]);
        $run = Run::factory()->for($change)->create(['driver' => 'worker', 'status' => RunStatus::Completed]);
        $run->createToken('worker', ['task'], now()->addMinutes(10));

        $this->undo($change)->assertSessionHasNoErrors();

        $this->assertSame(0, $run->tokens()->count());
    }

    public function test_a_change_is_undone_once(): void
    {
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);

        $this->undo($change)->assertSessionHasNoErrors();
        $revert = $change->refresh()->revert_sha;

        $this->undo($change)->assertSessionHasErrors(['change' => 'You undid this change already.']);

        $this->assertSame($revert, $change->refresh()->revert_sha);
        $this->assertSame($revert, $this->repository->head($this->project));
    }

    public function test_a_second_click_that_read_the_change_before_the_first_undo_finished_does_nothing(): void
    {
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        // The second click loaded the change while it was still kept.
        $stale = FeatureRequest::query()->findOrFail($change->id);

        app(RevertChange::class)->handle($change, $this->owner);
        $revert = $this->repository->head($this->project);

        try {
            app(RevertChange::class)->handle($stale, $this->owner);
            $this->fail('A second undo went through.');
        } catch (ValidationException $exception) {
            $this->assertSame(['You undid this change already.'], $exception->errors()['change']);
        }

        $this->assertSame($revert, $this->repository->head($this->project));
    }

    public function test_a_change_never_kept_is_not_undone(): void
    {
        $change = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id]);

        $this->undo($change)->assertSessionHasErrors(['change' => 'Only an accepted change can be undone.']);
    }

    public function test_undoing_a_change_that_added_a_migration_says_stored_information_stays(): void
    {
        $change = $this->keep('Keep invoices', ['database/migrations/2026_10_05_000000_create_invoices_table.php' => self::MIGRATION]);

        $this->undo($change)->assertSessionHasNoErrors();

        // The file goes, and its down() is never run on the owner's data.
        $this->assertFalse($this->exists('database/migrations/2026_10_05_000000_create_invoices_table.php'));
        $this->assertKeptData($change, true);
    }

    public function test_undoing_a_change_without_a_migration_says_nothing_about_stored_information(): void
    {
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $this->assertKeptData($change, false);

        $this->undo($change)->assertSessionHasNoErrors();

        $this->assertKeptData($change, false);
    }

    public function test_a_kept_migration_that_is_not_undone_says_nothing_yet(): void
    {
        // A migration is known by what it is, not by where it lives.
        $change = $this->keep('Keep invoices', ['app/Invoices.php' => self::MIGRATION]);

        $this->assertKeptData($change, false);
    }

    public function test_an_undone_change_leaves_the_live_app_once_it_is_put_online_again(): void
    {
        $remote = $this->publishTo();
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $this->publish()->assertSessionHasNoErrors();
        $this->assertContains('app/Team.php', $this->live($remote));

        $this->undo($change)->assertSessionHasNoErrors();
        $this->publish()->assertSessionHasNoErrors();

        $this->assertNotContains('app/Team.php', $this->live($remote));
        $this->assertSame(DeploymentStatus::Sent, Deployment::query()->latest('id')->first()?->status);
        // Only the first publish holds it.
        $this->assertSame(1, $change->refresh()->deployments()->count());
    }

    public function test_an_undone_change_stays_live_until_the_app_is_put_online_again(): void
    {
        $remote = $this->publishTo();
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $this->publish()->assertSessionHasNoErrors();
        Deployment::query()->latest('id')->firstOrFail()->update(['status' => DeploymentStatus::Published]);

        $this->undo($change)->assertSessionHasNoErrors();

        $this->assertContains('app/Team.php', $this->live($remote));
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $change))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.still_online.head', $this->repository->head($this->project))->etc());
    }

    public function test_a_failing_check_keeps_the_undone_change_live_and_says_so(): void
    {
        $remote = $this->publishTo();
        $change = $this->keep('Add a team page', ['app/Team.php' => "<?php\n"]);
        $this->publish()->assertSessionHasNoErrors();
        $this->undo($change)->assertSessionHasNoErrors();
        $this->failChecks();

        $this->publish();

        $this->assertSame(DeploymentStatus::Failed, Deployment::query()->latest('id')->first()?->status);
        $this->assertContains('app/Team.php', $this->live($remote));
    }

    /**
     * Keep a change that writes the given files, the way keeping one does:
     * a commit on the main line, with the patch that made it.
     *
     * @param  array<string, string>  $files
     */
    protected function keep(string $prompt, array $files): FeatureRequest
    {
        $this->travel(1)->minute();
        $commit = $this->repository->commitFiles($this->project, $this->repository->head($this->project), $files, $prompt, null);
        $patch = $this->repository->git($this->project, ['show', '--format=', $commit])->output();

        return FeatureRequest::factory()->generated()->create([
            'project_id' => $this->project->id,
            'prompt' => $prompt,
            'patch' => $patch,
            'commit_sha' => $commit,
            'accepted_at' => now(),
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    protected function undo(FeatureRequest $change): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('feature-requests.reversion.store', $change));
    }

    protected function exists(string $path): bool
    {
        return File::exists($this->repository->path($this->project).'/'.$path);
    }

    protected function assertKeptData(FeatureRequest $change, bool $expected): void
    {
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.kept_data', $expected)->etc());
    }

    /**
     * Point the project at a bare repository standing in for the one the
     * host deploys from. Nothing is pushed anywhere else.
     */
    protected function publishTo(): string
    {
        $this->driver = $this->fakeWorkspaces();
        $remote = sys_get_temp_dir().'/builder-test-remote-'.Str::lower(Str::random(8)).'.git';
        Process::run(['git', 'init', '--quiet', '--bare', $remote])->throw();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($remote));

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60]],
            'builder.publishing.allow_local_remotes' => true,
        ]);
        $this->project->update(['deploy_remote' => $remote, 'deploy_branch' => 'main']);

        return $remote;
    }

    protected function failChecks(): void
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'Tests failed.', durationMs: 5);
    }

    /**
     * @return TestResponse<Response>
     */
    protected function publish(): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('deployments.store', $this->project));
    }

    /**
     * Get the files the host would deploy.
     *
     * @return list<string>
     */
    protected function live(string $remote): array
    {
        return array_values(array_filter(explode("\n", Process::run(['git', '--git-dir', $remote, 'ls-tree', '-r', '--name-only', 'refs/heads/main'])->output())));
    }
}
