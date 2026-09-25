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

- `team_invitations` table and `App\Models\TeamInvitation` (team, email, role).
- Actions: `InviteTeamMember` (creates the invitation and sends
  `App\Mail\TeamInvitationMail` with a signed acceptance link),
  `AcceptTeamInvitation`, `CancelTeamInvitation`.
- Routes: `team-invitations.store`, `team-invitations.destroy` (scoped to the
  team) and `team-invitations.accept` (`signed` middleware, logged-in and
  verified user whose email matches, via `TeamInvitationPolicy::accept`).
- UI: invite form and pending invitations on `settings/Team.vue`.
- Tests: `tests/Feature/Teams/InviteTeamMemberTest.php` and
  `AcceptTeamInvitationTest.php`.

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

The script copies the fixture's tracked files into a temporary directory, runs
the fixture's checks on the baseline, then applies each patch in order and
re-runs the checks. It never modifies the repository.

## Regenerating a patch

Implement the change in `fixtures/customer-app` on a scratch branch, commit it,
and run:

```sh
git diff --binary --relative=fixtures/customer-app <base> <solution> > fixtures/reference-solutions/customer-app/NN-name.patch
```

Then restore the fixture to its baseline.
