# Customer-app fixture and invitation reference solution (G0.2)

Recorded 2026-09-25 on branch `g0-2-customer-fixture`.

No separate G0.2 work order was provided. This work follows the brief at the end
of G0.1: "a separate customer-application fixture with teams and roles, then its
invitation reference solution. Platform identities and customer-app identities
must remain distinct." The assumptions below fill the gaps.

## What was built

- **`fixtures/customer-app`:** a separate Laravel 13 + Inertia + Vue app
  created from the same starter revision as the control plane
  (`laravel/vue-starter-kit` `d282e81`, default auth features). It stands in for
  a customer's codebase.
- **Teams and roles** in that app (see below). There are no invitations: they
  are the feature the builder will be asked to generate.
- **`fixtures/reference-solutions`:** two patches that implement the expected
  answers, plus `verify.sh`, which proves they apply and pass all checks.
- **`fixtures/acceptance`:** platform-owned acceptance suites kept outside the
  app (see its README). The starter fails them and the solutions pass them.
- **CI jobs** `customer-app` and `reference-solutions` in
  `.github/workflows/ci.yml`.

## Assumptions

- **Location.** Laravel convention would give the customer app its own
  repository. No second repository was available in this session, so it lives
  in `fixtures/customer-app`. The control plane has no code that refers to that
  path, and the root tools (Pint, Oxfmt, Oxlint, Vite) exclude `fixtures/`.
  Moving it to its own repository later needs no code changes.
- **Laravel defaults.** The fixture keeps the starter's defaults: SQLite for
  development and in-memory SQLite for tests, the database queue and cache, and
  the starter's own script names (`composer test`, `npm run check`,
  `npm run types:check`). The only additions to its test setup are
  `withoutVite()` and a test helper trait.
- **Distinct identities.** The fixture has its own `users` table in its own
  SQLite database. It shares no database, role or credentials with the control
  plane.
- **Role model.** Three roles: `owner`, `admin`, `member`. Ownership is set when
  a team is created and cannot be assigned, changed or removed. Ownership
  transfer is out of scope.

## Teams and roles design

| Piece         | Where                                                                                                                                          |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Tables        | `teams` (name, personal_team), `team_user` (team, user, role), `users.current_team_id`                                                         |
| Models        | `Team`, `Membership` (pivot, role cast to `App\Enums\TeamRole`), `User` via `App\Models\Concerns\HasTeams`                                     |
| Permissions   | `config/teams.php`: `roles.<role>.permissions`, `*` = everything. Current permissions: `team:update`, `members:update-role`, `members:remove`  |
| Authorization | `App\Policies\TeamPolicy` (`view`, `update`, `updateMemberRole`, `removeMember`); form requests authorize through it                           |
| Actions       | `app/Actions/Teams`: `CreateTeam`, `UpdateTeamName`, `UpdateTeamMemberRole`, `RemoveTeamMember`, `SwitchCurrentTeam`                           |
| HTTP          | `Settings\TeamController`, `Settings\TeamMemberController`, `CurrentTeamController`; routes in `routes/settings.php`                           |
| UI            | `resources/js/pages/settings/Team.vue`, linked from the settings sidebar                                                                       |
| Registration  | `CreateNewUser` creates a personal team through `CreateTeam`                                                                                   |
| Seed data     | `php artisan db:seed`: `test@example.com` owns "Acme", with `admin@example.com` (admin) and `member@example.com` (member); password `password` |

Rules enforced by the actions: the owner's role cannot be changed, the owner
role cannot be assigned, the owner cannot be removed, and a removed member who
was working in the team moves to their personal team.

## Reference solutions

See `fixtures/reference-solutions/README.md`. Summary:

1. `01-team-invitations.patch`: email invitations with a role, following the
   section 7 contract. That means a hashed single-use token with a 7-day
   expiry, a normalized email, a queued email sent only after the transaction
   commits, a database guard against duplicates and double acceptance, and an
   acceptance page. The **permission step** is `TeamPolicy::inviteMember()`,
   backed by the `members:invite` permission (held by owner and admin).
2. `02-owner-only-invitations.patch`: the owner-only restriction, which removes
   `members:invite` from `admin` in `config/teams.php`, with tests that pin the
   new behavior.

These map onto the prototype flow: request invitations → preview the generated
feature → select its permission step → request an owner-only restriction →
inspect verification.

## Check status (local, 2026-09-25)

| Scope            | Command                                                    | Result                                                             |
| ---------------- | ---------------------------------------------------------- | ------------------------------------------------------------------ |
| Fixture baseline | `./vendor/bin/pint --test`                                 | passed                                                             |
|                  | `./vendor/bin/phpstan analyse` (level 7, starter config)   | passed                                                             |
|                  | `php artisan test`                                         | passed: 65 tests                                                   |
|                  | `npx vp fmt --check`, `npx vp lint`, `npm run types:check` | passed                                                             |
|                  | `npm run build`                                            | **blocked here**: sandbox egress returns 403 for `fonts.bunny.net` |
| After 01         | same checks via `verify.sh`                                | passed: 80 tests                                                   |
| After 02         | same checks via `verify.sh`                                | passed: 82 tests                                                   |
| Control plane    | all seven checks                                           | passed, except `npm run build` (same font block)                   |

Hosted CI has not run.

### Browser smoke check

Run on 2026-09-25 against a throwaway copy of the fixture with the reworked
`01-team-invitations.patch` applied. The copy's Vite config had its font entry
removed only so `npm run build` could run in this sandbox; the repository is
unchanged. Data came from `migrate --seed`, the site was served with
`php artisan serve`, a separate `php artisan queue:work` delivered mail (the
fixture's default database queue with the `log` mailer), and Playwright/Chromium
drove the browser.

1. The owner invited ` Newbie@Example.com` (sic). The pending list shows
   `newbie@example.com`, "Invited as Member", and the expiry date.
2. The queue worker delivered the email. Its "View invitation" link carries a
   64-character token.
3. The invitee registered and verified. Opening the link showed the
   acceptance page (200). The Accept button was reached with the Tab key and
   pressed with Enter.
4. The invitee landed on the team page, listed in Acme as Member.
5. Opening the link again returned 404 with a clear "not valid" alert. A
   different signed-in user opening someone else's link got 403 with "This
   invitation is for a different account".

Result: pass.
