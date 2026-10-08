<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     */
    protected function answer(array $before, array $after): void
    {
        $started = false;

        $this->driver->onExec = function (string $workspace, array $command) use ($before, $after, &$started) {
            if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'apply') {
                $started = in_array('--reverse', $command, true);
            }

            if ($command === ['php', 'roles.php']) {
                return new CommandResult(exitCode: 0, output: (string) json_encode([
                    'tenants' => [['model' => 'Team', 'relation' => 'members', 'column' => 'role', 'roles' => ['owner', 'admin', 'member']]],
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
}
