# Agent instructions

These rules apply to the whole repository. Instructions in a nested `AGENTS.md`
take precedence for files below it.

- **Preserve user work.** Do not discard, overwrite or reformat changes you did
  not make. Leave unrelated uncommitted changes alone.
- **Use Laravel defaults and first-party packages.** Keep framework and
  starter-kit defaults unless there is a recorded reason to change them. When a
  first-party Laravel package covers a need, use it instead of a third-party
  one. All AI and agent work uses the Laravel AI SDK (`laravel/ai`, configured
  in `config/ai.php`), and new agents, tools and middleware start from
  `php artisan make:agent` / `make:tool` / `make:agent-middleware`.
- **Follow Laravel conventions.** Keep controllers thin. Put behavior in actions
  or services, and authorization in policies or form requests. Read settings
  from `config/` backed by `.env`; do not hard-code values that operators should
  be able to change. Use `php artisan make:*` generators and the existing
  starter-kit components, and match sibling files.
- **No fixed customer-app folder requirements.** Do not add code or config that
  assumes customer applications live in a specific folder or have a specific
  structure.
- **Where to run commands:** `composer`, `npm` and `php artisan` from
  `apps/control-plane`; `docker compose` from the repository root.
- **Run the checks before you report.** Run all seven commands listed in
  `README.md` → Checks. Report each result truthfully, including failures and
  anything you did not run.
- **Never weaken verification to get a pass.** Do not edit test discovery, add
  PHPStan baselines, add path excludes, lower the analysis level, skip tests or
  weaken assertions. Fix the code, or report the blocker.
- **Protect the databases.** Tests run only against the `*_test` PostgreSQL
  database. Do not bypass the guard in `tests/TestCase.php`. Never run
  destructive commands (`migrate:fresh`, `db:wipe`, `down -v`) against the
  development database without explicit approval.
- **No production or remote actions.** Do not deploy, push, create remotes, send
  real email or use real credentials unless the user explicitly asks.
- **Stop at the work order boundary.** Build only what the current work order
  asks for. Do not start later-gate subsystems (agents, graph, workspace
  execution, invitations, teams or roles) early.
