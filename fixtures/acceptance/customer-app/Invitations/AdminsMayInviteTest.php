<?php

namespace Tests\Acceptance\Invitations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Acceptance\Support\InvitationScenario;
use Tests\TestCase;

/**
 * Initial rule: owners and administrators may invite.
 */
class AdminsMayInviteTest extends TestCase
{
    use InvitationScenario, RefreshDatabase;

    public function test_owners_and_admins_can_invite()
    {
        Notification::fake();
        [$team, $owner] = $this->createTeamWithOwner();
        $admin = $this->addMember($team, 'admin');

        $this->invite($owner, $team, 'from-owner@example.com')->assertSessionHasNoErrors();
        $this->invite($admin, $team, 'from-admin@example.com')->assertSessionHasNoErrors();

        $this->assertSame(2, $this->invitationCount($team));
    }
}
