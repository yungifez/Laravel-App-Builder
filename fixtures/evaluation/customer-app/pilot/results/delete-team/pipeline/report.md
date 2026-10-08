# Change report

**You asked:** Team owners should be able to delete a team.

**Understood as:** New permission and behaviour: deleting a team

## The change

The owner of a team gets a 'Delete team' option in the team settings. After they confirm, the team and its memberships are removed, and anyone who was using that team is moved back to their personal team.

## What behaves differently

- **Teams:** Deleting a team from the team settings page. Before: Teams could not be deleted. Now: The owner of a team that is not a personal team sees a 'Delete team' section with a warning in the team settings. After they confirm in a dialog, the team is permanently deleted, and the owner is taken to the dashboard with a 'Team deleted.' message.
- **Membership:** Who may delete a team. Before: Nobody could delete a team. Now: Only the team's owner can delete it. Admins, ordinary members and people outside the team are refused, and the team settings cannot change this.
- **Teams:** Personal teams. Before: Personal teams could not be deleted. Now: Personal teams still cannot be deleted. They show no delete option, and a direct attempt to delete one is refused.
- **Membership:** What happens to the people in a deleted team. Before: Not applicable, because teams could not be deleted. Now: Everyone in the deleted team loses access to it. Anyone who was working in that team, including the owner, is moved back to their personal team. People who were working in a different team stay where they are.

## Should stay the same

- Everyone still has a personal team, and it can never be deleted. — checked: tests for this area ran and passed
- Users can still switch between the teams they belong to, and their current team always points to a team they belong to. — checked: tests for this area ran and passed
- Admins can still rename the team, change members' roles and remove members, but cannot delete the team. — checked: tests for this area ran and passed
- Members can still see the team, but cannot change or delete it. — checked: tests for this area ran and passed
- The owner can still do everything in the team, including renaming it and managing members. — checked: tests for this area ran and passed
- Renaming a team and the existing team settings page keep working unchanged. — checked: tests for this area ran and passed
- Deleting a user account (profile settings) keeps working as it does now. — checked: tests for this area ran and passed

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
