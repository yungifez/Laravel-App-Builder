# G0.1: Runnable platform foundation (v1)

Version: v1, supersedes the draft of September 23, 2026  
Status: Ready for execution  
Executor: A coding model working in the intended platform repository  
Review: Independent review of the diff and acceptance evidence

## Assignment

Build the smallest reproducible Laravel control-plane application on which we can add the prototype. Finish this task and report its evidence. Do not begin the agent system, graph or workspace execution service.

The overall prototype lets an owner request invitations, preview the generated feature, select its permission step, request an owner-only restriction and inspect verification. This work order supplies only the development foundation.

### Scope at a glance

In scope: one bootable Laravel/Inertia/Vue app with working starter auth, one internal landing screen, local PostgreSQL and Redis via Compose, a safe test database, seven check commands, one CI workflow file, and handoff docs.

Out of scope: agent jobs, graph, workspace execution, invitations, teams/roles, billing, customer-app fixtures, deployment, real email, remote repositories, pushing, and activating hosted CI.

## 1. Inspect the repository first

1. Read every applicable AGENTS.md, `git status`, dependency manifests, lockfiles, and existing setup/CI files.
2. Record the starting commit SHA (or "no commits").
3. Decide how to proceed using this table:

| State found                                                                 | Action                                                                        |
| --------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| A Laravel app already using Inertia + Vue                                   | Use it in place. Do not relocate files to match this layout.                  |
| Empty, or only docs/licence/config                                          | Create the app at `apps/control-plane` from the official Laravel Vue starter. |
| A Laravel app with a different frontend stack (React, Livewire, Blade-only) | Stop and report. Switching stacks requires replanning.                        |
| An unrelated app, or more than one plausible target                         | Stop and report the ambiguity before modifying anything.                      |
| A required runtime is missing (PHP, a PHP extension, Node, Docker)          | Continue with independent work; report the exact missing prerequisite.        |

Version control rules: work on a local branch named `g0-1-foundation`, commit locally in small logical commits, and never push or create remotes. Leave pre-existing unrelated changes uncommitted and untouched. Record the final commit SHA.

Local file operations and local verification are in scope. Publishing, remote repository creation, real email, paid infrastructure and deployment are not.

## 2. Implementation decisions

| Concern             | Decision for this task                                                                                                                              |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Application         | Laravel, Inertia, Vue, TypeScript, Tailwind                                                                                                         |
| Initial UI          | Use existing starter primitives; use Shadcn Vue directly where included; no proprietary wrappers                                                    |
| Server structure    | Standard Laravel; thin controllers; add actions/services only for actual behavior                                                                   |
| Database            | Local PostgreSQL; dev DB `control_plane`, test DB `control_plane_test`                                                                              |
| Queue/cache         | Local Redis for dev queue/cache; tests do not use Redis; no agent jobs yet                                                                          |
| Redis client        | phpredis. If the host lacks the extension, add `predis/predis`, set `REDIS_CLIENT=predis`, and record which was used                                |
| Local mail          | `log` in dev, `array` in tests                                                                                                                      |
| Dependencies        | Preserve compatible existing locks; otherwise resolve the current stable starter and commit exact lockfiles                                         |
| Package manager     | Preserve the existing lockfile's manager; default to npm for an empty checkout                                                                      |
| Runtime pins        | `.nvmrc` (or existing equivalent) for Node; PHP version constraint in `composer.json`; exact versions recorded in the baseline doc and pinned in CI |
| Local services      | `compose.yaml` at repository root for PostgreSQL and Redis; PHP/Node run on host or an existing dev container                                       |
| PHP static analysis | Existing config, else Larastan at level 6 with no baseline file                                                                                     |
| CI                  | Adapt existing CI, else add one GitHub Actions workflow; do not activate remotely                                                                   |

### Directory convention

Repository root holds `README.md`, `AGENTS.md`, `compose.yaml`, `docs/` and `.github/`. The app lives in `apps/control-plane` (or its existing location). All `composer`, `npm` and `php artisan` commands run from the app directory; all `docker compose` commands run from the root. The README states this once and every documented command follows it.

### Starter-kit notes (verify at execution time)

The official starter has historically shipped with defaults that conflict with this brief. Check each and adapt rather than work around:

