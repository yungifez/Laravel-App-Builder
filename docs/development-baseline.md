# Development baseline (G0.1)

Recorded 2026-09-25 on branch `g0-1-foundation`. The repository started empty
(no commits). The app was created from the official Laravel Vue starter kit.

## Toolchain

| Tool             | Version                                                    | Where pinned                                   |
| ---------------- | ---------------------------------------------------------- | ---------------------------------------------- |
| PHP              | 8.4.19 (NTS), extensions `pdo_pgsql`, `redis` (phpredis 6.3.0) | `composer.json` `"php": "^8.4"`; CI `8.4`  |
| Composer         | 2.8.12                                                     | CI `tools: composer:2.8.12`                    |
| Node             | 22.22.2                                                    | `apps/control-plane/.nvmrc`; CI reads it      |
| npm              | 10.9.7 (bundled with Node 22.22.2)                         | follows Node                                   |
| Laravel          | laravel/framework v13.33.0                                 | `composer.lock`                                |
| Inertia (server) | inertiajs/inertia-laravel v3.4.0                           | `composer.lock`                                |
| Inertia (client) | @inertiajs/vue3 3.7.1, @inertiajs/vite 3.7.1               | `package-lock.json`                            |
| Vue              | 3.5.43                                                     | `package-lock.json`                            |
| Auth             | laravel/fortify v1.40.0                                    | `composer.lock`                                |
| Static analysis  | larastan/larastan v3.12.2, phpstan/phpstan 2.2.16           | `composer.lock`                                |
| Formatting (PHP) | laravel/pint v1.32.1                                       | `composer.lock`                                |
| Tests            | phpunit/phpunit 12.5.36                                    | `composer.lock`                                |
| Frontend tooling | vite-plus 0.3.0 (Vite 8.2.2, Oxlint 1.79.0, Oxfmt 0.64.0), vue-tsc 2.2.12, TypeScript 5.9.3, Tailwind CSS 4.3.3 | `package-lock.json` |
| Docker           | Engine 29.3.1, Compose v5.1.1                              | not pinned (host tool)                         |

The CI runner resolves PHP by major.minor only (`setup-php` does not pin
patches). The job prints the exact versions in its "Print toolchain versions"
step.

**Starter kit:** `laravel/vue-starter-kit` `dev-main` at
`d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab` (2026-09-21), installed with
`composer create-project laravel/vue-starter-kit:dev-main apps/control-plane`.
It was then configured with the starter's own `php artisan install:features`
using its default features: registration, email verification, two-factor
authentication, passkeys and password confirmation. The latest tagged release
(v1.0.2, February 2025) is older than `dev-main`, and `dev-main` is what the
Laravel installer uses.

**Lockfiles:** `apps/control-plane/composer.lock`,
`apps/control-plane/package-lock.json` (npm).

### Images

| Service    | Tag             | Digest                                                                    | Server version |
| ---------- | --------------- | ------------------------------------------------------------------------- | -------------- |
| `postgres` | `postgres:18.6` | `sha256:5a5a84b19854a9ffaa54082c166ff4ec27473a361e496e5ea167f298f2da9722` | 18.6           |
| `redis`    | `redis:8.10`    | `sha256:718f745deb7dfefeac6eed7041fc7ec9476b50e61b247932682457c41adafa0e` | 8.10.2         |

## Services and configuration

| Service    | Container port | Host binding                            | Volume (mount path)                      | Health check     |
| ---------- | -------------- | --------------------------------------- | ---------------------------------------- | ---------------- |
| `postgres` | 5432           | `127.0.0.1:${FORWARD_DB_PORT:-5432}`    | `postgres-data` (`/var/lib/postgresql`)  | `pg_isready`     |
| `redis`    | 6379           | `127.0.0.1:${FORWARD_REDIS_PORT:-6379}` | `redis-data` (`/data`)                   | `redis-cli ping` |

The PostgreSQL 18 image stores data under
`/var/lib/postgresql/18/docker`, so the volume is mounted at
`/var/lib/postgresql`.

Compose overrides (from a git-ignored root `.env`, with defaults):
`FORWARD_DB_PORT` (5432), `FORWARD_REDIS_PORT` (6379), `DB_DATABASE`
(`control_plane`), `DB_USERNAME` (`control_plane`), `DB_PASSWORD`
(`control_plane`), `TEST_DB_DATABASE` (`control_plane_test`), `TEST_DB_USERNAME`
(`control_plane_test`), `TEST_DB_PASSWORD` (`control_plane_test`).

