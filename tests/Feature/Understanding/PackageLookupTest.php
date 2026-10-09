<?php

namespace Tests\Feature\Understanding;

use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Enums\HealthCheckScope;
use App\Enums\HealthCheckStatus;
use App\Models\FeatureRequest;
use App\Models\HealthCheck;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PackagesNeedYou;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PackageLookupTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    /** @var list<string> Every command the workspace ran */
    protected array $ran = [];

    protected string $audit = '{"advisories": []}';

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
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60]],
            'builder.verification.security' => [
                'enabled' => true,
                'lookup_days' => 7,
                'steps' => [['name' => 'PHP packages', 'report' => 'composer', 'command' => ['composer', 'audit'], 'timeout' => 60]],
            ],
        ]);

        $this->driver->onExec = function (string $id, array $command) {
            $this->ran[] = implode(' ', $command);

            return $command === ['composer', 'audit']
                ? new CommandResult(exitCode: 0, output: $this->audit, errorOutput: '', durationMs: 5)
                : new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    protected function headCommit(): string
    {
        return app(ProjectRepository::class)->head($this->project);
    }

    /**
     * A full check the owner asked for, some time ago.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected function fullCheck(array $results, HealthCheckStatus $status = HealthCheckStatus::Failed): HealthCheck
    {
        return HealthCheck::factory()->for($this->project)->create([
            'commit_sha' => $this->headCommit(),
            'status' => $status,
            'results' => $results,
            'finished_at' => now(),
            'created_at' => now()->subDays(8),
        ]);
    }

    /**
     * @return array{active: bool, findings: list<array{title: string, details: list<string>}>, fixable: bool}|null
     */
    protected function health(): ?array
    {
        $health = null;

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(function (Assert $page) use (&$health) {
                $health = $page->toArray()['props']['health'];
            });

        return $health;
    }

    public function test_an_app_not_checked_for_a_while_has_only_its_packages_looked_up()
    {
        Notification::fake();

        $this->artisan('health:look-up-packages')->assertSuccessful();

        $lookup = HealthCheck::sole();
        $this->assertSame(HealthCheckScope::Packages, $lookup->scope);
        $this->assertSame(HealthCheckStatus::Passed, $lookup->status);
        $this->assertSame($this->headCommit(), $lookup->commit_sha);
        $this->assertSame(['PHP packages'], array_column($lookup->results, 'name'));
        // No install and no tests: the lock file is enough.
        $this->assertNotContains('composer install', $this->ran);
        $this->assertNotContains('php artisan test', $this->ran);
        // Lookups alone that found nothing have nothing to show or tell.
        $this->assertNull($this->health());
        Notification::assertNothingSent();
    }

    public function test_an_app_checked_within_the_days_is_left_alone_until_they_pass()
    {
        config(['builder.verification.security.lookup_days' => 3]);
        HealthCheck::factory()->for($this->project)->create([
            'commit_sha' => $this->headCommit(),
            'status' => HealthCheckStatus::Passed,
            'results' => [],
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('health:look-up-packages')->assertSuccessful();
        $this->assertSame(1, HealthCheck::query()->count());

        $this->travel(2)->days();
        $this->artisan('health:look-up-packages')->assertSuccessful();

        $this->assertSame(1, HealthCheck::query()->where('scope', HealthCheckScope::Packages)->count());
    }

    public function test_a_new_problem_is_told_once_and_shown_beside_what_the_full_check_found()
    {
        Notification::fake();
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));
        $full = $this->fullCheck([
            ['name' => 'Install PHP dependencies', 'kind' => 'setup', 'passed' => true],
            ['name' => 'Tests', 'kind' => 'check', 'passed' => false, 'output' => 'FAILED CartTest'],
            ['name' => 'PHP packages', 'kind' => 'packages', 'passed' => false, 'packages' => ['acme/old-mailer']],
        ]);
        $this->audit = '{"advisories": {"acme/old-mailer": [{"title": "Remote code"}], "acme/parser": [{"title": "Overflow"}]}}';

        $this->artisan('health:look-up-packages')->assertSuccessful();

        $lookup = HealthCheck::query()->where('scope', HealthCheckScope::Packages)->sole();
        $this->assertSame(HealthCheckStatus::Failed, $lookup->status);
        // Only what the full check did not know of is new.
        Notification::assertSentTo($this->owner, PackagesNeedYou::class, fn (PackagesNeedYou $notification) => $notification->packages === ['acme/parser']
            && $notification->toArray($this->owner)['kind'] === 'packages');

        // The lookup does not hide the failing test the owner's check found.
        $this->assertSame([
            ['title' => 'Some of your app\'s checks do not pass.', 'details' => ['Tests']],
            ['title' => 'Some packages your app uses have known security problems.', 'details' => ['acme/old-mailer', 'acme/parser']],
        ], $this->health()['findings']);
        $this->assertTrue($this->health()['fixable']);

        $this->actingAs($this->owner)->post(route('health-fixes.store', $this->project))->assertSessionHasNoErrors();
        $fix = FeatureRequest::sole();
        $this->assertSame($lookup->id, $fix->failed_checks['health_check_id']);
        $this->assertSame(['Tests', 'PHP packages'], array_column($fix->failed_checks['checks'], 'name'));
        $this->assertStringContainsString('acme/old-mailer, acme/parser', $fix->instructions());
        $this->assertNotSame($full->id, $fix->failed_checks['health_check_id']);

        // The same problem a week later is not news.
        $this->travel(8)->days();
        $this->artisan('health:look-up-packages')->assertSuccessful();
        $this->assertSame(2, HealthCheck::query()->where('scope', HealthCheckScope::Packages)->count());
        Notification::assertSentToTimes($this->owner, PackagesNeedYou::class, 1);
    }

    public function test_the_note_opens_what_the_app_is()
    {
        $this->audit = '{"advisories": {"acme/parser": [{"title": "Overflow"}]}}';

        $this->artisan('health:look-up-packages')->assertSuccessful();

        $notification = $this->owner->notifications()->sole();
        $this->assertSame('A package your app uses has a new security problem', $notification->data['title']);
        $this->assertSame('acme/parser', $notification->data['body']);
        $this->actingAs($this->owner)
            ->get(route('notifications.show', $notification->id))
            ->assertRedirect(route('projects.understanding.show', $this->project));
        $this->assertSame([['title' => 'Some packages your app uses have known security problems.', 'details' => ['acme/parser']]], $this->health()['findings']);
    }

    public function test_a_lookup_that_cannot_be_read_says_nothing_and_hides_nothing()
    {
        Notification::fake();
        $this->fullCheck([['name' => 'PHP packages', 'kind' => 'packages', 'passed' => false, 'packages' => ['acme/old-mailer']]]);
        $this->audit = 'Could not reach the advisory database';

        $this->artisan('health:look-up-packages')->assertSuccessful();

        $this->assertSame(HealthCheckStatus::Errored, HealthCheck::query()->where('scope', HealthCheckScope::Packages)->sole()->status);
        Notification::assertNothingSent();
        $this->assertSame([['title' => 'Some packages your app uses have known security problems.', 'details' => ['acme/old-mailer']]], $this->health()['findings']);
    }

    public function test_an_app_with_no_code_yet_is_not_looked_up()
    {
        $empty = Project::factory()->for($this->owner, 'owner')->create();

        $this->artisan('health:look-up-packages')->assertSuccessful();

        $this->assertSame(0, $empty->healthChecks()->count());
        $this->assertSame(1, $this->project->healthChecks()->count());
    }

    public function test_the_owners_full_check_is_not_answered_by_a_lookup_under_way()
    {
        $this->project->healthChecks()->create(['commit_sha' => $this->headCommit(), 'scope' => HealthCheckScope::Packages, 'status' => HealthCheckStatus::Running]);

        $this->actingAs($this->owner)->post(route('projects.health-checks.store', $this->project))->assertSessionHasNoErrors();

        $full = HealthCheck::query()->where('scope', HealthCheckScope::Full)->sole();
        $this->assertSame(HealthCheckStatus::Passed, $full->status);
        $this->assertSame(['Install PHP dependencies', 'Tests', 'PHP packages'], array_column($full->results, 'name'));
    }
}
