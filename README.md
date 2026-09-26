# Builder control plane

Internal builder prototype. This repository currently contains only the
development foundation (work order G0.1): a Laravel + Inertia + Vue control-plane
app with starter authentication, an internal landing screen, local PostgreSQL and
Redis, a guarded test database, standard check commands and a CI workflow.

The repository root is the Laravel application (Laravel 13, Inertia 3, Vue 3,
created from the official Vue starter kit), laid out the standard Laravel way.

## Layout

Besides the standard Laravel directories (`app/`, `config/`, `database/`,
`resources/`, `routes/`, `tests/`, …):

```
├── compose.yaml             Local PostgreSQL and Redis (trusted local services only)
├── docker/postgres/         Test database init script and manual re-run helper
├── docs/                    Architecture (docs/architecture/), handoff records and research
├── fixtures/customer-app/   Separate Laravel app standing in for a customer's codebase
├── fixtures/reference-solutions/  Expected feature patches for that app, and verify.sh
└── .github/workflows/ci.yml CI
```

The product architecture and the direction behind it are in
[docs/architecture/](docs/architecture/README.md).

Run every command from the repository root. The customer-app fixture is a
separate application with its own dependencies and checks; see
[docs/customer-app-fixture.md](docs/customer-app-fixture.md).

## Prerequisites

- PHP 8.4 with the `pdo_pgsql` and `redis` extensions
- Composer 2
- Node 22.22.2 (see `.nvmrc`) and npm
- Docker with Compose v2

## First-time setup

```sh
cp -n .env.example .env
docker compose up -d
docker compose ps            # wait until both services are (healthy)
composer install
npm ci
grep -q '^APP_KEY=.' .env || php artisan key:generate
php artisan migrate
npm run build
```

`composer setup` performs the same steps. It never overwrites an existing
`.env` or regenerates an existing `APP_KEY`.

## Running the app

```sh
composer dev
```

This runs the starter's `php artisan dev`, which starts the web server, queue
worker, log tail and Vite. The app is at <http://localhost:8000> and the health
endpoint is at <http://localhost:8000/up>.

### Creating the first user

1. Open <http://localhost:8000/register> and register.
2. Mail uses the `log` mailer. Find the most recent `Verify Email Address:` line
   in `storage/logs/laravel.log` and open that link.
3. You land on the internal landing screen.

Optionally, `php artisan db:seed` creates `test@example.com` with password
`password`. The seeder refuses to run unless `APP_ENV=local`.

### Prototype flow

