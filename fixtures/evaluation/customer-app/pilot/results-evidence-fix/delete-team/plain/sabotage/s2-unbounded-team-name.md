# Change report

Team owners can now delete a team. There is a new DELETE route, settings/teams/{team}, named teams.destroy, handled by TeamController@destroy. A TeamDestroyRequest checks the new TeamPolicy::delete ability. That ability uses a new 'team:delete' permission. Owners hold it through their '*' wildcard and admins and members do not, so it can be changed in config/teams.php. The work happens in a new App\Actions\Teams\DeleteTeam action. Personal teams are refused with a validation error on 'team'. For any other team, everyone who was working in it is moved to another team they belong to, preferring their personal team, and then the team and its memberships are deleted. After a delete the owner goes back to Team settings, or to the dashboard if they have no team left. The Team settings page now has a 'Delete team' section with a confirmation dialog. It is shown only when the new can.deleteTeam prop is true, which means the user is an owner and the team is not a personal team. I added tests/Feature/Teams/DeleteTeamTest.php (10 tests) and extended TeamSettingsTest. Checks: php artisan test passed (75 tests), Pint passed, PHPStan passed with 0 errors, npm run check passed after auto-formatting Team.vue, and vue-tsc passed. npm run build failed only because this sandbox cannot reach fonts.bunny.net (a font download, unrelated to this change). Nothing was committed.

## Check output

```
$ Tests (passed)
02s  
  ✓ users cannot switch to a team they do not belong to                  0.02s  

   PASS  Tests\Feature\Teams\TeamMemberRoleTest
  ✓ admins can change a members role                                     0.02s  
  ✓ the owners role cannot be changed                                    0.02s  
  ✓ the owner role cannot be assigned                                    0.02s  
  ✓ members cannot change roles                                          0.02s  
  ✓ users outside the team cannot be targeted                            0.03s  

   PASS  Tests\Feature\Teams\TeamSettingsTest
  ✓ guests are redirected to the login page                              0.01s  
  ✓ members see their current team and its members                       0.02s  
  ✓ owners can manage the team                                           0.02s  
  ✓ users without a team get not found                                   0.01s  

   PASS  Tests\Feature\Teams\UpdateTeamTest
  ✓ owners can rename the team                                           0.02s  
  ✓ admins can rename the team                                           0.02s  
  ✓ members cannot rename the team                                       0.02s  
  ✓ outsiders cannot rename the team                                     0.02s  
  ✓ permission to rename follows configuration                           0.02s  
  ✓ team name is required                                                0.02s  

  Tests:    75 passed (285 assertions)
  Duration: 3.44s
```
```
$ Static analysis (passed)
[OK] No errors                                                                 


Note: Using configuration file /tmp/builder-evaluation/sabotage-delete-team-plain-33f8ba4c/phpstan.neon.
```
```
$ PHP formatting (passed)
............................................................................
  ...........

  ──────────────────────────────────────────────────────────────────── Laravel  
    PASS   .......................................................... 87 files
```
```
$ Frontend format and lint (passed)
pass: All 75 files are correctly formatted (1945ms, 4 threads)
pass: Found no warnings or lint errors in 64 files (1.2s, 4 threads)
```
```
$ TypeScript (passed)
> types:check
> vue-tsc --noEmit
```
