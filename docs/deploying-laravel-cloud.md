# Deploying the control plane to Laravel Cloud

This guide puts the control plane (this Laravel app) on Laravel Cloud. The
workspaces run on runner machines on Hetzner. Owners' apps are published to
servers that Forge manages on Hetzner.

For a control plane on a server of your own, read
[Deploying the control plane](deploying.md) instead.

## What runs where

| Where                     | What                                                    |
| ------------------------- | ------------------------------------------------------- |
| Laravel Cloud             | Web app, workers, scheduler, Reverb, PostgreSQL, Valkey |
| GitHub organization       | A lasting copy of each app's code                       |
| Runner machines (Hetzner) | Workspaces: building, checking and previewing changes   |
| Forge servers (Hetzner)   | Owners' published apps                                  |

Laravel Cloud gives each server a new, empty disk at each deploy, and its
servers do not share a disk. So the control plane keeps each app's code in
GitHub, and it reaches previews through each runner's preview door, because
Cloud cannot join a private network with Hetzner.

## Check these first

Laravel Cloud's documentation does not answer these questions. Check them
before you deploy:

1. **Git and tar at run time.** The control plane runs `git` and `tar` for
   every change. In the Cloud environment, open **Commands** and run
   `which git tar`. Both must show a path.
2. **Wildcard domains.** Each preview gets its own subdomain. Use a domain
   with one level of wildcard, such as `*.example-previews.com` (see
   [Domains](#3-add-the-domains)).
3. **Long jobs.** A job can run for up to an hour. Flex managed queues stop
   a job after 90 seconds. Use Pro managed queues (Growth plan or higher) or
   a worker cluster.

## 1. Create the application

1. In Laravel Cloud, create an application from this repository.
2. Add a **PostgreSQL** database and a **Valkey** cache to the environment.
3. Add **Reverb** (managed WebSockets) to the environment.
4. Turn **hibernation** off. Queue workers and the scheduler must run all
   the time.

## 2. Set the build commands

Add these build commands after `composer install`:

```sh
npm ci
npm run build
php artisan projects:template
```

- The build makes the Wayfinder route helpers, so run it on every deploy.
- `projects:template` puts the app that new projects start from in place.
  The disk is new at each deploy, so make it in each build. It takes about a
  minute. Cloud stops a build after 15 minutes.

Add `php artisan migrate --force` as a deploy command.

## 3. Add the domains

You need two domains:

- `builder.example.com` for the app.
- A wildcard domain for previews, such as `*.example-previews.com`.

Use a separate domain for previews, not a subdomain of the app's domain. A
preview then has one level of wildcard, and the owners' apps cannot read the
builder's cookies.

## 4. Set the environment

Set these values. Leave other values at their defaults from `.env.example`.
Laravel Cloud sets the database, cache and Reverb values when you add those
resources.

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://builder.example.com

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
BROADCAST_CONNECTION=reverb

# A job can run for up to an hour. Below this value, a second worker
# takes over a slow job while the first still works on it.
REDIS_QUEUE_RETRY_AFTER=3700

BUILDER_VERIFICATION_QUEUE=checks
BUILDER_PREVIEW_QUEUE=previews

BUILDER_PREVIEW_DOMAIN=example-previews.com
BUILDER_PREVIEW_SCHEME=https
BUILDER_PREVIEW_PORT=

# Keep each app's code in GitHub, not on the server's disk.
BUILDER_PROJECT_STORE=github
BUILDER_GITHUB_ORGANIZATION=<your GitHub organization>
BUILDER_GITHUB_TOKEN=<a token that can make private repositories there>

# Runner machines on Hetzner, reached through their preview doors.
WORKSPACE_DRIVER=runner
WORKSPACE_BOX_PROVIDER=pool
WORKSPACE_RUNNER_SOCKET_URL=wss://<the Reverb host>
WORKSPACE_MACHINES_CLOUD=hetzner
WORKSPACE_MACHINES_HETZNER_TOKEN=<the Hetzner API token>
WORKSPACE_MACHINES_HETZNER_FIREWALL=<the firewall's id>
WORKSPACE_MACHINES_BOX_IMAGE=<registry>/builder-box:<version>
WORKSPACE_MACHINES_PREVIEW_DOOR_PORT=8443

OPERATIONS_OPERATORS=<your email address>
```

- `WORKSPACE_RUNNER_SOCKET_URL` is the Reverb host that Cloud shows, with
  `wss://`. Runners connect out to it to hear about new work at once.
  Without it, they still ask for work every 5 seconds.
- Do not set `WORKSPACE_MACHINES_HETZNER_NETWORK`. There is no private
  network.
- Set `MAIL_*` to a real mail service, so that owners get their emails.
- Set the model provider keys and the `BUILDER_*_PROVIDER` and
  `BUILDER_*_MODEL` values (see `config/builder.php`).
- To publish owners' apps to Forge, also set the values in
  [Publish owners' apps to Forge](deploying.md#publish-owners-apps-to-forge).

## 5. Start the queue workers

The control plane needs three queues. Each needs its own worker, so that a
long check does not stop previews from starting.

| Queue      | What it runs                     |
| ---------- | -------------------------------- |
| `default`  | Building changes                 |
| `checks`   | Checking changes                 |
| `previews` | Starting and rebuilding previews |

With a **worker cluster**, add one process for each queue:

```sh
php artisan queue:work redis --queue=default --timeout=3600 --tries=1
php artisan queue:work redis --queue=checks --timeout=3600 --tries=1
php artisan queue:work redis --queue=previews --timeout=3600 --tries=1
```

With **managed queues**, add one Pro queue for each of the three names.

Every deploy restarts the workers. A job that runs during a deploy stops.
The control plane finds it and tells the owner.

## 6. Turn on the scheduler

Turn on the scheduler in the environment. It closes old workspaces and
previews, finds lost runs, and starts or deletes runner machines.

## 7. Set up the runner machines

Follow [Let the pool grow and shrink on Hetzner](runner-machines.md#let-the-pool-grow-and-shrink-on-hetzner),
without a private network. In the Hetzner firewall for the machines:

- Allow port 8443 from everyone. This is the preview door. Laravel Cloud has
  no fixed outbound addresses, so you cannot allow only the control plane.
- Keep ports 20000–20999 closed.

The machines reach the control plane at `APP_URL` and Reverb at
`WORKSPACE_RUNNER_SOCKET_URL`, both over HTTPS.

## 8. Check the deploy

1. Open `https://builder.example.com/up`. It shows a success page.
2. Run `php artisan runners:list` in **Commands**. Each machine shows as
   `online`.
3. Sign in with the email in `OPERATIONS_OPERATORS` and open `/operations`.
   Each queue shows a live worker, and no item asks for attention.
4. Make a project and ask for a small change. The change gets built and
   checked, and its preview opens on an `example-previews.com` subdomain.
5. In GitHub, the organization has a private repository `code-<number>`
   for the project.

## Back up

- **The database.** Use Laravel Cloud's database backups.
- **The code.** The `code-*` repositories in GitHub hold each app's full
  history. Never give owners access to them.

## Limits

- Each server keeps a working copy of each app that it used, on its small
  disk. A copy not used for 6 hours is removed
  (`BUILDER_PROJECT_STORE_IDLE_MINUTES`), and comes back from GitHub when it
  is next used. Pick a size with enough disk for the apps used in that time.
- This guide is not yet proven on a real Laravel Cloud deploy.
