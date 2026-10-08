# Change report

**You asked:** Team owners should be able to delete a team.

**Understood as:** New permission and behaviour: deleting a team

## The change

The owner of a team gets a 'Delete team' option in the team settings. After they confirm, the team and its memberships are removed, and anyone who was using that team is moved back to their personal team.

## What behaves differently

- **Teams:** Deleting a team from the team settings. Before: There was no way to delete a team. Now: The owner of a team (but not of a personal team) sees a 'Delete team' section in the team settings. After confirming in a dialog, the team and everyone's membership in it are permanently removed, and the owner is taken to the dashboard with a 'Team deleted.' message.
- **Teams:** Where people end up when the team they were working in is deleted. Before: Not applicable; teams could not be deleted. Now: Anyone who was working in the deleted team, including the owner, is automatically moved to their personal team (or another team they belong to).
- **Membership:** Who is allowed to delete a team. Before: Nobody could delete a team. Now: Only the team's owner can delete it. Admins, members and people outside the team are refused, even if the role settings give admins every permission. Personal teams can never be deleted.
- **Teams:** Length limit on team names when renaming. Before: A team name longer than 255 characters was rejected with a clear message. Now: The length limit was removed, so very long names are no longer stopped with a friendly message and may cause an error when saving. This was not part of the request.

## Meant to stay the same

- Everyone still has a personal team, and it can never be deleted. — possible regression: the review or the change points here
- Users can still switch between the teams they belong to, and their current team always points to a team they belong to. — possible regression: the review or the change points here
- Admins can still rename the team, change members' roles and remove members, but cannot delete the team. — related tests passed; not checked by a test of its own; the review found problems with this change
- Members can still see the team, but cannot change or delete it. — related tests passed; not checked by a test of its own; the review found problems with this change
- The owner can still do everything in the team, including renaming it and managing members. — related tests passed; not checked by a test of its own; the review found problems with this change
- Renaming a team and the existing team settings page keep working unchanged. — possible regression: the review or the change points here
- Deleting a user account (profile settings) keeps working as it does now. — related tests passed; not checked by a test of its own; the review found problems with this change

## Problems found in review

- (blocking) The 'max:255' rule was removed from the team name validation. This is unrelated to the request, weakens input validation (names longer than the database column can now be submitted, likely causing a database error instead of a validation message), and changes renaming a team, which is listed under 'Must stay as it is'. Restore ['required', 'string', 'max:255'].
- (minor) The overall verification status is reported as 'unverified' even though every individual check passed; protected acceptance tests were not applicable, so the new behaviour is covered only by the diff's own tests.
- (minor) The fallback team uses a null-safe value, so a user with no remaining teams would be left with no current team. This cannot happen while every user keeps a personal team, but the assumption is implicit.
- (minor) The Vue template checks '!team.personal_team' in addition to 'can.deleteTeam', duplicating the rule already enforced by the policy. Harmless but redundant.

## Checks: unverified

- Tests: passed
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
