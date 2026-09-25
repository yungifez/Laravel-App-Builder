<?php

namespace Tests\Acceptance\Invitations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Acceptance\Support\InvitationScenario;
use Tests\TestCase;

/**
 * Invitation rules that hold whoever is allowed to invite
 * (implementation plan section 7).
 */
class InvitationContractTest extends TestCase
{
    use InvitationScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_an_invitation_is_pending_until_the_invitee_accepts_it()
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $this->invite($owner, $team, '  Invitee@Example.COM ', 'admin')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('team_invitations', ['team_id' => $team->id, 'email' => 'invitee@example.com', 'role' => 'admin']);
        $url = $this->invitationUrlFor('invitee@example.com');

        $invitee = User::factory()->withPersonalTeam()->create(['email' => 'invitee@example.com']);
        $this->assertFalse($this->isMember($team, $invitee));

        $this->actingAs($invitee)->get($url)->assertOk();
        $this->actingAs($invitee)->post($url)->assertRedirect();

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertSame('admin', $team->members()->whereKey($invitee->id)->first()->membership->role->value);
        $this->assertSame(0, $this->invitationCount($team));
    }

    public function test_the_link_works_only_once()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $url = $this->invitationUrlFor('invitee@example.com');
        $invitee = User::factory()->withPersonalTeam()->create(['email' => 'invitee@example.com']);

        $this->actingAs($invitee)->post($url);
        $second = $this->actingAs($invitee)->post($url);

        $this->assertTrue($second->isClientError(), 'A used invitation link must be rejected.');
        $this->assertSame(1, $team->members()->whereKey($invitee->id)->count());
    }

    public function test_the_token_is_not_stored_in_plain_text()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $token = basename(parse_url($this->invitationUrlFor('invitee@example.com'), PHP_URL_PATH));

        $this->assertGreaterThanOrEqual(32, strlen($token), 'The invitation token must be high entropy.');

        $row = (array) DB::table('team_invitations')->where('team_id', $team->id)->first();
        foreach ($row as $value) {
            $this->assertStringNotContainsString($token, (string) $value);
        }
    }

    public function test_the_invitation_expires_after_seven_days()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $url = $this->invitationUrlFor('invitee@example.com');
        $invitee = User::factory()->withPersonalTeam()->create(['email' => 'invitee@example.com']);

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->assertTrue($this->actingAs($invitee)->post($url)->isClientError(), 'An expired invitation must be rejected.');
        $this->assertFalse($this->isMember($team, $invitee));
    }

    public function test_only_the_invited_address_can_accept()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $url = $this->invitationUrlFor('invitee@example.com');
        $someoneElse = User::factory()->withPersonalTeam()->create();

        $this->actingAs($someoneElse)->post($url)->assertForbidden();

        $this->assertFalse($this->isMember($team, $someoneElse));
        $this->assertSame(1, $this->invitationCount($team));
    }

    public function test_guests_must_sign_in_before_accepting()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $url = $this->invitationUrlFor('invitee@example.com');
        auth()->logout();

        $this->post($url)->assertRedirect(route('login'));
        $this->assertSame(1, $this->invitationCount($team));
    }

    public function test_a_cancelled_invitation_cannot_be_accepted()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $this->invite($owner, $team, 'invitee@example.com');
        $url = $this->invitationUrlFor('invitee@example.com');
        $id = DB::table('team_invitations')->where('team_id', $team->id)->value('id');
        $invitee = User::factory()->withPersonalTeam()->create(['email' => 'invitee@example.com']);

        $this->actingAs($owner)->delete(route('team-invitations.destroy', [$team, $id]));

        $this->assertTrue($this->actingAs($invitee)->post($url)->isClientError(), 'A cancelled invitation must be rejected.');
        $this->assertFalse($this->isMember($team, $invitee));
    }

    public function test_a_repeated_invitation_is_rejected_without_another_email()
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $this->invite($owner, $team, 'invitee@example.com')->assertSessionHasNoErrors();
        $this->invite($owner, $team, 'INVITEE@example.com')->assertSessionHasErrors('email');

        $this->assertSame(1, $this->invitationCount($team));
        $this->assertSame(1, $this->invitationEmailsSentTo('invitee@example.com'));
    }

    public function test_existing_members_cannot_be_invited()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $member = $this->addMember($team, 'member');

        $this->invite($owner, $team, $member->email)->assertSessionHasErrors('email');
        $this->assertSame(0, $this->invitationCount($team));
    }

    public function test_invalid_input_writes_nothing_and_sends_nothing()
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $this->invite($owner, $team, 'not-an-email', 'owner')->assertSessionHasErrors(['email', 'role']);

        $this->assertSame(0, $this->invitationCount($team));
        Notification::assertNothingSent();
    }

    public function test_members_other_teams_and_guests_cannot_invite()
    {
        [$team, $owner] = $this->createTeamWithOwner();
        $member = $this->addMember($team, 'member');
        [, $otherOwner] = $this->createTeamWithOwner('Other');

        $this->invite($member, $team, 'a@example.com')->assertForbidden();
        $this->invite($otherOwner, $team, 'b@example.com')->assertForbidden();
        auth()->logout();
        $this->post(route('team-invitations.store', $team), ['email' => 'c@example.com', 'role' => 'member'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, $this->invitationCount($team));
        Notification::assertNothingSent();
    }
}
