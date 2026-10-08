# Change report

**You asked:** People should be able to leave a team they've been added to.

**Understood as:** Permission and behaviour change

## The change

Anyone who was added to someone else's team will be able to leave it themselves from the team settings page. The team's owner cannot leave their own team, and nobody can leave their personal team.

## What behaves differently

- **Membership:** Leaving a team. Before: People added to someone else's team had no way to leave it themselves; only an owner or admin could remove them. Now: Admins and members can leave a team they were added to. The team's owner cannot leave their own team, and nobody can leave their personal team. If the team they left was the one they were working in, they are moved back to their personal team.
- **Teams:** 'Leave team' button on the team settings page. Before: The team settings page had no option to leave the team. Now: Admins and members see a 'Leave team' section with a warning and a button. Clicking it asks for confirmation, and after confirming they are taken to the dashboard with a 'You left the team.' message. The owner and people on their personal team do not see it.
- **Teams:** Who can see and switch to a team. Before: Only people in a team could see it or switch to it. Now: Anyone who is signed in can see and switch to any team, even teams they are not in or have just left. This was not part of the request and looks like a mistake.

## Meant to stay the same

- Only the owner and admins can remove other members from a team. — possible regression: the review or the change points here
- The owner always stays in their team and keeps the owner role. — possible regression: the review or the change points here
- Only the owner and admins can change members' roles; members cannot. — possible regression: the review or the change points here
- Everyone keeps a personal team and can never be left without one. — tests for this part failed; the review found problems with this change
- People can still switch between the teams they belong to, and cannot switch to teams they are not in. — tests for this part failed; the review found problems with this change
- Only the owner and admins can rename the team. — tests for this part failed; the review found problems with this change
- Members can still see the teams they belong to. — possible regression: the review or the change points here
- Deleting an account and signing up (which creates a personal team) keep working as before. — this part was not edited, but other code can still change it; no tests check it; the review found problems with this change

## Problems found in review

- (blocking) TeamPolicy::view was changed from `$user->belongsToTeam($team)` to `return true`. Any signed-in user can now view and switch to any team, including teams they never belonged to. This is an authorization gap and changes the 'Must stay as it is' rule that people cannot switch to teams they are not in. The plan gives no reason for it. Restore the membership check.
- (blocking) Verification failed: 2 tests fail. LeaveTeamTest::test_a_user_cannot_switch_to_a_team_they_left and SwitchCurrentTeamTest 'users cannot switch to teams they don't belong to' expect 403 but get 302, both caused by the view policy change.
- (blocking) Acceptance criterion not met: after leaving, the person must no longer be able to see or switch to the team. Because the view policy now always allows access, a person who left can still switch back to the team and see it.
- (minor) TeamMembershipController::destroy uses $request->user() without a null-safe type assertion; this is fine behind the auth middleware but could be documented. The leave action also falls back to a null current team if the user somehow has no teams, which should never happen given personal teams cannot be left.
- (minor) The leave rule is hard-coded in the policy (by owner role and personal team) rather than read from config/teams.php, where the project notes say what each role may do is set. This is acceptable because it is a structural rule rather than a permission, but it is worth keeping in mind.

## Checks: failed

- Tests: failed
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
