# Change report

**You asked:** People should be able to leave a team they've been added to.

**Understood as:** Permission and behaviour change

## The change

Anyone who was added to someone else's team will be able to leave it themselves from the team settings page. The team's owner cannot leave their own team, and nobody can leave their personal team.

## What behaves differently

- **Membership:** People can leave a team they were added to. Before: Only the team's owner or an admin could take someone out of a team. A person could not remove themselves. Now: An admin or member of someone else's team can leave it themselves. Afterwards they no longer see that team and cannot switch to it. The owner cannot leave their own team, and nobody can leave their personal team.
- **Teams:** 'Leave team' button on the team settings page. Before: The team settings page had no option to leave the team. Now: People who are allowed to leave see a 'Leave team' section with a warning and a button that asks them to confirm. The owner does not see it, and it does not appear on personal teams.
- **Membership:** What happens after leaving. Before: Not applicable. Now: After leaving, the person is taken to the dashboard with a 'You left the team.' message. If they were working in the team they left, they are switched to their personal team.
- **Teams:** Length limit on team names when renaming. Before: A team name could be at most 255 characters. Longer names were rejected with a clear message. Now: The length limit is gone, so very long names are no longer stopped by the form and may instead cause an error when saving. This was not part of the request.

## Should stay the same

- Only the owner and admins can remove other members from a team. — checked: tests for this area ran and passed
- The owner always stays in their team and keeps the owner role. — checked: tests for this area ran and passed
- Only the owner and admins can change members' roles; members cannot. — checked: tests for this area ran and passed
- Everyone keeps a personal team and can never be left without one. — checked: tests for this area ran and passed
- People can still switch between the teams they belong to, and cannot switch to teams they are not in. — checked: tests for this area ran and passed
- Only the owner and admins can rename the team. — checked: tests for this area ran and passed
- Members can still see the teams they belong to. — checked: tests for this area ran and passed
- Deleting an account and signing up (which creates a personal team) keep working as before. — checked: tests for this area ran and passed

## Problems found in review

- (blocking) Unrelated change: the 'max:255' rule was removed from the team name validation. The plan does not ask for this. It leaves team-name input unbounded, so an over-long name can reach the database and cause a server error or store oversized data. It also changes the rename-team behaviour, which is not part of this request. Restore 'max:255'.
- (minor) The leave route sits outside the route group that holds the other team member routes. This is fine, since it relies on the policy through its form request, but it should be confirmed that the surrounding group applies the same auth and verified middleware as the sibling team routes.
- (minor) LeaveTeam can set current_team_id to null if no fallback team is found. The personal-team rule should prevent that, but a defensive guard or comment would make the invariant explicit.

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
