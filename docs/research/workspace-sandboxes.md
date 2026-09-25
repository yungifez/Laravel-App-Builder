# Research: workspace sandboxes ("dev containers") for the builder

Researched 2026-09-25. Status: recommendation, not yet built.

## Question

The builder has to run customer code: clone a customer app, install its
dependencies, let an agent edit it, run it so the owner can preview the
generated feature, and run its tests to show verification. That needs
disposable development environments like the one Claude Code on the web gives
each session. What should they run on, how do we isolate them, and how do we
keep one customer app from eating all the CPU?

## Short answer

1. **Use microVMs, not plain Docker containers.** The code we run is
   customer code plus AI-generated code, so it is untrusted and multi-tenant. A
   shared-kernel container is not a safe boundary for that. Firecracker-class
   microVMs (or Kata/gVisor under Kubernetes) are the norm, including for the
   environment this research ran in.
2. **Buy first, keep the option to self-host.** Put every provider behind one
   Laravel driver interface (`WorkspaceManager`, the framework's Manager
   pattern, chosen in `config/workspaces.php`). Start with a local `docker`
   driver for development and CI against our own trusted fixture, plus one
   hosted microVM provider for real customer code. Later, self-host with the
   Kubernetes `agent-sandbox` controller on Kata or gVisor if cost or
   compliance calls for it.
3. **Ration at three levels:** hard per-workspace ceilings (vCPU, memory,
   disk, processes, I/O), per-tenant concurrency limits enforced by the control
   plane's queue, and aggressive idle suspend. Most important of all, do the
   expensive work (dependency installs) once per template snapshot, not once per
   workspace.
4. **Next step:** a short spike that runs the fixture's verification loop and
   a preview URL on two hosted providers (E2B and Fly Machines/Sprites) behind
   the driver interface, with Daytona as the self-hostable fallback. Several
   vendor sites were blocked from this sandbox, so the spike must confirm the
   vendor details below first-hand.

## 1. Reference design: what this environment actually is

Inspected from inside this session's machine (`uname`, `/proc/cmdline`,
`dmesg`, `mount`, `lsblk`), plus the public docs.

| Observation                                                                                                                                                                                                                 | Evidence                                                                                                                                             | What we copy                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Each session is a **Firecracker microVM**, not a container                                                                                                                                                                  | kernel `6.18.44-fc`, `Hypervisor detected: KVM`, kernel cmdline `--firecracker-init`; docs: "each session runs in an isolated, Anthropic-managed VM" | One microVM per workspace                                                       |
| PID 1 is an **in-VM agent** (`/process_api`) that serves command execution over vsock and WebSocket (`--listen-vsock-port 2024`, `--block-local-connections`)                                                               | `ps`, `/proc/cmdline`                                                                                                                                | The control plane talks to an agent inside the workspace, never SSH             |
| **Resource ceiling:** 4 vCPU, ~16 GB RAM, ~30 GB disk                                                                                                                                                                       | `nproc`, `free`, docs "Resource limits"                                                                                                              | Fixed per-workspace sizes                                                       |
| **Disk allowance** enforced inside a large volume with ext4 reserved blocks                                                                                                                                                 | `/` mounted `resv_strict,resuid=65534`; the "no space left" note in the environment docs                                                             | Quotas per workspace, not per host                                              |
| **Toolchains mounted read-only** from separate images (`/opt/claude-code`, `/opt/env-runner`, skills)                                                                                                                       | `lsblk`: `vdc`/`vdd` ext4 `ro`, `vde`/`vdf` squashfs                                                                                                 | Shared read-only tool images, cheap to update                                   |
| **All egress through a proxy** that re-terminates TLS (session trusts a CA bundle), enforces an allowlist and injects credentials outside the VM                                                                            | `HTTPS_PROXY`, `SSL_CERT_FILE`, the proxy README; docs "Security proxy", "API credentials"                                                           | Secrets never enter the workspace                                               |
| **Git through a separate proxy** holding a scoped credential; push only to the working branch                                                                                                                               | docs "GitHub proxy"; observed `403` on repos not attached to the session                                                                             | Agents can only push to their own branch                                        |
| **Traffic shaping** devices present                                                                                                                                                                                         | `ifb0`, `ifb1` interfaces                                                                                                                            | Per-workspace bandwidth limits                                                  |
| **Setup script + filesystem snapshot**: the first session runs the setup script, the result is snapshotted and reused for about 7 days, and it is rebuilt when the script or network policy changes. Processes are not kept | docs "Environment caching"                                                                                                                           | Per-customer template snapshots                                                 |
| **Idle reclaim**: the VM is reclaimed after inactivity; resuming provisions a fresh VM and restores the conversation, not running processes                                                                                 | system prompt; docs "Environment expired"                                                                                                            | Suspend or destroy idle workspaces; state lives in the control plane and in git |
| Docker runs **inside** the VM                                                                                                                                                                                               | `dockerd` works (we ran Postgres and Redis in it)                                                                                                    | Nested containers are safe inside a microVM                                     |

