# Deploying the control plane

This guide puts the control plane (this Laravel app) on a Hetzner Cloud VM
managed by Laravel Forge. The workspaces run on separate runner machines.
[Runner machines](runner-machines.md) tells how to add them.

## What runs where

| Where                     | What                                                                                         |
| ------------------------- | -------------------------------------------------------------------------------------------- |
| Control plane VM (Forge)  | Web app, Reverb, three queue workers, the scheduler, PostgreSQL, Redis, project repositories |
| Runner machines (Hetzner) | Workspaces: building, checking and previewing changes                                        |

The control plane keeps each project's git repository on its own disk, in
`storage/app/private/projects` (`BUILDER_PROJECT_REPOSITORIES`). These
repositories are the owner's code. Keep the disk, and back it up (see
[Back up](#8-back-up)). For this reason the control plane needs a server
with a disk that lasts, not a platform with a temporary disk.

## What you need

- A Laravel Forge account, connected to your Hetzner Cloud project.
- A domain. You need two DNS names:
    - `builder.example.com` for the app.
    - `*.preview.example.com` for previews. Each preview gets a subdomain.
- A Hetzner private network. The control plane and the runner machines
  join it. Runners reach Reverb on it, and the control plane reaches
  previews on it.
- An API key for each model provider you use (see `config/ai.php`).

## 1. Create the server

1. In Forge, create an **App server** on Hetzner. Use at least 4 GB of
   memory (for example `cx33`). Choose PHP 8.4 and PostgreSQL. Forge installs
   Redis, Node and Nginx.
2. In the Hetzner console, attach the server to the private network. Write
   down its private address. The runner machines use it.
3. In the Hetzner firewall for this server, allow 22, 80 and 443 from
   anywhere. Allow 8080 (Reverb) only from the private network.

## 2. Create the site

1. In Forge, create a site for `builder.example.com` and connect the
   repository. Turn on **Allow wildcard sub-domains** and add
   `*.preview.example.com` as an alias.
2. Get a certificate for both names. A wildcard certificate needs a DNS
   challenge, so use a DNS provider that Forge supports for Let's Encrypt.
3. Add these lines to the deploy script, after `composer install`:

    ```sh
    npm ci
    npm run build
    $FORGE_PHP artisan migrate --force
    $FORGE_PHP artisan optimize
    $FORGE_PHP artisan queue:restart
    $FORGE_PHP artisan reverb:restart
    ```

    The build makes the Wayfinder route helpers, so run it on every deploy.

## 3. Set the environment

Set these values in the site's environment. Leave other values at their
defaults from `.env.example`.

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://builder.example.com

DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
BROADCAST_CONNECTION=reverb

# A job can run for up to an hour. Below this value, a second worker
# takes over a slow job while the first still works on it.
REDIS_QUEUE_RETRY_AFTER=3700

BUILDER_VERIFICATION_QUEUE=checks
BUILDER_PREVIEW_QUEUE=previews

BUILDER_PREVIEW_DOMAIN=preview.example.com
BUILDER_PREVIEW_SCHEME=https
BUILDER_PREVIEW_PORT=

WORKSPACE_DRIVER=runner
WORKSPACE_BOX_PROVIDER=pool
WORKSPACE_RUNNER_SOCKET_URL=ws://<the server's private address>:8080

OPERATIONS_OPERATORS=<your email address>
```

- Keep `BUILDER_PREVIEW_PORT` empty. Previews then use the normal HTTPS
  port.
- Set `MAIL_*` to a real mail service, so that owners get their emails.
- Set the model provider keys and the `BUILDER_*_PROVIDER` and
  `BUILDER_*_MODEL` values (see `config/builder.php`).
- Set the `REVERB_*` values that Forge gives when you turn on Reverb (step
  5).

## 4. Start the queue workers

In Forge, add three queue workers. Give each of them the connection `redis`,
a timeout of `3600` and `1` try:

| Queue      | What it runs                     |
| ---------- | -------------------------------- |
| `default`  | Building changes                 |
| `checks`   | Checking changes                 |
| `previews` | Starting and rebuilding previews |

Each queue needs its own worker. Otherwise a long check stops previews from
starting.

## 5. Start Reverb and the scheduler

1. In the site's settings, turn on **Laravel Reverb**. Forge runs it as a
   daemon on port 8080. Runner machines reach it over the private network,
   at the address in `WORKSPACE_RUNNER_SOCKET_URL`.
2. Turn on the **scheduler**. It runs `schedule:run` every minute. The
   scheduler closes old workspaces and previews, finds lost runs, and
   starts or deletes cloud runner machines.

## 6. Add runner machines

Follow [Runner machines](runner-machines.md). Use the server's private
address where that guide asks for the control plane's private address.

## 7. Check the deploy

1. Open `https://builder.example.com/up`. It shows a success page.
2. Run `php artisan runners:list`. Each runner machine shows as `online`.
3. Sign in with the email in `OPERATIONS_OPERATORS` and open `/operations`.
   Each queue shows a live worker, and no item asks for attention. If
   "Slow jobs can run twice" shows, `REDIS_QUEUE_RETRY_AFTER` is missing.
4. Make a project and ask for a small change. The change gets built and
   checked, and its preview opens on a `preview.example.com` subdomain.

## 8. Back up

Two things hold data that cannot be made again:

- **The database.** Use Forge's database backups, to storage off the
  server.
- **`storage/app/private`.** It holds the project repositories. With
  zero-downtime deploys, Forge keeps `storage` in a shared folder, so a
  deploy does not remove it. Back it up off the server, for example with
  Hetzner's server backups or with a nightly copy to object storage.

Restore both from the same time. A repository that is older than the
database can be missing changes that the database says were kept.

## Not proven yet

- Starting and deleting runner machines through the Hetzner API is tested
  only against a fake API. Try one start and one delete in a test project
  first.
- How a new machine finds its private address from Hetzner's metadata
  service is not tested on a real machine.
- New machines pull the box image from a registry. Push it with a version
  tag before you turn on `WORKSPACE_MACHINES_CLOUD`.
