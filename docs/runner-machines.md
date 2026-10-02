# Runner machines

A runner machine holds workspaces: it builds, checks and previews changes.
In production, runner machines are small VMs hosted apart from the control
plane. One machine holds many workspaces. This guide adds one machine to the
pool.

## What you need

- A Linux VM with Docker. Two CPUs and 4 GB of memory hold a few workspaces
  at a time. Give it enough disk for the box image (about 4 GB) and the
  workspaces.
- A private network between the VM and the control plane.
- The box image, `builder-box`. Build it with `vendor/bin/sail build box`.
  Copy it to the VM, for example through your private registry. The image
  holds the runner and every tool a workspace needs (PHP, Composer, Node,
  Chromium for the screen check, iptables).

You can also install these tools on the VM as packages and run
`resources/box-runner/runner.mjs` with Node, as root. Then you must put the
agent runner, the preview tools and the screen check at the same paths as
in the image (`/opt/...`, see `docker/box/Dockerfile`) and keep their
versions the same. The box image is easier to keep the same, so this guide
uses it.

## 1. Set up the control plane

1. Set these values in the control plane's `.env`:

    ```
    WORKSPACE_DRIVER=runner
    WORKSPACE_BOX_PROVIDER=pool
    WORKSPACE_RUNNER_SOCKET_URL=wss://<the Reverb address the VM can reach>
    WORKSPACE_RUNNER_MAX_WORKSPACES=4
    ```

    `WORKSPACE_RUNNER_MAX_WORKSPACES` is the most workspaces one machine
    holds. A full machine gets no new workspaces until some close. `0` means
    no limit. Each workspace can use up to `WORKSPACE_MEMORY_MB` of memory
    (2 GB by default), but most of the time it uses much less. Start with
    twice the machine's memory divided by that size, for example 4 for a
    4 GB machine. Watch the machine's memory, and change the value if
    necessary.

    A machine with less than 2 GB of free disk also gets no new workspaces.
    The runner reports its free disk while it asks for work. To change the
    amount, set `WORKSPACE_RUNNER_MIN_FREE_DISK_MB`.

2. Add the machine. Use lowercase letters and digits only:

    ```
    php artisan runners:add vm1
    ```

    The command shows the machine's token one time only. Copy it now. The
    control plane keeps only a hash of it.

## 2. Join the private network

The control plane reaches previews on the VM through a private network. Use
the private network of your hosting provider, or make one with Tailscale or
WireGuard. Tailscale and WireGuard also work across hosting providers.

**Tailscale:**

1. Install Tailscale on the control plane and on the VM.
2. Run `tailscale up` on each. Join both to the same tailnet.
3. On the VM, get its address with `tailscale ip -4`. It starts with `100.`.
   This is the VM's private address.

**WireGuard:**

1. Install WireGuard on the control plane and on the VM.
2. Give each one an address in a private range, such as `10.10.0.1` (the
   control plane) and `10.10.0.2` (the VM), and add each as the other's peer.
3. Start the tunnel with `wg-quick up wg0` on each. Enable it at boot with
   `systemctl enable wg-quick@wg0`.

Workspace users cannot reach private ranges, which include the Tailscale
range (`100.64.0.0/10`) and WireGuard addresses in `10.0.0.0/8`. So the
private network is open to the runner and the control plane, but not to the
code in a workspace.

If the control plane is also on the private network, `RUNNER_URL` can use
its private address. The tunnel encrypts the traffic.

## 3. Run the runner as a service

1. Put the runner's settings in a file that only root can read:

    ```
    sudo install -m 600 /dev/null /etc/builder-runner.env
    sudoedit /etc/builder-runner.env
    ```

    ```
    RUNNER_URL=https://<the control plane>
    RUNNER_TOKEN=<the token from runners:add>
    RUNNER_SERVICE_HOST=<the VM's private address>
    ```

2. Make the service file `/etc/systemd/system/builder-runner.service`:

    ```
    [Unit]
    Description=Builder runner
    Requires=docker.service
    After=docker.service network-online.target
    Wants=network-online.target

    [Service]
    Restart=always
    RestartSec=5
    ExecStartPre=-/usr/bin/docker rm -f runner
    ExecStart=/usr/bin/docker run --rm --name runner \
        --network host --cap-add NET_ADMIN \
        --env-file /etc/builder-runner.env \
        -v /srv/workspaces:/workspaces \
        builder-box
    ExecStop=/usr/bin/docker stop runner

    [Install]
    WantedBy=multi-user.target
    ```

