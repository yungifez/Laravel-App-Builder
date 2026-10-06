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

### Running with Sail

Use Sail when the host PHP lacks `pdo_pgsql` or `redis`. Sail runs PHP and
Node in the `laravel.test` container of `compose.yaml`.

1. In `.env`, set `DB_HOST=postgres`, `REDIS_HOST=redis`,
   `WORKSPACE_DRIVER=runner`, `REVERB_HOST=reverb` and a long random
   `WORKSPACE_RUNNER_TOKEN`. Run `vendor/bin/sail artisan reverb:install` once
   for the Reverb keys. Set `APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`
   and `FORWARD_REDIS_PORT` if the defaults are in use. Set `APP_URL` and
   `BUILDER_PREVIEW_PORT` to match `APP_PORT`.
2. Start the services and prepare the app:

    ```sh
    composer install
    vendor/bin/sail up -d
    vendor/bin/sail artisan key:generate
    vendor/bin/sail artisan migrate
    vendor/bin/sail npm ci && vendor/bin/sail npm run build
    ```

3. Start the queue workers and the scheduler, each in its own terminal. The
   first worker builds changes, the second runs their checks and the third
   starts previews (set `BUILDER_VERIFICATION_QUEUE=checks` and
   `BUILDER_PREVIEW_QUEUE=previews`; without them, checks and previews wait in
   line on the first worker):

    ```sh
    vendor/bin/sail artisan queue:work --timeout=3600
    vendor/bin/sail artisan queue:work --queue=checks --timeout=3600
    vendor/bin/sail artisan queue:work --queue=previews --timeout=3600
    vendor/bin/sail artisan schedule:work
    ```

Run the checks with the `vendor/bin/sail` prefix, for example
`vendor/bin/sail composer check:tests`.

#### Workspaces in Sail

Each change is built, checked and previewed in a workspace. With Sail, the
`runner` service stands in for the disposable box each workspace gets in
production. It holds the workspaces, the language toolchains and the agent
runner. It never sees this repository, `.env` or the database.

- The runner (`resources/box-runner/runner.mjs`) connects out to the control
  plane. It fetches commands and posts results over HTTP
  (`/api/runner/*`, with its token). The `reverb` service only rings its
  doorbell when work arrives. If Reverb is down, the runner polls every few
  seconds instead.
- Each workspace's commands run as a user of its own. The runner itself runs
  as root, so code in a workspace cannot read its token, stop it, or read
  another workspace.
- In production, runners live on machines of their own. See
  [Runner machines](docs/runner-machines.md). To deploy the control plane
  itself, see [Deploying the control plane](docs/deploying.md).
- Previews listen inside the runner. Set `BUILDER_PREVIEW_LISTEN_HOST=0.0.0.0`
  so the control plane can reach them at `http://runner:{port}`.
- After changing `resources/box-runner`, restart the runner only:
  `vendor/bin/sail up -d --no-deps --force-recreate runner`. Without
  `--no-deps`, compose restarts the app container and its workers too.

To give each workspace a container of its own, as a real box provider does,
use the `docker` box provider:

1. Build the box image: `docker compose --profile boxes-image build box`.
2. Set `WORKSPACE_BOXES_TOKEN` to a long random value and
   `WORKSPACE_BOX_PROVIDER=docker` in `.env`.
3. Start the box service:
   `docker compose --profile boxes up -d --no-deps boxes`.

The box service (`docker/boxes/server.mjs`) holds the Docker socket. It only
creates, lists and removes containers from the `builder-box` image, on the
app's network, with the workspace size limits. The control plane never gets the
socket. Each box runs its own runner with a token that opens only its own
commands. Previews in a box are reached at `http://box-{name}:{port}`.
Rebuild the image after changing `resources/box-runner`,
`resources/agent-runner` or `resources/preview-tools`. Containers share this
machine's kernel, so this is for local development only.

The `local` driver still works: it runs workspaces as folders inside the app
container. A coding agent there can read the control plane's files, so
agents refuse to run in it unless `WORKSPACE_LOCAL_AGENTS=true`. Set that only
for trusted apps, such as our fixtures.

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
people") → accept the change → the next request starts from it.

**Project repositories.** The builder keeps a Git repository for each project
under `BUILDER_PROJECT_REPOSITORIES` (default `storage/app/private/projects`).
Registering a project imports its source as the first commit, without
`vendor`, `node_modules`, `.env` and the source's own `.git`. Each request
records the commit it is based on, and runs, verifications and previews start
from that commit. When a run completes, the owner can **accept** the change.
It becomes one commit, authored by the owner. A follow-up is committed
together with the unaccepted changes it builds on. Only the state that was
checked is committed. When the project moved on after the change was checked,
it is never merged onto the newer commits. Instead, "Keep it" builds and
checks the same request again on the current app. A follow-up that builds on
other unkept changes is refused, and the owner asks again. An accepted change can be
**undone** with a revert commit, unless later commits build on it. Git runs
there with hooks and signing off; customer code never runs in the repository.

