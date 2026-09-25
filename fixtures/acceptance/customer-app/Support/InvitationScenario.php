<?php

namespace Tests\Acceptance\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Platform-owned helpers for the invitation acceptance suites.
 *
 * They drive the application only through HTTP, the starter's team models and
 * the invitation email. They never use code the generated feature adds, so
 * the feature cannot change what "accepted" means.
 *
 * Contract the generated feature must meet (see README.md):
 * - POST route('team-invitations.store', $team) with `email` and `role`.
 * - An on-demand mail notification to the invitee whose action URL opens
 *   the invitation. A POST to that same URL accepts it.
 * - Invitations are stored in `team_invitations` (`team_id`, `email`, `role`).
 */
trait InvitationScenario
{
    protected function createTeamWithOwner(string $name = 'Acme'): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->ownedBy($owner)->create(['name' => $name]);

        return [$team, $owner];
    }

    protected function addMember(Team $team, string $role, array $attributes = []): User
    {
        $user = User::factory()->withPersonalTeam()->create($attributes);
        $team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function invite(User $actor, Team $team, string $email, string $role = 'member'): TestResponse
    {
        return $this->actingAs($actor)->post(route('team-invitations.store', $team), [
            'email' => $email,
            'role' => $role,
        ]);
    }

    /**
     * Get the invitation URL from the latest invitation email sent to the address.
     */
    protected function invitationUrlFor(string $email): string
    {
        $urls = [];

        foreach (Notification::sentNotifications() as $notifiableType => $notifiables) {
            if ($notifiableType !== AnonymousNotifiable::class) {
                continue;
            }

            foreach ($notifiables as $sent) {
                foreach ($sent as $records) {
                    foreach ($records as $record) {
                        $notifiable = $record['notifiable'];

                        if (strcasecmp((string) $notifiable->routeNotificationFor('mail'), $email) === 0) {
                            $urls[] = $record['notification']->toMail($notifiable)->actionUrl;
                        }
                    }
                }
            }
        }

        Assert::assertNotEmpty($urls, "No invitation email was sent to [{$email}].");

        return (string) end($urls);
    }

    protected function invitationEmailsSentTo(string $email): int
    {
        $count = 0;

        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $sent) {
            foreach ($sent as $records) {
                foreach ($records as $record) {
                    if (strcasecmp((string) $record['notifiable']->routeNotificationFor('mail'), $email) === 0) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    protected function invitationCount(Team $team): int
    {
        return DB::table('team_invitations')->where('team_id', $team->id)->count();
    }

    protected function isMember(Team $team, User $user): bool
    {
        return $team->members()->whereKey($user->id)->exists();
    }
}
