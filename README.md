# Builder control plane

Internal builder prototype. This repository currently contains only the
development foundation (work order G0.1): a Laravel + Inertia + Vue control-plane
app with starter authentication, an internal landing screen, local PostgreSQL and
Redis, a guarded test database, standard check commands and a CI workflow.

## Layout

```
.
├── apps/control-plane/      Laravel 13 + Inertia 3 + Vue 3 app (official Vue starter kit)
├── compose.yaml             Local PostgreSQL and Redis (trusted local services only)
├── docker/postgres/         Test database init script and manual re-run helper
├── docs/                    Handoff records (development-baseline.md)
└── .github/workflows/ci.yml CI for the control plane
```

**Where to run commands:** every `composer`, `npm` and `php artisan` command runs
from `apps/control-plane`. Every `docker compose` command runs from the
repository root.

## Prerequisites

- PHP 8.4 with the `pdo_pgsql` and `redis` extensions
- Composer 2
- Node 22.22.2 (see `apps/control-plane/.nvmrc`) and npm
- Docker with Compose v2

## First-time setup

From the repository root, start the services and wait until both are `healthy`:

```sh
docker compose up -d
docker compose ps
```

From `apps/control-plane`:

```sh
composer install
npm ci
cp -n .env.example .env
grep -q '^APP_KEY=.' .env || php artisan key:generate
php artisan migrate
npm run build
```

`composer setup` performs the same steps. It never overwrites an existing
`.env` or regenerates an existing `APP_KEY`.

## Running the app

From `apps/control-plane`:

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

### AI SDK

The app includes the Laravel AI SDK (`laravel/ai`) with its published default
configuration in `config/ai.php`. Its migration creates the
`agent_conversations` and `agent_conversation_messages` tables. To call a
provider, set that provider's key (for example `OPENAI_API_KEY` or
`ANTHROPIC_API_KEY`) in `apps/control-plane/.env`. Nothing in the app uses the
SDK yet, and no key is needed to run the app or the tests.

## Local services

| Service    | Image           | Host port (override)                   |
| ---------- | --------------- | -------------------------------------- |
| `postgres` | `postgres:18.6` | `127.0.0.1:5432` (`FORWARD_DB_PORT`)   |
| `redis`    | `redis:8.10`    | `127.0.0.1:6379` (`FORWARD_REDIS_PORT`) |

Ports bind to loopback only. Override them, and the database names and
credentials (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `TEST_DB_DATABASE`,
`TEST_DB_USERNAME`, `TEST_DB_PASSWORD`), in a root `.env` file (git-ignored). If
you change a port, set `DB_PORT` / `REDIS_PORT` to match in
`apps/control-plane/.env`.

Data lives in the named volumes `postgres-data` and `redis-data`.
`docker compose stop` and `docker compose down` keep them.

### Test database

The first time the `postgres-data` volume is initialized,
`docker/postgres/init/01-create-test-database.sh` creates the
`control_plane_test` role and database. It also revokes `PUBLIC` connect on the
development database, so the test role cannot reach it.

Init scripts do not run against an existing volume. To create the test database
on an existing volume, run this from the repository root. You can run it more
than once safely:

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

Run from `apps/control-plane`. None of these modify tracked files.

| Command                 | What it runs                                         |
| ----------------------- | ---------------------------------------------------- |
| `composer check:format` | Pint in test mode                                    |
| `composer check:types`  | PHPStan / Larastan using `phpstan.neon`              |
| `npm run check:format`  | Oxfmt in check mode (via Vite+)                      |
| `npm run build`         | Production frontend build                            |
| `npm run check:lint`    | Oxlint without `--fix` (via Vite+)                   |
| `npm run check:types`   | `vue-tsc --noEmit`                                   |
| `composer check:tests`  | `config:clear`, then the full suite on the test DB  |

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
