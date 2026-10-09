# Deploying the control plane

This guide puts the control plane (this Laravel app) on a Hetzner Cloud VM
managed by Laravel Forge. The workspaces run on separate runner machines.
[Runner machines](runner-machines.md) tells how to add them.

To put the control plane on Laravel Cloud instead, read
[Deploying the control plane to Laravel Cloud](deploying-laravel-cloud.md).

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
  previews on it. A control plane that cannot join one, such as one on Laravel
  Cloud, reaches previews through each runner's preview door instead (see
  [Runner machines](runner-machines.md#no-private-network-the-preview-door)).
- An API key for each model provider you use (see `config/ai.php`).

## 1. Create the server

1. In Forge, create an **App server** on Hetzner. Use at least 4 GB of
   memory: `cx23` (2 CPUs, 4 GB) to start, or `cx33` (4 CPUs, 8 GB) for
   more owners. Choose PHP 8.4 and PostgreSQL. Forge installs
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
    $FORGE_PHP artisan ai:check-formats || true
    ```

    The build makes the Wayfinder route helpers, so run it on every deploy.
    `ai:check-formats` makes one tiny call per agent, with no project data,
    to show that the AI service accepts each answer format. A refused format
    stops every call to that agent, and tests cannot show it. It does not
    stop the deploy: a failure shows on the operations page. It also runs
    daily in production.

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

# One log file a day, kept for 14 days, so logs cannot fill the disk.
LOG_STACK=daily

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
- The Claude app, VS Code and Cursor sign in with keys on the server's disk.
  Make them once, in the site's **Commands**: `php artisan passport:keys`.
  New keys sign out every tool, so do not make them again. To keep them out
  of the disk, set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` instead
  (see [Laravel Cloud](deploying-laravel-cloud.md#4-set-the-environment)).

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
   starts or deletes cloud runner machines. Once a day it also looks up
   new security problems in the packages of apps nobody changed for a week.

## 6. Add runner machines

Follow [Runner machines](runner-machines.md). Use the server's private
address where that guide asks for the control plane's private address.

## 7. Check the deploy

1. Open `https://builder.example.com/up`. It shows a success page.
2. Run `php artisan runners:list`. Each runner machine shows as `online`.
3. Sign in with the email in `OPERATIONS_OPERATORS` and open `/operations`.
   Each queue shows a live worker, and no item asks for attention. If
   "Slow jobs can run twice" shows, `REDIS_QUEUE_RETRY_AFTER` is missing.
   "This server is running out of disk" shows when less than 2 GB is free
   (`OPERATIONS_MIN_FREE_DISK_MB`).
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

On a host whose disk does not last, such as Laravel Cloud, keep the
repositories off the server. Set `BUILDER_PROJECT_STORE` to one of these:

- `github`: each app gets a private repository named
  `<prefix>-<app number>` (`BUILDER_PROJECT_STORE_PREFIX`, default `code`).
  It is made in `BUILDER_PROJECT_STORE_ORGANIZATION`, or in
  `BUILDER_GITHUB_ORGANIZATION` when that is empty, with
  `BUILDER_GITHUB_TOKEN`. The token must be able to make repositories in
  that organization. This repository holds the builder's full history of the
  app, so never give owners access to it. It is not the repository that
  an app is published from.
- `disk`: each app is saved as one Git bundle on a filesystem disk from
  `config/filesystems.php`, for example Cloudflare R2 or Hetzner Object
  Storage. Set `BUILDER_PROJECT_STORE_DISK` to the disk's name.

Each server keeps a working copy, saves every change to the store, and
gets a newer copy when another server saved one. A change that the store
did not take is not kept, and the owner sees "This is our fault". Back up
the store instead of `storage/app/private`. Do not switch the store while
apps exist: the new store starts empty.

Restore both from the same time. A repository that is older than the
database can be missing changes that the database says were kept.

If a project's repository is missing, the control plane does not make it
again from the original source, because that would drop the kept changes
without a warning. The owner sees "This is our fault", and `/operations`
lists the app under "Apps whose saved code is missing" until you restore
the repository.

## Publish owners' apps to Forge

Owners' apps can go to Hetzner servers that Forge manages, instead of
Laravel Cloud. Many apps share one server. Each app gets its own site, its
own database and a free `on-forge.com` address with HTTPS, so you need no
DNS for it.

1. In Forge, connect GitHub so that Forge can read the repositories in
   `BUILDER_GITHUB_ORGANIZATION`.
2. In Forge, connect your Hetzner project. Write down the IDs of the
   credential, the private network, the region and the server size.
3. Set these values:

    ```
    BUILDER_PUBLISH_HOST=forge
    FORGE_API_TOKEN=<a Forge API token>
    FORGE_ORGANIZATION=<your Forge organization slug>
    FORGE_SERVER_PREFIX=apps
    FORGE_SITES_PER_SERVER=15
    FORGE_HETZNER_CREDENTIAL=<credential ID>
    FORGE_HETZNER_NETWORK=<network ID>
    FORGE_HETZNER_REGION=<region ID>
    FORGE_HETZNER_SIZE=<size ID>
    FORGE_BACKUP_STORAGE=<storage provider ID>
    ```

4. In Forge, add a storage provider for database copies, for example
   Hetzner Object Storage. Put its ID in `FORGE_BACKUP_STORAGE`.

The control plane puts each app on a server whose name starts with
`apps-` and that has fewer than 15 sites. When every such server is full, it
makes a new one on Hetzner through Forge. This takes about 10 minutes, and
the publish waits for it. Leave the `FORGE_HETZNER_*` values empty to add
servers by hand. Then a publish fails when every server is full.

Forge copies each app's database every night and keeps 7 copies
(`FORGE_BACKUP_RETENTION`). It also makes a copy before each release that
changes how the app stores information. When that copy fails, or
`FORGE_BACKUP_STORAGE` is empty, the release does not go online.

The control plane reads the errors that an app raises online from the
app's log file in Forge, `storage/logs/laravel.log`. New sites write to
this one file (`LOG_STACK=single`). If an owner's app writes its log
somewhere else, its owner is not told about its errors.

Not done yet for Forge: the cost per app on `/operations`.

## Not proven yet

- Starting and deleting runner machines through the Hetzner API is tested
  only against a fake API. The requests match Hetzner's published API
  description. Try one start and one delete in a test project
  first.
- A new machine reads its private address from Hetzner's metadata
  service. The parsing is tested against the example answer in Hetzner's
  documentation, but not yet on a real machine.
- Publishing to Forge is tested only against a fake API. The requests
  match Forge's published API description. Publish one test app first.
- New machines pull the box image from a registry. Push it with a version
  tag before you turn on `WORKSPACE_MACHINES_CLOUD`.
