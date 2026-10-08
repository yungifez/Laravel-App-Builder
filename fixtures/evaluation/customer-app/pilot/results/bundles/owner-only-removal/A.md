# Change report A

**You asked:** Only the team owner should be able to remove people from a team. Admins shouldn't be able to anymore.

**Understood as:** Permission change

## The change

Only the team owner will be able to remove people from a team. Admins keep their other powers, such as renaming the team and changing roles, but they will no longer be able to remove members and will not see the remove option.

## What behaves differently

- **Membership:** Admins removing people from a team. Before: An admin could remove members and other admins from the team. Now: Admins can no longer remove anyone. If they try, they are refused and the person stays in the team. Only the team owner can remove members and admins.
- **Membership:** Remove option on the team settings page. Before: Both the owner and admins saw the option to remove people from the team. Now: Only the team owner sees the remove option. Admins and ordinary members do not.
- **Membership:** An admin trying to remove the team owner. Before: The admin got an error message saying the owner cannot be removed. Now: The admin is refused outright because they are not allowed to remove anyone. The owner still stays in the team.

## Should stay the same

- Admins can still change members' roles. — checked: tests for this area ran and passed
- Admins can still rename the team. — checked: tests for this area ran and passed
- The team owner can still remove members and admins. — checked: tests for this area ran and passed
- The team owner cannot be removed from their own team. — checked: tests for this area ran and passed
- Ordinary members still cannot remove anyone. — checked: tests for this area ran and passed
- Everyone keeps their personal team and can still switch between their teams. — checked: tests for this area ran and passed
- Members can still see the team they belong to. — checked: tests for this area ran and passed

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
