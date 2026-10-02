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
  Copy it to the VM, for example through your private registry.

## 1. Set up the control plane

1. Set these values in the control plane's `.env`:

    ```
    WORKSPACE_DRIVER=runner
    WORKSPACE_BOX_PROVIDER=pool
    WORKSPACE_RUNNER_SOCKET_URL=wss://<the Reverb address the VM can reach>
    ```

2. Add the machine. Use lowercase letters and digits only:

    ```
    php artisan runners:add vm1
    ```

    The command shows the machine's token one time only. Copy it now. The
    control plane keeps only a hash of it.

## 2. Start the runner on the VM

Run the box image as root, on the host network, with permission to set the
firewall:

```
docker run -d --name runner --restart unless-stopped \
  --network host --cap-add NET_ADMIN \
  -e RUNNER_URL=https://<the control plane> \
  -e RUNNER_TOKEN=<the token from runners:add> \
  -e RUNNER_SERVICE_HOST=<the VM's private address> \
  -v /srv/workspaces:/workspaces \
  builder-box
```

- `RUNNER_URL` is where the runner reaches the control plane. The runner only
  connects out. It accepts no connections.
- `RUNNER_SERVICE_HOST` is where the control plane reaches previews on this
  machine. Previews listen only on this address. Use the private address, not
  the public one.
- `RUNNER_FIREWALL=off` tells the runner not to change the firewall. Use it
  only when you fence workspaces in another way.

## 3. Make sure it works

Read the runner's log with `docker logs runner`. You must see these lines:

```
Firewall is on (iptables, ip6tables).
Runner vm1 is ready.
```

If the log says `Firewall is off`, the container did not get `--cap-add
NET_ADMIN`, or the image has no iptables. Do not give workspaces to that
machine.

In the control plane, the machine's `last_seen_at` in the `runners` table
updates while the runner asks for work. A machine counts as online while it
asked in the last 120 seconds (`WORKSPACE_RUNNER_ONLINE_SECONDS`). New
workspaces go to the online machine that holds the fewest.

## 4. Set the cloud firewall

Set these rules in your hosting provider's firewall for the VM:

| Direction | Allow                                                           |
| --------- | --------------------------------------------------------------- |
| Inbound   | Ports 20000–20999 from the control plane's private address only |
| Inbound   | SSH from your own address only, if you need it                  |
| Outbound  | HTTPS to the control plane and Reverb                           |
| Outbound  | HTTPS to the internet, so workspaces can install packages       |

Block all other inbound traffic. Previews use ports 20000–20999
(`config/builder.php`, `preview.ports`).

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

## Limits

- There is no command to remove a machine or to replace its token yet. To
  replace a token, add the machine again under a new name.
- Workspaces can reach the public internet. Package installs need it.