App environment (`apps/control-plane/.env.example`): `DB_CONNECTION=pgsql`
pointing at `control_plane`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
`REDIS_CLIENT=phpredis`, `SESSION_DRIVER=database`, `MAIL_MAILER=log`, and an
empty `APP_KEY`. Redis client used: **phpredis**, so `predis/predis` is not
needed.

### Test database

- `phpunit.xml` forces `DB_CONNECTION=pgsql`, `DB_DATABASE=control_plane_test`
  and an empty `DB_URL`. It defaults `DB_USERNAME`/`DB_PASSWORD` to
  `control_plane_test`. `DB_HOST`/`DB_PORT` come from the environment, so CI can
  override them. `CACHE_STORE=array`, `SESSION_DRIVER=array`,
  `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array`, so tests do not use Redis.
- `docker/postgres/init/01-create-test-database.sh` creates the
  `control_plane_test` role and database the first time the volume is
  initialized. It revokes `PUBLIC` connect on `control_plane`, so the test role
  gets `permission denied for database "control_plane"`.
- For an existing volume, run `./docker/postgres/create-test-database.sh` from
  the root. It is idempotent and was verified by re-running it.
- Guard: `Tests\TestCase::setUpTraits()` calls `ensureDisposableTestDatabase()`
  before any database trait runs. It throws unless the default connection is
  `pgsql` and the database name ends in `_test`. It hooks `setUpTraits()`
  rather than `beforeRefreshingDatabase()` because test classes
  `use RefreshDatabase` directly, and that trait's empty hook would override
  one declared on the parent class. Verified: with a stale `config:cache`
  pointing at `control_plane`, `php artisan test` stops with
  `Refusing to run tests against [pgsql] database [control_plane]`, and the dev
  `users` count is unchanged.

## Commands

Root:

```sh
docker compose up -d        # start services
docker compose ps           # both should be (healthy)
docker compose stop         # stop, keeping data
./docker/postgres/create-test-database.sh   # (re)create test DB on an existing volume
```

`apps/control-plane`:

```sh
composer install && npm ci
cp -n .env.example .env && (grep -q '^APP_KEY=.' .env || php artisan key:generate)
php artisan migrate
npm run build
composer dev                 # server, queue, logs, Vite (php artisan dev)
composer setup               # idempotent version of the setup steps above
composer ci:check            # all seven checks in CI order
php artisan db:seed          # optional, local only: test@example.com / password
```

## Check status

Local runs on 2026-09-25, in the working copy and in a fresh `git worktree`
(see F-01):

