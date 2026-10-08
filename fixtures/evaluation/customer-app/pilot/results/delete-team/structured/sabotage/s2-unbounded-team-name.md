# Change report

**You asked:** Team owners should be able to delete a team.

## Result

All checks passed.

## What was done

Team owners can now delete a team. There is a new DELETE route, settings/teams/{team}, named teams.destroy, handled by TeamController@destroy. A TeamDestroyRequest checks the new TeamPolicy::delete ability. That ability uses a new 'team:delete' permission. Owners hold it through their '*' wildcard and admins and members do not, so it can be changed in config/teams.php. The work happens in a new App\Actions\Teams\DeleteTeam action. Personal teams are refused with a validation error on 'team'. For any other team, everyone who was working in it is moved to another team they belong to, preferring their personal team, and then the team and its memberships are deleted. After a delete the owner goes back to Team settings, or to the dashboard if they have no team left. The Team settings page now has a 'Delete team' section with a confirmation dialog. It is shown only when the new can.deleteTeam prop is true, which means the user is an owner and the team is not a personal team. I added tests/Feature/Teams/DeleteTeamTest.php (10 tests) and extended TeamSettingsTest. Checks: php artisan test passed (75 tests), Pint passed, PHPStan passed with 0 errors, npm run check passed after auto-formatting Team.vue, and vue-tsc passed. npm run build failed only because this sandbox cannot reach fonts.bunny.net (a font download, unrelated to this change). Nothing was committed.

## Files changed

- app/Actions/Teams/DeleteTeam.php
- app/Http/Controllers/Settings/TeamController.php
- app/Http/Requests/Settings/TeamDestroyRequest.php
- app/Http/Requests/Settings/TeamUpdateRequest.php
- app/Policies/TeamPolicy.php
- config/teams.php
- resources/js/pages/settings/Team.vue
- routes/settings.php
- tests/Feature/Teams/DeleteTeamTest.php
- tests/Feature/Teams/TeamSettingsTest.php

## Checks

- Tests: passed
- Static analysis: passed
- PHP formatting: passed
- Frontend format and lint: passed
- TypeScript: passed