**Project notes** (what the app is for, its areas and rules) live in the
`project_notes` table, one copy for the main app and one for each idea. They
are never committed to the project's repository. A write is saved to the
database first. Each workspace gets a copy in `BUILDER_NOTES_DIRECTORY`
(default `.product-notes`), and what a run changes there is kept with the change
and saved when the change is accepted. Files a workspace's setup makes, such as
`.env` (`BUILDER_WORKSPACE_FILES`), are kept encrypted and given to every later
workspace, so a workspace can be thrown away at any time. Run
`php artisan projects:move-notes` once to move notes that older versions kept
in `.builder/` out of existing repositories.

Each request starts a **build run** (`config/builder.php`, `construction`). The
run moves through queued → planning → implementing → verifying → reviewing →
completed, or stops at "needs your decision", cancelled or failed; the page
shows its state and its numbered event log, and the owner can cancel it.
When the owner only asks about the app ("Who can invite people?"), the
planner answers and the run completes from planning, as "Answered", with
nothing built, checked or reviewed. While the change is being made, the
agent runner writes `progress.json` next to its task file, and the thread
says which areas of the app it is reading or changing, or that it is trying
the change out. When the coder is done, the project's formatters
(`BUILDER_CONSTRUCTION_FORMATTERS`, by default Pint and `vp fmt`) run on the
files the change touched, so formatting never costs a repair. The coder runs
only the tests for what it changed; the full checks run afterwards.
While implementing, the run works in its own workspace: the project is copied
in, the changes it follows up on are applied, and the result is committed as
a baseline. The baseline's commit ID is stored with the workspace, outside
the agent's reach. The construction driver then changes the project only
through server-side tools (`read_file`, `list_files`, `search`, `write_file`,
`apply_patch`, `run_command` by allowlisted name), and the change is read back
from the workspace as a diff against that stored baseline. Commits an agent
makes itself stay part of the change, and cannot hide changes to protected
paths.

- **One writer.** A worker claims the run with a lease and a fencing token.
  An expired lease can be taken over; the new holder gets a higher token and
  the old holder's writes are refused. While a coding agent works, its lease
  is renewed every `BUILDER_RUN_HEARTBEAT_SECONDS` (15). When the lease is
  lost or the owner cancels, the agent and every process it started are
  stopped at once, so it never edits a workspace another worker took over. A
  duplicate job finds the run claimed and exits. `php artisan runs:reconcile` (scheduled every minute) resumes
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

**Verify items.** The brief's "Done when" items are its verify items. The
coder must add or update a test for each one. The reviewer names the test that
checks each item. The platform holds that claim against what ran: the suite
check writes a JUnit report (its `report` setting), and an item is "tested"
only when the named test is in a file the change touches and the report shows
it ran and passed. A named test that is missing, skipped or misnamed does not
count, and neither does a suite without a report ("claimed"). A missing test,
or one that did not run, is a blocking finding, so the coder is sent back to
fix it (`BUILDER_REQUIRE_VERIFY_TESTS`).

**Telemetry.** The project page shows cost per accepted change, the share of
runs that passed verification on the first attempt, the share of changes that
touched parts the request was not about, and repairs before acceptance. Coding
agents report their own cost. Planner and reviewer calls are priced from
`BUILDER_MODEL_PRICES`; calls without a price are counted, not guessed.

Two construction drivers are available (`BUILDER_CONSTRUCTION_DRIVER`):

- `scripted` (default) makes the change that the `reference` generator finds
  among the known-good solutions in `BUILDER_REFERENCE_SOLUTIONS` (see
  `fixtures/reference-solutions`). It needs no model.
