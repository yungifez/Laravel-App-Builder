# Reference solutions

Known-good implementations of the features the builder is expected to generate
into the customer-app fixture (`fixtures/customer-app`). Each one is a patch
relative to the fixture root. Apply them in order:

| Patch | Request it answers |
| ----- | ------------------ |
| `customer-app/01-team-invitations.patch` | "Let team owners and admins invite people to their team by email." |
| `customer-app/02-owner-only-invitations.patch` | "Only the team owner may invite people." |

The fixture itself stays at the baseline: teams and roles, but no invitations.

## 01: team invitations

This follows the implementation plan's section 7 contract.

- **Storage:** the `team_invitations` table and `App\Models\TeamInvitation`
  (team, normalized email, role, SHA-256 `token_hash`, `expires_at`). The
  table has unique `(team_id, email)` and unique `token_hash` indexes.
- **Inviting:** `InviteTeamMember` issues a 64-character random token and
  stores only its hash. The expiry comes from `teams.invitations.expires_after_days`
  (default 7). The invitation is written in a transaction, and
  `TeamInvitationNotification` is queued with `afterCommit()`, so a
  rolled-back invitation never sends mail. An expired invitation for the same
  address is replaced. A concurrent duplicate hits the unique index and becomes
  a validation error without a second email.
- **Accepting:** `GET team-invitations/{token}` shows the invitation, or explains
  why it is invalid (404), expired (410) or for another account (403).
  `POST` to the same URL accepts it. `AcceptTeamInvitation` locks the
  invitation row, adds the membership with the invited role and deletes the
  invitation, so the link works once. Accepting requires a signed-in user with
  a verified, matching email.
- **UI:** the invite form, pending invitations with their expiry, and the
  `team-invitations/Show` acceptance page.
- **Tests:** `InviteTeamMemberTest`, `AcceptTeamInvitationTest` and
  `InvitationDeliveryTest`. The delivery test runs the real sync queue and
  mailer to show the email is delivered only after the commit.

**Permission step.** Every invitation write goes through one authorization
point: `App\Policies\TeamPolicy::inviteMember()`, called from the invitation form
requests and the `can.inviteMembers` page prop. It checks the
`members:invite` permission, which `config/teams.php` grants to roles. In this
solution, `owner` (through `*`) and `admin` hold it.

## 02: owner-only invitations

Restricts the permission step to the owner by removing `members:invite` from the
`admin` role in `config/teams.php`. No code changes. The tests now require that
admins get `403` when inviting or cancelling, and that they do not see the
invitation controls.

## Verifying

```sh
fixtures/reference-solutions/verify.sh              # all checks except the production build
fixtures/reference-solutions/verify.sh --with-build # also npm run build
```

The script copies the fixture's tracked files into a temporary directory and
runs the fixture's checks on the baseline. It then applies each patch in order
and re-runs the checks. At every stage it also runs the platform-owned
acceptance suites from `fixtures/acceptance/customer-app` (listed per solution
in `manifest.json`). The starter must fail the first solution's suite and each
solution must pass its own. It never modifies the repository.

## Regenerating a patch

Implement the change in `fixtures/customer-app` on a scratch branch, commit it,
and run:

```sh
git diff --binary --relative=fixtures/customer-app <base> <solution> > fixtures/reference-solutions/customer-app/NN-name.patch
```

Then restore the fixture to its baseline.
