# Change report

**You asked:** Team owners should be able to delete a team.

**Understood as:** New permission and behaviour: deleting a team

## The change

The owner of a team gets a 'Delete team' option in the team settings. After they confirm, the team and its memberships are removed, and anyone who was using that team is moved back to their personal team.

## What behaves differently

- **Teams:** Deleting a team. Before: There was no way to delete a team. Now: The owner of a team that is not a personal team sees a 'Delete team' section in the team settings. After they confirm in a dialog, the team and everyone's membership in it are removed permanently, and the owner is taken to the dashboard with a 'Team deleted.' message.
- **Teams:** Where people end up after their team is deleted. Before: Not applicable, because teams could not be deleted. Now: Anyone who was working in the deleted team, including the owner, is moved to their personal team, or to another team they belong to if they have no personal team.
- **Membership:** Who may delete a team. Before: Nobody could delete a team. Now: Only the team's owner can delete it, and personal teams can never be deleted. Admins, members and outsiders are refused even if the role settings give them every other permission.
- **Membership:** Viewing and switching into teams you are not part of. Before: People could only view and switch to teams they belonged to. Trying to switch into another team was refused. Now: Any signed-in person is allowed to view any team and can switch their current team to a team they are not a member of. The request did not ask for this, and it is why the checks failed.

## Meant to stay the same

- Everyone still has a personal team, and it can never be deleted. — possible regression: the review or the change points here
- Users can still switch between the teams they belong to, and their current team always points to a team they belong to. — possible regression: the review or the change points here
- Admins can still rename the team, change members' roles and remove members, but cannot delete the team. — possible regression: the review or the change points here
- Members can still see the team, but cannot change or delete it. — possible regression: the review or the change points here
- The owner can still do everything in the team, including renaming it and managing members. — possible regression: the review or the change points here
- Renaming a team and the existing team settings page keep working unchanged. — possible regression: the review or the change points here
- Deleting a user account (profile settings) keeps working as it does now. — this part was not edited, but other code can still change it; no tests check it; the review found problems with this change

## Problems found in review

- (blocking) TeamPolicy::view was changed from `$user->belongsToTeam($team)` to `return true`. Nothing in the request or plan asks for this. It removes the membership check, so any signed-in user is authorized to view any team and to switch their current team to it. This is an authorization gap.
- (blocking) This breaks the 'Must stay as it is' rule that a user's current team always points to a team they belong to. Outsiders can now switch into a team they are not in (PUT current-team.update returns a redirect instead of 403).
- (blocking) Verification failed. In Tests\Feature\Teams\SwitchCurrentTeamTest, 'users cannot switch to a team they do not belong to' expected 403 but received 302 (1 failed, 73 passed). This is caused by the view policy change.
- (minor) The capability notes say the owner is moved 'to their personal team (or another team they belong to)'. The action also sets current_team_id to null if a user has no other team. This should not happen given the personal-team rule, but the null case is unhandled and undocumented.
- (minor) The Vue check `can.deleteTeam && !team.personal_team` repeats the personal-team check that the policy already makes. This is harmless but redundant.

## Checks: failed

- Tests: failed
- Static analysis: passed
- PHP formatting: passed
- Frontend format and lint: passed
- TypeScript: passed
- Protected acceptance tests: not_applicable

## Decisions made for you

- Personal teams cannot be deleted, because every user must always keep a personal team (as in Laravel Jetstream).
- Deletion is permanent (no soft deletes) and memberships are removed together with the team, either via cascading foreign keys or explicitly in the action.
- Members affected by the deletion are not emailed; no notification is sent.
- The owner confirms in a dialog by clicking a button; re-entering the team name or password is not required.
- The route teams.destroy is added to routes/settings.php next to the existing team settings routes, behind the auth and verified middleware, and after deletion the owner is redirected to the dashboard.
- Authorization is enforced through TeamPolicy::delete, which is called from a form request.
