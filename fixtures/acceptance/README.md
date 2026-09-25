# Platform-owned acceptance suites

Protected acceptance tests for features generated into customer apps. They live
**outside** the application source, so a generated change, whether written by
the agent or by hand, cannot edit or weaken them. Verification copies the
listed files into the app's `tests/Acceptance` directory in a fresh copy, runs
`php artisan test tests/Acceptance`, and throws the copy away.

The manifest in `fixtures/reference-solutions/customer-app/manifest.json`
lists which suites apply to each solution.
`fixtures/reference-solutions/verify.sh` checks that:

- the untouched starter **fails** the first solution's suite (so a prebuilt
  feature cannot count as generated), and
- each reference solution **passes** its own suite.

## `customer-app` invitation contract

These are the implementation plan's section 7 rules, expressed through the
app's public surface only: HTTP, the starter's team models, and the
invitation email. A generated invitations feature must provide:

| Contract | Detail |
| -------- | ------ |
| Invite | `POST route('team-invitations.store', $team)` with `email` and `role` (`admin` or `member`) |
| Storage | Table `team_invitations` with `team_id`, `email`, `role`; the email is stored trimmed and lowercased |
| Email | An on-demand mail notification to the invitee; `toMail()->actionUrl` opens the invitation |
| Accept | `GET` of that URL shows the invitation; `POST` to the same URL accepts it |
| Cancel | `DELETE route('team-invitations.destroy', [$team, $invitationId])` |

Rules checked:

- An invitation is pending until accepted. Accepting adds the member with the
  invited role and consumes the invitation.
- The link works once. The token is high entropy and not stored in plain text.
  It expires after seven days.
- Only the signed-in user with the invited address can accept. Guests are sent
  to log in. A cancelled invitation cannot be accepted.
- A repeated active invitation is rejected without another email. Existing
  members cannot be invited. Invalid input writes and sends nothing.
- Members, owners of other teams and guests cannot invite.
- `Invitations/AdminsMayInviteTest.php`: owners **and admins** may invite
  (the initial rule).
- `OwnerOnlyInvitations/OwnerOnlyInvitationsTest.php`: **only the owner** may
  invite (the restricted rule).

Not yet covered here: the browser checks (keyboard navigation, labels, visible
errors, contrast), whether the UI hides the invite form from admins after the
owner-only change, and true parallel acceptance. The reference solutions' own
tests cover the last two at the application level.
