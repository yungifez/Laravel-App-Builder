<?php

namespace Tests\Unit;

use App\Enums\TeamRole;
use Tests\TestCase;

class TeamRoleTest extends TestCase
{
    public function test_owner_holds_every_permission_through_the_wildcard()
    {
        $this->assertTrue(TeamRole::Owner->hasPermission('team:update'));
        $this->assertTrue(TeamRole::Owner->hasPermission('anything:else'));
    }

    public function test_permissions_come_from_configuration()
    {
        $this->assertTrue(TeamRole::Admin->hasPermission('members:remove'));
        $this->assertFalse(TeamRole::Member->hasPermission('members:remove'));

        config(['teams.roles.admin.permissions' => []]);
        config(['teams.roles.member.permissions' => ['members:remove']]);

        $this->assertFalse(TeamRole::Admin->hasPermission('members:remove'));
        $this->assertTrue(TeamRole::Member->hasPermission('members:remove'));
    }

    public function test_owner_is_not_an_assignable_role()
    {
        $this->assertSame([TeamRole::Admin, TeamRole::Member], TeamRole::assignable());
    }
}