- It may default to SQLite in `.env.example` and `phpunit.xml`. Switch both to PostgreSQL.
- Its `lint`/`format` scripts may auto-fix (`--fix`, `--write`). Check scripts must use non-mutating equivalents.
- Its bundled workflow files may run fixers or assume SQLite. Adapt them to call the check scripts instead of adding a parallel workflow.
- A bundled setup script, if present, may run `key:generate` unconditionally or use `npm install`. Do not document it as the setup path unless it is made idempotent and lockfile-respecting.
- It ships a dashboard route. Repurpose that as the internal landing screen rather than adding a second one.

Record the official installation source and revision used. Preserve the starter's working authentication. Do not assemble a competing auth stack or add team features.

A stable version update within this stack is routine setup. Changing framework, database, UI architecture or introducing a service requires replanning.

## 3. Required work

### A. Bootable control plane

- Keep the starter's registration, login, email verification and logout working.
- Replace the dashboard content with a screen identifying this as the internal builder prototype, with an honest empty state: no fake projects, runs or success indicators.
- Keep the framework health endpoint (`/up`) or a minimal equivalent that returns success without revealing configuration.
- `.env.example` holds local-only defaults with an empty `APP_KEY`. Ignore real `.env*` files (except `.env.example`), `vendor/`, `node_modules/` and build output.
- Document how to create the first user: register through the UI and read the verification link from the log. A seeder is optional; if added, it must refuse to run unless `APP_ENV=local`.

### B. Reproducible local services

`compose.yaml` defines two services, `postgres` and `redis`:

- Images pinned to explicit major.minor tags; record the resolved digests in the baseline doc.
- Ports bound to loopback with overrides, for example `127.0.0.1:${FORWARD_DB_PORT:-5432}:5432` and `127.0.0.1:${FORWARD_REDIS_PORT:-6379}:6379`.
- Health checks using `pg_isready` and `redis-cli ping`.
- Named volumes so `docker compose down` / `up` preserves dev data. Mount the PostgreSQL volume at the path documented for the chosen major version (this path changed in PostgreSQL 18).
- An init script under `docker/postgres/` creates the test database and role on first volume initialization. Because init scripts do not run on existing volumes, also document an idempotent command that creates the test database manually.
- No Docker socket mount. Compose supplies trusted local services only; it is not the future customer-code isolation design.
- Never document `down -v` or volume deletion as routine setup. If a reset is documented, label it destructive.

Test environment, set in `phpunit.xml`:

| Variable           | Value                | Notes                                             |
| ------------------ | -------------------- | ------------------------------------------------- |
| `DB_CONNECTION`    | `pgsql`              | `force="true"`                                    |
| `DB_DATABASE`      | `control_plane_test` | `force="true"`; host/port stay overridable for CI |
| `QUEUE_CONNECTION` | `sync`               |                                                   |
| `CACHE_STORE`      | `array`              |                                                   |
| `SESSION_DRIVER`   | `array`              |                                                   |
| `MAIL_MAILER`      | `array`              |                                                   |

Test database guard: before any database refresh runs, the base test case must fail the run unless the active connection is `pgsql` and the database name ends in `_test` (for example via the `beforeRefreshingDatabase()` hook). `check:tests` must clear cached config first, since cached config causes the test environment variables to be ignored.

### C. Standard check interface

Expose these scripts in the app directory. None may modify tracked files.

| Command                 | Required behavior                                                  |
| ----------------------- | ------------------------------------------------------------------ |
| `composer check:format` | Pint in test mode                                                  |
| `composer check:types`  | PHPStan/Larastan with the committed config                         |
| `composer check:tests`  | Clear config, then the full unit/feature suite against the test DB |
| `npm run check:format`  | Prettier in check mode                                             |
| `npm run check:lint`    | ESLint without `--fix`                                             |
| `npm run check:types`   | `vue-tsc --noEmit` or the starter's equivalent                     |
| `npm run build`         | Production frontend build                                          |

Wrap existing equivalents rather than duplicating them. If feature tests render pages that need the Vite manifest, either build first or disable Vite in tests; CI runs `npm run build` before `check:tests`.

Static analysis rules: analyse at least `app/`; no baseline file; no path excludes beyond vendor/generated code; any `ignoreErrors` entry must target one specific finding and carry a comment explaining it. If level 6 cannot pass without weakening, fix the code; do not drop below level 5, and record the final level.

CI workflow requirements: PostgreSQL and Redis service containers using the same image tags as Compose; exact PHP version with `pdo_pgsql` and `redis` extensions; Node version from `.nvmrc`; `composer install --no-interaction --prefer-dist`; `npm ci`; copy `.env.example` and generate a key; then all seven commands. Any nonzero exit fails the job.

