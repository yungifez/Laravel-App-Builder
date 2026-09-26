# Direction 02: Project runtime and model access

> Source: direction from the project owner, recorded as given (formatting repaired
> only). Covers where the coding agent lives and how users supply model access.

## The agent lives in the project runtime, not in the control plane

The agent itself should live in the project runtime, not in your Laravel control plane.

For your architecture, think of three places:

1. **Control Plane**: your Laravel SaaS
    - project plan
    - feature graph
    - approvals
    - user/account state
    - orchestration
2. **Project Runtime**: the environment where the repo actually exists
    - Codex / Claude Code / your agent process
    - shell
    - git
    - composer
    - npm
    - tests
    - browser
    - preview server
3. **Model Provider**: OpenAI / Anthropic / local model

So the agent usually lives here:

```
Laravel Control Plane
        │
        │ WebSocket / RPC
        ▼
┌─────────────────────────────┐
│ Project Runtime             │
│                             │
│ /workspace/my-saas          │
│                             │
│  ├── Laravel repo           │
│  ├── Codex agent  ← HERE    │
│  ├── PHP                    │
│  ├── Node                   │
│  ├── browser                │
│  └── test runner            │
└─────────────────────────────┘
        │
        ▼
      OpenAI
```

For your managed version, that runtime would probably be an isolated cloud container/VM you create for each project or active session.

For example:

```
User browser
    ↓
app.yourbuilder.com
    ↓
Laravel control plane
    ↓
Project Runtime #1842
    ├── cloned GitHub repo
    ├── Codex CLI
    ├── php artisan serve
    ├── Vite
    └── Playwright
```

Codex runs inside that runtime, beside the code it is editing.

For the T3-style BYO approach, it changes slightly:

```
Your SaaS
     │
     ▼
User's laptop
┌──────────────────────┐
│ Your runner daemon   │
│ Codex CLI            │
│ Laravel project      │
│ composer/npm/git     │
└──────────────────────┘
```

Then their authenticated Codex installation talks to OpenAI using their entitlement.

I would not put the actual coding agent inside your main Laravel web process, like:

```php
// DON'T architect it like this
Route::post('/generate', function () {
    // agent edits files for 20 minutes
});
```

Your Laravel app should instead issue work:

```
"Implement feature abc123"
```

to a runtime.

The runtime then reports events back:

```json
{ "type": "file.changed", "path": "app/Actions/InviteMember.php" }
{ "type": "command.finished", "command": "php artisan test", "exitCode": 0 }
{ "type": "agent.message", "message": "Invitation flow implemented." }
```

This gives you another useful abstraction:

```ts
interface ProjectRuntime {
    execute(command: string): Promise<Result>;
    read(path: string): Promise<string>;
    write(path: string, content: string): Promise<void>;
    runAgent(task: AgentTask): AsyncIterable<Event>;
}
```

Then where the agent physically lives stops mattering.

You can have:

- LocalRuntime
- DockerRuntime
- CloudVMRuntime
- LaravelCloudRuntime
- CodespacesRuntime

all implementing the same interface.

For your builder, I think the cleanest long-term model is:

```
                 ┌───────────────┐
                 │ Control Plane │
                 │    Laravel    │
                 └───────┬───────┘
                         │
              Project Runtime Protocol
                         │
          ┌──────────────┼──────────────┐
          ▼              ▼              ▼
     Cloud runner    Local runner    Enterprise
     your infra      user's PC       BYO infra
          │              │              │
       Codex          Codex          Codex
       Claude         Claude         Claude
```

That runtime protocol may end up being one of the most important pieces of the whole product. It lets the Laravel-specific intelligence stay in your platform while the actual coding agent can move between local, your cloud, or eventually customer-owned infrastructure.

## Bring your own model access

- We can make the app a "bring your own auth token" situation, so users can choose their model. The only thing is we want the app as seamless as possible.
- It shouldn't be just "paste an API key"; users should also be able to paste an auth token (for example a subscription token).

Decisions and constraints recorded in response (see the consolidated
architecture, "Model gateway and credentials"):

- Included credits are the seamless default; bring-your-own is an option.
- API keys stay in the control plane and are injected by a model gateway, so an
  agent with a shell never sees them.
- Subscription tokens (for example `claude setup-token` → `CLAUDE_CODE_OAUTH_TOKEN`,
  or a Codex ChatGPT login) are technically supported, but Anthropic's Agent SDK
  documentation says third-party developers may not offer claude.ai login or rate
  limits in their products unless previously approved. They stay flagged off for
  our cloud until the providers approve, and are allowed on the user's own
  machine (local runner).

## Use official agent interfaces, not CLI automation

We previously considered automating Claude Code itself as the execution environment.

Do not make CLI automation the primary architecture.

Prefer official programmatic agent interfaces such as the Claude Agent SDK, and maintain an abstraction that allows other agent runtimes to be added later.

The distinction between our control plane and the coding agent runtime is important.

### Our control plane owns product and application intelligence

Our system should decide:

- what the user is actually trying to accomplish
- which product capability is involved
- what application surfaces are affected
- what Laravel structure already tells us deterministically
- whether an existing trusted capability/package solves the problem
- whether Rector or another deterministic transformation can perform some of the work
- what context should be provided to the coding agent
- which model/runtime should execute the task
- cost/token/runtime budgets
- permissions and tool boundaries
- whether production approval is required
- which tests/verifiers must run
- whether the resulting change is acceptable
- what reusable knowledge should be extracted afterward

This is our control plane.

### The agent runtime owns open-ended engineering execution

Once the control plane has defined a sufficiently clear engineering task, delegate open-ended implementation to an existing high-quality coding-agent runtime.

For Claude, prefer the Claude Agent SDK rather than automating the interactive Claude Code CLI.

The agent runtime can own:

- repository exploration
- reading files
- editing source
- shell commands
- debugging
- tool calls
- iterative reasoning
- implementation planning within the scoped task
- subagent usage where useful
- running tests during implementation

Do not recreate these generic agent capabilities merely because we can.

Our advantage is not building a better generic repository agent.

Our advantage is giving a generic frontier coding agent an unusually well-understood Laravel environment.

### Architectural boundary

```
USER
  ↓
PRODUCT LAYER
  ↓
OUR CONTROL PLANE
  ├── product intent
  ├── Laravel application graph
  ├── capability system
  ├── trusted package registry
  ├── package adapters
  ├── context compiler
  ├── change decomposition
  ├── Rector/deterministic mutation selection
  ├── model/runtime routing
  ├── budgets
  ├── permissions
  └── verification policy
          ↓
EXECUTION ADAPTER
          ↓
  Claude Agent SDK
  OpenAI agent runtime
  future agent runtime
          ↓
  ISOLATED PROJECT ENVIRONMENT
          ├── filesystem
          ├── git
          ├── PHP
          ├── Composer
          ├── Node
          ├── Artisan
          ├── database
          ├── browser
          └── tests
```

The execution adapter should prevent the rest of our product from becoming tightly coupled to Claude-specific APIs.

### Do not confuse orchestration layers

Claude's agent runtime may internally:

- plan
- use subagents
- search the repository
- execute tools
- retry
- reason about implementation

That is fine.

Our control plane does not need to duplicate those decisions at a lower level.

_(The message was cut off here, at "Our orchestra…". The consolidated architecture
assumes it continued: our orchestration works at the product level; the agent's
works at the engineering level.)_