Sources: [Claude Code in the cloud](https://code.claude.com/docs/en/claude-code-on-the-web),
[Configure cloud environments](https://code.claude.com/docs/en/cloud-environments),
[Self-hosted environments](https://code.claude.com/docs/en/self-hosted-environments).

The self-hosted runner design is also worth copying. A runner claims a session
from a queue, holds a lease kept alive by polling (the session is requeued after
about 60 s of silence), and is locked to one owner so checked-out code never
mixes between tenants. It exits after its work so the orchestrator restarts it
with a fresh disk.

## 2. What our workspaces must do

Derived from the prototype flow (request invitations → preview → select
permission step → owner-only restriction → inspect verification) and the
customer-app fixture:

- Start from a **template** per customer app (repo + toolchain + installed
  dependencies), in seconds.
- **Execute commands** with streaming output, exit codes and timeouts, plus
  read and write files (the agent's tools).
- **Expose a port** as an authenticated preview URL (`php artisan serve` or
  similar) for the "preview the generated feature" step.
- **Run verification** (tests, static analysis, formatting, types, build) and
  report the results.
- **Snapshot or pause** a workspace between owner interactions, and resume it.
- **Isolate** tenants from each other and from the control plane; control
  egress; keep customer secrets and git credentials outside the workspace.
- **Ration** CPU, memory, disk and time (section 5).

## 3. Isolation options

| Technology          | Boundary                                                               | Overhead                                       | Notes                                                                                                                          |
| ------------------- | ---------------------------------------------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Docker (runc)       | Shared host kernel; namespaces and cgroups                             | Lowest                                         | Not a security boundary for hostile code. Fine for our own fixture in local dev and CI                                         |
| Sysbox              | Shared kernel, stronger user-namespace setup                           | Low                                            | Good for Docker-in-Docker; still shares the kernel                                                                             |
| gVisor (`runsc`)    | Userspace kernel intercepts syscalls                                   | Syscall-heavy work is slower                   | Used by Modal; works as a Kubernetes RuntimeClass                                                                              |
| Kata Containers     | Lightweight VM per pod (QEMU, Cloud Hypervisor or Firecracker)         | VM boot and memory overhead                    | VM-grade isolation with the Kubernetes pod API                                                                                 |
| Firecracker microVM | Dedicated guest kernel, minimal VMM, jailer (chroot, seccomp, cgroups) | ~100-200 ms class boots, small memory overhead | Used by this environment, AWS Lambda, E2B, Vercel Sandbox and Fly.io. Built-in token-bucket rate limiters for disk and network |

For LLM-generated code, the 2026 consensus is a dedicated kernel per sandbox
(Firecracker or Kata), or gVisor at minimum.
([LogRocket comparison](https://blog.logrocket.com/comparing-ai-agent-sandbox-platforms-e2b-modal-daytona-and-more/),
[Northflank: sandboxes on Kubernetes](https://northflank.com/blog/sandboxes-on-kubernetes))

## 4. Build vs buy

| Option                                            | Isolation                                          | Pause, snapshot                                                                                                    | Preview ports                           | Control from Laravel                                                   | Self-host                                                         | Pricing and limits (as reported)                                                                                   |
| ------------------------------------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| **E2B**                                           | Firecracker                                        | Pause keeps memory and filesystem; resume ≈1 s; pause ≈4 s per GiB of RAM; snapshots expire 30 days after last use | Yes, per-port host                      | JS/Python SDKs; no official PHP SDK, so plan for HTTP integration work | Open-source runtime; self-hosting is reported to have rough edges | ≈$0.0504/vCPU-hr + $0.0162/GiB-hr while alive, per second. CPU and RAM are fixed at template build time            |
| **Daytona**                                       | Docker by default; Kata or Sysbox optional         | Snapshots; auto-stop after 15 min idle, auto-archive after 7 days (configurable)                                   | Yes                                     | REST API and CLI                                                       | Yes, AGPL-3.0 core                                                | Same per-second rates as E2B, reported ~90 ms creation                                                             |
| **Fly Machines / Sprites**                        | Firecracker on bare metal                          | Suspend and resume with the full memory snapshot; Sprites add copy-on-write checkpoints and sleep when idle        | Yes, via Fly networking                 | REST Machines API                                                      | No                                                                | Per-second billing; sub-1 s starts                                                                                 |
| **Vercel Sandbox**                                | Firecracker                                        | Snapshots (30-day default expiry)                                                                                  | Up to 4 ports                           | TypeScript SDK                                                         | No                                                                | Bills **active CPU** only (≈$0.128/vCPU-hr) + memory; up to 8 vCPU (Pro) / 32 (Enterprise); max runtime 24 h (Pro) |
| **Modal Sandboxes**                               | gVisor                                             | Snapshots                                                                                                          | Tunnels                                 | Python SDK only                                                        | No                                                                | No compute charge when idle                                                                                        |
| **Cloudflare Sandbox SDK**                        | Containers on Cloudflare's network (GA April 2026) | Yes                                                                                                                | Preview URLs                            | TypeScript (Workers)                                                   | No                                                                | Instance types lite → standard-2                                                                                   |
| **Kubernetes `agent-sandbox`** (SIG Apps, v1.0.x) | Whatever RuntimeClass you choose: gVisor or Kata   | Pause and resume; `SandboxWarmPool` for pre-warmed pods; `SandboxTemplate` and `SandboxClaim`                      | Stable identity plus an optional router | Kubernetes API (Go/Python clients; call from PHP via the K8s REST API) | Yes, Apache-2.0 (GKE and Red Hat ship it)                         | Your cluster costs                                                                                                 |
| **DIY Firecracker**                               | Firecracker + jailer                               | You build it (Firecracker snapshots)                                                                               | You build it                            | Whatever you write                                                     | Yes                                                               | Most engineering; only worth it at scale                                                                           |

Sources:
[E2B CPU/RAM](https://e2b.dev/docs/sandbox-template/customize-cpu-ram),
[E2B alternatives](https://opencomputer.dev/guides/e2b-alternatives/),
[Northflank: Daytona vs E2B](https://northflank.com/blog/daytona-vs-e2b-ai-code-execution-sandboxes),
[Daytona sandboxes](https://www.daytona.io/docs/en/sandboxes/),
[Daytona snapshots](https://www.daytona.io/docs/en/snapshots/),
[Fly suspend/resume](https://fly.io/docs/reference/suspend-resume/),
[Fly vs Modal](https://fly.io/learn/fly-vs-modal/),
[Vercel Sandbox pricing](https://vercel.com/docs/sandbox/pricing),
[Vercel Sandbox vs E2B](https://vercel.com/kb/guide/vercel-sandbox-vs-e2b),
[Cloudflare Containers/Sandbox GA](https://developers.cloudflare.com/changelog/post/2026-04-13-containers-sandbox-ga/),
[Cloudflare preview URLs](https://developers.cloudflare.com/sandbox/concepts/preview-urls/),
[kubernetes-sigs/agent-sandbox](https://github.com/kubernetes-sigs/agent-sandbox),
[GKE Agent Sandbox](https://docs.cloud.google.com/kubernetes-engine/docs/how-to/agent-sandbox),
[2026 comparison (StartupHub)](https://www.startuphub.ai/ai-news/artificial-intelligence/2026/daytona-vs-e2b-vs-modal-vs-vercel-sandbox-2026).

Numbers come from vendor pages and third-party comparisons found by search.
modal.com and bex.co were blocked by this sandbox's egress policy, so treat
every figure as something the spike must confirm.

**Our constraint:** the control plane is Laravel. Providers with only Python
or TypeScript SDKs (Modal, Vercel, Cloudflare) would need a sidecar service.
Providers with a documented HTTP API (Daytona, Fly, Kubernetes) fit Laravel's
HTTP client directly. E2B is the most purpose-built for this use case, but it
needs HTTP integration work.

## 5. Rationing CPU (and everything else)

### What the fixture actually costs

Measured in this 4-vCPU VM on a fresh copy of `fixtures/customer-app`
(`getrusage` of child processes):

| Step                             | Wall   | CPU time | Avg cores | Peak RSS |
| -------------------------------- | ------ | -------- | --------- | -------- |
| `composer install` (warm cache)* | 93.5 s | 80.0 s   | 0.86      | 555 MB   |
| `npm ci` (warm cache)            | 9.1 s  | 14.7 s   | 1.62      | 638 MB   |
| `php artisan migrate` (SQLite)   | 0.5 s  | 0.2 s    | 0.49      | 67 MB    |
| `php artisan test` (65 tests)    | 3.1 s  | 1.5 s    | 0.48      | 111 MB   |
| `phpstan` (level 7)**            | 1.5 s  | 1.5 s    | 0.99      | 165 MB   |
| `pint --test`                    | 0.9 s  | 0.9 s    | 0.99      | 63 MB    |
| `vp check` (Oxfmt + Oxlint)      | 3.8 s  | 9.0 s    | 2.36      | 201 MB   |
| `vue-tsc`                        | 6.2 s  | 11.8 s   | 1.91      | 529 MB   |

\* Inflated: this sandbox blocks GitHub zip downloads, so Composer cloned
sources (4.2 GB `vendor/`). A normal `--prefer-dist` install is much cheaper,
but dependency installs are still the biggest CPU cost.
\** PHPStan may have reused a result cache from earlier runs.

**Takeaways.** The full verification loop costs about 25 CPU-seconds, and 1 GB
of memory is enough. Dependency installs cost several times more and spike
memory. So the biggest single saving is to **install once per template
snapshot**, as this environment's setup-script cache does, and only re-run
installs when lockfiles change. The front-end tools (Oxfmt/Oxlint, vue-tsc,
Vite) are the parts that use multiple cores.

### Levers

**Per workspace: hard ceilings**

- **vCPU count:** fixed per microVM or template, for example 2 vCPU by default
  and 4 for builds.
- **Sustained CPU quota** on the VMM process via cgroup v2 `cpu.max`, so a
  2-vCPU workspace can be held to, say, 1 core on average.
- **Memory:** `memory.max`, with no swap.
- **Processes:** `pids.max`, which stops fork bombs.
- **Disk:** a per-workspace quota, for example 10 GB, like the reserved-blocks
  allowance here.
- **I/O:** Firecracker's per-device token buckets (bytes/s and ops/s) for disk
  and network.
- **Egress bandwidth:** shaping on the host side (`tc`, as the `ifb` devices
  here suggest).
- Hosted providers expose most of this as the template's CPU and RAM size.
- The local `docker` driver gets the same caps with `--cpus`, `--memory`,
  `--pids-limit` and a storage limit.

**Per command: time boxes**

- Every exec from the control plane gets a timeout (Laravel `Process::timeout()`
  locally, the provider's exec timeout remotely), for example 10 min for
  installs, 5 min for tests and 1 min for linters.
- Kill the process tree on timeout.

**Per workspace: lifetime**

- Suspend after N minutes idle; this environment reclaims idle VMs, and Daytona
  defaults to 15 min.
- Destroy after a maximum age.
- Preview servers stay off unless someone has the preview open, and run with a
  single worker (`PHP_CLI_SERVER_WORKERS=1`).
- The customer app's queue workers and schedulers stay off in workspaces unless
  the task needs them.

**Per tenant and globally: admission control in the control plane**

- Limit concurrent workspaces and concurrent heavy commands per team with
  Laravel's Redis funnel (`Redis::funnel('tenant:'.$id)->limit(2)`) or the
  `RateLimited` and `WithoutOverlapping` job middleware on the jobs that start
  workspaces or run commands.
- Excess work waits in the queue instead of oversubscribing hosts.
- Keep a per-tenant CPU-seconds budget, from provider usage APIs or host
  cgroup `cpu.stat`, checked before starting new work.

**Placement**

- Put heavy one-off work (template builds) on a separate queue, or on larger
  but fewer workers.
- If we self-host: allow moderate vCPU overcommit for interactive workspaces
  (most are idle while the model thinks), and pin or reserve cores for builds.

**Billing model**

- Active-CPU billing (Vercel) or scale-to-zero (Modal, Fly suspend) matches
  agent workloads, which mostly wait on the model. With wall-clock billing (E2B,
  Daytona), idle suspend matters even more.

## 6. Proposed shape in the control plane (Laravel)

Not built yet. This is the shape to spike against.

- `config/workspaces.php`:
    - default driver;
    - per-driver credentials (from `.env`);
    - default size (vCPU, memory, disk);
    - command timeouts;
    - idle and maximum lifetimes;
    - per-tenant concurrency;
    - egress allowlist.
- `App\Workspaces\WorkspaceManager` extends `Illuminate\Support\Manager`, with
  drivers such as `docker` (local development and CI, trusted fixture only),
  `e2b`, `fly` and `daytona`. Each driver implements a contract:
    - `create(Template, Size)`
    - `exec(cmd, cwd, timeout)`, streaming, and returns an exit code
    - `readFile` / `writeFile`
    - `expose(port)`, which returns a preview URL
    - `pause` / `resume` / `snapshot`
    - `destroy`
- Models:
    - `WorkspaceTemplate` (customer app + commit + toolchain definition + snapshot
      ID);
    - `Workspace` (status, driver ID, size, last activity);
    - `WorkspaceCommand` (command, exit code, duration, CPU-seconds, truncated
      output).
- Jobs:
    - `BuildWorkspaceTemplate` runs installs once and snapshots;
    - `ProvisionWorkspace`;
    - `RunWorkspaceCommand`, with funnel and timeout;
    - `SuspendIdleWorkspaces` and `DestroyExpiredWorkspaces`, on the scheduler.
- **Environment definition:**
    - If the customer repo has `.devcontainer/devcontainer.json`, build the
      template from it with the Dev Container CLI (`devcontainer build`).
    - Otherwise use a default Laravel image (PHP 8.4, Composer, Node 22, SQLite),
      like the preinstalled toolchains here.
    - ([devcontainer spec](https://github.com/devcontainers/spec),
      [devcontainer CLI](https://github.com/devcontainers/cli))
- **Credentials:**
    - Customer secrets and git tokens stay in the control plane or an egress
      proxy and are never written into the workspace.
    - Git pushes go through a scoped credential limited to the workspace's
      branch, mirroring the GitHub proxy here.
- **Where the agent runs:** the agent loop (laravel/ai) runs in the control
  plane. Its tools call the workspace driver. The model API key never enters
  the workspace.

## 7. Recommended next step (spike)

Time-box a few days:

1. Define the `WorkspaceDriver` contract and implement the `docker` driver with
   hard caps. Prove it by running `fixtures/reference-solutions/verify.sh`'s
   steps and a preview server through it.
2. Implement the same contract against **E2B** and **Fly Machines/Sprites**.
   Measure against the fixture:
    - template build time;
    - cold start and resume time;
    - verification wall time at 2 vCPU;
    - preview URL setup;
    - cost per run;
    - how hard the PHP integration was.
3. Pick one provider for G1. Keep Daytona (self-hostable) and Kubernetes
   `agent-sandbox` + Kata/gVisor as the self-host path.

Open questions for the owner:

- Where do customer apps live: GitHub only, or other git hosts?
- Must customer code stay in a region or on our own infrastructure (compliance)?
- What budget per workspace-hour is acceptable?
