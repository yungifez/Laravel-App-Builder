# Boundaries and chaos: a proposal

Answer to [direction 33](../architecture/direction/33-architecture-boundaries-and-chaos.md).
Status: the owner adopted the MVP (§20) on 2026-10-01. Built so far: phase
and frames per effect, the phase rules (`AppBoundaries`), the same rules
read from the code (`BoundaryCode`, including the app's start), the ratchet
that does not count a finding that only moved (§16.1), exceptions the owner
gives in the proof for one change (§16.3, without budgets or review dates
yet), containment read from where the app already calls each outside service
(reviewer only, not yet confirmed by developers), the job that runs twice,
the job retried after its save failed, the outside call made again after no
answer, the server error the app does not ask about, listeners run in
reverse order, a queued job held back until the response and run as a worker
runs it, fault places ordered by boundary findings, the protected
verification inputs, and the gate (§6) for the phase rules, where a proven
finding sends the change back without the reviewer. These are described in
architecture.md §12. The rest is proposal. When parts are adopted, they are
folded into the existing sections of
[architecture.md](../architecture/architecture.md) (§12 Verification, §26.4
Effects, §31.2 Knowledge is not enforcement, §10 Governance), not appended.

Written by session d0 (boundaries) with session 19, which builds the fault
engine (`AppTraces`, `AppFaults`, `resources/trace-recorder`). The fault
engine's current design is in architecture.md §12. Where this proposal meets
it (§9, §10, §11), session 19 reviewed the text.

## Contents

The direction asks for 25 deliverables. This table maps each one to its
section.

| Deliverable                          | Section | Deliverable                                | Section |
| ------------------------------------ | ------- | ------------------------------------------ | ------- |
| 1. Unified conceptual model          | §1, §2  | 14. Unified verification pipeline          | §12     |
| 2. Rule representation               | §5      | 15. Other engines                          | §13     |
| 3. Severity model                    | §6      | 16. Examples with real Laravel code        | §14     |
| 4. Artifact categories and detection | §3      | 17. Blocked by default                     | §15     |
| 5. Capability / effect categories    | §4      | 18. Advisory by default                    | §15     |
| 6. Static analysis architecture      | §7      | 19. Blocking only in strict mode           | §15     |
| 7. Runtime instrumentation           | §8      | 20. Failure modes of this design           | §21     |
| 8. Baseline and ratchet              | §16.1   | 21. Where static analysis is unreliable    | §18     |
| 9. Debt representation               | §16.2   | 22. Where runtime evidence is insufficient | §18     |
| 10. Exceptions and approval          | §16.3   | 23. MVP and long term                      | §20     |
| 11. Protected control plane          | §11     | 24. Tooling candidates                     | §19     |
| 12. Architecture feeds chaos         | §9      | 25. Open research questions                | §22     |
| 13. Chaos feeds architecture         | §10     |                                            |         |

## 1. The short answer

The brief proposes **Artifact × Capability × Constraint**. That model mixes
three different things. Separating them makes the system both simpler and
stronger.

1. **Where code runs is a phase, not a class.** The danger in "a Policy
   writes" is not the class `PostPolicy`. It is that something wrote _while
   Laravel was deciding whether the person may act_. The same write is fine
   when the controller calls the same method. Laravel has a small, fixed set
   of phases: boot, middleware, authorization, validation, handling,
   rendering, model hooks, listeners, jobs and commands. The framework itself
   marks each one, so both static analysis and the recorder can find them
   without trusting folder names.
2. **What code does to the world is an effect with a target.** Read, write,
   send a mail, call a host, dispatch a job. Each effect has a target: a
   table, a host, a mail class, a queue. "Irreversible" is a property of the
   target (a payment host, an email), not a kind of effect.
3. **Order matters as much as presence.** "Sent while the save could still
   fail" and "saved half" are the most common real defects. No
   artifact × capability table can express them. The fault engine already
   finds them.

So the core of the system is one relation, recorded and inferred the same
way:

```
effect(class, target) happened in phase P, from frames F, in order O,
with T transactions open
```

Every rule is a predicate over that relation. Static analysis predicts it
for all paths, with less certainty. The recorder observes it for tested
paths, with certainty. The fault engine observes it on the failure paths
that tests never take.

The second simplification is to **split rules by who owns them**:

| Family                | Example                                                | Owner                  | Default   |
| --------------------- | ------------------------------------------------------ | ---------------------- | --------- |
| **Phase rules**       | No write or send while authorizing                     | Platform               | Send back |
| **Ordering rules**    | No mail while the save can still roll back             | Platform               | Send back |
| **Integrity rules**   | A feature change does not edit how the app is checked  | Platform (protected)   | Refuse    |
| **Containment rules** | Calls to `api.stripe.com` only from the Billing area   | Project                | Ask       |
| **Shape rules**       | Mutating routes go through `app/Actions`               | Project (a convention) | Note      |
| **Drift measures**    | Effects per request in an area, coupling between areas | Nobody (measured)      | Record    |

Platform rules are true for every Laravel app, whatever its style. Project
rules are chosen by the project and can be anything its developer wants.
Shape rules are where Laravel style lives. They are advisory by default, so
no project is forced into Actions, Services or repositories.

The third simplification is **one observation, many readers**. The test
suite runs once with the recorder. Every engine, architecture included,
reads that one recording as a pure function. The only extra runs are
experiments (faults, duplicates, interference), planned under one budget.

## 2. Does Artifact × Capability × Constraint hold?

It is a good first sketch and it breaks in seven places:

1. **An artifact is not an execution context.** A Policy method called
   directly from a controller as a helper is not authorization. A
   controller method reused from a job is not request handling. Rules keyed
   on class names misfire both ways.
2. **Effects are transitive.** `CheckoutController` calls `BillingService`,
   which calls Cashier, which calls Stripe. No HTTP call appears in the
   controller. A class-level rule sees nothing; a phase-level rule sees the
   whole chain.
3. **Order is invisible to it.** "Write, then mail" and "mail, then write"
   have the same capabilities and very different failure behaviour.
4. **The target decides the risk.** HTTP to a weather API and HTTP to a
   payment provider are the same capability. A write to `audit_logs` and a
   write to `payments` are the same capability.
5. **Responsibilities are not effects.** `ValidateInput` and `Authorize` in
   the brief's list are things code is _for_, not things it does to the
   world. They belong to presence rules ("a mutating route is authorized"),
   which the recorder can confirm: the request entered the authorization
   phase, or it did not.
6. **Irreversibility is not a capability.** It is a property of a target,
   declared by a package contract (Cashier says Stripe charges are
   irreversible) or by the project.
7. **Intent changes the verdict.** "Mark notifications as read when the page
   opens" is a write on GET by design. The rule must know what the plan
   asked for.

The context a rule actually needs is:

```
phase × effect class × target × order (and open transactions) × scope (area) × intent (plan)
```

**Risk is deliberately not a rule input.** Risk changes _how much we look_
(how many faults, which engines, strict mode for an area). It does not
change _what a finding means_. That keeps verdicts predictable: the same code
gets the same finding in a low-risk and a high-risk change.

## 3. Laravel artifacts, phases and how they are detected

Artifacts are found from what the framework has **registered**, not from
folders. The box runs a small read-only introspection at boot (like the
recorder: neutral name, no file of the app changes), plus PHPStan reflection
for class relations.

| Artifact                            | Static detection                                        | Runtime registry / frame                               | Phase it runs in                    |
| ----------------------------------- | ------------------------------------------------------- | ------------------------------------------------------ | ----------------------------------- |
| Route, closure route                | `route:list --json`                                     | `RouteMatched`                                         | handling                            |
| Middleware                          | route middleware list, `bootstrap/app.php`              | `Pipeline` frames                                      | middleware                          |
| Controller action                   | route action                                            | frame of the route action                              | handling                            |
| FormRequest                         | subclass of `FormRequest`                               | `FormRequest::validateResolved` frame                  | validation (and authorization)      |
| Custom validation rule              | implements `ValidationRule`                             | `Validator` frames                                     | validation                          |
| Policy, Gate ability                | `Gate::policies()`, auto-discovery, `Gate::define`      | `Gate::raw` / `inspect` frames                         | authorization                       |
| Action, Service, repository         | **not detected as a role**; they are ordinary code      | appear as frames                                       | inherits the caller's phase         |
| Model                               | subclass of `Model`; `model:show --json`                | —                                                      | —                                   |
| Observer, model event closure       | `model:show --json` (observers), `booted()` closures    | `Model::fireModelEvent` frames                         | model hook                          |
| Accessor, mutator, cast             | `Attribute` methods, `get*Attribute`, `CastsAttributes` | `Model::getAttribute` / `setAttribute` frames          | model hook (serialization)          |
| Global / local scope                | `Scope` classes, `scope*` methods, `#[ScopedBy]`        | builder frames                                         | inherits                            |
| Event, listener, subscriber         | `event:list`                                            | `Dispatcher::dispatch` frames                          | listener (sync) or job (queued)     |
| Job                                 | implements `ShouldQueue`                                | `CallQueuedHandler::call`                              | job                                 |
| Notification, Mailable              | subclasses                                              | `toMail`/`toArray`/`content` frames                    | rendering                           |
| Command, schedule                   | `list --format=json`, `schedule:list`                   | `Command::execute` frames                              | command                             |
| Service provider                    | `bootstrap/providers.php`, package discovery            | `Application::boot` / `register` frames                | boot                                |
| Migration                           | `database/migrations`                                   | `Migrator` frames                                      | migration (own rules, §12 existing) |
| API resource                        | subclass of `JsonResource`                              | `JsonResource::resolve` frames                         | rendering                           |
| Blade view, component               | `resources/views`, `View\Component`                     | `View::render` frames                                  | rendering                           |
| Inertia page and props              | `Inertia::render` calls, `HandleInertiaRequests::share` | `Inertia\Response::toResponse` frames                  | rendering                           |
| Livewire component                  | subclass of `Livewire\Component`                        | Livewire lifecycle frames (`mount`, actions, `render`) | handling, rendering for `render`    |
| Facade, cache, storage, HTTP, queue | Larastan resolves facades to their classes              | effect sources (§4)                                    | —                                   |

Two consequences:

- **Actions, Services and repositories have no phase of their own.** They
  inherit the phase of whoever calls them. That is exactly right: an Action
  called from a Policy is authorization code. It is also why the system
  never needs to know whether a project uses Actions.
- **Phase is the innermost classified framework frame.** A mail sent from an
  observer that fired during a FormRequest's `passedValidation` hook is in
  the model-hook phase, inside validation. The recorder keeps the whole
  stack of phases; rules match the innermost one unless they say otherwise.

## 4. Effects (capabilities)

The vocabulary maps one to one onto the recorder's effect kinds, so static
and runtime evidence speak the same language.

| Effect class     | Recorder kind today               | Target                       | Notes                                                                |
| ---------------- | --------------------------------- | ---------------------------- | -------------------------------------------------------------------- |
| `db.read`        | `query` (select)                  | table                        |                                                                      |
| `db.write`       | `query` (insert/update/delete)    | table                        | A database notification channel is a `db.write` too.                 |
| `db.tx`          | `begin` / `commit` / `rollback`   | connection                   | Structure, not an effect on the world.                               |
| `queue.dispatch` | `job`                             | job class, connection        | Plus whether it waits for commit (`afterCommit`).                    |
| `mail.send`      | `mail`                            | mailable class               | Irreversible.                                                        |
| `notify.send`    | `notification`                    | notification class, channels | Irreversible for mail, SMS and broadcast channels.                   |
| `http.out`       | `http`                            | method and host              | Irreversible when the target says so (package contract or project).  |
| `event.dispatch` | `events` (top level of the trace) | event class, listeners       | Only listeners found by event discovery; the disk gives their order. |
| `cache.write`    | not yet (`KeyWritten`)            | key prefix                   |                                                                      |
| `fs.write`       | not yet (needs a disk decorator)  | disk, path prefix            | Storage fires no events; this is the costly one to record.           |

Targets carry attributes that rules use:

- **irreversible** (from package contracts, §19 of architecture.md "package
  contract" statements, or the project);
- **tenant-scoped** (a model with an owner or tenant column or a global
  scope);
- **area** (from each area's `paths` in the notes).

`ValidateInput`, `Authorize` and `StartTransaction` from the brief are not
effects. The first two are phases; the third is structure that ordering
rules read.

## 5. Rule representation

A rule is data, versioned, owned and evaluated by the control plane. It is
never a file in the customer's repository.

```yaml
id: BND-AUTH-WRITE # stable, never reused
version: 3
family: phase # phase | ordering | integrity | containment | shape | drift
owner: platform # platform | project
when:
    phase: authorization # innermost phase, or `within: authorization` for any depth
    effect:
        [
            db.write,
            queue.dispatch,
            mail.send,
            notify.send,
            http.out,
            event.dispatch,
        ]
unless:
    plan_asks: false # true when the plan's intent can allow it ("mark as read on open")
evidence: [runtime, static] # which evidence can prove it
outcome: # by certainty of the evidence (§6)
    proven: send_back
    likely: note
    possible: note
explain: # deterministic templates, filled from the finding
    agent: >
        {frames} {effect} while Laravel was checking whether the person may
        {ability}. Authorization runs many times per page (for example once per
        row in a list), so this {effect} repeats. Move it into the action that
        runs after the check.
    owner: >
        Checking who may do something changed what your app saved.
```

A **finding** is one rule matched once:

```yaml
rule: BND-AUTH-WRITE@3
fingerprint: 9f2c… # rule + entry point + innermost app symbol + effect + target (§16.1)
where:
    entry: GET posts.index
    phase: [handling, rendering, authorization]
    frames: [App\Policies\PostPolicy::view, App\Models\Post::recordView]
    at: app/Models/Post.php:88
effect: { class: db.write, target: posts }
evidence: { kind: runtime, trace: t-41/r-3/e-7 } # or static: call path; or fault: run id
certainty: proven # proven | likely | possible
origin: new # new | existing | moved (from the baseline, §16.1)
outcome: send_back # computed by the gate, never stored by a model
```

Project rules use the same shape with `owner: project` and a provenance
(`confirmed` by the owner or a developer, or `proposed`). Only confirmed
rules can produce more than a note. This is the same rule §12 already
applies: only DERIVED, CONFIRMED and PACKAGE CONTRACT statements feed hard
gates.

## 6. Graduated enforcement

The brief's BLOCK / ERROR / WARN / OBSERVE mixes two questions: _how sure are
we_ and _what happens to the change_. They are kept apart.

**Certainty of the evidence:**

| Certainty    | Comes from                                                                                                                           |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| **proven**   | The recorder saw it (a test run or a fault run), or a static fact that cannot be wrong (a file was edited, a secret is in the diff). |
| **likely**   | Static analysis with every call on the path resolved.                                                                                |
| **possible** | Static analysis through something it could not resolve (a container call, `__call`, a closure).                                      |

**Outcome for the change**, in the product's own words:

| Outcome       | Meaning                                                                                                    | Who can override                |
| ------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------- |
| **Refuse**    | The change cannot be kept in this form. Only a different kind of change can do this (§11).                 | Nobody, inside a feature change |
| **Send back** | The agent must fix it. Uses the existing repair loop and its limit.                                        | An approved exception (§16.3)   |
| **Ask**       | A person decides before keeping: a developer when one is linked (§29), otherwise the owner in plain words. | The person asked                |
| **Note**      | Kept. Shown in the proof or the review, and counted for the ratchet.                                       | —                               |
| **Record**    | Kept. Only counted for drift and for later audits.                                                         | —                               |

**The gate** is one deterministic function. No model takes part in it, and a
finding never changes a check's result by itself (the fault engine's rule):

```
outcome(rule, certainty, origin, exceptions, mode) =
    exception covers fingerprint          → record (the debt item, §16.2)
    origin = existing                     → record
    origin = moved                        → note
    integrity rule                        → refuse
    certainty = proven                    → rule.outcome.proven
    certainty = likely                    → rule.outcome.likely
    certainty = possible                  → note, and a scenario to prove it (§9)
    then, if mode = strict and the result is note → send back
```

Three properties follow:

- **The model never decides whether its own violation is acceptable.** It can
  only fix the code or call `propose_exception` (§16.3), which a person
  answers.
- **The reviewer can raise, never lower.** The independent reviewer may add
  a blocking finding of its own. It cannot turn a deterministic send back
  into a note.
- **Uncertainty turns into an experiment, not a verdict.** A "possible"
  finding never sends a change back in standard mode. It becomes a question
  for the fault engine, which makes it proven or drops it.

**Strict mode** is `deny(warnings)` for new findings: notes on new code become
send backs, and "possible" findings send back when no experiment could
settle them. It can be set for a whole project or for one area (for example
Billing).

## 7. Static analysis

Purpose: predict effects on **all** paths, including untested ones, before
anything runs, and fast enough to run inside the agent's working loop.

1. **Role index.** Built once per commit from the framework's own
   registries (§3), run in the box. It answers: which methods are
   entry points, and which phase does each start in.
2. **Effect sites.** A Larastan-based collector finds direct effect sites:
   Eloquent writes (`create`, `save`, `update`, `delete`, `increment`, query
   builder writes, `DB::` writes), `Http::`, `Mail::`, `Notification::`,
   `->notify()`, `dispatch()` and `Bus::`, `event()`, `Cache::put`,
   `Storage::put`, `DB::transaction`, and package contract methods (Cashier's
   `charge`).
3. **Effect summaries.** Each method gets a summary: the effects it may
   cause, through resolved calls, computed as a fixpoint over the call
   graph. A call that cannot be resolved adds `unknown`. `unknown` never
   means "no effect". It lowers the certainty of what follows it to
   "possible".
4. **Entry point signatures.** The summary of each entry point, by phase, is
   its _effect signature_. That signature is what the planner, the scenario
   planner and the other engines read.
5. **Shape and containment rules** are mostly plain dependency checks over
   the same index: "no `Stripe\` outside `app/Billing`", "no `DB::` in
   `App\Http\Controllers`". Pest `arch()` covers the simple ones today.

Static analysis is incremental. Only changed files and their callers are
re-summarized while the agent works. The whole index is rebuilt in the
independent verification.

## 8. Runtime instrumentation

The recorder (session 19) already writes one line per request with each
effect, its kind, the nearest app line (`at`) and the open transactions.
Two additions make it architecture evidence. Session 19 builds them when the
MVP is chosen:

1. **App frames per effect.** A short list of the app's own frames
   (`class::method`, nearest first, about 6). `origin()` already walks the
   backtrace, so this is cheap and stays deterministic.
2. **Phase per effect.** Computed from the same backtrace by matching a
   fixed table of framework frames, named by full class:
   `Illuminate\Auth\Access\Gate::raw` → authorization,
   `Illuminate\Foundation\Http\FormRequest::validateResolved` → validation,
   `Illuminate\View\View::render`,
   `Illuminate\Http\Resources\Json\JsonResource::resolve` and
   `Inertia\Response::toResponse` → rendering,
   `Illuminate\Database\Eloquent\Model::fireModelEvent` → model hook,
   `Illuminate\Events\Dispatcher::dispatch` → listener,
   `Illuminate\Foundation\Application::boot` → boot,
   `Illuminate\Queue\CallQueuedHandler::call` → job. Names matter:
   `Illuminate\Bus\Dispatcher::dispatch` is a job dispatch, not a listener.
   Frames nest (a listener inside a job), and the **innermost** framework
   frame wins. A frame the table does not know gives phase `unknown`, never
   a guess. The backtrace is capped at 80 frames, so a deep render or
   model-hook stack can also end `unknown`. Rules treat `unknown` like a
   static "possible": a note, never a send back. The table is versioned per
   Laravel major version and tested on fixtures.

Since bf9b9c5 the recorder stands in for the Mail, Notification, Queue and
Bus fakes, so a send under a fake is an effect too, with the same backtrace.
Some things stay hidden, and the recorder names them in `blind`:
`Event::fake`, notifications to channels other than mail, and jobs the app
runs inline under `Bus::fake`. A presence check ("authorization ran") on a
request with `blind` set is "not seen", never "absent".

With these, runtime evidence shows what static analysis cannot (§18):
effects of packages, of the container, of observers and listeners
registered at boot, of serialization, and of whatever runs only under the
real configuration.

`event.dispatch` is recorded since 7e4149f, as `events` with the listeners
event discovery found. Later recorder additions, each one listener:
`cache.write` (`KeyWritten`), authorization decisions
(`Gate::after`: ability, result) for the "authorization ran" presence check,
and `fs.write` (a disk decorator; the costliest).

## 9. Architecture drives chaos

The scenario planner reads the effect signature of each entry point the
change touches (static ∪ observed) and derives experiments. Each experiment
is one rerun of one test with one fault, the shape `AppFaults` already uses:
`(test, request, effect place, fault kind, property to check)`. No model is
involved and nothing is random. The planner gives `AppFaults::points()` an
ordered list; the fault engine stays the only thing that runs faults.

| Signature contains                                  | Experiment                                                                                           | Property checked (generic, no oracle needed)                                                                                                                                                                                                                | Exists today                                                                                                                                                      |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| send, then a write                                  | the write fails                                                                                      | nothing left that was sent for a save that was lost ("sent, then lost")                                                                                                                                                                                     | yes                                                                                                                                                               |
| write, then a send                                  | the send fails                                                                                       | the person gets an error and nothing was kept that a retry repeats ("saved, then failed")                                                                                                                                                                   | yes                                                                                                                                                               |
| two or more writes outside one transaction          | the last write fails                                                                                 | no half save ("saved in part")                                                                                                                                                                                                                              | yes                                                                                                                                                               |
| two or more writes in a transaction                 | the last write fails                                                                                 | nothing sent or saved outside the transaction stays (the database puts the writes back itself)                                                                                                                                                              | yes                                                                                                                                                               |
| `queue.dispatch`                                    | the job runs twice                                                                                   | judged by shape: a second insert or a second send is a finding; a repeated update is unknown, not clean                                                                                                                                                     | yes                                                                                                                                                               |
| a queued job the sync queue ran that sends or saves | the job is held back until the response, then runs as a worker runs it: no request, nobody signed in | the request does the same ("needs the job done"); the job does the same ("job needs the request"); a read of the job's table, same shape: unknown. A job sent to the sync queue by name runs in place in use too, so it is part of the request, not a place | yes                                                                                                                                                               |
| `queue.dispatch` of a job that reads a model        | the model changes or disappears before the job runs                                                  | the job neither crashes unhandled nor acts on a deleted record                                                                                                                                                                                              | partly: a model deleted after queueing is found ("job needs the request"); a changed one with the same shape is not                                               |
| a job with a write and a send                       | the write fails, then the job is retried                                                             | the send happened once ("sent again")                                                                                                                                                                                                                       | yes                                                                                                                                                               |
| `http.out` with a POST to an irreversible target    | the call reaches the target, then the client times out                                               | no second identical POST or PATCH without an idempotency key ("called again")                                                                                                                                                                               | yes                                                                                                                                                               |
| `http.out`                                          | timeout, server error                                                                                | the person gets a handled answer, nothing half done; on a server error the app asks the answer before it goes on ("answer not checked")                                                                                                                     | yes                                                                                                                                                               |
| an event with two or more listeners                 | the listeners run in reverse order                                                                   | the same sends, saves that stayed and status, by shape ("depends on order"); one table, no proof: unknown                                                                                                                                                   | yes                                                                                                                                                               |
| a listener with a send or a save                    | that send or save fails                                                                              | covered by the send and save places inside the listener                                                                                                                                                                                                     | yes                                                                                                                                                               |
| a read, then a write of the same row                | another change to that row lands between the two                                                     | the second change is not lost (lost update). Needs row identity: an owner decision (§22)                                                                                                                                                                    | no                                                                                                                                                                |
| `cache.write` with a `db.write`                     | the write fails after the cache write                                                                | the cache does not hold what the database lost                                                                                                                                                                                                              | skipped: Laravel's default cache store is the database, where the cache write rolls back with the transaction, and tests cannot know the store used in production |
| `fs.write` with a `db.write`                        | either one fails                                                                                     | no file without its record, no record without its file                                                                                                                                                                                                      | skipped for now: Laravel fires no events for disk writes, so it needs wrappers, for little gain                                                                   |

The recorder keeps no values, by rule: queries keep their placeholders.
So every property above is judged by the **shape** of the effects, and
where shape cannot decide, the result is unknown, never clean.

The ordered list is a pure function of (trace, patch, static findings), so
the same change always gives the same places. Experiments are chosen, in
order, inside the existing budget (today 8 places, no new rerun after
180 s, about 1–4 s per rerun):

1. places on the change's own lines (the fault engine's current rule);
2. findings with "possible" certainty, because an experiment settles them;
3. irreversible targets before reversible ones;
4. areas in strict mode before others.

The planner also reads the **plan**. If the plan says "charge the card, then
create the order", the order of effects is known before the code exists, and
the experiments are known too. The agent's brief can then say what will be
checked: "The order must not be lost when it fails to save after the
charge." That is the cheapest prevention there is, with two limits:

- **It invites a swallowed error.** The fault engine reads an app that
  catches the failure and answers in its own way as "took the failure in".
  An agent that knows the experiment can pass it with a `try`/`catch` that
  hides the error. So this idea only ships with the static rule against
  swallowed exceptions around a send or a save (§11, Blinding), at send
  back level for new code.
- **It says what, never how.** The brief names the property, not the
  engine, the recorder or the fault places (the trade-secret rule).

## 10. Chaos tests architecture

Every fault run is also a recording. It shows effects on the paths that
tests never take: exception handlers that send notifications, `failed()`
hooks on jobs, `report()` calls to outside services, retries written by
hand. Three things flow back:

1. **Findings in phases the happy path never shows.** For example, the
   exception handler writes to the database while rendering the error page.
2. **Certainty.** A static "possible" finding becomes proven when a fault run
   shows the effect. When the path ran and the effect did not happen, it is
   "not seen on the paths the tests take" and stays "possible": one test's
   path is not every path of the code.
3. **Guarantees as facts.** "POST /orders is all-or-nothing under 3 faults"
   is stored for the entry point, with the places tried and the revision.
   It ends when the entry point's code changes. The review, the owner's
   proof and the other engines read it. An Effect between two areas seen in a fault run is
   observed evidence for §26.4.

The fault engine's rules carry over unchanged: findings never change a
check's result by themselves, only lines the change adds are held against
it, and a fault that did not happen is `missed`, never clean.

## 11. The protected control plane

The feature agent must not be able to pass a check by weakening it. Most of
this already holds, because verification runs in a fresh worker with our
commands and trusts nothing from the agent's workspace (§12). The remaining
attack surface is **the app's own files that our checks read**, and **code
that blinds our observation**.

**Roles:**

| Role                                    | May                                                                                                      | May not                                                               |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| Coding worker (ours or the owner's own) | Change app code and the tests it adds. Run checks. Call `propose_exception`.                             | Change rules, exceptions, baselines, protected inputs, fault settings |
| Planner (a model)                       | Propose project rules, exceptions, a verification change, a refactor                                     | Apply any of them                                                     |
| Verification (deterministic)            | Compute findings and outcomes, with rules pinned to a version                                            | Be configured by the change it checks                                 |
| Reviewer (another model)                | Add blocking findings                                                                                    | Lower a deterministic outcome                                         |
| Owner                                   | Confirm project rules in plain words; answer owner-level Ask outcomes; accept owner-level exceptions     | Weaken platform rules                                                 |
| Developer (§29)                         | Confirm technical rules; accept technical exceptions; approve verification changes and metric allowances | Weaken platform rules                                                 |
| Platform (us)                           | Ship platform rule versions through §10's governance                                                     | —                                                                     |

**Protected inputs** are app files that change how the app is checked:
`phpunit.xml`, `tests/Pest.php`, test discovery, generated `arch()` files,
the frozen acceptance tests (§12), `phpstan.neon*`, composer scripts our
checks run, and CI workflows. A feature change that edits one is **refused**
with a plain reason ("This file decides how your app is checked"). Changing
them needs a **verification change**: its own kind of change, proposed by the
planner, approved by a developer, and verified by a monotonic check: the set
of tests discovered and rules run does not shrink unless the approval says
so.

**Blinding** is reported, not guessed at. Each is a note by default and a
send back in strict mode:

- a new fake the recorder cannot see through (`Event::fake()` in
  `tests/Pest.php`), or a rise in the share of requests marked `blind`;
- a new catch-all around a send or a save (`catch (\Throwable)` that
  neither rethrows nor reports) in changed code. This matters because the
  fault engine reads a caught failure as "took the failure in";
- `Model::withoutEvents`, `Event::fake` or `Http::fake` in app code.

**How a rule changes.** A rule change is a proposal: the rule, what it would
add and remove on the current code (a dry run), the reason, and who must
approve it. Accepted, it gets a new version, and the baseline is recomputed
with it (§16.1). Agents can propose; only people apply.

## 12. One verification pipeline

The brief's pipeline is a straight line after implementation. Its main
problems are:

- all checking happens after the code is written, so prevention is wasted;
- the test suite and the runtime trace are drawn as two stages, but they are
  one run;
- chaos comes after the architecture checks, but chaos should be driven by
  what those checks could not settle;
- mutation testing sits on every change's critical path, which the owner pays
  for in waiting;
- an architecture score feeds the release decision, which invites gaming;
- there is no feedback edge back to the agent, no baseline step, and no
  output for "what we could not see".

Proposed pipeline:

```
1. Plan
   risk class; effect contract (expected entry points, effects, order, targets)
   brief gets: the phase rules and containment rules that apply, the experiments that will run
        │
2. Work loop  (fast, while the agent works)
   static checks on changed code: phase × effect, containment, shape, protected inputs
   deterministic messages; same fingerprints as the final verification
        │
3. Observe once  (the existing coverage run with the recorder)
   tests + traces with phase and frames
   pure readers: phase rules, ordering shapes (AppTraces), plan vs effects, authorization ran
        │
4. Experiment  (budgeted reruns of one test each)
   scenario planner (§9) → fault engine → findings, certainty settled, guarantees
        │
5. Decide  (the gate, §6)
   findings + baseline + exceptions + mode → refuse | send back | ask | note | record
   send back → step 2 with the finding's template (existing repair limit)
        │
6. After keeping  (off the owner's wait)
   ratchet update, debt review triggers, drift measures, facts for other engines
   heavy engines (mutation, replay) for risky areas, before publishing
```

Every step writes **what it could not see** next to what it found: tests that
faked effects, paths no test reached, faults that did not happen. Absence of
evidence is a result, never a pass.

## 13. How other engines plug in

Steps 3 and 4 produce **operation facts** for each entry point: the phases
it visits, its effects with targets and order, transactions, models touched
with their traits (tenant column, soft deletes, a policy), queues and
whether dispatch waits for commit, outside hosts, abilities checked, and the
guarantees proven by faults. An engine registers two things: a predicate
over these facts that activates it, and, if it runs experiments, a scenario
kind for the planner. One budget covers all experiments.

| Facts show                                           | Engine activated                    |
| ---------------------------------------------------- | ----------------------------------- |
| `queue.dispatch`, or a job with an irreversible send | Idempotency                         |
| a write to a tenant-scoped model, or a read of one   | Tenant isolation                    |
| two or more writes in one operation                  | Rollback                            |
| a read, then a write of the same row                 | Concurrency                         |
| a route whose request entered authorization          | Permission matrix                   |
| a write to a status or enum column                   | State transition                    |
| the change touches an entry point with a kept trace  | Differential, Replay                |
| any send to an irreversible target                   | Effects review in the owner's proof |

## 14. Examples on real Laravel code paths

**A policy that writes.**

```php
// app/Policies/PostPolicy.php
public function view(User $user, Post $post): bool
{
    $post->increment('views');            // db.write in the authorization phase

    return $post->published || $user->is($post->author);
}
```

`@can('view', $post)` in a list renders it once per row, so opening the list
writes 50 times. The recorder shows `db.write posts` in phase
`authorization` within `rendering`, on `GET /posts`. BND-AUTH-WRITE is
proven: send back. "Saved on a read" (AppTraces) finds it too, from a
different angle.

**A real finding: a contact form that saves, then mails.** The fault
engine's first live finding (change 8, `POST /contact`):
`ContactController.php:37` inserts into `contact_messages`, then sends a mail
notification in the same request. With the mail made to fail, the person
gets an error page while the message is already saved, so they send it
again: "saved, then failed". The test used `Notification::fake()`, so no
engine saw it until the recorder stood in for fakes.

**A FormRequest that sends.**

```php
// app/Http/Requests/StoreOrderRequest.php
protected function passedValidation(): void
{
    Mail::to($this->user())->send(new OrderReceived($this->validated()));
}
```

The controller then fails to save (stock ran out). The mail left. Phase
`validation` with `mail.send`: send back. The fault engine finds "sent, then
lost" on the same request.

**A controller that charges through a service.**

```php
// app/Http/Controllers/CheckoutController.php
public function store(CheckoutRequest $request, BillingService $billing)
{
    $billing->charge($request->user(), $request->amount());   // → Cashier → POST api.stripe.com
    Order::create([...]);                                     // db.write after the charge
}
```

Static analysis of the controller alone sees no HTTP call. The recorder's
frames show `CheckoutController::store → BillingService::charge →
Laravel\Cashier…`, with `http.out api.stripe.com`. Two findings follow:

- Containment (a project rule "Stripe only from the Billing area"): passes,
  because the nearest app frame is in `app/Billing`. Had the agent called
  `$user->charge()` in the controller, the nearest frame is the controller,
  the target is irreversible, and the outcome is **ask**.
- Chaos: "send, then a write" derives "the write fails". The card was
  charged and no order exists: "sent, then lost", send back. The ambiguous
  timeout experiment then checks the fix: a retry without an idempotency
  key would charge twice.

**An observer that mails inside a transaction.**

```php
// app/Observers/UserObserver.php
public function created(User $user): void
{
    Mail::to($user)->send(new Welcome($user));   // registration runs in DB::transaction
}
```

"Sent before saved" (AppTraces) already catches it. The phase adds a second
fact: a model-hook send also fires in seeders, imports and factories. The fix
the message names is `afterCommit`, or a queued mail with
`ShouldQueueAfterCommit`.

**A job that is not idempotent.**

```php
// app/Jobs/SendInvoice.php
public function handle(): void
{
    $this->invoice->update(['sent_at' => now()]);
    Mail::to($this->invoice->customer)->send(new InvoiceMail($this->invoice));
}
```

The duplicate-delivery experiment runs it twice. Two mails: an Idempotency
finding. The fix the message names: return early when `sent_at` is set, or
`ShouldBeUnique`.

**A provider that queries at boot.**

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    View::share('categories', Category::all());   // db.read in the boot phase
}
```

It runs on every Artisan command and queue worker, and breaks
`composer install` where there is no database yet. Static analysis proves it
without a test. Send back; the fix is a view composer (rendering phase,
where reads are fine).

**An accessor that calls out.**

```php
protected function avatarUrl(): Attribute
{
    return Attribute::get(fn () => Http::get("https://gravatar.com/{$this->hash}")->json('url'));
}
```

Every serialization of a user calls Gravatar, including inside Inertia
props. `http.out` in a model hook (serialization): send back.

## 15. Defaults

**Blocked by default** (new code only; refuse or send back):

| Rule                                                                                       | Outcome                              |
| ------------------------------------------------------------------------------------------ | ------------------------------------ |
| A feature change edits a protected input (§11)                                             | Refuse                               |
| A secret in the diff                                                                       | Refuse                               |
| Authorization that always allows (`Gate::before(fn () => true)`)                           | Refuse                               |
| A write, send, dispatch or outside call while authorizing                                  | Send back                            |
| A write, send or outside call while validating                                             | Send back                            |
| A write, send or outside call while rendering (views, resources, Inertia props, accessors) | Send back                            |
| Any query or outside call in a service provider's `boot` or `register`                     | Send back                            |
| Sent, then lost; saved, then failed; saved in part (fault findings)                        | Send back (exists, via the reviewer) |
| A send while a transaction is open, without waiting for commit                             | Send back (exists)                   |
| A committed write on a GET, unless the plan asks for it                                    | Send back (exists)                   |
| A tenant-scoped query with its scope removed (`withoutGlobalScopes`) in changed code       | Send back                            |
| `Model::unguard()` in app code, raw SQL built from request input                           | Send back                            |
| An irreversible target reached from outside its declared area                              | Ask                                  |

**Advisory by default** (note): writes or outside calls directly in
controllers; long controller actions; an observer with several effects;
model-hook sends that do wait for commit; repeated lookups (exists, SL204);
cross-area model access; a new dependency; a mutating route without a
FormRequest; blinding signals (§11); a mutating request that never entered
authorization when static analysis found no check either.

**Blocking only in strict mode:** every advisory item above for new code;
"possible" findings that no experiment could settle; a new entry point that
no test reached; a POST to an irreversible target without an idempotency
key; queued jobs with irreversible sends and no `$tries`, backoff or
uniqueness; outside calls without an explicit timeout.

## 16. Baseline, ratchet, debt and exceptions

### 16.1 Baseline and ratchet

Counting is too weak. "17 before, 17 after" can hide one fixed and one new
violation, and the new one is in the code the agent just wrote. So identity
rules ratchet by **fingerprint**, and only drift measures ratchet by count.

**Fingerprint:** rule id, entry point, the innermost app symbol
(`class::method`), effect class and target. No line numbers, so edits around
a finding do not make it "new".

**For each change**, with findings computed at the base commit and at the
change's commit, by the same rule versions:

```
new      = head − base
fixed    = base − head
existing = head ∩ base
moved    = a fixed and a new finding with the same rule, entry point, effect and target
```

Only `new` findings are judged at their rule's outcome. `moved` is a note.
`fixed` is recorded as an improvement and leaves the baseline, so it cannot
come back without a new decision. This refines the rule the fault engine
already follows ("only lines the change adds are held against it") without
replacing it.

**The baseline is never a file in the app.** A PHPStan-style baseline file in
the repository is one the agent can edit. The control plane computes the
baseline from the base commit, or stores it by commit.

**Rule upgrades do not punish old code.** When a platform rule gets a new
version, every project is re-baselined with it. Findings the upgrade reveals
are `existing`, and they show up as things to tidy, not as send backs on
someone's unrelated next change.

**Drift measures** ratchet per area, never globally, so improving one area
cannot hide worsening in another. Each measure has a ceiling per area. A
change that raises it beyond a tolerance is a note (strict mode: send back
when it exceeds a larger threshold). When the measure improves, the ceiling
moves down to the new value plus a small slack, so noise does not lock it.

### 16.2 Debt

Grandfathered findings and accepted exceptions are one thing: a debt item.

```yaml
fingerprint: 9f2c…
rule: BND-CTRL-HTTP@2
where: App\Http\Controllers\WebhookController::handle
origin: accepted # grandfathered (before Determinism) | accepted (an exception)
reason: 'Stripe webhooks must answer within 10 s; the call is a read.'
approved_by: developer # owner | developer | platform
introduced_by: change 4812 # or "before Determinism"
created_at: 2026-10-01
review_when: next_change_to_symbol # or a date, or "before publishing", or "when area Billing changes"
status: open # open | repaid | lapsed
```

**Debt never blocks a change that does not touch it.** When a review
condition is met, the exception lapses only for changes that touch its
symbol. A date that passes adds the item to the developer's list; it does
not break someone else's work at midnight.

**Budgets** keep exceptions honest: above a project's limit of open accepted
exceptions at send-back level (for example 10), new exceptions need a
developer, not the owner.

**Trends** are counts of open debt per rule family and area over time. They
are shown to developers, not to the owner.

### 16.3 Exceptions

An exception is an explicit cast, and like a cast it is narrow and named:

- It covers **one fingerprint**, never a whole rule. Relaxing a rule for a
  project is a rule change (§11).
- It is **requested** by the agent through `propose_exception(fingerprint,
reason)`, or by a person. It is **granted** only by a person at the level
  the rule requires.
- It **lives in the control plane**, not in a code comment. A comment can
  explain an exception but cannot grant one, because the agent writes
  comments.
- It always has a **review condition** (§16.2).

The owner sees it in plain words: "Your app answers the payment company
directly here, which we usually keep in one place. A developer said this is
fine because the payment company needs a fast answer. We will look again
the next time this part changes."

## 17. Scores

A single "architecture score: 84" is false precision. It hides which
boundary moved, it invites the agent to optimize the number, and it makes
the owner trust a figure nobody can explain. So there is **no composite
score**, and no number reaches the owner.

Developers see counts by family and area with a trend: open findings by
rule family, open debt, effects per request, cross-area dependencies.
Measures feed the ratchet; they are never a goal and never a release input.
The owner sees proof lines, as today ("Checking who may do something never
changes what your app saves").

## 18. Static and runtime: where each one fails

**Static analysis is unreliable; runtime evidence is needed for:** facades
and container resolution beyond Larastan's reach; `__call`, macros and
dynamic properties; observers, listeners and global scopes registered at
boot; event discovery; what packages do inside (Cashier calls Stripe; a
logger calls an outside service); Livewire's lifecycle; accessors that run
during serialization; lazy loading; `dispatch` of a class name in a
variable; the queue being sync or async in the real configuration;
middleware order.

**Runtime evidence is insufficient; static constraints are needed for:**
paths no test reaches (absence of evidence is not evidence); rare and error
branches; production-only configuration (real queue, real mail); secrets;
injection patterns; edits to protected inputs; dependency rules that must
hold on every path ("no Stripe outside Billing"); the presence of
authorization on a new route; migration safety (existing, §12); `env()`
outside config; schedule definitions; anything that only happens after
deployment.

**The rule of thumb:** runtime proves, static predicts. A static finding
asks for an experiment; a runtime finding settles it; neither one's silence
proves anything.

## 19. Tooling worth investigating

None of these decides the design; each could carry part of it.

- **Larastan / PHPStan:** custom rules and collectors for effect sites and
  the call graph; PHPStan's purity analysis (impure points, `@phpstan-pure`)
  as a starting point for effect inference.
- **Pest `arch()`**, **Deptrac**, **PHPArkitect:** dependency and shape rules.
  Pest `arch()` is already in §12; the others are richer for layer rules.
- **nikic/php-parser:** custom static passes where PHPStan rules are awkward.
- **Rector (with rector-laravel):** fixes paired with detectors (§10).
- **Laravel's own introspection:** `route:list --json`, `event:list`,
  `model:show --json` (observers, relations), `schedule:list`, `about --json`.
- **Laravel Telescope's watchers** (gates, queries, jobs, mail, cache, HTTP
  client, events) as a reference for hooks, not as a dependency.
- **Laravel's own test fakes and guards:** `Http::preventStrayRequests`,
  `Queue::fake` semantics, `ShouldDispatchAfterCommit`,
  `ShouldQueueAfterCommit`, `ShouldBeUnique`.
- **Infection and Pest `--mutate`:** the mutation engine.
- **OpenTelemetry PHP:** a phase as a span; a reference for context
  propagation.
- **Psalm taint analysis:** injection flows from request input.
- **gitleaks / trufflehog:** secrets in the diff.
- **Jepsen / Elle ideas:** histories and anomalies for the concurrency engine.

## 20. MVP and long term

**MVP: one loop in both directions, on what exists.**

1. Recorder: app frames and phase per effect (session 19).
2. `AppBoundaries`: pure functions over the trace, beside `AppTraces`, for
   the phase rules in §15 (authorization, validation, rendering, boot), with
   proven certainty only. Outcomes through the existing reviewer path.
3. The gate table in `config/builder.php` (`boundaries`), with the outcome
   per rule. The ratchet stays "only lines the change adds".
4. Architecture → chaos: one new derived experiment, the job that runs twice
   (session 19's next fault kind), and the planner's priority order passed to
   `AppFaults::points()`.
5. Protected inputs: the list, and a refuse for feature changes that edit
   them.
6. Owner: one proof line per clean family; plain send-back text for the
   agent.

No debt store, no exceptions beyond "the plan asks for exactly that", no
static effect inference, no strict mode, no scores. This tests the core
claim: phase rules from runtime evidence catch real defects with few false
alarms.

**Next, in order:** static effect summaries and the role index; fingerprints
and a baseline per commit in the control plane; exceptions and debt with
review conditions; containment rules confirmed by developers, the first
graduation of developer guidance into guardrails (§31.2); strict mode per
area; the experiments in §9 (ambiguous timeout, stale read, listener
reorder); operation facts for other engines; convention inference that
proposes shape rules from the codebase.

## 21. Failure modes of this design

- **Noise.** Too many notes and the owner stops reading, and the agent loops
  on send backs. Mitigation: proven-only send backs, new code only, drift
  never blocks.
- **Legitimate patterns flagged.** Marking notifications read on open,
  updating `last_seen_at` in middleware, counting views. Mitigation:
  middleware writes are allowed; the plan can ask for a write on read;
  measure the false alarm rate on a corpus first (§22).
- **Gaming.** The agent hides effects: fakes, catch-all blocks, raw
  `DB::statement`, effects moved into closures. Mitigation: blinding
  signals (§11); runtime phases do not care where code lives.
- **Fingerprint churn.** A refactor renames methods and everything looks new.
  Mitigation: move matching; measure churn on real histories.
- **Baseline legitimizes bad code.** The ratchet stops things getting worse,
  not bad things staying. Mitigation: debt is visible and reviewed when
  touched; refactor changes repay it.
- **Exceptions pile up.** Mitigation: budgets, review conditions, developer
  approval above the budget.
- **False safety.** "No finding" read as "safe". Mitigation: unseen and
  missed are first-class results in every output.
- **Framework drift.** The phase table depends on framework internals.
  Mitigation: versioned per Laravel major version, with fixtures.
- **Packages blamed for their own effects.** Mitigation: rules match the
  nearest app frame; package effects count only through the app code that
  called them.
- **Cost and waiting.** Reruns add time. Mitigation: one recording, budgeted
  experiments, heavy engines at publishing.
- **Architecture for architecture's sake** (§27.3). Mitigation: the MVP is a
  few pure functions over a recording that already exists.
- **Trade secrets.** Rules and instrumentation must never land in the
  customer's repository. Mitigation: everything lives in the control plane
  and in the neutral recorder.

## 22. Open research questions

1. Is phase classification from backtraces stable across Laravel 11–13, and
   what does it cost per effect?
2. What is the false alarm rate of the phase rules on real apps (the fixture,
   imported apps, open-source Laravel apps)?
3. How stable are fingerprints across real refactors?
4. How many entry points can Larastan-based effect inference resolve fully,
   and how often does "possible" turn out to be real?
5. How often do the generic properties in §9 hold without declared
   invariants, and how often do they raise false alarms?
6. Can a stale read be injected deterministically between a read and a
   write in one PHP process (the recorder changes the row at the pause
   point)?
7. Does telling the agent the effect contract and the planned experiments up
   front reduce repairs per kept change, compared with send backs alone?
8. When is a project convention true enough to propose as a shape rule (for
   example "90% of mutating routes go through `app/Actions`")?
9. Can owners make exception decisions in plain words, or do these always
   need a developer?
10. **An owner decision:** should the recorder relax "no values" to keep
    row identity (for example a hash of the primary key)? Lost update and a
    precise "job runs twice" need it. Without it, both stay judged by shape
    and often end unknown.
