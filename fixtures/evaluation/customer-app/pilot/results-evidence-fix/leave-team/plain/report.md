# Change report

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

## Check output

```
$ Tests (passed)
02s  
  ✓ users cannot switch to a team they do not belong to                  0.02s  

   PASS  Tests\Feature\Teams\TeamMemberRoleTest
  ✓ admins can change a members role                                     0.04s  
  ✓ the owners role cannot be changed                                    0.02s  
  ✓ the owner role cannot be assigned                                    0.02s  
  ✓ members cannot change roles                                          0.02s  
  ✓ users outside the team cannot be targeted                            0.02s  

   PASS  Tests\Feature\Teams\TeamSettingsTest
  ✓ guests are redirected to the login page                              0.02s  
  ✓ members see their current team and its members                       0.02s  
  ✓ owners can manage the team                                           0.02s  
  ✓ users without a team get not found                                   0.02s  

   PASS  Tests\Feature\Teams\UpdateTeamTest
  ✓ owners can rename the team                                           0.02s  
  ✓ admins can rename the team                                           0.02s  
  ✓ members cannot rename the team                                       0.02s  
  ✓ outsiders cannot rename the team                                     0.02s  
  ✓ permission to rename follows configuration                           0.03s  
  ✓ team name is required                                                0.03s  

  Tests:    74 passed (258 assertions)
  Duration: 3.49s
```
```
$ Static analysis (passed)
[OK] No errors                                                                 


Note: Using configuration file /tmp/builder-evaluation/score-leave-team-plain/phpstan.neon.
```
```
$ PHP formatting (passed)
............................................................................
  ............

  ──────────────────────────────────────────────────────────────────── Laravel  
    PASS   .......................................................... 88 files
```
```
$ Frontend format and lint (passed)
pass: All 75 files are correctly formatted (1987ms, 4 threads)
pass: Found no warnings or lint errors in 64 files (1.1s, 4 threads)
```
```
$ TypeScript (passed)
> types:check
> vue-tsc --noEmit
```
