# Change report

**You asked:** Only the team owner should be able to remove people from a team. Admins shouldn't be able to anymore.

**Understood as:** Permission change

## The change

Only the team owner will be able to remove people from a team. Admins keep their other powers, such as renaming the team and changing roles, but they will no longer be able to remove members and will not see the remove option.

## What behaves differently

- **Membership:** Who can remove people from a team. Before: Both the team owner and team admins could remove members from the team. Now: Only the team owner can remove members or admins. If an admin tries, they are refused and the person stays in the team.
- **Membership:** Remove option on the team settings page. Before: Admins saw the option to remove members on the team settings page. Now: Only the team owner sees the remove option. Admins can still rename the team and change roles, but no longer see the remove option.
- **Teams:** Seeing and switching to teams you are not part of. Before: People could only see and switch to teams they belonged to. Trying to open someone else's team was refused. Now: Any signed-in person can open any team and switch to it, even if they are not a member. Nobody asked for this, it is a security problem, and it should be undone.

## Should stay the same

- Admins can still change members' roles. — not checked
- Admins can still rename the team. — not checked
- The team owner can still remove members and admins. — not checked
- The team owner cannot be removed from their own team. — not checked
- Ordinary members still cannot remove anyone. — not checked
- Everyone keeps their personal team and can still switch between their teams. — not checked
- Members can still see the team they belong to. — not checked

## Problems found in review

- (blocking) TeamPolicy::view now returns true instead of checking $user->belongsToTeam($team). This was not part of the request or plan, and it removes the team membership check. Any signed-in user can now view and switch to any team, including other people's teams. Revert it to `return $user->belongsToTeam($team);`.
- (blocking) Verification failed. In tests/Feature/Teams/SwitchCurrentTeamTest.php, 'users cannot switch to a team they do not belong to' expected 403 but got 302. This is caused by the TeamPolicy::view change.
- (blocking) The diff changes behaviour under 'Must stay as it is' (switching between teams, and team visibility limited to members). Outsiders can now switch into teams they are not part of, which breaks the existing team-switching rules.
- (minor) No test checks that a personal team's owner can never be removed. The general 'owner cannot be removed' test probably covers this, but an explicit personal-team case would document that acceptance criterion.
- (minor) test_admins_cannot_remove_the_owner now expects 403 instead of a validation error. This follows from the new rule, since admins can no longer remove anyone, so it is not a weakening. Note that the owner-protection validation is now only tested with the owner as the person doing the removing.

## Checks: failed

- Tests: failed
- Static analysis: passed
- PHP formatting: passed
- Frontend format and lint: passed
- TypeScript: passed
- Protected acceptance tests: not_applicable

## Decisions made for you

- "Team owner" means the user with the owner role on that team (the person who created it), as described in the project notes.
- The check is enforced on the server through the existing TeamPolicy / form request, not only by hiding the button.
- Whether a member can leave a team on their own is not covered by this request; any existing self-leave behaviour is left unchanged.
- No database change is needed; roles stay as they are.
