# Change report

**You asked:** People should be able to leave a team they've been added to.

## Result

All checks passed.

## What was done

Team members can now leave a team they were added to.

What changed:
- New route: DELETE settings/teams/{team}/membership, named team-membership.destroy, behind the auth and verified middleware. It goes to Settings\TeamMembershipController@destroy, and the signed-in user is the one who leaves.
- Authorization: a new TeamPolicy::leave ability, checked by TeamMembershipDestroyRequest. Anyone who belongs to the team can leave, except its owner. Owners and people outside the team get a 403.
- New App\Actions\Teams\LeaveTeam action. It re-checks the owner rule and then reuses RemoveTeamMember, so a person leaving the team they are working in is moved to another of their teams, their personal team first.
- After leaving, the person goes back to team settings with the toast "You left <team>.". If they have no team left, they go to the dashboard instead, because team settings would return a 404.
- Team settings page: a new can.leaveTeam prop, and a "Leave team" section with a confirmation dialog. Owners don't see it.
- Tests: tests/Feature/Teams/LeaveTeamTest.php has 9 cases: guest, member, admin, current-team fallback, non-current team, no remaining team, owner forbidden, non-member forbidden, and only the signed-in user being removed. TeamSettingsTest now checks the new prop.

Checks:
- php artisan test: 74 passed
- pint --test: passed
- phpstan: 0 errors
- vp check: passed
- vue-tsc: passed
- npm run build: fails in this sandbox because the laravel:fonts plugin can't reach fonts.bunny.net (no network). This change does not cause that failure.

Nothing was committed.

## Files changed

- app/Actions/Teams/LeaveTeam.php
- app/Http/Controllers/Settings/TeamController.php
- app/Http/Controllers/Settings/TeamMembershipController.php
- app/Http/Requests/Settings/TeamMembershipDestroyRequest.php
- app/Policies/TeamPolicy.php
- resources/js/pages/settings/Team.vue
- routes/settings.php
- tests/Feature/Teams/LeaveTeamTest.php
- tests/Feature/Teams/TeamSettingsTest.php

## Checks

- Tests: passed
- Static analysis: passed
- PHP formatting: passed
- Frontend format and lint: passed
- TypeScript: passed
