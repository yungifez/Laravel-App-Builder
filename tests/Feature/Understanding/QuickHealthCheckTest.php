<?php

namespace Tests\Feature\Understanding;

use App\Actions\Projects\CreateProject;
use App\Enums\HealthCheckStatus;
use App\Jobs\CheckProjectHealth;
use App\Models\HealthCheck;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class QuickHealthCheckTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    /** @var array<string, CommandResult> What each command answers, by its first word */
    protected array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [['name' => 'Install PHP dependencies', 'command' => ['composer', 'install'], 'timeout' => 60]],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60],
                ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan'], 'timeout' => 60],
            ],
            'builder.verification.security' => [
                'enabled' => true,
                'steps' => [['name' => 'PHP packages', 'report' => 'composer', 'command' => ['composer', 'audit'], 'timeout' => 60, 'needs' => 'composer.json']],
            ],
        ]);

        $this->answer(['composer', 'audit'], 0, '{"advisories": []}');
        $this->driver->onExec = fn (string $id, array $command) => $this->answers[implode(' ', $command)]
            ?? new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
    }

    protected function answer(array $command, int $exitCode, string $output = ''): void
    {
        $this->answers[implode(' ', $command)] = new CommandResult(exitCode: $exitCode, output: $output, errorOutput: '', durationMs: 5);
    }

    /**
     * Ask for the check, as "Check my app" does, and read what the page shows.
     *
     * @return array{active: bool, findings: list<array{title: string, details: list<string>}>}|null
     */
    protected function check(): ?array
    {
        $this->actingAs($this->owner)
            ->post(route('projects.health-checks.store', $this->project))
            ->assertSessionHasNoErrors();

        $health = null;

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(function (Assert $page) use (&$health) {
                $health = $page->toArray()['props']['health'];
            });

        return $health;
    }

    public function test_the_full_checks_and_package_lookups_run_on_the_current_version_and_pass()
    {
        $health = $this->check();

        $healthCheck = HealthCheck::sole();
        $this->assertSame(HealthCheckStatus::Passed, $healthCheck->status);
        $this->assertSame(app(ProjectRepository::class)->head($this->project), $healthCheck->commit_sha);
        $this->assertSame(['Install PHP dependencies', 'Tests', 'Static analysis', 'PHP packages'], array_column($healthCheck->results, 'name'));
        $this->assertSame(['active' => false, 'findings' => []], $health);
        // Nothing in the app changed.
        $this->assertSame($healthCheck->commit_sha, app(ProjectRepository::class)->head($this->project));
    }

    public function test_a_failing_check_and_packages_with_known_problems_are_listed_without_what_they_said()
    {
        $this->answer(['php', 'artisan', 'test'], 1, 'FAILED  Tests\\Feature\\CartTest secret-output');
        $this->answer(['composer', 'audit'], 1, '{"advisories": {"acme/old-mailer": [{"title": "Remote code"}], "acme/fine": []}}');

        $health = $this->check();

        $this->assertSame(HealthCheckStatus::Failed, HealthCheck::sole()->status);
        $this->assertSame([
            ['title' => 'Some of your app\'s checks do not pass.', 'details' => ['Tests']],
            ['title' => 'Some packages your app uses have known security problems.', 'details' => ['acme/old-mailer']],
        ], $health['findings']);
        $this->assertStringNotContainsString('secret-output', (string) json_encode($health));
        // What the check said stays with the builder, for a fix.
        $this->assertStringContainsString('secret-output', (string) HealthCheck::sole()->results[1]['output']);
    }

    public function test_a_failed_setup_stops_before_the_checks()
    {
        $this->answer(['composer', 'install'], 1, 'Your requirements could not be resolved');

        $health = $this->check();

        $this->assertSame(HealthCheckStatus::Failed, HealthCheck::sole()->status);
        $this->assertSame(['Install PHP dependencies'], array_column(HealthCheck::sole()->results, 'name'));
        $this->assertSame([['title' => 'Your app could not be set up, so its checks did not run.', 'details' => ['Install PHP dependencies']]], $health['findings']);
    }

    public function test_a_package_lookup_that_fails_says_it_is_our_fault()
    {
        $this->answer(['composer', 'audit'], 1, 'Could not reach the advisory database');

        $health = $this->check();

        $this->assertSame(HealthCheckStatus::Failed, HealthCheck::sole()->status);
        $this->assertSame('This is our fault: I could not look up known problems in your app\'s packages this time.', $health['findings'][0]['title']);
    }

    public function test_a_check_that_stops_on_our_side_says_it_is_our_fault()
    {
        $this->driver->failCreate = true;

        $health = $this->check();

        $this->assertSame(HealthCheckStatus::Errored, HealthCheck::sole()->status);
        $this->assertStringStartsWith('This is our fault:', $health['findings'][0]['title']);
    }

    public function test_a_second_click_while_it_runs_does_not_start_another()
    {
        Queue::fake();

        $this->check();
        $health = $this->check();

        $this->assertSame(1, HealthCheck::query()->count());
        Queue::assertPushed(CheckProjectHealth::class, 1);
        $this->assertSame(['active' => true, 'findings' => []], $health);
    }

    public function test_a_check_of_an_earlier_version_is_not_shown()
    {
        HealthCheck::factory()->for($this->project)->create([
            'commit_sha' => str_repeat('b', 40),
            'status' => HealthCheckStatus::Failed,
            'results' => [['name' => 'Tests', 'kind' => 'check', 'passed' => false]],
            'finished_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('health', null));
    }

    public function test_only_the_owner_can_check_the_app()
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.health-checks.store', $this->project))
            ->assertForbidden();

        $this->assertSame(0, HealthCheck::query()->count());
        Queue::assertNothingPushed();
    }
}