3. Start it, and start it at boot:

    ```
    sudo systemctl daemon-reload
    sudo systemctl enable --now builder-runner
    ```

The settings:

- `RUNNER_URL` is where the runner reaches the control plane. The runner only
  connects out. It accepts no connections.
- `RUNNER_SERVICE_HOST` is where the control plane reaches previews on this
  machine. Previews listen only on this address. Use the private address, not
  the public one.
- `RUNNER_FIREWALL=off` tells the runner not to change the firewall. Use it
  only when you fence workspaces in another way.

The runner runs as root in the container, on the host network, with
permission to set the firewall (`--cap-add NET_ADMIN`). It needs root to give
each workspace a user of its own. Workspaces stay in `/srv/workspaces` when
the service restarts.

## 4. Make sure it works

Read the runner's log with `journalctl -u builder-runner`. You must see these lines:

```
Firewall is on (iptables, ip6tables).
Runner vm1 is ready.
```

If the log says `Firewall is off`, the container did not get `--cap-add
NET_ADMIN`, or the image has no iptables. Do not give workspaces to that
machine.

In the control plane, run `php artisan runners:list`. The machine must show
as `online`. A machine counts as online while it asked for work in the last
120 seconds (`WORKSPACE_RUNNER_ONLINE_SECONDS`). New workspaces go to the
online machine that holds the fewest. A `draining` machine gets no new
workspaces (see [Remove a machine](#remove-a-machine)).

## 5. Set the cloud firewall

Set these rules in your hosting provider's firewall for the VM:

| Direction | Allow                                                           |
| --------- | --------------------------------------------------------------- |
| Inbound   | Ports 20000–20999 from the control plane's private address only |
| Inbound   | SSH from your own address only, if you need it                  |
| Inbound   | UDP 51820 from the control plane, if you use WireGuard          |
| Inbound   | UDP 41641, if you use Tailscale (it also works without it)      |
| Outbound  | HTTPS to the control plane and Reverb                           |
| Outbound  | HTTPS to the internet, so workspaces can install packages       |

Block all other inbound traffic. Previews use ports 20000–20999
(`config/builder.php`, `preview.ports`). With Tailscale or WireGuard, the
control plane reaches these ports through the tunnel, so the cloud firewall
can keep them closed to everything else.

## What the runner does to keep workspaces apart

- **A user for each workspace.** Each workspace runs as a user of its own,
  never as root. A workspace cannot read another workspace, the runner's
  token or the runner's files.
- **No private network.** Workspace users cannot reach private addresses
  (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10), the cloud
  metadata address (169.254.0.0/16) or IPv6 private addresses. The control
  plane's database and cache are on the private network, so workspaces cannot
  reach them. DNS still works.
- **Previews answer their own workspace only.** On the machine, only the
  workspace that started a preview can open its port. The control plane
  reaches it from outside the machine.
- **Rules follow workspaces.** The runner adds a preview's rule when the
  preview starts. It removes the rules of a workspace when the workspace
  closes. When the runner restarts, it keeps the rules of the workspaces that
  are still on the machine.

`tests/Fixtures/box-runner-firewall.mjs` proves these rules. Run it as root
in the box image with `--cap-add NET_ADMIN`.

## Let the pool grow and shrink on Hetzner

Instead of adding machines by hand, the control plane can start and delete
them on Hetzner Cloud. Each minute, `runners:scale` (in the scheduler) does
one of these things:

- It starts a machine when fewer than `WORKSPACE_MACHINES_SPARE_WORKSPACES`
  places are free. Workspaces that wait for room count as places in use.
  When the pool is full, a new workspace waits up to
  `WORKSPACE_MACHINES_BOOT_MINUTES` for a machine instead of failing. Without
  a cloud, it fails at once. A change that waits keeps its worker's lease,
  tells the owner that a computer is getting ready, and stops waiting when
  the owner cancels it.
- It deletes a machine that held nothing for
  `WORKSPACE_MACHINES_EMPTY_MINUTES`, if the pool still has enough room
  without it.
- It deletes a machine whose runner did not ask for work for
  `WORKSPACE_MACHINES_BOOT_MINUTES`, and closes the workspaces on it. If
  the runner on a new machine never asked for work, no machine starts for
  `WORKSPACE_MACHINES_BOOT_RETRY_MINUTES` (30). A wrong box image or
  control plane address makes every machine fail, so the pool does not
  pay for new ones. The same pause follows when the cloud refuses to start
  a machine, for example when the project reached its server limit. The
  operations page, `runners:scale` and the log tell you when this occurs.
- It deletes machines in the pool that no runner belongs to.

Hetzner bills each machine by the started hour, so the pool costs little
when nobody works. An empty machine is deleted only in the last 10 minutes
of an hour it was paid for. Before that, deleting it saves nothing, and a
new workspace can use it instead of a new machine. Set
`WORKSPACE_MACHINES_HETZNER_BILLING_MINUTES=0` if your account bills by the
second.

1. In Hetzner Cloud, make a project for the pool. Make an API token with
   read and write access. Make a private network that includes the control
   plane, and a firewall (see [Set the cloud firewall](#5-set-the-cloud-firewall)).
2. Push the box image to a registry that new machines can pull from.
3. Set these values in the control plane's `.env`:

    ```
    WORKSPACE_DRIVER=runner
    WORKSPACE_BOX_PROVIDER=pool
    WORKSPACE_RUNNER_MAX_WORKSPACES=8
    WORKSPACE_MACHINES_CLOUD=hetzner
    WORKSPACE_MACHINES_HETZNER_TOKEN=<the API token>
    WORKSPACE_MACHINES_HETZNER_SERVER_TYPE=cx33
    WORKSPACE_MACHINES_HETZNER_LOCATION=fsn1
    WORKSPACE_MACHINES_HETZNER_NETWORK=<the network's id>
    WORKSPACE_MACHINES_HETZNER_FIREWALL=<the firewall's id>
    WORKSPACE_MACHINES_BOX_IMAGE=<registry>/builder-box:<version>
    WORKSPACE_MACHINES_MIN=1
    WORKSPACE_MACHINES_MAX=3
    ```

4. Make sure that the scheduler runs (`php artisan schedule:work`, or a cron
   entry for `schedule:run`).
5. Run `php artisan runners:scale` once. Then run `php artisan runners:list`.
   A new machine shows as `starting`, then as `online` after a few minutes.

Each new machine runs a boot script once. The script installs Docker if
the image has none, writes the runner's settings to
`/etc/builder-runner.env`, and starts the runner as the same service as in
[Run the runner as a service](#3-run-the-runner-as-a-service). The
machine's token is in the script, and Hetzner keeps the script as the
machine's user data. Workspace users cannot read it: the firewall blocks
the metadata address.

If two control planes, for example staging and production, share one
Hetzner project, give each its own `WORKSPACE_MACHINES_POOL_LABEL`. The
scaler touches only machines with its own label.

To give the machines a new box image, push it with a new tag and set
`WORKSPACE_MACHINES_BOX_IMAGE` to that tag. The scaler then replaces the
machines on the older image, one at a time, oldest first. It stops giving
the machine new workspaces and deletes it when its last workspace closes.
New machines start on the new image when the pool needs room. Always use
a new tag: the scaler compares tags, not image contents.

Machines added by hand stay in the pool, and the scaler does not delete
or replace them. Another cloud is added as a driver in
`app/Workspaces/Machines/MachineCloudManager.php`.

## Replace a token

If a token leaks, or a person who knew it leaves, give the machine a new
token:

```
php artisan runners:token vm1
```

The old token stops working immediately. Put the new token in
`/etc/builder-runner.env` on the VM, then restart the runner with
`sudo systemctl restart builder-runner`.

## Remove a machine

1. Start the removal:

    ```
    php artisan runners:remove vm1
    ```

    If the machine holds no workspaces, the command removes it, and its token
    stops working. If it still holds workspaces, the command only drains it:
    the machine gets no new workspaces, and the command shows how many it
    still holds.

2. Keep the runner running while it drains. It must stay online to close its
   workspaces. They close when their people finish or when they sit idle.
3. Run `php artisan runners:remove vm1` again until it says the machine is
   removed.
4. Stop and disable the service with
   `sudo systemctl disable --now builder-runner`, or delete the VM.

### If the machine is gone for good

If the VM is deleted or cannot start again, its workspaces cannot close on
it. Remove it with `--gone`:

```
php artisan runners:remove vm1 --gone
```

The control plane closes the machine's workspaces and stops their previews.
The people who used them can start their apps again on another machine.
Runs that were working on the machine stop. The command refuses
while the machine still asks for work, so make sure the runner is stopped.

## Limits

- Workspaces can reach the public internet. Package installs need it.