Projects → request a feature → preview the generated change → select a step
→ request a change to that step (for example "Only the team owner may invite
people").

Each request starts a **build run** (`config/builder.php`, `construction`). The
run moves through queued → planning → implementing → verifying → reviewing →
completed, or stops at "needs your decision", cancelled or failed; the page
shows its state and its numbered event log, and the owner can cancel it.
While implementing, the run works in its own workspace: the project is copied
in, the changes it follows up on are applied, and the result is committed as
a baseline. The construction driver then changes the project only through
server-side tools (`read_file`, `list_files`, `search`, `write_file`,
`apply_patch`, `run_command` by allowlisted name), and the change is read back
from the workspace as a diff against the baseline.

- **One writer.** A worker claims the run with a lease and a fencing token.
  An expired lease can be taken over; the new holder gets a higher token and
  the old holder's writes are refused. A duplicate job finds the run claimed
  and exits. `php artisan runs:reconcile` (scheduled every minute) resumes
  runs whose worker stopped and settles verifications whose result was lost.
- **Operation journal.** Every tool call has an operation key and is recorded
  before it runs. Repeating a key replays the recorded result; reusing it for
  a different call is refused. A call whose outcome was lost is checked
  against the workspace (did the patch or write land?) before it runs again.
- **Server-side checks.** Changes must name the workspace revision they are
  based on, and file replacements the hash of the contents read. Patches must
  apply exactly. Paths outside the project, symbolic links out of it, and
  protected paths (`tests/Acceptance`, `.git`, `vendor`, `node_modules`,
  `.env`) are refused.
- **Budgets.** 30 tool operations and 20 minutes by default; a run out of
  budget stops for the owner's decision.

Two construction drivers are available (`BUILDER_CONSTRUCTION_DRIVER`):

- `scripted` (default) makes the change that the `reference` generator finds
  among the known-good solutions in `BUILDER_REFERENCE_SOLUTIONS` (see
  `fixtures/reference-solutions`). It needs no model.
- `agent` uses the three model roles (see [Model roles](#model-roles)). The
  **planner** turns the request into a saved plan: summary, acceptance
  criteria, assumptions, tasks and selectable steps. The **coder** carries the
  plan out through the tools. The **reviewer** judges the verified change from
  evidence the platform assembles (plan, diff, deleted or weakened tests,
  verification results), never from the coder's account. A failed
  verification or a blocking review finding sends the change back to the
  coder with the failures, up to `BUILDER_RUN_MAX_REPAIRS` times. Which
  protected acceptance suites apply is decided by the platform, not by a
  model. Every model call is logged on the run with its tokens.

When the change is built, the run hands it to verification. **Run
verification** also re-runs it on demand. Verification copies the project into
a fresh workspace, applies the change and every change it follows up on, runs
the setup commands and checks from `config/builder.php`, then runs the
platform-owned **protected acceptance tests** (`BUILDER_ACCEPTANCE_PATH`) with
their own runner configuration, and shows each result as passed, failed,
errored, skipped or not applicable. A change is only **Passed** when the
protected tests pass. With no applicable protected tests it is
**Unverified**. A passing (or unverified) change completes the run after
review; a failing one is repaired or stops the run for the owner's decision.
Runs and verification run on the queue, so keep a worker running
(`composer dev` starts one). Verification can take several minutes, so keep
`REDIS_QUEUE_RETRY_AFTER` above the jobs' one-hour timeout (see
`.env.example`). The default `local` workspace driver runs in a temporary
directory on this machine with a scrubbed environment. It is for trusted
fixtures only (see `config/workspaces.php`).

### Previews

On a generated change, **Start preview** copies the project into its own
workspace, applies the change and every change it follows up on, runs the
preview setup (`config/builder.php`, `preview.setup`: install, key, migrate,
build) and starts the app with PHP's built-in server. **Open preview** then
takes the owner to `http://{host}.preview.localhost:8000`.

- **Own origin.** Each preview has its own host, so the customer app never
  shares the control plane's origin, cookies or session. A global middleware
  hands preview hosts to the preview gateway before routing, so a preview host
  never reaches the control plane's routes, even for a signed-in owner.
- **Access.** Opening a preview issues a single-use grant that lives 60
  seconds; the preview host exchanges it for an HttpOnly cookie scoped to that
  host (two hours by default). Requests without it are refused and never reach
  the app.
- **Relay.** The gateway forwards requests to the app with the preview's host,
  so the links and emails the app generates point at the preview. It strips
  its own cookie, rebuilds form and file-upload bodies, and relays responses
  and cookies unchanged.
- **Lifetime.** `php artisan previews:reap` (every five minutes) stops
  previews that are past `BUILDER_PREVIEW_MAX_MINUTES` or idle for
  `BUILDER_PREVIEW_IDLE_MINUTES`, and stopping removes the workspace and its
  server. Starting a preview again replaces the running one.

The gateway relays plain HTTP only: previews serve built assets, not the Vite
dev server, so there is no hot reload yet. The Docker workspace driver can run
previews only on a network the control plane can reach
(`WORKSPACE_DOCKER_NETWORK`, with `BUILDER_PREVIEW_LISTEN_HOST=0.0.0.0`).

### AI SDK

The app uses the Laravel AI SDK (`laravel/ai`) with its published default
configuration in `config/ai.php`. Its migration creates the
`agent_conversations` and `agent_conversation_messages` tables. No key is needed
to run the app or the tests: the default `scripted` driver makes no model calls,
and the tests fake the agents.

### Model roles

The `agent` driver uses three roles, each with its own provider and model
(`config/builder.php`, `models`):

| Role     | Agent                          | Settings                                              |
| -------- | ------------------------------ | ----------------------------------------------------- |
| Planner  | `App\Ai\Agents\FeaturePlanner` | `BUILDER_PLANNER_PROVIDER`, `BUILDER_PLANNER_MODEL`   |
| Coder    | `App\Ai\Agents\FeatureCoder`   | `BUILDER_CODER_PROVIDER`, `BUILDER_CODER_MODEL`       |
| Reviewer | `App\Ai\Agents\ChangeReviewer` | `BUILDER_REVIEWER_PROVIDER`, `BUILDER_REVIEWER_MODEL` |

Providers are the names in `config/ai.php` (for example `anthropic` or
`openai`); an empty provider uses the SDK default, and an empty model uses the
provider's default. Keys are per provider (for example `ANTHROPIC_API_KEY`), so
one key covers every role that uses that provider.

## Local services

| Service    | Image           | Host port (override)                    |
| ---------- | --------------- | --------------------------------------- |
| `postgres` | `postgres:18.6` | `127.0.0.1:5432` (`FORWARD_DB_PORT`)    |
| `redis`    | `redis:8.10`    | `127.0.0.1:6379` (`FORWARD_REDIS_PORT`) |

Ports bind to loopback only. Like Laravel Sail, Compose reads the app's `.env`,
so one file configures both: `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` set
the development database, and `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`,
`TEST_DB_DATABASE`, `TEST_DB_USERNAME` and `TEST_DB_PASSWORD` override the
remaining defaults. If you change a forwarded port, set `DB_PORT` / `REDIS_PORT`
to match.

Data lives in the named volumes `postgres-data` and `redis-data`.
`docker compose stop` and `docker compose down` keep them.

### Test database

The first time the `postgres-data` volume is initialized,
`docker/postgres/init/01-create-test-database.sh` creates the
`control_plane_test` role and database. It also revokes `PUBLIC` connect on the
development database, so the test role cannot reach it.

Init scripts do not run against an existing volume. To create the test database
on an existing volume, run this. You can run it more than once safely:

```sh
./docker/postgres/create-test-database.sh
```

### Destructive reset

> **Destructive:** this deletes all local development data.
>
> ```sh
> docker compose down -v
> ```

This is not part of routine setup.

## Checks

None of these modify tracked files.

| Command                 | What it runs                                       |
| ----------------------- | -------------------------------------------------- |
| `composer check:format` | Pint in test mode                                  |
| `composer check:types`  | PHPStan / Larastan using `phpstan.neon`            |
| `npm run check:format`  | Oxfmt in check mode (via Vite+)                    |
| `npm run build`         | Production frontend build                          |
| `npm run check:lint`    | Oxlint without `--fix` (via Vite+)                 |
| `npm run check:types`   | `vue-tsc --noEmit`                                 |
| `composer check:tests`  | `config:clear`, then the full suite on the test DB |

`npm run build` also generates the Wayfinder TypeScript route helpers
(git-ignored) that `check:lint` and `check:types` import. On a fresh checkout,
run it before those checks, or run `php artisan wayfinder:generate --with-form`.
The build downloads the Instrument Sans font from `fonts.bunny.net` (the
starter's default), so it needs network access to that host. The PHP test suite
does not need a build: `tests/TestCase.php` calls `withoutVite()`.
`composer ci:check` runs all seven checks in this order.

Tests always use PostgreSQL database `control_plane_test`. `tests/TestCase.php`
stops the run if the active connection is not `pgsql` or its database name does
not end in `_test`.
