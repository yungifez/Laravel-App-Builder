# Change report

**You asked:** Only the team owner should be able to remove people from a team. Admins shouldn't be able to anymore.

**Understood as:** Permission change

## The change

Only the team owner will be able to remove people from a team. Admins keep their other powers, such as renaming the team and changing roles, but they will no longer be able to remove members and will not see the remove option.

## What behaves differently

- **Membership:** Who can remove people from a team. Before: Both the team owner and team admins could remove members from the team. Now: Only the team owner can remove members or admins. Admins are refused if they try.
- **Membership:** Remove option on the team settings page. Before: Admins saw the option to remove members. Now: Only the team owner sees the remove option. Admins and members do not.
- **Teams:** Length limit on team names when renaming a team. Before: A team name could be at most 255 characters. Longer names were rejected with a clear message. Now: Team names have no length limit, so very long names are accepted by the form and may fail with an error when saved. This was not part of the request.

## Meant to stay the same

- Admins can still change members' roles. — related tests passed; not checked by a test of its own; the review found problems with this change
- Admins can still rename the team. — possible regression: the review or the change points here
- The team owner can still remove members and admins. — related tests passed; not checked by a test of its own; the review found problems with this change
- The team owner cannot be removed from their own team. — related tests passed; not checked by a test of its own; the review found problems with this change
- Ordinary members still cannot remove anyone. — related tests passed; not checked by a test of its own; the review found problems with this change
- Everyone keeps their personal team and can still switch between their teams. — possible regression: the review or the change points here
- Members can still see the team they belong to. — related tests passed; not checked by a test of its own; the review found problems with this change

## Problems found in review

- (blocking) An unrequested change removes the 'max:255' rule from the team name. Team names are no longer length-checked. That weakens input validation, and very long names could cause a database error (500) instead of a clear validation message. This is outside the plan and touches team renaming, which is listed under 'Must stay as it is'. Restore the 'max:255' rule.
- (minor) The old 'owner cannot be removed' test (an admin trying to remove the owner, expecting a validation error) is now a 403 check. That fits the new rules. A new test keeps the owner-self-removal case with the validation error. Nothing is lost, but the change of assertion is worth noting.
- (minor) The overall verification status is 'unverified' because no protected acceptance tests apply, even though every check passed. The hidden remove option is only tested through the 'can.removeMembers' page flag. The front end itself was not checked.

## Checks: unverified

- Tests: passed
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
