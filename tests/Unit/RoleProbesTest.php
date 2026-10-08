<?php

namespace Tests\Unit;

use App\Features\RoleProbes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RoleProbesTest extends TestCase
{
    /**
     * What the introspection script prints for an app whose teams have an
     * owner, admins and members, with a route to rename a team and one to
     * remove a member.
     */
    protected function printed(): string
    {
        return "Booting.\n".json_encode([
            'tenants' => [['model' => 'Team', 'relation' => 'members', 'column' => 'role', 'roles' => ['owner', 'admin', 'member']]],
            'routes' => [
                ['method' => 'PATCH', 'uri' => '/settings/teams/{team}', 'name' => 'teams.update', 'tenant' => 'Team', 'team' => 'team', 'member' => null],
                ['method' => 'DELETE', 'uri' => '/settings/teams/{team}/members/{member}', 'name' => 'team-members.destroy', 'tenant' => 'Team', 'team' => 'team', 'member' => 'member'],
            ],
        ]);
    }

    public function test_each_route_is_tried_by_people_outside_the_team_and_each_role(): void
    {
        $found = RoleProbes::found($this->printed());
        $probes = RoleProbes::plan($found, 60);

        $this->assertSame(['guest', 'stranger', 'role:owner', 'role:admin', 'role:member'], array_column(array_slice($probes, 0, 5), 'actor'));
        $this->assertSame('team-members.destroy', $probes[5]['route']);
        $this->assertSame('member', $probes[5]['member']);
        $this->assertCount(3, RoleProbes::plan($found, 3));
    }

    public function test_a_team_without_roles_and_routes_of_other_models_are_left_out(): void
    {
        $found = RoleProbes::found((string) json_encode([
            'tenants' => [['model' => 'Club', 'relation' => 'members', 'column' => 'role', 'roles' => []]],
            'routes' => [['method' => 'PATCH', 'uri' => '/clubs/{club}', 'name' => null, 'tenant' => 'Club', 'team' => 'club', 'member' => null]],
        ]));

        $this->assertSame(['tenants' => [], 'routes' => []], $found);
        $this->assertSame([], RoleProbes::plan($found, 60));
    }

    public function test_output_that_is_not_the_scripts_is_not_read(): void
    {
        $this->assertNull(RoleProbes::found('Fatal error: Class "App\Models\Team" not found'));
        $this->assertNull(RoleProbes::found('{"tenants":[]}'));
    }

    public function test_a_role_that_gained_a_thing_is_told_but_someone_outside_who_gained_one_is_a_finding(): void
    {
        $probes = RoleProbes::plan(RoleProbes::found($this->printed()), 60);
        $refused = ['status' => 403, 'changed' => false, 'invalid' => false];
        $done = ['status' => 302, 'changed' => true, 'invalid' => false];

        // Before: owners and admins remove members. After: admins cannot,
        // members and people outside the team can.
        $before = [5 => $refused, 6 => $refused, 7 => $done, 8 => $done, 9 => $refused];
        $after = [5 => $refused, 6 => $done, 7 => $done, 8 => $refused, 9 => $done];

        $measured = RoleProbes::measure($probes, $after, $before, ['teams.update', 'team-members.destroy']);

        $this->assertSame([
            ['route' => 'team-members.destroy', 'actor' => 'stranger', 'before' => 'no', 'after' => 'yes'],
            ['route' => 'team-members.destroy', 'actor' => 'role:admin', 'before' => 'yes', 'after' => 'no'],
            ['route' => 'team-members.destroy', 'actor' => 'role:member', 'before' => 'no', 'after' => 'yes'],
        ], $measured['changed']);
        $this->assertSame([['route' => 'team-members.destroy', 'actor' => 'stranger', 'before' => 'no', 'after' => 'yes']], $measured['findings']);
        $this->assertSame(5, $measured['tried']);

        $described = RoleProbes::describe($measured);
        $this->assertStringContainsString('A signed-in person outside the team can now use team-members.destroy.', $described);
        $this->assertStringContainsString('- A member with the admin role can no longer use team-members.destroy', $described);
        $this->assertStringContainsString('- A member with the member role can now use team-members.destroy', $described);
    }

    public function test_a_new_route_lists_who_could_use_it_and_a_broken_page_proves_nothing(): void
    {
        $probes = RoleProbes::plan(RoleProbes::found($this->printed()), 60);
        $refused = ['status' => 403, 'changed' => false, 'invalid' => false];
        $done = ['status' => 302, 'changed' => true, 'invalid' => false];
        $after = [5 => $refused, 6 => $refused, 7 => $done, 8 => ['status' => 500, 'changed' => false, 'invalid' => false], 9 => ['status' => 302, 'changed' => false, 'invalid' => true]];

        $measured = RoleProbes::measure($probes, $after, [], ['teams.update']);

        $this->assertSame(['guest' => 'no', 'stranger' => 'no', 'role:owner' => 'yes', 'role:member' => 'no'], $measured['new']['team-members.destroy']);
        $this->assertSame([], $measured['changed']);
        $this->assertSame([], $measured['findings']);
        $this->assertStringContainsString('New: team-members.destroy. Could use it: a member with the owner role.', RoleProbes::describe($measured));
    }

    /**
     * What the script prints for an app on Spatie's permission package:
     * roles as rows, each with the permissions it grants.
     *
     * @param  array<string, mixed>  $tenant
     */
    protected function printedSpatie(array $tenant): string
    {
        return (string) json_encode([
            'tenants' => [['model' => 'Team', 'roles' => ['Team Admin', 'editor', 'viewer'], ...$tenant]],
            'routes' => [
                ['method' => 'PATCH', 'uri' => '/teams/{team}', 'name' => 'teams.update', 'tenant' => 'Team', 'team' => 'team', 'member' => null],
            ],
        ]);
    }

    public function test_a_spatie_team_app_is_tried_as_each_role_given_in_the_team(): void
    {
        $found = RoleProbes::found($this->printedSpatie([
            'relation' => null,
            'column' => null,
            'spatie' => [
                'teams' => true,
                'key' => 'team_id',
                'guards' => ['Team Admin' => 'web', 'editor' => 'web', 'viewer' => 'web'],
                'permissions' => ['Team Admin' => ['edit team', 'remove members'], 'editor' => ['edit team'], 'viewer' => []],
            ],
        ]));
        $probes = RoleProbes::plan($found, 60);
        $test = RoleProbes::test($probes, $found['tenants'], 'probes.jsonl');

        $this->assertSame(['guest', 'stranger', 'role:Team Admin', 'role:editor', 'role:viewer'], array_column($probes, 'actor'));
        $this->assertSame(['teams' => true, 'key' => 'team_id', 'guards' => ['Team Admin' => 'web', 'editor' => 'web', 'viewer' => 'web'], 'permissions' => ['Team Admin' => ['edit team', 'remove members'], 'editor' => ['edit team'], 'viewer' => []]], $found['tenants'][0]['spatie']);
        // Each role is given in the team, with what it permits, and the
        // request is sent with that team in use.
        $this->assertStringContainsString('$person->assignRole($given);', $test);
        $this->assertStringContainsString("\$given->givePermissionTo(array_map(fn (string \$name) => \$registrar->getPermissionClass()::findOrCreate(\$name, \$guard), \$spatie['permissions'][\$role]));", $test);
        $this->assertSame(2, substr_count($test, 'setPermissionsTeamId($team->getKey())'));
        $this->assertStringContainsString("'remove members'", $test);
        $this->assertNotFalse(token_get_all($test, TOKEN_PARSE));
    }

    public function test_a_spatie_app_without_teams_gives_its_roles_to_the_teams_members(): void
    {
        $spatie = ['teams' => false, 'key' => null, 'guards' => ['Team Admin' => 'web', 'editor' => 'admin', 'viewer' => 'web'], 'permissions' => ['Team Admin' => ['edit team']]];
        $found = RoleProbes::found($this->printedSpatie(['relation' => 'members', 'column' => null, 'spatie' => $spatie]));

        $this->assertSame(['model' => 'Team', 'relation' => 'members', 'column' => null, 'roles' => ['Team Admin', 'editor', 'viewer'], 'spatie' => [
            'teams' => false,
            'key' => null,
            'guards' => ['Team Admin' => 'web', 'editor' => 'admin', 'viewer' => 'web'],
            'permissions' => ['Team Admin' => ['edit team'], 'editor' => [], 'viewer' => []],
        ]], $found['tenants'][0]);
        $this->assertCount(5, RoleProbes::plan($found, 60));

        // Global roles with no members to give them to reach no team.
        $this->assertSame([], RoleProbes::found($this->printedSpatie(['relation' => null, 'column' => null, 'spatie' => $spatie]))['tenants']);
    }

    public function test_an_app_with_neither_enum_roles_nor_spatie_is_not_probed(): void
    {
        $found = RoleProbes::found((string) json_encode(['tenants' => [], 'routes' => [
            ['method' => 'PATCH', 'uri' => '/teams/{team}', 'name' => 'teams.update', 'tenant' => 'Team', 'team' => 'team', 'member' => null],
        ]]));

        $this->assertSame(['tenants' => [], 'routes' => []], $found);
        $this->assertSame([], RoleProbes::plan($found, 60));

        // Teams mode named with a key that is not a column is not read.
        $this->assertSame([], RoleProbes::found($this->printedSpatie(['relation' => null, 'column' => null, 'spatie' => ['teams' => true, 'key' => 'team id; drop', 'guards' => [], 'permissions' => []]]))['tenants']);
        $this->assertNotFalse(token_get_all(RoleProbes::introspection(), TOKEN_PARSE));
    }

    /**
     * Run the start of the introspection script in a folder holding the
     * given files, with a clean environment plus $environment, and say
     * what the app would read.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $environment
     * @return array{env: array<string, string|false>, server: string|null}
     */
    protected function settings(array $files, array $environment = []): array
    {
        $folder = storage_path('framework/testing/role-probes-'.getmypid());
        File::deleteDirectory($folder);
        File::ensureDirectoryExists($folder);

        foreach ($files as $name => $contents) {
            File::put("{$folder}/{$name}", $contents);
        }

        File::put("{$folder}/settings.php", RoleProbes::testSettings()."echo json_encode(['env' => ['APP_ENV' => getenv('APP_ENV'), 'DB_CONNECTION' => getenv('DB_CONNECTION'), 'DB_DATABASE' => getenv('DB_DATABASE')], 'server' => \$_SERVER['DB_HOST'] ?? null]);\n");
        $variables = array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($environment), $environment);
        $result = Process::path($folder)->run(['env', '-i', 'PATH='.getenv('PATH'), ...$variables, PHP_BINARY, 'settings.php']);
        File::deleteDirectory($folder);

        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true);
    }

    /**
     * A phpunit.xml with the given lines in its <php> block.
     */
    protected function phpunit(string $lines): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" bootstrap=\"vendor/autoload.php\">\n    <php>\n{$lines}\n    </php>\n</phpunit>\n";
    }

    public function test_the_roles_are_read_with_the_apps_own_test_database_settings(): void
    {
        $read = $this->settings(['phpunit.xml' => $this->phpunit(<<<'XML'
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="shop_test"/>
        <server name="DB_HOST" value="127.0.0.1"/>
XML)]);

        $this->assertSame(['env' => ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'shop_test'], 'server' => '127.0.0.1'], $read);
    }

    public function test_a_dist_file_is_read_and_a_set_value_stays_unless_forced(): void
    {
        $read = $this->settings(['phpunit.xml.dist' => $this->phpunit(<<<'XML'
        <env name="APP_ENV" value="testing" force="true"/>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="shop_test"/>
XML)], ['APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql']);

        // As PHPUnit runs it: a forced value wins, a set one stays.
        $this->assertSame(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'shop_test'], $read['env']);
    }

    public function test_an_app_without_readable_test_settings_keeps_its_own(): void
    {
        $nothing = ['env' => ['APP_ENV' => false, 'DB_CONNECTION' => false, 'DB_DATABASE' => false], 'server' => null];

        $this->assertSame($nothing, $this->settings([]));
        $this->assertSame($nothing, $this->settings(['phpunit.xml' => '<phpunit><php><env name="DB_DATABASE"']));
        // A broken file does not hide a good one after it.
        $this->assertSame('shop_test', $this->settings([
            'phpunit.xml' => '<phpunit><php>',
            'phpunit.xml.dist' => $this->phpunit('        <env name="DB_DATABASE" value="shop_test"/>'),
        ])['env']['DB_DATABASE']);
    }
}