- `sdk` uses the model roles (see [Model roles](#model-roles)). The
  **planner** turns the request into a saved plan: summary, acceptance
  criteria, assumptions, tasks and selectable steps. A **coding agent**
  (Claude Code, or Codex when Anthropic cannot serve the task) carries the plan
  out in the workspace through the Node runner in `resources/agent-runner`.
  The **reviewer**, on the other provider, judges the verified change from
  evidence the platform assembles (plan, diff, deleted or weakened tests,
  verification results), never from the coding agent's account. A failed
  verification or a blocking review finding sends the change back to the
  coding agent with the failures, up to `BUILDER_RUN_MAX_REPAIRS` times. Which
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
`.env.example`). Workspaces run in the `runner` service with Sail (see
[Workspaces in Sail](#workspaces-in-sail)). The `local` workspace driver runs
in a temporary directory on this machine with a scrubbed environment. It is
for trusted fixtures only (see `config/workspaces.php`).

### Previews

A preview of each change starts by itself as soon as the change is built,
while it is checked and reviewed (`BUILDER_PREVIEW_AUTOMATIC`). The owner can
try it straight away. Keeping the change still waits for the checks and the
review.

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
  server. Starting a preview again replaces the running one. A preview is
  in use when someone opens one of its pages, or while a page is in view:
  each page pings `/__builder/alive` once a minute while its tab is visible.
  Requests a page makes by itself, such as polling, do not count. An owner
  can have `BUILDER_PREVIEW_MAX_RUNNING_PER_OWNER` previews running (3 by
  default). Starting one more stops the one they used least recently.

The gateway relays plain HTTP only: previews serve built assets, not the Vite
dev server, so there is no hot reload yet. The Docker workspace driver can run
previews only on a network the control plane can reach
(`WORKSPACE_DOCKER_NETWORK`, with `BUILDER_PREVIEW_LISTEN_HOST=0.0.0.0`).

### Publishing

**Put it online** runs the verification setup and every check on the exact
commit, then pushes it to the branch the hosting platform deploys from. A push
is not the app being online: the hosting platform can still fail to build or
start it. When the owner gives the app's web address, the builder checks it
after the push (`BUILDER_PUBLISH_CHECK_PATHS`, by default `/up` and `/`). The
app is **Online** only when every path answers without an error. If it does
not answer within `BUILDER_PUBLISH_CONFIRM_SECONDS` (600), the publish **needs
attention**. Without an address, a publish is only **Sent**. Only public
HTTPS addresses are accepted, unless `BUILDER_PUBLISH_ALLOW_LOCAL_REMOTES` is
on for development. A publish that has not changed for
`BUILDER_PUBLISH_STALLED_MINUTES` (70) while it checks, sends or confirms lost
its job. `php artisan publishing:reconcile` (scheduled every five minutes) ends
it with a next step for the owner.

### AI SDK

The app uses the Laravel AI SDK (`laravel/ai`) with its published default
configuration in `config/ai.php`. Its migration creates the
`agent_conversations` and `agent_conversation_messages` tables. No key is needed
to run the app or the tests: the default `scripted` driver makes no model calls,
and the tests fake the agents.

### Decisions before building

With `TYPESAFE_API_KEY` set, each new change request is classified by Jev, a
typed decision model, through the AI SDK's `typesafe` classification provider.
The model gets only the owner's words. It answers five questions:

- how big the change is (trivial, normal or substantial);
- whether the owner is only asking a question;
- whether the change touches permissions, stored data, or deletes something.

Each answer is kept in the `decisions` table with its probabilities and
confidence. For now nothing acts on them (shadow mode). The run never waits
for them. To see how often the confident answers matched what happened, run:

```bash
vendor/bin/sail artisan builder:decisions
```

What happened comes from the final diff and its repairs. For example, a new
migration means stored data changed. `BUILDER_DECISION_PROVIDERS` lists the
classification providers to try in order.

### Model roles

The `sdk` driver uses three roles. The planner and reviewer each have their own
provider and model (`config/builder.php`, `models`). The coder is a coding agent
(`config/builder.php`, `agents`):

| Role     | Agent                                    | Settings                                                  |
| -------- | ---------------------------------------- | --------------------------------------------------------- |
| Planner  | `App\Ai\Agents\FeaturePlanner`           | `BUILDER_PLANNER_PROVIDER`, `BUILDER_PLANNER_MODEL`       |
| Coder    | Claude Code, then Codex (`agents.order`) | `BUILDER_CLAUDE_AGENT_MODEL`, `BUILDER_CODEX_AGENT_MODEL` |
| Reviewer | `App\Ai\Agents\ChangeReviewer`           | `BUILDER_REVIEWER_PROVIDER`, `BUILDER_REVIEWER_MODEL`     |

Providers are the names in `config/ai.php` (for example `anthropic` or
`openai`); an empty provider uses the SDK default, and an empty model uses the
provider's default. Keys are per provider (for example `ANTHROPIC_API_KEY`), so
one key covers every role that uses that provider.

When the planner's or reviewer's provider cannot serve a call (it is down,
rate-limited or out of credit), the call moves to the next provider in
`BUILDER_MODEL_FAILOVER` that has a key, on that provider's default model. The
run log records a review that moved. The coder never changes provider in the
middle of a change.

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

The browser tests in `tests/Browser` do need a build. They drive the owner's
core loop (ask, read the details, ask for more, keep, undo) in Chromium, so they
use the built frontend. Run `npm run build` before `composer check:tests`, or
they test old code. Install Chromium once with
`npx playwright install --with-deps chromium`. With Sail, run the system part
as root: `docker compose exec -u root laravel.test npx playwright install-deps chromium`,
then `sail npx playwright install chromium`.

We use Pest's browser plugin instead of Laravel Dusk. Pest serves the app
inside the test process, so browser tests share the test database, the
`phpunit.xml` settings and the database guard. Dusk needs a running server and
swaps `.env` during a run, which breaks other people who use the development
server at the same time. The other tests stay PHPUnit classes; Pest runs them
unchanged.

The plugin starts a Playwright server through `sh -c` and stops only that
shell, so the server used to outlive every run. `tests/Pest.php` loads
`tests/Browser/exit-with-pest.cjs` into it through `NODE_OPTIONS`. The server
then ends when its run ends, even when `timeout` kills the run. To check, count
`pgrep -f "[p]laywright run-server"` before and after a run; the number must not
grow.

Tests always use PostgreSQL database `control_plane_test`. `tests/TestCase.php`
stops the run if the active connection is not `pgsql` or its database name does
not end in `_test`.
