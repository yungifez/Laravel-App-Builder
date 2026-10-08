# Change report C

**You asked:** People should be able to leave a team they've been added to.

**Understood as:** Permission and behaviour change

## The change

Anyone who was added to someone else's team will be able to leave it themselves from the team settings page. The team's owner cannot leave their own team, and nobody can leave their personal team.

## What behaves differently

- **Teams:** A 'Leave team' section on the team settings page. Before: The team settings page had no way for someone to take themselves out of a team. Now: Admins and members of someone else's team see a 'Leave team' section with a warning and a button. Pressing it asks them to confirm before anything happens. The owner does not see it, and nobody sees it on their personal team.
- **Membership:** Leaving a team. Before: Only the team's owner or an admin could take someone out of a team. People could not leave on their own. Now: An admin or member can leave a team they were added to. They disappear from the team's member list, can no longer see or switch to that team, and land on the dashboard with a message saying they left. Nobody else in the team is affected.
- **Membership:** Owners and personal teams cannot be left. Before: Leaving a team was not possible at all. Now: The owner cannot leave the team they own, nobody can leave their personal team, and people outside a team cannot use the leave option for it. Each of these attempts is refused and nothing changes.
- **Teams:** Current team after leaving. Before: Someone's current team only changed when they switched it themselves. Now: If someone leaves the team they were working in, they are moved back to their personal team automatically. If they leave a different team, their current team stays the same.

## Should stay the same

- Only the owner and admins can remove other members from a team. — checked: tests for this area ran and passed
- The owner always stays in their team and keeps the owner role. — checked: tests for this area ran and passed
- Only the owner and admins can change members' roles; members cannot. — checked: tests for this area ran and passed
- Everyone keeps a personal team and can never be left without one. — checked: tests for this area ran and passed
- People can still switch between the teams they belong to, and cannot switch to teams they are not in. — checked: tests for this area ran and passed
- Only the owner and admins can rename the team. — checked: tests for this area ran and passed
- Members can still see the teams they belong to. — checked: tests for this area ran and passed
- Deleting an account and signing up (which creates a personal team) keep working as before. — checked: tests for this area ran and passed

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
