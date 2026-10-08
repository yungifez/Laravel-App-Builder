# Change report

**You asked:** People should be able to leave a team they've been added to.

**Understood as:** Permission and behaviour change

## The change

Anyone who was added to someone else's team will be able to leave it themselves from the team settings page. The team's owner cannot leave their own team, and nobody can leave their personal team.

## What behaves differently

- **teams:** A 'Leave team' section on the team settings page. Before: The team settings page had no way for someone to take themselves out of a team. Now: Admins and members of someone else's team see a 'Leave team' section with a warning and a button. Pressing it asks them to confirm before anything happens. The owner does not see it, and nobody sees it on their personal team.
- **membership:** Leaving a team. Before: Only the team's owner or an admin could take someone out of a team. People could not leave on their own. Now: An admin or member can leave a team they were added to. They disappear from the team's member list, can no longer see or switch to that team, and land on the dashboard with a message saying they left. Nobody else in the team is affected.
- **membership:** Owners and personal teams cannot be left. Before: Leaving a team was not possible at all. Now: The owner cannot leave the team they own, nobody can leave their personal team, and people outside a team cannot use the leave option for it. Each of these attempts is refused and nothing changes.
- **teams:** Current team after leaving. Before: Someone's current team only changed when they switched it themselves. Now: If someone leaves the team they were working in, they are moved back to their personal team automatically. If they leave a different team, their current team stays the same.

## Meant to stay the same

- Only the owner and admins can remove other members from a team. — related tests passed; not checked by a test of its own
- The owner always stays in their team and keeps the owner role. — related tests passed; not checked by a test of its own
- Only the owner and admins can change members' roles; members cannot. — related tests passed; not checked by a test of its own
- Everyone keeps a personal team and can never be left without one. — related tests passed; not checked by a test of its own
- People can still switch between the teams they belong to, and cannot switch to teams they are not in. — related tests passed; not checked by a test of its own
- Only the owner and admins can rename the team. — related tests passed; not checked by a test of its own
- Members can still see the teams they belong to. — related tests passed; not checked by a test of its own
- Deleting an account and signing up (which creates a personal team) keep working as before. — related tests passed; not checked by a test of its own

## Problems found in review

- (minor) The project rule says what each role may do is set in one place (config/teams.php). The new 'leave' policy instead checks the Owner role directly in code. Who may leave works correctly, but it does not follow the config-driven permission pattern the other team abilities use.
- (minor) The tests check the 'canLeave' page flag but not the button or confirmation dialog themselves. The frontend type check and lint passed, so the risk is low.
- (minor) TeamMembershipDestroyRequest has an empty rules() method. That is harmless because the request takes no input, but the method could carry a short comment saying so.
- (minor) The verification run is labelled 'unverified' because no protected acceptance tests apply to this change. All the checks that did run passed.

## Checks: unverified

- Tests: passed
- Static analysis: passed
- PHP formatting: passed
- Frontend format and lint: passed
- TypeScript: passed
- Protected acceptance tests: not_applicable

## Decisions made for you

- The owner may not leave their own team, since a team must keep its owner; transferring ownership is out of scope.
- Personal teams cannot be left.
- No email or notification is sent to the owner or admins when someone leaves.
- Leaving needs a confirmation dialog in the interface but not password confirmation.
- After leaving, the person is redirected to the dashboard with a flash toast, following the existing flashToast pattern.
- The route lives in routes/settings.php alongside the other team settings routes, behind the auth and verified middleware.
