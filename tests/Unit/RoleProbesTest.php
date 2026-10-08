<?php

namespace Tests\Unit;

use App\Features\RoleProbes;
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
}