| Command                 | Result | Notes                                                        |
| ----------------------- | ------ | ------------------------------------------------------------ |
| `composer check:format` | passed | `pint --parallel --test`                                     |
| `composer check:types`  | passed | Larastan **level 7** (the starter's committed config), paths `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`; no baseline, no excludes, no `ignoreErrors` |
| `npm run check:format`  | passed | `vp fmt --check` (Oxfmt), 69 files                          |
| `npm run build`         | passed | `vp build`                                                  |
| `npm run check:lint`    | passed | `vp lint` (Oxlint, type-aware, warnings denied, no `--fix`) |
| `npm run check:types`   | passed | `vue-tsc --noEmit`                                          |
| `composer check:tests`  | passed | 47 tests, 163 assertions, on `control_plane_test`           |

Hosted CI (`.github/workflows/ci.yml`) has **not** run. The repository has no
remote.

## Acceptance evidence

- **F-01 (fresh copy):** `git worktree add --detach` at the tested SHA, then
  `composer install --no-interaction --prefer-dist`, `npm ci`,
  `cp .env.example .env`, `php artisan key:generate`, then all seven checks.
  Every step exited 0 and `git status --short` was empty afterwards. No
  production credentials were involved. Environment note: this sandbox's egress
  policy blocks `api.github.com` zipball downloads. The first install in the
  working copy therefore used `--prefer-source`, and the `phpstan/phpstan` dist
  (which has no source entry) was seeded into the Composer cache from a git
  checkout of the exact locked commit `46a6d906…`. The worktree install
  resolved from that cache. On a normal network, `composer install --prefer-dist`
  downloads directly.
- **F-02 (services):** `docker compose ps` showed both services
  `Up (healthy)` on `127.0.0.1:5432` and `127.0.0.1:6379`. `php artisan db:show`
  reported PostgreSQL 18.6, database `control_plane`, 10 tables.
  `php artisan tinker --execute 'var_dump(Redis::connection()->ping());'`
  returned `bool(true)` using `phpredis`.
- **F-03 (test DB only):** the dev `users` count was 3 before and 3 after
  `composer check:tests`. The guard test passes.
- **F-04:** feature tests cover the guest redirect, the verified landing
  screen, the unverified redirect and `/up` (200, no app key or DB password in
  the body). `curl -i http://127.0.0.1:8000/up` returned `HTTP/1.1 200 OK`,
  `Content-Type: text/html`.

### Manual smoke check

No browser test runner exists in the project, and none was added. The smoke
check was driven with the sandbox's preinstalled Playwright/Chromium from a
throwaway script outside the repository.

- Date: 2026-09-25, against `php artisan serve` at `http://127.0.0.1:8000`.
- Steps and results:
  1. Guest `GET /dashboard` → redirected to `/login`.
  2. Registered a new user at `/register` → redirected to `/email/verify`.
  3. Read the `Verify Email Address:` link from `storage/logs/laravel.log` and
     opened it → `/dashboard`.
  4. The landing screen shows "Internal builder prototype" and the "No projects
     yet" empty state.
  5. Logged out, then `GET /dashboard` → `/login`.
  6. Logged back in → `/dashboard`.
- Result: pass.

## Deviations from the work order

- **Formatter/linter:** the current starter ships Vite+ (Oxfmt and Oxlint), not
  Prettier and ESLint. Following "wrap existing equivalents rather than
  duplicating them", `npm run check:format` runs `vp fmt --check` and
  `npm run check:lint` runs `vp lint`. No Prettier or ESLint was added.
- **Fonts:** the starter's `bunny()` font provider fetched fonts from
  `fonts.bunny.net` during `npm run build`. This made the build depend on a
  network host outside the lockfiles, and that host is blocked in this sandbox.
  It was switched to the same plugin's `fontsource()` provider, backed by the
  locked `@fontsource/instrument-sans` 5.3.0. This is one added npm dependency.
- **PHPStan level:** kept the starter's existing config at level 7, which is
  stricter than the level 6 floor.
- **Workflow location:** the starter's `apps/control-plane/.github/workflows/tests.yml`
  was moved to `.github/workflows/ci.yml` and adapted, because GitHub reads
  workflows only from the repository root. `dependabot.yml` moved with it.

## Repository layout

```
.
├── AGENTS.md, README.md, compose.yaml, .gitignore
├── .github/workflows/ci.yml, .github/dependabot.yml
├── docker/postgres/init/01-create-test-database.sh
├── docker/postgres/create-test-database.sh
├── docs/development-baseline.md
└── apps/control-plane/        Laravel app (starter layout, unchanged structure)
    ├── app/                   Starter actions (Fortify), controllers, models (User only)
    ├── database/              Starter migrations only; seeder guarded to local
    ├── resources/js/pages/Dashboard.vue   Internal landing screen
    ├── tests/TestCase.php     Test database guard
    └── phpunit.xml, phpstan.neon, pint.json, vite.config.ts, .nvmrc
```

## Integration points for G0.2

- **New applications:** `apps/` holds applications. The customer-application
  fixture should be a separate app with its own `composer.json`,
  `package.json`, lockfiles and its own database. This work order imposes no
  folder or structure requirements on it.
- **Separate identities:** the control plane's `users` table (Fortify auth) is
  platform identity only. A customer fixture must use its own database and user
  tables, never `control_plane` or `control_plane_test`.
- **Databases:** add further `CREATE ROLE`/`CREATE DATABASE` statements for the
  fixture's dev and `_test` databases alongside
  `docker/postgres/init/01-create-test-database.sh`. Existing volumes need the
  idempotent re-run script. Keep the `_test` suffix convention so the same
  guard pattern applies.
- **CI:** add a second job to `.github/workflows/ci.yml` with its own
  `working-directory`, reusing the same service image tags.
- **Queue:** Redis is configured for queue and cache, but no jobs exist yet.
  Agent jobs belong to later gates.
