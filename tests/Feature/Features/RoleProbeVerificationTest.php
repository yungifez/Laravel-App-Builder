<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class RoleProbeVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const PROBE = ['sh', '-c', 'run the role probes', 'sh'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.security.enabled' => false,
            'builder.verification.screens.enabled' => false,
            'builder.verification.change_evidence.enabled' => false,
            'builder.verification.access.enabled' => false,
            'builder.verification.roles' => [
                'enabled' => true,
                'probes' => 60,
                'test' => 'tests/Feature/RoleProbeTest.php',
                'models' => 'roles.php',
                'command' => self::PROBE,
                'timeout' => 60,
                'report' => 'probes.jsonl',
            ],
        ]);
    }

    /**
     * Answer the script with a team whose members may be removed, and the
     * probes with what each did: on the starting commit while the change
     * is taken out, with the change otherwise. Probes 5 to 9 remove a
     * member as a visitor, an outsider, an owner, an admin and a member.
     *
     * @param  array<int, bool>  $before  Whether each removal went through before the change
     * @param  array<int, bool>  $after  Whether each removal went through with it
     * @param  list<array<string, mixed>>|null  $tenants  The teams the script finds; an enum role column by default
     * @param  string|null  $unread  Why the script could not read the app's Spatie roles
     */
    protected function answer(array $before, array $after, ?array $tenants = null, ?string $unread = null): void
    {
        $started = false;
        $tenants ??= [['model' => 'Team', 'relation' => 'members', 'column' => 'role', 'roles' => ['owner', 'admin', 'member']]];

        $this->driver->onExec = function (string $workspace, array $command) use ($before, $after, $tenants, $unread, &$started) {
            if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'apply') {
                $started = in_array('--reverse', $command, true);
            }

            if ($command === ['php', 'roles.php']) {
                return new CommandResult(exitCode: 0, output: (string) json_encode([
                    'tenants' => $tenants,
                    'unread' => $unread,
                    'routes' => [
                        ['method' => 'PATCH', 'uri' => '/settings/teams/{team}', 'name' => 'teams.update', 'tenant' => 'Team', 'team' => 'team', 'member' => null],
                        ['method' => 'DELETE', 'uri' => '/settings/teams/{team}/members/{member}', 'name' => 'team-members.destroy', 'tenant' => 'Team', 'team' => 'team', 'member' => 'member'],
                    ],
                ]), errorOutput: '', durationMs: 5);
            }

            if (array_slice($command, 0, 4) === self::PROBE) {
                $done = $started ? $before : $after;
                $this->driver->files["{$workspace}:probes.jsonl"] = implode("\n", array_map(
                    fn (int $id) => json_encode(['id' => $id, 'status' => ($done[$id] ?? false) ? 302 : 403, 'changed' => $done[$id] ?? false, 'invalid' => false]),
                    range(0, 9),
                ));
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_what_a_role_gained_or_lost_is_kept_without_sending_the_change_back(): void
    {
        $this->answer(before: [7 => true, 8 => true], after: [7 => true, 9 => true]);
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Who may do what in a team');
        $this->assertSame(['checks', 'passed'], [$result['stage'], $result['outcome']]);
        $this->assertStringContainsString('- A member with the member role can now use team-members.destroy', $result['output']);
        $this->assertStringContainsString('- A member with the admin role can no longer use team-members.destroy', $result['output']);
        $this->assertCount(2, $verification->evidence['roles']['changed'] ?? []);
        $this->assertNotSame(VerificationStatus::Failed, $verification->status);
    }

    public function test_someone_outside_the_team_who_gained_a_thing_fails_the_checks(): void
    {
        $this->answer(before: [7 => true], after: [6 => true, 7 => true]);
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Who may do what in a team');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('A signed-in person outside the team can now use team-members.destroy.', $result['output']);
        $this->assertSame(VerificationStatus::Failed, $verification->status);
    }

    public function test_a_change_without_php_is_not_probed(): void
    {
        $this->answer(before: [], after: [6 => true]);
        $change = FeatureRequest::factory()->generated()->create(['patch' => "diff --git a/resources/js/pages/Team.vue b/resources/js/pages/Team.vue\n--- a/resources/js/pages/Team.vue\n+++ b/resources/js/pages/Team.vue\n@@ -1 +1,2 @@\n <template>\n+<p>Team</p>\n"]);

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may do what in a team'));
        $this->assertSame(0, collect($this->driver->executed)->where('command', ['php', 'roles.php'])->count());
    }

    /**
     * A team whose roles come from Spatie's permission package.
     *
     * @return list<array<string, mixed>>
     */
    protected function spatie(bool $teams): array
    {
        return [[
            'model' => 'Team',
            'relation' => $teams ? null : 'members',
            'column' => null,
            'roles' => ['owner', 'admin', 'member'],
            'spatie' => [
                'teams' => $teams,
                'key' => $teams ? 'team_id' : null,
                'guards' => ['owner' => 'web', 'admin' => 'web', 'member' => 'web'],
                'permissions' => ['owner' => ['remove members', 'edit team'], 'admin' => ['remove members'], 'member' => []],
            ],
        ]];
    }

    public function test_someone_outside_a_spatie_team_who_gained_a_thing_fails_the_checks(): void
    {
        $this->answer(before: [7 => true, 8 => true], after: [6 => true, 7 => true, 8 => true], tenants: $this->spatie(teams: true));
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Who may do what in a team');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('A signed-in person outside the team can now use team-members.destroy.', $result['output']);
        $this->assertSame([['route' => 'team-members.destroy', 'actor' => 'stranger', 'before' => 'no', 'after' => 'yes']], $verification->evidence['roles']['findings'] ?? null);
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        // Each role is given in the team, with what it permits.
        $test = (string) collect($this->driver->files)->first(fn (string $content, string $path) => str_ends_with($path, ':tests/Feature/RoleProbeTest.php'));
        $this->assertStringContainsString("'remove members'", $test);
        $this->assertStringContainsString('setPermissionsTeamId', $test);
    }

    public function test_what_a_spatie_role_gained_without_teams_is_told_and_passes(): void
    {
        $this->answer(before: [7 => true, 8 => true], after: [7 => true, 9 => true], tenants: $this->spatie(teams: false));
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Who may do what in a team');
        $this->assertSame('passed', $result['outcome']);
        $this->assertStringContainsString('- A member with the member role can now use team-members.destroy', $result['output']);
        $this->assertStringContainsString('- A member with the admin role can no longer use team-members.destroy', $result['output']);
        $this->assertSame([], $verification->evidence['roles']['findings'] ?? null);
        $this->assertNotSame(VerificationStatus::Failed, $verification->status);
    }

    public function test_an_app_with_neither_role_column_nor_spatie_is_not_probed(): void
    {
        $this->answer(before: [], after: [6 => true], tenants: []);
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may do what in a team'));
        $this->assertSame(1, collect($this->driver->executed)->where('command', ['php', 'roles.php'])->count());
        $this->assertSame(0, collect($this->driver->executed)->filter(fn (array $run) => array_slice($run['command'], 0, 4) === self::PROBE)->count());
    }

    /**
     * What the owner reads about trying the change as each role, and the
     * check's own result.
     *
     * @return array{result: array<string, mixed>|null, said: list<string>, status: VerificationStatus}
     */
    protected function verified(): array
    {
        $change = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();

        return [
            'result' => collect($verification->results)->firstWhere('name', 'Who may do what in a team'),
            'said' => array_values(array_filter(array_map(fn (array $line) => $line['kind'] === 'gap' ? $line['text'] : '', app(DescribeProof::class)->handle($change)), fn (string $text) => str_contains($text, 'role'))),
            'status' => $verification->status,
        ];
    }

    public function test_spatie_roles_that_could_not_be_read_are_told_to_the_owner(): void
    {
        $this->answer(before: [], after: [], tenants: [], unread: 'failed');

        $verified = $this->verified();

        $said = 'I could not try this change as each role in a team. Your app makes its roles when it sets up its database, and that did not work here.';
        $this->assertSame(['skipped', $said], [$verified['result']['outcome'], $verified['result']['output']]);
        $this->assertSame([$said], $verified['said']);
        $this->assertNotSame(VerificationStatus::Failed, $verified['status']);
        $this->assertSame(0, collect($this->driver->executed)->filter(fn (array $run) => array_slice($run['command'], 0, 4) === self::PROBE)->count());
    }

    public function test_an_app_without_roles_set_up_or_requests_that_did_not_run_say_so(): void
    {
        $this->answer(before: [], after: [], tenants: [], unread: 'empty');
        $this->assertSame(['I could not try this change as each role in a team. Your app sets up no roles in its database, so there were none to try.'], $this->verified()['said']);

        $answer = $this->driver->onExec;
        $this->driver->onExec = function (string $workspace, array $command, int $timeout) use ($answer) {
            /** @var list<string> $command */
            return $command === ['php', 'roles.php']
                ? new CommandResult(exitCode: 255, output: 'PHP Fatal error: Class "App\Models\Team" not found', errorOutput: '', durationMs: 5)
                : $answer($workspace, $command, $timeout);
        };
        // Its tests passed, so the app starts: output that is not the
        // script's is our script breaking.
        $this->assertSame(['This is our fault: I could not try this change as each role in a team. We have been told.'], $this->verified()['said']);

        // Every request broke, so no role proved anything either way.
        $this->answer(before: [], after: []);
        $answer = $this->driver->onExec;
        $this->driver->onExec = function (string $workspace, array $command, int $timeout) use ($answer) {
            /** @var list<string> $command */
            $result = $answer($workspace, $command, $timeout);

            if (array_slice($command, 0, 4) === self::PROBE) {
                $this->driver->files["{$workspace}:probes.jsonl"] = implode("\n", array_map(fn (int $id) => json_encode(['id' => $id, 'status' => 500, 'changed' => false, 'invalid' => false]), range(0, 9)));
            }

            return $result;
        };
        $verified = $this->verified();
        $this->assertSame('skipped', $verified['result']['outcome']);
        $this->assertSame(["I could not try this change as each role in a team. The requests did not run in your app's tests here."], $verified['said']);
    }

    public function test_our_own_failure_is_said_as_ours_and_an_app_without_team_roles_stays_quiet(): void
    {
        $this->answer(before: [], after: []);
        $answer = $this->driver->onExec;
        $this->driver->onExec = function (string $workspace, array $command, int $timeout) use ($answer) {
            /** @var list<string> $command */
            if (array_slice($command, 0, 4) === self::PROBE) {
                throw new RuntimeException('The probe test could not be written.');
            }

            return $answer($workspace, $command, $timeout);
        };

        $this->assertSame(['This is our fault: I could not try this change as each role in a team. We have been told.'], $this->verified()['said']);

        $this->answer(before: [], after: [], tenants: []);
        $verified = $this->verified();
        $this->assertNull($verified['result']);
        $this->assertSame([], $verified['said']);
    }
}