Normal install uses `composer install` and `npm ci`, never dependency update. Any scripted setup command must not overwrite an existing `.env` or regenerate an existing key.

### D. Small, meaningful verification

Reuse the starter's tests. Add:

1. Health endpoint returns 200 and its body contains neither the app key nor the database password.
2. A guest requesting the landing screen is redirected to login.
3. An authenticated, verified user sees the landing screen.
4. The test database guard: the suite runs on `pgsql` against a database ending in `_test`.

If a browser runner already exists, use it for login/landing. Otherwise do a manual smoke check and record the URL, steps, result and date in the baseline doc. Do not install a browser test platform for an empty page. Do not add tests that only assert generated files exist.

### E. Handoff records

`docs/development-baseline.md` contains:

- Exact PHP, Composer, Node, npm, Laravel, Inertia, Vue and starter revision; lockfile locations; image tags and digests.
- Service names, ports, override variables, test database setup and every runnable command.
- Check status per command: passed, failed or not run, with the reason for anything not run.
- Manual smoke-check record.
- Current repository layout and integration points for G0.2.

Add or extend AGENTS.md with concise rules: preserve user work; follow Laravel conventions; no fixed customer-app folder requirements; run the check scripts before reporting; never edit test discovery, add baselines or weaken assertions to obtain a pass; no production or remote actions; stop at the work order boundary. Existing instructions take precedence; do not overwrite them wholesale.

## 4. Acceptance gate

| ID   | Requirement                                                              | Evidence to report                                                                                                            |
| ---- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| F-01 | A fresh isolated copy installs from locks without production credentials | `git worktree add` at the final SHA, `.env` from example, `composer install`, `npm ci`, all seven checks; command log excerpt |
| F-02 | Local PostgreSQL/Redis start and the app connects                        | `docker compose ps` showing healthy; `php artisan db:show`; a Redis ping via artisan tinker                                   |
| F-03 | Tests target only the disposable test DB                                 | Guard test passes; dev DB `users` row count identical before and after `composer check:tests`                                 |
| F-04 | Guest denied, authenticated landing renders, health responds             | Feature tests; `curl -i` of `/up`; manual smoke record                                                                        |
| F-05 | All seven commands pass, or a concrete blocker is reported               | Results table with failure excerpts                                                                                           |
| F-06 | CI executes those commands with the documented toolchain                 | Workflow path and the lines invoking each script; explicit statement that hosted CI was not run                               |
| F-07 | No secrets, vendor, node_modules or build output tracked                 | `git ls-files` filtered for those paths returns nothing; `git check-ignore .env` succeeds                                     |
| F-08 | No later-gate subsystems introduced                                      | Changed-files list; no migrations or models beyond the starter's                                                              |

Report local CI-equivalent execution separately from hosted CI. Missing runtime or network access is a blocker, not a pass. Complete the independent work that remains possible and name the exact missing prerequisite.

## 5. Stop conditions

Stop and report, without further modification, if:

- The repository state matches a "stop" row in section 1.
- The starter is incompatible with the available runtime and fixing it would change the prescribed stack.
- A check can only pass by weakening assertions, adding a baseline, excluding paths or editing test discovery.
- Any step would contact non-local services or require real credentials.
- Any destructive database or volume operation would target anything other than the test database.

## 6. Completion report

Return exactly this structure:

```
## G0.1 completion report
Starting SHA / final SHA / branch:
Repository state found and path chosen:

### What runs
Start commands (from root and app dir):
URLs (app, /up):
First-user setup:

### Acceptance
| ID | Status (met / blocked / not met) | Evidence |

### Checks
| Command | Result | Notes or failure excerpt |

### Toolchain
PHP / Composer / Node / npm / Laravel / Inertia / Vue / starter revision / image digests:

### Files changed
(grouped by purpose)

### Limitations and blockers
### G0.2 readiness: yes / no, with reason
```

Stop after G0.1. The next task creates a separate customer-application fixture with teams and roles, then its invitation reference solution. Platform identities and customer-app identities must remain distinct.

## Prompt to give the coding model

> Implement G0.1 (v1) in the assigned platform repository using this work order. Inspect existing instructions and source first and choose a path using section 1. Reuse compatible scaffolding, complete the bounded foundation, run the seven checks, and report evidence against F-01 through F-08 using the section 6 template. Work on a local branch and never push. Stop immediately on any section 5 condition. Do not build later gates. If a prerequisite prevents a check, report it accurately and finish the independent work you can perform.
