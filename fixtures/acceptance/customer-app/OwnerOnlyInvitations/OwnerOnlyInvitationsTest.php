<?php

namespace Tests\Acceptance\OwnerOnlyInvitations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Acceptance\Support\InvitationScenario;
use Tests\TestCase;

/**
 * Restricted rule: only the team owner may invite.
 */
class OwnerOnlyInvitationsTest extends TestCase
{
    use InvitationScenario, RefreshDatabase;

    public function test_only_the_owner_can_invite()
    {
        Notification::fake();
        [$team, $owner] = $this->createTeamWithOwner();
        $admin = $this->addMember($team, 'admin');
        $member = $this->addMember($team, 'member');

        $this->invite($admin, $team, 'from-admin@example.com')->assertForbidden();
        $this->invite($member, $team, 'from-member@example.com')->assertForbidden();
        $this->invite($owner, $team, 'from-owner@example.com')->assertSessionHasNoErrors();

        $this->assertSame(1, $this->invitationCount($team));
        $this->assertSame(0, $this->invitationEmailsSentTo('from-admin@example.com'));
    }
}
