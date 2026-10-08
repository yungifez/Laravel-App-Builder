# Change report B

Only the team owner can remove people from a team now. Role permissions are set in config/teams.php, so I took 'members:remove' out of the admin role. The owner keeps it through the '*' wildcard. The existing policy (TeamPolicy::removeMember), form request and settings page already check that permission. That means admins now get a 403 if they try to remove someone, and the Remove button is hidden for them on the team settings page (can.removeMembers is false). Admins can still rename the team and change members' roles. Tests: the 'owner cannot be removed' test used to act as an admin, which now gets a 403 before reaching that check, so it now acts as the owner trying to remove themselves. The config unit test now uses 'members:update-role' to show that permissions come from config. I added tests that admins can't remove members or other admins, that the default config gives this permission only to the owner, and that admins see can.removeMembers=false on the settings page. php artisan test passed (69 tests), and Pint and PHPStan also passed. No frontend files changed. Nothing was committed.

## Check output

```
$ Tests (passed)
02s  

   PASS  Tests\Feature\Teams\TeamMemberRoleTest
  ✓ admins can change a members role                                     0.03s  
  ✓ the owners role cannot be changed                                    0.03s  
  ✓ the owner role cannot be assigned                                    0.03s  
  ✓ members cannot change roles                                          0.03s  
  ✓ users outside the team cannot be targeted                            0.02s  

   PASS  Tests\Feature\Teams\TeamSettingsTest
  ✓ guests are redirected to the login page                              0.02s  
  ✓ members see their current team and its members                       0.03s  
  ✓ owners can manage the team                                           0.02s  
  ✓ admins can manage the team but not remove members                    0.03s  
  ✓ users without a team get not found                                   0.02s  

   PASS  Tests\Feature\Teams\UpdateTeamTest
  ✓ owners can rename the team                                           0.02s  
  ✓ admins can rename the team                                           0.02s  
  ✓ members cannot rename the team                                       0.02s  
  ✓ outsiders cannot rename the team                                     0.03s  
  ✓ permission to rename follows configuration                           0.02s  
  ✓ team name is required                                                0.02s  

  Tests:    69 passed (253 assertions)
  Duration: 3.57s
```
```
$ Static analysis (passed)
[OK] No errors                                                                 


Note: Using configuration file /tmp/builder-evaluation/score-owner-only-removal-plain/phpstan.neon.
```
```
$ PHP formatting (passed)
............................................................................
  ........

  ──────────────────────────────────────────────────────────────────── Laravel  
    PASS   .......................................................... 84 files
```
```
$ Frontend format and lint (passed)
pass: All 75 files are correctly formatted (2000ms, 4 threads)
pass: Found no warnings or lint errors in 64 files (1.2s, 4 threads)
```
```
$ TypeScript (passed)
> types:check
> vue-tsc --noEmit
```
