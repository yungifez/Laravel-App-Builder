# Architecture

**Version 33.** This document consolidates the direction in [direction/](direction/)
into one architecture. Version 7 adds the "convention over generation"
reassessment ([§24](#24-convention-over-generation-reassessment)), aligns the
product ontology, removes implementation details from the product model, and
replaces Project Memory with hierarchical Project Context and discovery
([§7](#7-project-context-and-discovery)). Version 8 re-justifies the design
against observed user complaints: it adds outcome measurement and falsification
([§25](#25-outcomes-measurement-and-falsification)), makes the Context Compiler
a named subsystem ([§8](#8-context-compiler)), splits behaviour diffs into
requested and unexpected changes, and postpones what exists mainly for
elegance. Version 9 adds precedents to discovery
([§7](#precedents-showing-what-is-possible)): questions offer the common ways
products solve a problem, with a recommendation grounded in the user's own
context and "Something different" always available; references to other
products are stored by the aspect the user meant. **Version 10 freezes the
architecture and defines V0 ([§26](#26-v0-what-we-build-now-version-10)):
selective context as Markdown files in the application, advisory Effects, a
plain-language behaviour review and verification, with every other subsystem
deferred until a real failure asks for it. For V0, §26 wins over the sections
before it.** Version 11 adds decisions before generation
([§26.9](#269-decisions-before-generation-version-11)): the cheapest reliable
decision first, failing safe to the baseline. Version 12 adds audits and adversarial reviews
([§26.10](#2610-audits-and-adversarial-reviews-version-12)), with evidence on every
finding; V0 builds only the deterministic quick health check. Version 13 reserves room for Laravel-native active
testing ([§26.11](#2611-laravel-native-active-testing-versions-1314-later-stage)):
probes generated as tests from the framework's own routes, rules, policies and
factories. Version 15 makes visual editing concrete
([§26.12](#2612-visual-properties-on-a-tailwind-substrate-version-15)):
human-readable properties, written as clean Tailwind with the app's own
merge, no model call. **Version 16 sets the V1 plan ([§27](#27-v1-plan-version-16)), which wins
over §21 and §26 for V1.** Version 17 adds the Grandma-first rule and its
translation layer ([§28](#28-grandma-first-the-translation-layer-version-17)): no
internal noun in the default UI, and the differentiators that follow from
maintained product understanding, ranked for after V1. Version 18 frames people
in the loop as leverage, not fallback ([§29](#29-human-judgment-where-it-has-leverage-version-18)):
developer guidance lives in the notes and reaches every later change. Version 19 names the
product a software stewardship platform ([§30](#30-software-stewardship-version-19)):
three primitives (notes, Change Records, evidence) and no new entities. Version 20 adds
that complexity moves upward ([§31](#31-complexity-moves-upward-version-20)): knowledge
is not enforcement, constraints graduate into checks, and human interventions are
measured. Version 21 makes tests the bridge between product meaning and code
([direction 22](direction/22-tests-as-the-semantic-bridge.md)): Effects gain
evidence from test execution ([§6](#effects), [§26.4](#264-effects)), verification is
scoped by risk and never by diff size, and publishing always runs the full checks
([§12](#12-verification)). Version 22 makes assumptions first-class inside and
one question outside ([direction 23](direction/23-assumptions.md),
[§7](#when-to-ask-the-decision-check)): existing assumptions are questioned before new
ones, evidence resolves what it can, and the owner sees the one question where
being wrong matters most. Version 23 makes the engine a lightweight goal-directed
control plane over a real Laravel application
([direction 24](direction/24-goal-directed-control-plane.md)): Laravel is most of
the formal system ([§1](#1-principles), [§4](#4-two-ontologies)), every change runs
a closed loop that compares the result with the goal ([§9](#9-the-change-pipeline)),
and symbolic planners and duplicated models of the app are ruled out
([§20](#20-deliberately-not-built-yet)). Version 24 makes pricing Grandma-first
([direction 25](direction/25-pricing-for-grandma.md), [§16](#16-model-gateway-and-credentials)):
one unified price by default, and pay as you go for power users. Version 25 makes V1
the smallest complete evolution loop ([direction 26](direction/26-evolution-loop-and-design-contract.md)):
publishing ends in smoke checks and a plain health state, product concepts keep stable
keys shared by tests and Change Records, soft requirements climb the same ladder from
checks to judgment ([§12](#12-verification)), and each app gets a design contract
([§7](#design-context)); the demo is evolution, not generation ([§27](#27-v1-plan-version-16)). Version 26 moves
every workspace into its own disposable box from any provider behind the runtime contract
([direction 27](direction/27-workspace-boxes.md), [§11](#adapters)): keys, git credentials
and our instructions stay outside the box, and local development only stands in for it. Version 27 puts
publishing behind the same kind of contract ([direction 28](direction/28-publishing-hosts.md),
[§27.1](#271-what-proves-the-differentiation)): any host can serve a published app, and Grandma's apps
publish to Laravel Cloud by default. Version 28 adds delegation by certainty
([direction 29](direction/29-delegation.md), [§9](#delegation-by-certainty)): for reliability,
the planner states the data shape once, deterministic scaffolds build the parts
it fixes, one coding agent keeps every seam, and small models only repair what a
local check can judge. Version 29 ties compatibility to whether anyone uses the app
([direction 30](direction/30-backwards-compatibility.md), [§9](#compatibility-follows-the-apps-life)):
an app that has never been online is changed in place, and a live app moves its
data forward with a migration before it keeps an old way alongside the new one.
The owner sees this as a switch and can set it either way. Version 30 adds
ready-made services ([§17](#outside-services-and-their-keys)): the owner pastes
the keys for payments or email, and the app is changed to use them.
Version 31 puts every worker, ours or the owner's own Claude Code or Codex,
behind one boundary ([direction 31](direction/31-external-workers.md),
[§11](#workers-one-boundary-for-ours-and-theirs)): a brief that is written
to be read, and a few MCP tools scoped to one change by a token. The secret
is our machinery, not the owner's knowledge of their own app.
Version 32 makes more of the proof independent of the model
([direction 32](direction/32-deterministic-verification-engines.md),
[§12](#12-verification)): the app is run with and without the change, so a
test the change added counts only when it fails without the change, and what
the app saves and sends is recorded while its tests run. One failure at a
time is then caused where the change sends or saves, to show what the app
leaves behind. None of this keeps the owner waiting.
Version 33 adds the boundary rules
([direction 33](direction/33-architecture-boundaries-and-chaos.md),
[§12](#12-verification)): the recorder names the part of the request each
effect ran in, and the change's code may not save or send while Laravel
checks who may act, checks the input or builds the answer. The files that
decide how the app is checked are protected from coding workers.
When they disagree, the direction documents state intent
and this document states the current design; raise the disagreement rather than
silently following either.

- [Principles](#1-principles)
- [Positioning: convention over generation](#2-positioning-convention-over-generation)
- [Users and progressive disclosure](#3-users-and-progressive-disclosure)
- [Two ontologies](#4-two-ontologies)
- [System overview](#5-system-overview)
- [Product Behavior Graph](#6-product-behavior-graph)
- [Project Context and discovery](#7-project-context-and-discovery)
- [Context Compiler](#8-context-compiler)
- [The change pipeline](#9-the-change-pipeline)
- [Deterministic engines](#10-deterministic-engines)
- [Execution: agents, runtimes and routing](#11-execution-agents-runtimes-and-routing)
- [Verification](#12-verification)
- [Packages: trust, understanding and adapters](#13-packages-trust-understanding-and-adapters)
- [Visual editor](#14-visual-editor)
- [Previews](#15-previews)
- [Model gateway and credentials](#16-model-gateway-and-credentials)
- [Safety and approvals](#17-safety-and-approvals)
- [Imported applications](#18-imported-applications)
- [Learning and privacy](#19-learning-and-privacy)
- [Deliberately not built yet](#20-deliberately-not-built-yet)
- [Status and staged plan](#21-status-and-staged-plan)
- [Risks](#22-risks)
- [Open decisions](#23-open-decisions)
- [Convention over generation: reassessment](#24-convention-over-generation-reassessment)
- [Outcomes, measurement and falsification](#25-outcomes-measurement-and-falsification)
- [V0: what we build now](#26-v0-what-we-build-now-version-10)
- [V1 plan](#27-v1-plan-version-16)
- [Grandma first: the translation layer](#28-grandma-first-the-translation-layer-version-17)
- [Human judgment where it has leverage](#29-human-judgment-where-it-has-leverage-version-18)
- [Software stewardship](#30-software-stewardship-version-19)
- [Complexity moves upward](#31-complexity-moves-upward-version-20)

## 1. Principles

1. **Convention over generation.** Use the framework, then a first-party package,
   then a trusted package, then our own capability; generate only what is
   specific, ambiguous or novel. The order of preference for any change is
   **reuse → configure → compose → transform → generate**. The platform should
   generate less over time.
2. **Convention over questioning.** Never ask the user an engineering decision our
   conventions already make. Ask about the product only when a wrong assumption
   costs more than the interruption.
3. **Generative models create new information; deterministic tooling propagates
   known information.** Before choosing a model, ask whether a model is needed.
   Use a model to resolve ambiguity once; use deterministic systems to enforce
   and verify what was resolved. When software can answer a question (which
   routes exist, which policy protects an action, which tests run this code,
   what changed), never ask a model to infer it.
4. **Our orchestration works at the product level; the agent's works at the
   engineering level.** We decide what changes, what is already known, what
   context and limits apply, and whether the result is acceptable. The agent
   decides how to implement a semantic change. We never micromanage its steps.
5. **The repository is the source of truth for behaviour.** Everything we persist
   about what an application does is derived from its code. Product intent lives
   in Project Context, with provenance, and is exported to the repository.
6. **Generated applications stay ordinary Laravel applications.** Everything we
   add to a customer repository works without us: Rector sets, Pest invariants,
   PHPStan rules, Boost guidelines, the exported Project Context.
   `git clone && composer install && npm install && php artisan migrate && npm run build`
   always works.
7. **No single model provider is the intelligence layer.** Providers are
   replaceable execution engines chosen by evidence.
8. **Every repeated piece of generative work is a candidate for infrastructure:**
   a rule, package, adapter, verifier or template.
9. **Say "unknown" rather than guess.** Every user-visible statement carries its
   provenance.
10. **Laravel is most of the formal system.** Routes, middleware, policies and
    gates, form requests, models and relationships, migrations, events, jobs,
    commands, container bindings, configuration and tests are the source of
    implementation truth. Real software structure outranks our interpretation
    of it. The product layer holds only what Laravel cannot know: the goal, why
    something exists, what is assumed, what the owner decided, what must stay
    true, which behaviours matter to the owner, and what changed in meaning.

## 2. Positioning: convention over generation

- **Public identity:** software built through opinionated, understandable,
  proven patterns. Customers build _their application_; they never need to
  learn that it is Laravel.
- **Internal advantage:** Laravel is the substrate. Its conventions for routing,
  authentication, authorization, validation, data, migrations, queues, events,
  notifications, scheduling, files, caching, realtime, APIs, testing,
  dependency injection, configuration and deployment let us constrain a problem
  before any model sees it. A generic coding agent has to discover an
  architecture; ours is already known.
- **For developers, Laravel is a selling point, not a secret.** "You own a normal
  Laravel application" is the portability promise, and it is how a technical
  buyer trusts the product. Disclosure depth, not concealment, decides how much
  Laravel a user sees.
- **Broad outcomes, constrained implementation.** Marketplaces, internal tools,
  booking systems, APIs, workflow and content products, integration services,
  realtime and admin systems are all in scope. How they are built is narrow:
  one stack, one set of conventions, trusted capabilities.
- **A strong default path with an unrestricted code escape hatch, not a template
  catalogue.** Capabilities and conventions are the default; an agent can always
  write ordinary code when a requirement does not fit (see §24.7).
- **Two ladders, not one.** Product precedents (§7) decide _what the product
  should do_; the reuse ladder decides _how to build it_. A precedent is not an
  implementation rung between our capabilities and generation: a chosen
  precedent points into the reuse ladder (`realised_by`) when we have a
  capability for it, and is otherwise built by convention. Keeping them apart
  stops precedents from turning into templates.
- **Stronger models should reduce our orchestration, not increase it.** Our
  durable work is what models do not own: product intent, observability,
  package trust and lifecycle, capability reuse, deterministic transformations,
  behaviour-level review, safe production operations, visual selection, empirical
  routing and framework-specific verification.
- **Strategic test** for any major decision: does it make the application easier
  to understand, reduce reinvention, make future changes safer, use Laravel's
  structure, stay useful if models get much smarter, help both users, and keep
  ordinary source code as the final artifact? If several answers are no,
  challenge it.
- **The measure of the idea:** the share of each change made without a model,
  and the amount of generated foundation code per feature, should both move in
  the right direction over time (see [Learning and privacy](#19-learning-and-privacy)).

## 3. Users and progressive disclosure

One system, one application model, two very different users. No modes.

- **Target A, the non-technical owner.** Builds, inspects, understands, approves
  and maintains an application without learning any software concept. Audits
  behaviour by browsing, not by asking an AI.
- **Target B, the power vibe coder.** Wants visibility and control, but still
  wants AI to do most of the implementation. Drills from behaviour to rules,
  data, permissions, where it happens, implementation, tests and source without
  leaving the product.

Disclosure levels, rendered from the same records:

1. **What this does**: plain language.
2. **How it behaves**: rules, who can do it, what happens next.
3. **Data and permissions**: what it reads and changes, who has access, which
   outside services are involved, where it happens.
4. **Technical implementation**: route, policy, action, jobs, packages, tests.
5. **Source**: read-only code, history, "Ask AI to change".

Depth preference is remembered per user from what they expand. Provenance
badges appear at every level: "✓ checked by a test", "from your app's code",
"our description (may be out of date)", "unknown".

Top-level navigation is framework-free and is a set of queries over the Product
Behavior Graph: **What people can do · What happens automatically · Your data ·
People & permissions · Connections · History**.

## 4. Two ontologies

The user-facing model is independent of Laravel; everything beneath it is
aggressively Laravel-native. The seam between them is deliberately thin.

| Product ontology (what users see) | User-facing word                        | Implementation ontology (Laravel substrate)                                                      |
| --------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Application                       | your app                                | repository, Composer and npm manifests                                                           |
| Capability                        | (its own name: "Appointments")          | native features, first-party and trusted packages, our packages, custom code                     |
| Behavior                          | (its own name: "Cancel an appointment") | a handler plus its effects: controller action, job, listener, command, scheduled task            |
| Actor                             | person or role                          | guards, policies, gates, role and permission data                                                |
| Data                              | your data ("Customers")                 | Eloquent models, tables, relationships, migrations                                               |
| Rule                              | rule                                    | config values, enums, validation, policy conditions, tests                                       |
| Automation                        | happens automatically                   | schedule entries, queued jobs, listeners (a view over behaviours, not a separate record)         |
| Connection                        | connection ("Stripe")                   | HTTP clients, SDK packages, webhook routes, `config/services.php`                                |
| Surface                           | screen, button, "other systems can…"    | Inertia pages, Vue components, Wayfinder-bound actions, API and webhook routes, schedule entries |

The mapping between the two columns is part of the product's value; the layers
are never collapsed.

Rules for the seam:

- The Product Behavior Graph schema, the product UI and Project Context never
  reference Laravel types. Keys are opaque and stable (`cancel-appointment`),
  never route names or class names; kinds are stack-neutral; Laravel
  identifiers appear only in implementation references and in derivation data
  kept outside product records.
- Laravel knowledge lives in one layer, the **Laravel stack profile**: the
  introspector, the extractors that turn Laravel facts into graph fields, the
  verification checks, Rector sets, templates and Boost guidelines.
- Implementation references are typed strings owned by the stack profile
  (`laravel.route:appointments.cancel`,
  `laravel.policy:App\Policies\AppointmentPolicy@cancel`). Level 4 renders them
  through the profile.
- **Do not formalize what Laravel already formalizes.** A product rule
  ("Managers cannot refund over $500") points at the policy or gate that
  enforces it and the tests that prove it; there is no second authorization
  model. The same holds for validation (form requests), data (migrations,
  schema introspection, relationships), background work (events, listeners,
  jobs, configuration) and impact (test impact analysis, imports, the Vite
  module graph, listeners, bindings). A copy is kept only to present it.
- **Do not build a generic multi-framework system.** There is exactly one stack
  profile. The seam exists so that the product model does not become
  Laravel-shaped, not so that we can swap stacks soon.

## 5. System overview

```
USER
  │
PRODUCT LAYER        views over the Product Behavior Graph, behaviour diffs,
  │                  visual editor, previews, approvals, history
CONTROL PLANE        (Laravel; this repository)
  ├── Product Behavior Graph        persistent, derived, small
  ├── Project Context               intent, decisions, design, invariants; exported to the app repo
  ├── Change pipeline               classify → operations → execute → verify → explain
  ├── Execution router              per stage, by evidence
  ├── Deterministic engines         Rector, visual edits, capability config, scaffolds
  ├── Verification policy           gates by provenance and behaviour diff
  ├── Package registry              trust levels, adapters
  ├── Model gateway + credentials   metering, budgets, keys
  └── Runs, budgets, approvals      state machine, leases, telemetry
          │
EXECUTION ADAPTERS
  ├── Agent adapter    claude-agent-sdk · openai · script   (vendor types stop here)
  └── Runtime adapter  any box provider, chosen in config · local runner
          │
PROJECT RUNTIME      reproducible workspace: repo, PHP, Composer, Node, Postgres,
                     browser, tests, preview server, the runner, builder/introspect
```

## 6. Product Behavior Graph

The persistent representation of what the application does. Small, derived from
code, rebuilt incrementally, never edited by hand.

### Contents

```
Capability   key (opaque), name, source (built-in | platform | trusted-package | custom), membership rule
  Behavior   key (opaque), kind (action | view | automation | incoming),
             name, purpose, outcome, actors[], permissions, side_effects[],
             connections[], surfaces[], impl_refs[], provenance per field
    Surface  kind (screen | control | api | webhook | schedule | trigger | operator_tool),
             human label, audience (people | other systems | external services | automatic),
             impl_ref (the route, component, schedule entry or command behind it)
Connection   key (opaque), name ("Stripe"), direction (we call it | it calls us | both)
```

Added when real cases need them, in the same document (a schema version bump,
not a new data model): `data[]` (business concepts, linked to models and tables
through implementation references), `rules[]`, `invariants[]`.

The build-cache inputs used for incremental regeneration are kept in a separate
derivation table, not in the product record, so product records contain no file
paths or class names outside `impl_refs`.

Not persisted: the chains between routes, controllers, requests, policies,
actions, models, events, listeners and jobs. Those belong to the ephemeral
engineering graph, built on demand ([Context Compiler](#8-context-compiler)).

### Behaviour identity and grouping

- A behaviour is one entry handler plus what it causes. It gets a stable key
  the first time it is discovered (a readable slug such as `booking.cancel`;
  journeys and invariants get the same kind of key, such as
  `tenant.data-isolation`). Tests name the keys they cover and Change Records
  name the keys they touch, so later runtime evidence can attach to them
  without a new mapping (direction 26). The key is only a name: what it
  refers to stays in the notes and in Laravel's own routes, policies and
  tests, never in a second model of the app. It also gets an **anchor** (route name, job class,
  schedule id or command signature) that re-finds it on each rebuild. Renames
  made by known transforms move the anchor; other re-matches are confirmed, so
  history is never silently broken.
- Deterministic grouping keeps behaviours at a human scale: a create and store
  pair is one behaviour, resource routes group by model, read-only pages fold
  into "View X".
- Capabilities use membership rules (for example route prefix `appointments.*`
  plus the `Appointment` model), so new behaviours join automatically. Package
  capabilities come from manifests and adapters; native ones from detection
  (Fortify → Identity, Cashier → Billing); custom ones are clustered
  deterministically and named once by AI, and owners can rename them.

### Where each field comes from

| Field                     | Deterministic source                                                                                                                        | AI needed                                                                           |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Surfaces                  | `route:list`, `schedule:list`, commands, webhook routes (signature middleware, package webhook controllers), Inertia pages, Wayfinder usage | A human label for unusual endpoints only; standard kinds use templates and adapters |
| Actors, permissions       | auth and guest middleware, policy abilities, role → permission data                                                                         | Only for arbitrary authorization code                                               |
| Side effects              | observed in test runs (mail, notifications, jobs, events, outbound HTTP hosts), plus a static scan                                          | No                                                                                  |
| Implementation references | routes, controller actions, FormRequests, policy methods, jobs, notifications, tests calling the route                                      | No                                                                                  |
| Data (later)              | tables written (observed), `model:show`, humanized model names                                                                              | Rarely                                                                              |
| Rules (later)             | config parameters and enums, FormRequest validation, Pest test names, Pennant flags                                                         | For rules hidden in handler or policy code                                          |
| Name, purpose, outcome    | —                                                                                                                                           | Yes, once, cached with an anchor                                                    |
| Custom capability names   | clustering by route prefix and model                                                                                                        | Yes, once                                                                           |

Three sources carry most of the value:

1. **Tests as behaviour specifications.** A Pest test named
   `it('prevents customers cancelling within 24 hours of the start')` is a
   verified business rule, mapped to behaviours through the routes it calls.
2. **Side effects observed in tests.** A dev-only listener in `builder/introspect`
   records, per test, the tables written and the mail, notifications, jobs,
   events and outbound hosts. Verification already runs the suite, so this is
   free and accurate for every tested path.
3. **Wayfinder links controls to behaviours.** A component using
   `CancelAppointmentController.store.form()` is a deterministic link from a
   button or form to a behaviour.

Generated code is steered, through our conventions and normalization, towards
being observable: business parameters in config or enums, role → permission
maps as data, tests named as business statements. That turns rules hidden in
code into deterministic facts, and makes changing them a configuration edit.

### Provenance

| Class                                          | Example                                                             | May become a hard protection                 |
| ---------------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------- |
| DERIVED (static or observed in tests)          | "requires `ProjectPolicy::invite`", "queues InvitationNotification" | Yes                                          |
| CONFIRMED (owner intent, Project Context)      | "customers cannot cancel within 48 hours"                           | Yes                                          |
| PACKAGE CONTRACT (trusted manifest or adapter) | "a team always has an owner"                                        | Yes                                          |
| AI INTERPRETATION                              | "invites someone to collaborate"                                    | No; display only, revisable                  |
| PROPOSED                                       | "tenants never see each other's bookings?"                          | No, until confirmed or matched to a contract |

`unknown` and `not verified` are valid values and are shown as such.

### Freshness and incremental regeneration

Only annotations can go stale; derived fields are recomputed from code for every
snapshot. Each behaviour stores a flat `inputs[]` list (files and config keys
with hashes), which works like a build cache:

```
new commit
 1. discovery, always in full (seconds): route:list, schedule:list, commands,
    policy map, Wayfinder map, config hash → behaviour keys and surfaces
 2. per behaviour: hash inputs[]; unchanged → reuse the previous record;
    changed or new → re-run that behaviour's extractors
 3. side effects from this commit's verification test run
    (otherwise carried forward, marked "observed at <commit>")
 4. annotations: attach those whose anchor matches; queue refresh for the rest
 5. assemble the snapshot; diff against the parent snapshot
```

Record states: `current` → `dirty` (an input changed) → `refreshed`
(re-extracted or re-annotated) → `verified` (test observations at this commit).

Annotations (names, purposes, AI-described rules) store an **anchor**: a hash of
the normalized code they describe plus the fields they summarized. On mismatch,
an AI annotation is marked stale and refreshed in a batch by a cheap model; an
agent that changed a behaviour updates its annotation in the same run; an
owner-pinned annotation is never overwritten and is flagged for review instead.
A new extractor version triggers a full rebuild; annotations with matching
anchors carry over.

### Behaviour diffs

Compare two snapshots key by key: added, removed and changed behaviours, and
field-level changes rendered through templates:

> **Invite a teammate**
> Previously: owners and administrators could invite people.
> Now: only owners can invite people. ✓ checked by "admins cannot invite members"

The structured part of the diff needs no model. The behaviour diff is the
primary review artifact; source diffs sit one level down. It also drives
approval gates (new email, new charge, new outside service, data deletion).

**Requested vs. unexpected changes.** The interpret stage declares which
behaviours a request targets. Every behaviour change outside that set is shown
separately, deterministically:

> **Requested:** managers can now invite contractors.
> **Other observed changes:** none detected.

or

> ⚠ **Another behaviour also changed:** customer cancellation cut-off, 48 hours →
> 24 hours. This does not appear related to your request. [Keep] [Undo]

This makes agent mistakes visible in product language instead of promising they
will not happen. Its limit is stated honestly: it catches changes the graph can
see (who may do what, rules from config and tests, side effects, surfaces), not
subtle logic bugs, which only tests catch.

### Effects

Advisory relationships between behaviours and capabilities ("inviting a member
may also affect billing"), with a strength, a reason and a source. They are
relevance hints for context, review and verification, never a dependency graph.
V0 keeps them in capability files; see [§26.4](#264-effects).

Each Effect says why we believe it, from one of four kinds of evidence:

- **Observed:** test execution. A behaviour owns tests; test impact analysis
  (Pest TIA) records what each test executes; code changed for one behaviour
  selects tests that belong to another. This is the strongest kind, because it
  comes from running the application, not from a model.
- **Static:** framework structure (route → controller, policy → model,
  event → listener, action → job, component → imported component).
- **Historical:** accepted changes that repeatedly touched both areas.
- **Product semantic:** the owner, an expert or a model says the two may
  interact. Useful, but the weakest.

Observed evidence only covers what tests exercise. A missing test hides a
relationship, so the absence of evidence never means "no effect" (§12).

### Storage

Postgres, relational tables plus `jsonb`. No graph database: queries are at most
two hops, and diffs are key-by-key comparisons.

```sql
app_snapshots   (id, project_id, commit_sha, parent_id, extractor_version, status, created_at)
graph_nodes     (hash pk, project_id, kind, key, schema_version, body jsonb, created_at)  -- immutable, content-addressed
snapshot_nodes  (snapshot_id, node_hash)                                                  -- which records a snapshot contains
annotations     (id, project_id, subject_key, field, text, source, anchor_hash, model,
                 pinned, stale_since, created_at)                                         -- durable, they cost money
derivations     (snapshot_id, behavior_key, anchor, inputs jsonb, input_hash)            -- build cache, outside product records
surface_index   (snapshot_id, behavior_key, kind, technical_id, component_path,
                 template_line, wayfinder_action, audience)                               -- derived, disposable
```

`body` is validated by versioned PHP value objects. Content addressing means a
snapshot only stores what changed, and history and diffs come free. Old
snapshots are pruned, keeping `main`'s history and each run's base and
candidate.

## 7. Project Context and discovery

Project Context holds what the user intends; the Product Behavior Graph holds
what the application does. Discovery fills Project Context through natural,
contextual questions over time, never through a questionnaire, and never in
project-management vocabulary. The goal is the feeling "this understands what I
am building", not "this made me a product manager".

> **Version 10:** V0 stores context as Markdown files, kept in our database
> and copied into each workspace
> ([§26.3](#263-context-as-markdown-in-the-application)). The
> table below is the later stage, built only when Markdown limits us.

### Schema (V0)

One append-only table. The conceptual model matters more than the storage; add
specialised tables only when evidence demands them.

```sql
context_entries (
  id, project_id,
  scope_type,     -- application | capability | behavior | surface
  scope_key,      -- null for application; otherwise a Product Behavior Graph key
  category,       -- goal | user | real_world | workflow | terminology | design | rule
                  -- | constraint | decision | rejected | future_direction | open_question
                  -- | reference
  key,            -- a stable slug, e.g. cleaner_assignment, density, org_term
  value jsonb,    -- {text, structured?} e.g. {"text": "…", "structured": {"hours": 48}}
  source,         -- confirmed | derived | package_contract | ai_interpretation | proposed
  status,         -- active | superseded | retracted | open
  supersedes_id, overrides_id,
  origin,         -- conversation message, run, selection, review
  created_by, created_at
)
```

The **current value** of a key at a scope is its latest `active` entry. History
is the chain of `supersedes_id`. Open questions are entries with category
`open_question` and status `open`; answering one writes a `confirmed` entry.

Project Context is authoritative in the control plane, because it needs
provenance, statuses and history, and is **exported to the application
repository** as readable Markdown (`docs/product/`, generated, with a note that
edits there are imported back as proposals). The app stays self-describing
outside the platform.

### Inheritance and overrides

- Scopes form a chain: application → capability → behaviour or surface. The
  chain comes from the Product Behavior Graph: a behaviour belongs to a
  capability; a surface belongs to a behaviour or screen.
- **Resolution** walks from the most specific scope upwards; the first active
  entry for a key wins. A new screen therefore inherits "large controls, low
  density" and "organizations are called Clinics" with no extra work.
- **Categories that inherit downward:** terminology, design, users, constraints,
  goals. **Categories that stay local:** decisions and rules, which apply to
  their scope and everything beneath it but never spread sideways.
- **An override** is an entry with the same key at a lower scope, pointing at the
  entry it overrides (`overrides_id`). Overrides are shown as such ("Reporting:
  higher density, overriding the app default"), so inheritance never becomes
  rigidity and never hides.
- Constraints ("what must never happen") can only be overridden by a confirmed
  entry.

### When to ask: the decision check

In the interpret stage, the planner lists the product decisions the task depends
on. For each one it states whether Project Context answers it, the default it
would choose, which consequence categories a wrong guess touches, and whether a
prototype would make the question easier to answer.

**Existing assumptions come first.** Before it looks for new ambiguity, the
planner checks, in order: recorded assumptions relevant to the task, those the
request contradicts, those that became more consequential, and those more of
the product now depends on. Only then does it look for new ones. "Earlier I
was working on the assumption that each customer belongs to one location. This
feature may change that" feels like continuity; a fresh question does not.

**Evidence before questions.** An assumption that code, tests, framework
introspection, test impact analysis or earlier Change Records can settle is
settled that way and becomes a fact. The owner is never asked what the
application can answer.

**Priority is consequence, not uncertainty:** consequence if wrong × difficulty
to reverse × relevance to this task, with the model's uncertainty only as a
modifier. A deterministic gate then decides:

| Situation                                                                                                                                            | Action                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Answered by context                                                                                                                                  | Use it. Never re-ask unless the user reopens the decision.                                                                                                          |
| Engineering decision (controllers, queues, validation, migrations, policies, tests)                                                                  | Decide by convention. Never ask.                                                                                                                                    |
| Unanswered, wrong guess touches data model, money, permissions, destructive behaviour, external integrations, legal expectations or a major workflow | **Ask before building.** One concise question, multiple choice with a recommended default.                                                                          |
| Unanswered, easier to judge after seeing it, or low consequence                                                                                      | **Build with the default**, record it as `proposed`, and ask for confirmation in the review ("I set it up so cleaners are assigned automatically. Is that right?"). |
| Several high-consequence unknowns                                                                                                                    | Ask the most consequential one. The rest wait behind "Ask me more questions", one at a time.                                                                        |

The rule underneath: ask when the cost of a wrong assumption is meaningfully
greater than the cost of interrupting. The consequence categories come from the
decision's reach in the Product Behavior Graph and the change class, so the gate
is mostly mechanical; the model only proposes the decision list and defaults.
Today the planner tags its one question with what a wrong guess touches,
whether the owner could switch options later without losing data, money or
access, and whether the choice is easier to judge after trying the change. The
run stops only for a hard-to-reverse choice in one of the consequences listed
in `builder.construction.questions.ask_about`. Otherwise it builds on the
recommended option and lists that option with the change's other decisions.

### Not annoying people

- **One question by default, depth on demand.** At most one question before
  building ("One thing I want to confirm"), with "Ask me more questions" for
  owners who want the rest. No beginner and expert modes: the same page, at two
  depths.
- The same pattern serves discovery ("One thing I want to understand"),
  building ("One thing I want to confirm"), evolution ("Something we assumed
  earlier may have changed") and expert review ("These are the assumptions most
  likely to affect this decision"). They are one question: what is the most
  important thing I do not know yet?
- Always multiple choice, with a recommended option and "you decide".
- Never an engineering question; never a question already answered.
- Post-build confirmations are bundled into the change review, next to the
  behaviour diff, where the user can see what they are confirming.
- Opening discovery is at most a handful of plain questions (what are you
  building, who is it for, what do people need to get done, how does it work
  today, what is painful), then building starts.
- An owner can say "ask me less"; the gate's threshold then rises for
  medium-consequence decisions.
- Tracked metric: questions per request, which should fall as the project
  matures.

### Precedents: showing what is possible

Owners often do not know what is possible, so a good question offers the common
answers instead of asking the owner to invent one ("There are a few common ways
this works. Which is closest?"). A small **precedent library** supplies them:
product patterns, not copied applications and not implementations. Discovery
reasons over three sources: what the user told us (Project Context), what the
application does (Product Behavior Graph), and what products like it commonly
do (precedents). The user's own context always outweighs the precedent.

**Why curate at all.** Frontier models already know that booking can be
request-and-confirm or self-service, and will list the options when asked. The
library earns its place only where it adds what general model knowledge does
not:

1. options ordered by complexity, so the simplest sufficient one is recommended;
2. which decisions are **hard to reverse** (who owns a subscription shapes the
   data model; a reminder's lead time does not), which is what makes a question
   worth asking now;
3. what each option usually requires, as a checklist for the build and review
   (self-booking needs double-booking protection);
4. links to what we can already build and verify (a first-party capability, a
   package, protected tests);
5. curated, reviewed wording, so a question reads the same way to every owner
   and can be evaluated.

V0 tests whether this beats the model producing options itself (§25.4, P1; in V0 by hand-written cards first, §26.6 F). If
it does not, we keep the question format and drop the library.

#### Entry format (V0)

Platform-wide content, never per project and never mined from customer code.
The unit is a **decision** with its options; each option is a pattern. Stored
as versioned YAML files in the control-plane repository and reviewed like code:

```yaml
decision: booking.how_times_are_chosen
applies_to: { capability: booking } # or application_type, behavior, design surface
question: How should booking work?
reversibility: medium # hard | medium | easy
affects: [major_workflow, data_model] # the decision check's consequence categories
options:
    - key: request_and_confirm
      complexity: 1 # ordering only; never shown as a level
      label: Customers request a time and you confirm it.
      useful_when: You want to check every job before accepting it.
      usually_requires: [a request inbox, confirmation messages]
      tradeoffs: { pros: [simple to run], cons: [more office work] }
    - key: pick_available_times
      complexity: 2
      label: Customers only see times when someone is free, and book instantly.
      useful_when: You want less back-and-forth and staff calendars are reliable.
      usually_requires:
          [
              an availability source,
              double-booking protection,
              cancellation and rescheduling rules,
          ]
      common_extensions: [buffers, staff choice, recurring bookings, waitlist]
      realised_by: null # a capability or package key when we have one
    - key: resource_scheduling
      complexity: 3
      label: Availability also considers skills, location, travel time and buffers.
common_next_steps: [reminders, online rescheduling, cancellation policy]
sources: [internal curation]
status: curated # draft | curated | retired
```

Application-level decisions (a marketplace: who receives payment, whether the
platform holds funds) use the same format with `applies_to: {application_type}`
and are not asked up front: they wait until a request touches them.

#### How discovery uses it

1. **Retrieve by key.** In the interpret stage the planner lists the decisions
   the task depends on (the decision check). The library adds the decisions
   attached to the target capability keys and the application type,
   deterministically: the scope hierarchy is the retrieval strategy, as in §8.
   This is where unknown unknowns come from: "add subscriptions" brings in "who
   owns the subscription?" although nobody mentioned it.
2. **Drop what is known.** Remove decisions that Project Context answers, and
   those the graph shows are already settled in code.
3. **Gate.** The existing gate decides ask now or build with the default. A
   precedent's `affects` and `reversibility` feed it: hard to reverse and
   touching data, money or permissions means ask; otherwise build with the
   default and confirm in the review. The clarification budget (§25.5) still
   holds: precedents make questions better, not more numerous.
4. **Render.** Two to four options in plain language, the recommended one
   first, plus **"Something different"** (free text, always) and "You decide".
   "Show more" expands to every option, tradeoffs, requirements and related
   patterns for Target B. There is no beginner or expert mode.
5. **Recommend with a reason from the user's own context.** "Recommended:
   assign available staff automatically. Why: you said the main goal is less
   office scheduling." With no supporting entry, recommend the simplest
   sufficient option and say it can change later. The phrasing is "a common
   approach is…"; never "most companies do this" and never "this is how booking
   software works".
6. **Record.** The answer is written deterministically as a confirmed `decision`
   entry with `{precedent, option, library_version}` in `structured`.
   "Something different" stores the user's own words, with no precedent link.
7. **Brief.** The build brief gets the chosen option's `usually_requires` as a
   checklist. It does not get the alternatives: the coding agent needs the
   decision, not the menu.

#### Common next steps

After an accepted change, the review may show up to three **common next steps**
from the decision's `common_next_steps`, minus what the graph already has and
anything with a `rejected` context entry. They are optional, never built
automatically, and never a checklist of everything products like this have.
"Not now" records nothing; "Don't suggest this" writes a `rejected` entry. This
is the hidden-agile loop: build a useful slice, offer a few sensible next
options, let the owner choose.

#### References to other products

"Like Linear's issue creation" is high-information context, but not an
instruction to copy. It is stored as a Project Context entry with category
`reference`, recording what the user meant:

```json
{
    "text": "Like Linear's issue creation",
    "structured": {
        "product": "Linear",
        "about": "issue creation",
        "dimensions": ["workflow"],
        "wanted": ["fast inline creation"],
        "not_wanted": ["visual styling"]
    }
}
```

Dimensions: visual style, interaction, workflow, information architecture,
navigation, behaviour, terminology, density, animation, onboarding. When the
dimension is ambiguous and it matters, ask once ("the look, the workflow, or
both?"); otherwise infer it and store it as `proposed` for confirmation in the
review. The compiler includes a reference only when its dimensions match the
work, and agents receive the wanted aspects in words, never "copy Product X".
We never reproduce another product's branding or assets. V0 does not fetch or
screenshot referenced products.

#### Guardrails

- **Evidence, not requirements.** Nothing is enforced from a precedent, and
  "Something different" is always available: a fast path, never the only path.
- **Quality.** Every decision file has sources and a curation status; only
  `curated` files are shown to owners, `draft` files are used only in
  evaluation. A file whose options are often replaced by "Something different"
  or reversed later is flagged for review.
- **No bloat.** Two to four options, at most three next steps, and only for the
  capability being touched.
- **Privacy.** No mining of customer code. Aggregate, anonymous signals (which
  options owners choose) only under §19's consent rules, and not in V0.

#### V0

Version 10: hand-written precedent cards in owner sessions come first (§26.6,
F); the files below follow only if the cards help. Then about 10–15
hand-curated decision files for the pilot's domains only: booking
and scheduling, subscriptions and billing, team membership and invitations. No
database table, no editing interface, no retrieval beyond keys, no sources
pipeline.

### Provenance

Only the user makes intent. An answer, an explicit statement or an approval of a
listed assumption is `confirmed`. A default the platform chose is `proposed`
until the user confirms it, and is shown as "assumed". Anything the model
inferred from conversation is `ai_interpretation` and never drives hard gates.
Facts derived from the code belong in the Product Behavior Graph, not in Project
Context. Package guarantees are `package_contract`. Not every sentence becomes
memory: only durable, product-relevant statements are written.

**Every statement keeps its kind,** so inferences never drift into truth:
_fact_ (observed or verified: `derived`), _decision_ (the owner chose:
`confirmed`), _assumption_ (treated as true for now: `proposed` or
`ai_interpretation`), _guidance_ (a developer recommends), _invariant_ (must
stay true: a confirmed `constraint`), and _effect_ (may interact). The owner
never sees these words.

**An assumption's life** is unconfirmed → confirmed (it becomes a decision),
rejected (a `retracted` entry, and the areas built on it are re-planned), or
superseded. Nothing is promoted without the owner or evidence.

**Assumption debt** is not the number of unconfirmed assumptions. It is an
important unconfirmed assumption that more and more of the product depends on,
counted from the areas and Change Records that used it. When it grows, the
assumption is raised once, before it gets harder to change: "Quick question
before this gets harder to change: can a customer ever use more than one
location?"

### Versioning

Append-only. A change of mind writes a new entry that supersedes the old one;
retracting marks it `retracted`. The latest confirmed entry drives
implementation. History is visible ("Early on: only staff create appointments.
Since 12 March: patients self-book."), and each change is also a commit in the
exported `docs/product/`.

### Intent and behaviour together

Scopes are Product Behavior Graph keys, so context attaches to real capabilities,
behaviours and screens, and a new behaviour inherits its capability's context
automatically. When a behaviour disappears, its local entries are flagged rather
than silently dropped.

Comparisons: structured rules (`{"hours": 48}`) are compared deterministically
with the graph's rules; prose rules are compared by AI only when the related
behaviour changes. The platform aims to surface missing behaviour, accidental
behaviour, permission, workflow and terminology mismatches, and design drift.

### Contradictions

Three kinds: intent against behaviour ("you said only owners manage billing, but
administrators can change payment methods"), new intent against older intent
at another scope, and a new request against an earlier assumption ("Something
we assumed earlier may have changed: your app assumed one location, and you are
adding a second"). Both appear as a plain question with two answers, "keep how
it works now" (updates intent) or "change the app to match" (starts a change),
inline on the behaviour card, in the change review, and in a short "needs your
decision" list. Neither side is ever corrected automatically.

### Design context

Design is product context in the same table (`category = design`): tone,
density, primary devices, audience, accessibility, brand. It is hierarchical
like everything else (application default → capability refinement → screen
override). Following convention over generation, whatever can become code does:
brand colours, radius and spacing scale live as Tailwind `@theme` tokens and
component defaults; "large interaction targets" becomes component size
defaults. What cannot (tone, density intent) goes into agent briefs for UI work
and sets the visual editor's defaults. The user is never asked to restate
aesthetic direction.

**Each app has a design contract** (direction 26): a design note, kept like
every other note, written in constitution form: principles, then rules,
patterns, tokens, examples and forbidden patterns. It is beefy in coverage and
short in prose, because the coder reads it on every UI change. A new app's
contract starts from the design direction the owner picks when creating it
(one of a few, shown as looks, never as a form about design). Its tokens are
the app's Tailwind `@theme`, and the coder uses them rather than inventing
values. Invariants such as "do not add a new pattern when an existing one
solves the problem" and "do not raise density unless it helps the owner's
next decision" are part of it. Parts of the contract graduate into checks
([§12](#12-verification)); what cannot be checked goes to the reviewer. The
builder's own UI follows the same form in its repository `DESIGN.md`.

### Hidden agile

| Kept internally     | As                                                                            |
| ------------------- | ----------------------------------------------------------------------------- |
| Product vision      | application goal and identity entries                                         |
| Personas            | `users` entries and Actors                                                    |
| Epics               | capabilities                                                                  |
| User stories        | behaviours                                                                    |
| Acceptance criteria | "what should happen when this works" statements, which become protected tests |
| Edge cases          | "what could go wrong" questions, which become tests                           |
| Definition of done  | verification requirements                                                     |
| Backlog             | behaviours with status "planned", shown as "Next up"                          |
| Increment           | a verified behaviour change                                                   |
| Retrospective       | lessons in Project Context and the learning loop                              |

Deliberately omitted: sprints, story points and estimates, velocity, burndown,
ceremonies, ticket numbers and boards, priority frameworks, formal requirement
documents, sign-off workflows, agile role names, and any of these words in the
product. The only prioritisation question is the natural one ("these three are
enough for a first version: which matters most?").

### Both users

Target A answers plain, multiple-choice questions and sees a "What I know about
your product" page in plain language, where anything can be corrected. Target B
sees the same entries with scope, provenance, overrides, structured values and
history, can edit them directly, and can open exactly what an agent was given
for any run. Questions themselves disclose progressively: "Who will use this?"
can expand into roles, permissions, data ownership and API access.

### Selective, cheap memory creation

Memory is written rarely and cheaply; it is never a rewrite of a summary.

1. **Answers to our own questions** are the main source. The question already
   defines scope, category and key, so the answer is written deterministically,
   with no model call.
2. **Visual edits and direct manipulations never create memory.** "Make that
   button bigger" is a code change, not product knowledge.
3. **Free-text statements** are classified once per change request, not per
   message, by a small model in one batched call: is this durable product
   knowledge; which category and scope? A statement the user made explicitly
   ("we call these Clinics") is stored as confirmed, with the quoted words as
   evidence; an inference is stored as proposed.
4. **Consolidation** (merging duplicates, marking superseded entries) runs as an
   occasional batch job with a small model.

Budget: at most one small-model call per change request for memory. Strong
models are never used for memory upkeep.

### V0 simplifications

The schema keeps all four scopes because the column costs nothing, but the V0
resolver only needs application + capability + behaviour, and overrides are only
supported for design and terminology keys. Structured-rule contradiction checks
ship first; prose comparison by AI waits.

### Smallest proof (V0)

1. The `context_entries` table, the resolver with inheritance and overrides, and
   the export to `docs/product/`.
2. The decision check in the interpret stage, with the gate above.
3. Answers saved as `confirmed`; defaults saved as `proposed` and confirmed in
   the review.
4. The effective context compiled into the agent brief.
5. The "What I know about your product" page.

Proven by a scripted session, first with faked models in tests, then live:

- "I need booking software for my cleaning business" → a few opening questions,
  including "How should booking work?" offered as three precedent options plus
  "Something different", and "Do customers choose a cleaner?" (no, assign
  whoever is available) → first slice built → up to three common next steps
  offered in the review.
- "Add recurring bookings" → exactly one new question ("keep the same cleaner,
  or assign whoever is available each time?"); none of the earlier questions
  repeated; the brief contains the earlier answers.
- A later request inherits both answers, and a new screen uses the stored
  terminology and design context.

## 8. Context Compiler

A named subsystem with one job: turn accumulated knowledge into the **minimum
sufficient** context pack for one task. It compiles; it never dumps chat
history, the whole context store, every behaviour, or the repository. It is
deterministic code, not a model call.

**Inputs:** the request (and any selection context), the target scopes from the
interpret stage (behaviour and capability keys), Project Context, the Product
Behavior Graph, capability metadata.

**Algorithm (V0):**

1. Scopes = the target behaviours + their capabilities + the application.
   Neighbouring behaviours named by the targets' Effects add only their rules,
   strongest evidence first (§6); never their whole capability.
2. Resolve effective Project Context for those scopes (§7). Always include every
   confirmed rule, constraint and decision in scope, whatever their size: these
   are what prevent failed trajectories. Include design context only for UI
   work, and terminology only for terms used in the touched area.
3. Current behaviour of the target behaviours, from the graph; the names only of
   sibling behaviours in the same capability.
4. Capability metadata for package-backed capabilities: compressed knowledge the
   agent would otherwise rediscover by reading code.
5. Implementation references and paths, not code: the agent reads code itself.
6. Render fixed sections (PRODUCT, USERS, TERMINOLOGY, the capability's
   context, DECIDED (do not ask again), CURRENT BEHAVIOUR, REQUEST, RELEVANT
   IMPLEMENTATION) with per-section caps. Only low-priority sections are ever
   truncated.
7. **Log exactly what was included:** entry ids and tokens per section, for the
   experiments in §25.
8. **Keep each statement's kind** (§7 Provenance): unconfirmed assumptions are
   rendered as assumptions, never mixed into DECIDED, so the agent cannot
   mistake an inference for a decision.

Precedents (§7) are compiled only for the interpret stage, as the decisions
attached to the target scopes, capped. The build stage gets only the chosen
option's requirements. `reference` entries are included only when their
dimensions match the work.

Application essentials form a stable prefix, cached per commit and context
version. No embeddings or retrieval system in V0: the scope hierarchy is the
retrieval strategy until an experiment shows it is not enough. The agent can
still ask for more through the worker tools
([§11](#workers-one-boundary-for-ours-and-theirs)) and explore the repository
freely; the pack guides, it never imprisons. The pack reaches the worker as
compiled text only: what was included and why stays with us.

The deeper engineering graph for a task is still built on demand in the runtime
by `builder/introspect --around=<behavior-key>` and discarded after the run.

## 9. The change pipeline

The pipeline is a closed loop, not a plan executed once. For each substantial
change the engine knows the **goal** (the outcome wanted), the **current
behaviour**, the **assumptions** it is making, what to **preserve**, and the
**effects** worth checking. The agent **acts** inside those limits; the engine
**observes** with deterministic evidence (introspection, tests, test impact,
static analysis, the build), **verifies** that the goal is now true and the
preserved behaviour still holds, and repairs or replans when it is not. The
accepted result is **recorded** as a Change Record, which is the execution
trace from intent to verified result (§30.1). Comparing the state with the
goal after every step is the useful part of goal-directed agents; a formal
planning language is not needed (§20).

```
request (chat, behaviour card, or visual selection)
 → intent: which capability and behaviours?           small model + graph + context
 → decision check: what does this depend on that      §7; at most a few questions,
   the context does not answer?                        otherwise proceed with stated assumptions
 → classify into change classes and typed operations
 → deterministic operations                           no model
 → agent tasks                                        execution router → agent adapter
 → normalization                                      curated Rector set + Pint
 → verification                                       §12; fail → repair or replan
 → behaviour diff, assumptions to confirm → preview → approvals
 → learn: normalization hits, residuals, failures → candidate queue
```

### Change classes

| Class                           | Examples                                                            | Engine                             |
| ------------------------------- | ------------------------------------------------------------------- | ---------------------------------- |
| 1. Known transformation         | API rename, deprecations, namespace moves, package migration        | Rector, codemods                   |
| 2. Known shape, new names       | model, migration, policy, job, installing a capability              | `make:*`, starter kits, installers |
| 3. Structured configuration     | role permissions, feature flags, config values, business parameters | typed edits against a known schema |
| 4. Local semantic change        | domain logic inside known boundaries                                | coding agent                       |
| 5. Cross-cutting or ambiguous   | new architecture, unclear requirements                              | strong planner, then agent         |
| Overlay: production consequence | anything touching data, money, communication, DNS                   | approval gate                      |

When classification is uncertain, choose semantic execution. The safe failure is
extra model cost, not a wrong transform.

### Typed operations

```yaml
operations:
    - capability_config: teams / grant members:invite to manager, revoke from admin
    - transform: rector set organizations-v2
    - scaffold: make:policy InvitationPolicy --model=Invitation
    - agent_task: objective, brief, selection context
    - normalize: rector set builder-conventions (over the agent's diff)
```

Deterministic operations run first, agent tasks run on the result, then
normalization, then verification. Every operation shows its engine in the run
log. The share of work done without a model is a tracked metric.

### Compatibility follows the app's life

Keeping old behaviour working costs code, tests and attention. It is worth it
only when something real depends on the old behaviour. So every plan and every
agent task says which of two stages the app is in:

| Stage        | Known by                                            | What the agent does                                                                                                                                                                                         |
| ------------ | --------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Never online | started here from the template, and never published | Changes things in place. Renames, reshapes and removes columns, routes and screens directly. Adds no fallbacks, aliases, old names or "legacy" paths.                                                       |
| Online       | published at least once, or brought in from outside | Moves forward with a migration that carries the existing data to the new shape. Keeps an old way only when something outside the app depends on it (a saved link, another service calling it), and says so. |

- An imported app counts as online. It may already serve people elsewhere, and
  guessing wrong there loses data. Starting here is the only proof it has not.
- Migrations stay additive in both stages: the agent adds a migration, it never
  edits one that ran. Previews and workspaces have already run the old ones.
- The stage is a fact the engine knows (the project and its deployments), not
  a model judgement, so it is deterministic.
- It is a feature the owner sees, not a hidden rule. "What I know" shows a
  switch, "Keep old information and links working", with the reason for its
  current state. The owner can set it either way, for example when nobody
  uses an imported app yet, and "Decide for me" hands it back to the stage.
- Every change says which way it was built, and why, next to how it was
  checked. The choice is recorded on the run when it is planned, so a later
  switch does not rewrite what an earlier change says.
- Once published, the stored-data warning when publishing and this rule
  work together: the migration moves the data, and the owner is told before it
  runs on the live app.

### Delegation by certainty

Work is split by how certain it is, not by file type. The goal is
reliability: fewer mismatches between the parts of one change. Cost and speed
follow from it, and are never traded against it.

1. **The planner states the data shape once.** For a change that stores
   something new, the plan carries a typed data shape: each record, its
   fields with types and whether they are required, the fixed choices of an
   enum, the relations, and who may create, change or remove it. The shape is
   the single source for every part below, so a field cannot be `phone` in
   the migration and `phone_number` in the form.
2. **Deterministic scaffolds build what the shape fixes.** With no model,
   the `scaffold` operation derives the migration, the model's casts and
   relations, the factory, the form request's rules, the policy's methods,
   the resource routes and the tests that guard access ("a guest cannot
   create a booking"). Types map to rules and columns by a fixed table
   (`string, required, max 255` → `required|string|max:255`, a `string`
   column, a factory sentence). The mapping table is tested like any
   deterministic engine ([§10](#10-deterministic-engines)).
3. **One coding agent does behaviour and wiring on the result.** What
   happens on confirm, emails, the screens and edge cases stay with one
   agent, which reads the scaffold as ordinary code and may change it. It
   keeps every seam in one head.
4. **Small models only repair what a local check can judge.** One PHPStan
   error on one line, a formatting failure, one failing test the model can
   run again. The check decides whether the repair worked, so a cheap model
   is safe there and a wrong answer costs one retry
   ([§11](#execution-router)).

**Rejected: sub-agents by file type.** "A small agent writes the request
class" moves the risk to the hand-off. To brief it, the larger agent must
already decide the fields, rules and permissions, which is the hard part; the
small agent then adds a seam where names and rules drift. It also needs the
same context the larger agent read, or it guesses. The coding agents we run
already delegate inside their own harness, which we do not control.

**Owners see the shape.** The data shape is shown in plain words before
building ("For each booking I keep: who booked, when, and whether it is
pending, confirmed or cancelled. Only you can confirm."). It is asked about
only when it is hard to change later ([§7](#when-to-ask-the-decision-check));
otherwise it is shown with the change, like any assumption.

**Measured, not assumed.** Per change: which scaffolded files the coding
agent later changed, and why; review findings about mismatches between
parts; and repairs by small models that a check then refused. A scaffold
that the agent keeps rewriting is a wrong mapping, fixed in the table, not
worked around in prompts.

**Status.** V1 plans carry steps (a kind, a file and a symbol), not a typed
data shape, and there is no `scaffold` operation yet. The first slice: the
planner returns the shape for data steps, the change card shows it, and a
scaffold writes only the migration and the form request before the coding
agent starts.

## 10. Deterministic engines

### Rector

A mutation engine, not the understanding layer. It runs in the runtime as a dev
dependency of the customer application, with Larastan loaded so it can see
through Laravel's dynamic features.

- **Uses:** planned transforms; agent-invoked transforms through MCP; normalization
  after the agent.
- **Residuals:** every transform reports what it could not change safely
  (facades, `__call`, dynamic properties). Residuals become a small agent task
  with the rule as context.
- **Detectors paired with fixers:** each PHPStan rule or Pest architecture test we
  ship may carry a Rector fix; a failing detector with a fixer is a class 1
  change.
- **Ecosystem first:** community `rector-laravel` covers framework upgrades; we
  write rules only for our conventions, our packages and recurring agent
  patterns.
- **Portable:** our rule sets ship as open-source Composer packages, so developers
  can run them without the platform.

### Governance for trusted rules

A bad agent change affects one application; a bad trusted rule can affect
thousands. Every rule needs: fixtures (Rector's fixture tests), an idempotence
check, applicability checks, a dry run over a representative corpus, human
approval, versioning, canary rollout, verification per project and a rollback
path.

### Visual edits and configuration edits

Tailwind class and literal-text edits ([Visual editor](#14-visual-editor)) and
`capability_config` edits against a declared schema are deterministic engines
with a blast radius of one project.

### Catalogue

A versioned registry of typed operations (`rename_class`, `move_namespace`,
`replace_method_call`, `change_signature`, `add_interface`, `add_trait`,
`replace_deprecated_helper`, `capability_config`, `scaffold`,
`package_upgrade`). The planner selects from it and agents can call it. It pays
off mostly in maintenance (upgrades, deprecations, capability API changes,
imports); for new features, expect mostly scaffolds and configuration at first.

## 11. Execution: agents, runtimes and routing

### Contracts

`AgentTask` (sent by the control plane): `objective`; `context` (brief, seed
behaviours, selection context, memory rules); `workspace` (runtime handle, base
commit); `permissions` (protected paths, package policy, network policy);
`tools` (our MCP servers, Boost); `budget` (tokens or cost, turns, wall clock);
`expected_result`; `verification_requirements`; and `requires`, optional
capabilities such as `session_resume`, `subagents`, `vision`, `long_running`,
`structured_output`.

`AgentResult` (returned by the adapter): `status` and `failure_class`; `changes`
(the resulting commit, read back from the workspace); `tool_activity`; `usage`
and cost (from the gateway); `notes` (stored, never trusted); `self_checks`
(tests the agent ran, informational).

Verification state is **not** part of `AgentResult`. Verification is always our
pipeline's, in a fresh worker, attached by the control plane; a provider never
grades its own work.

### Adapters

- **Agent adapters** implement the contract and declare extra capabilities in a
  provider profile (models, capability flags, context window, cost model, rate
  limits, auth modes). Vendor types never leave the adapter.
    1. `claude-agent-sdk`: the Agent SDK's permission callbacks and hooks enforce
       boundaries (protected paths, package allowlist, secrets); MCP; session
       resume for repairs. We use the SDK, not automation of the interactive CLI.
    2. `script`: tests and evaluations.
    3. An OpenAI agent adapter, built early, before more is layered on top: the
       second adapter proves the contract is not Claude-shaped.
- **Runtime adapters** are independent of agent adapters: `provision(template)`
  from a pinned image digest, `exec`, files, `startService`/`serviceUrl`,
  `snapshot`/`restore`, `destroy`. Default: our own runtime (our containers or
  VMs, or a bought sandbox provider), so every stage and provider shares one
  reproducible workspace with our tools, previews and verification.
  Provider-hosted sandboxes can be added later as adapters that declare fewer
  capabilities. A local runner (the user's machine) is a runtime adapter too.
- **One box per workspace, from any provider.** In production each workspace
  is its own disposable box: a microVM or an equally strong boundary, never a
  container that shares a kernel with other customers
  ([research](../research/workspace-sandboxes.md)). A provider is a runtime
  adapter selected in `config/workspaces.php`. No code outside that adapter
  names a vendor, and changing provider changes configuration, not the
  pipeline. The contract asks only for what every provider offers: create from
  an image, run a command with a timeout that stops its whole process tree,
  read and write files, expose a service URL, and destroy. Snapshots, suspend
  and resume are declared capabilities, never assumptions.
- **What a box holds:** the customer's repository, the language toolchains and
  the agent CLI. It never holds control-plane code, our `.env`, database
  access, provider keys, git credentials or our prompts. The customer's code
  and tests run in the box, so the customer's code can read anything the box
  holds.
- **What stays outside the box:** git goes through the control plane, with a
  credential scoped to one repository and one branch. Model calls go through
  the model gateway ([§16](#16-model-gateway-and-credentials)). Outbound
  traffic is denied, except to package registries and the gateway.
- **The agent loop runs in the box,** as the SDK agents do today. The
  alternative is a loop in the control plane that only sends tool calls to the
  box. It would keep even the harness out, but it rebuilds what the SDKs
  already do well. We revisit it only if the gateway boundary proves too weak.
- **How the control plane and a box talk.** The runner in the box
  (`resources/box-runner`) connects out, so the box accepts no connections
  and needs nothing from its provider but outbound network. Commands (run a
  program, write or read a file, unpack the project, start a service) and
  their results travel over HTTPS, each command claimed exactly once, with a
  token that opens only that runner's commands. Reverb (Laravel's WebSocket
  server) only rings the runner's doorbell when work arrives, including a
  request to stop a command. When the socket is down, the runner polls, so
  work is delayed, never lost. Commands run as an unprivileged user, and the
  runner itself as root, so code in the box cannot read the runner's token or
  stop it. The `runner` workspace driver implements the whole workspace
  contract this way. A provider adapter only creates boxes, destroys them and
  says where a box's services are reachable.
- **Local development stands in for boxes and never replaces them.** In Sail,
  the `runner` service plays the box through the `static` provider: one runner
  that is already running, with no project mount, no `.env` and no database,
  so local runs cross the same boundary. It holds every local workspace under
  one user, so it is for our trusted fixtures only. The `local` driver still
  runs workspaces inside the control plane's container. Agents refuse to run
  there unless `WORKSPACE_LOCAL_AGENTS` allows it for trusted apps.
- **The `docker` provider tries the box lifecycle locally.** It makes one
  container per workspace through a small box service that alone holds the
  Docker socket and can only create, list and remove labelled box containers.
  Each box gets a runner token derived from its name, so it opens only its own
  commands. Containers share the host kernel, so this never replaces a
  microVM provider.
- **The runner** is a TypeScript process in the runtime that hosts the agent
  engines and speaks the runtime protocol to the control plane over an outbound
  connection: tasks (`transform`, `agent`, `prepare`) in; numbered events
  (`agent.message`, `file.changed`, `command.finished`, `heartbeat`,
  `task.finished`) out, fenced by the run's token.
- **Reproducible workspace:** the blessed template pins PHP 8.5, Node, Composer,
  Postgres, Chromium and cached dependency layers; each run records an
  environment manifest (image digest, lockfile hashes, tool versions).
- **Hand-offs between stages and providers** happen only through structured
  artifacts and commits (plan, brief, behaviour diff, verification results),
  never raw transcripts.

### Workers: one boundary for ours and theirs

A **worker** is anything that writes the code for one change: our Claude or
Codex agent in a box, the owner's own Claude Code or Codex on their machine,
or a developer they hire. All workers sit outside the control plane and get
the same thing: one brief, a few task-scoped tools, and a place to hand the
change back ([direction 31](direction/31-external-workers.md)).

**Assume the worker's human reads everything.** The brief, the tool names,
every tool answer and our working rules can be read by the person who runs
the worker. So nothing that would do harm when read goes to a worker. The
moat is not a secret prompt. It is what the brief is compiled from and what
happens after the hand-off: the project's accumulated understanding, the
choice of what matters for this change, the deterministic engines, and the
verification that the worker cannot edit. A competitor who learns that briefs
have "goal, preserve, verify" has learned nothing that they can use.

**What is secret, what is compiled, what is open.** The owner's knowledge
about their own app is the owner's data, not our secret. The builder already
shows it to them, and it is in every workspace today. Hiding it from their
own worker gains nothing. The secrets are our machinery and other customers.

| Kind                                                       | In this codebase                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Server only**                                            | Routing: adapter order, models, light models, effort, circuit and prices (`config/builder.php`). The planner's and reviewer's instructions (`app/Ai/Agents`). Decision-model probabilities, confidence and thresholds (`Decision`). What the compiler included and why (`ContextPack` `included`, `outline`, `problems`). How Effects are counted and when they appear. Run telemetry and cost (`run_events`). Other runs, other projects, other customers. |
| **Compiled output** (made for this task, then handed over) | The goal and the owner's request. What the app does now. Preserve and verify items. The notes of the target areas, their tests and "may also affect" hints with a reason in words. The names of the other areas. Decisions already made that apply. The files to look at.                                                                                                                                                                                   |
| **Open on purpose** (being open builds trust)              | The tests that must pass. Verification results with their output. Review findings, sent back as problems to fix. Why a question goes to the owner, and the owner's answer. The assumptions the change makes.                                                                                                                                                                                                                                                |

Worker text uses neutral words: area, rule, decision, check, "may also
affect". It never uses Effect, Context Compiler, capability, confidence or
score, and it never names a builder or a platform
([§19](#19-learning-and-privacy)). A hint says why it matters ("the booking
tests run this code too"), never a number.

**Critique of the proposed Worker Gateway.** A separate gateway service is
not needed. Each of its duties already has a Laravel home:

| Duty                    | Where it lives                                                                                    |
| ----------------------- | ------------------------------------------------------------------------------------------------- |
| Check the active task   | The token belongs to one `Run`. A tool refuses when the run is not `implementing`.                |
| Enforce scope           | No tool takes an id, a project or a path to data. The scope is the token's run.                   |
| Clean the answers       | Tools return text made by one renderer (the brief's). They never return models, arrays or config. |
| Rate limit              | A named `RateLimiter` per token.                                                                  |
| Record requests         | Each call is a `RunEvent` (`worker_query`), in the log that runs already keep.                    |
| Strip internal metadata | Nothing internal is loaded into an answer in the first place.                                     |

So the "gateway" is one route group: a `laravel/mcp` server, behind
`auth:sanctum` and `throttle`. Each tool is a thin wrapper over an existing
action. This is the first-party way, and it keeps the boundary small enough
to review.

**Agnostic by design.** Claude Code and Codex both read a task file and both
call MCP servers over HTTP. So the boundary is exactly two things:

1. **The brief as a Markdown file.** Today it is `buildPrompt` plus
   `workingRules` in `task.json` (`.git/agent-task`). It becomes `TASK.md`
   there, rendered from `Plan` and `ContextPack`. It also answers the
   `get_task` tool, for a worker that has no file.
2. **The MCP tools below.** No tool is shaped for one vendor.

Our own agents become the first external worker. The runner in the box gets
the same brief and a token for the same tools. So the boundary is tested on
every run, not only when an owner connects Claude Code. Adapters then differ
only in how they start the agent, resume it and read its usage
([§11 Adapters](#adapters)).

**The first tools (minimum for the experiment).**

| Tool                                           | Does                                                                                                                                                                                                                                                                                                           | Reuses                                                       |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| `get_task`                                     | Returns the brief, with the owner's answers so far and the problems from the last check.                                                                                                                                                                                                                       | `Plan`, `CompileContext`, `buildPrompt`, `Run::$feedback`    |
| `ask_about_product(question, area?)`           | Answers from the notes of the task's areas. A named area outside the task is an **expansion**: only its rules and decisions are returned, capped, and the expansion is logged. No model in the first experiment: it returns the matching sections of the notes.                                                | `ProjectContext`, `ProjectNotes`                             |
| `ask_owner(question, options, recommendation)` | Stops for the owner. This is the existing question flow: the run goes to `needs_user_decision` and the owner answers in the builder. The worker calls `get_task` later for the answer. The worker never records product truth: the answer is kept by us and reaches the notes only through an accepted change. | `Run::$question`, `question_limit`, `answers`                |
| `submit_change(patch, summary, assumptions)`   | Takes a diff against the base commit in the brief. We apply it in our own workspace, then run our verification and review. The checks the worker says it ran are for information only.                                                                                                                         | `ApplyPatch`, `ExtractCandidateChange`, verification, review |
| `check_status`                                 | Says where the change is. When a check failed, it gives the problems to fix, in the same words as `Run::$feedback`.                                                                                                                                                                                            | `CompleteRunVerification`                                    |

After the first experiment: `preview_activity` (emails sent and problems met,
from the preview of the submitted change) and `open_preview` (a single-use
grant link). There is no `list_*`, `search_*` or `dump_*` tool, and none is
planned.

**Authorization.** Sanctum personal access tokens, with the `Run` as the
tokenable model (`HasApiTokens` on `Run`). This adds one table:
`personal_access_tokens`.

- **Abilities:** one, `task`. It opens only this change's tools.
- **Expiry:** the token lapses after `agents.workers.minutes`. It is revoked
  when the run ends or is cancelled, or when the owner makes a new
  connection. It stays valid while the change is checked and reviewed: the
  worker calls `check_status` then, and hands back a fix when the change
  goes back to it.
- **Notes:** the worker's copy of the app has no `.product-notes`. Its brief
  tells it to describe each area it changed in its summary, and notes in a
  handed-back patch are left out, so they never clash with ours.
- **A new try:** when the change tries a stopped one again, the brief lists
  what its checks reported and its review's blocking findings. This holds
  for our own agent too.
- **Box runs:** the runner gets the token in its environment, like the
  provider keys, never in `task.json`. The box runner's own token
  (`AuthenticateRunner`) stays separate. It opens that runner's commands, not
  the tools.
- **Local Claude Code or Codex:** this is power-user depth
  ([§3](#3-users-and-progressive-disclosure)). The owner picks "Work on this
  yourself" on a change. We show the token once, inside ready-to-paste
  commands: `claude mcp add --transport http …` with an `Authorization`
  header, and the matching `codex` MCP settings. The worker never gets the
  owner's session, password, account token or provider keys, and the token
  opens only this one change.

**Why no one can enumerate.** A token reaches one run, so one project and one
line of work. No tool takes an identifier, so there is nothing to walk. An
expansion returns one named area's rules and decisions, capped, and at most
`workers.expansions` per run. Every call is logged and throttled. No query
can reach another project, and tests prove it. Inside the project, a worker
can at most rebuild the notes the owner can already read. Only the owner
can make a connection, so every worker works for the owner. Our own
developers do not use these tools: they read an owner's question in
operations ([§29.3](#29-human-judgment-where-it-has-leverage-version-18)).

**Models, values and runtime state.**

- **Models (existing):** `Run` is the task. `FeatureRequest` is the change.
  `Workspace`, `Preview` and `Verification` stay as they are.
- **Models (new):** only the Sanctum token table.
- **Worker queries:** `RunEvent`s. They need no new table.
- **Values:** `Plan`, `ContextPack` and a brief renderer are readonly values.
  The renderer is the only code that writes worker text, so it is the one
  place to review and to lint.
- **Runtime state:** MCP sessions and agent sessions. A repair pass resumes
  the agent's own session (Claude `resume`, Codex `resumeThread`) and sends
  only the problems to fix. When the session is gone, the agent starts fresh
  with the whole brief.

**Local and remote.** The brief names the base commit. The worker hands back
a patch, not a push, so it needs no git credential. We apply the patch
three-way in our workspace. A conflict goes back as a problem to fix. Our
repository, branch and credentials never leave the control plane
([§11 Adapters](#adapters)). The preview of a submitted change is an ordinary
preview of our workspace ([§15](#15-previews)). A local worker may also run
the app on its own machine, but only our preview and verification count.

**Query logs improve the compiler.** Each `worker_query` records the area
asked about and whether it was in the brief. When questions keep reaching an
area that the brief left out, the area was a missed target. These counts are
candidates for `CompileContext`, reviewed like any other rule
([§19](#19-learning-and-privacy)). The same count is a cost signal: every
question means a round trip that a better brief would save
([§25.2](#252-the-economic-metric-cost-per-accepted-change)).

**Not now (over-engineering at this stage):**

- a separate gateway service or process;
- OAuth or a device flow (Passport) for workers;
- signed capability tokens of our own;
- graph or embedding queries;
- a model that answers product questions;
- a field-level redaction engine;
- a git server or proxy for local workers;
- streaming preview traces.

Each comes back only when the experiment shows the need.

**Testable now, with what exists.**

- A Sanctum token on a `Run` opens its tools, and a token of another run, of
  another project or of a finished run is refused.
- `laravel/mcp`'s test helpers call each tool.
- The fake runner (`tests/Fixtures/fake-agent-runner.mjs`) submits a patch,
  and verification runs on it.
- A **brief lint** fails the build when worker text contains a forbidden word
  (builder, platform, control plane, a configured model id, confidence,
  score, Effect) or any internal field.
- `worker_query` events are recorded.

**Status.** Partly built.

- **Built:**
    - `WriteBrief` is the only code that writes worker text, and a brief lint
      tests it.
    - A Sanctum token on `Run` (`GrantWorkerAccess`) opens the `laravel/mcp`
      server at `/mcp/task` (`routes/ai.php`). The server has `get_task`,
      `submit_change` and `check_status`. The token is revoked when the run
      ends.
    - The `worker` construction driver (`WorkerDriver`) plans and reviews like
      `sdk`. It waits in `implementing` until a worker hands back a patch.
    - Each patch is the whole change against the owner's commit. It is applied
      three-way on a clean baseline. A patch that does not apply goes back to
      the worker with git's reason. `runs:reconcile` leaves a waiting run
      alone.
    - "Use my own Claude Code or Codex" (`HandChangeToOwner`) is offered on a
      change we are making or one that stopped. The change starts again with
      the `worker` driver. The thread shows the connect command and what to
      ask, once. "Connect again" closes the earlier token.
- **Next:**
    1. Point our own runner at the token and the tools.
    2. Add `ask_about_product` and `ask_owner`.
- **Open:**
    - A waiting run has no time limit yet; the owner can cancel it.
    - A worker's change is reviewed by the default reviewer, which is logged
      as not independent.

### Execution router

| Stage                                            | Starts with                                                       | Escalation                                                     |
| ------------------------------------------------ | ----------------------------------------------------------------- | -------------------------------------------------------------- |
| Interpret request, plan                          | strong reasoning model                                            | —                                                              |
| Classify, map to capabilities, name UI, annotate | cheap model                                                       | stronger model on low confidence                               |
| Deterministic operations                         | no model                                                          | go semantic when unsure                                        |
| Implement                                        | coding engine per task class                                      | after 2 failed repairs: stronger model or a different provider |
| Repair that a local check can judge              | cheap model, one error at a time                                  | after 1 refused repair: back to the implementing agent         |
| Independent review                               | only when triggered, on a different provider than the implementer | —                                                              |

**Review triggers:** the behaviour diff touches permissions, money, deletion,
external communication, tenant data or migrations; the covering tests are weak;
or the implementer needed repairs. Otherwise the deterministic gates suffice.

**Telemetry per stage execution:** task class (change class, capability,
operation type, UI or backend, context size), engine and model, tokens, cost,
duration, retries, escalations, verification outcome, and later regressions
(reverted, or fixed by a follow-up within N days).

**Maturity:** v0 is a routing table per task class in configuration, with
telemetry from day one. v1: the evaluation harness runs the task set across
providers and models and recommends a table for review. v2: limited experiments
in production on low-risk task classes only, never on a non-technical owner's
high-risk change.

### Runs

**Pictures with a request.** An owner can attach up to four pictures (a
screenshot, a sketch, a design) to a request or a follow-up, by the attach
button, by pasting, or by dropping them on the message box. PNG, JPEG, WebP
and GIF are taken; SVG is not, since it can hold code. They are kept on the
request images disk under the project, shown back only to people who may
see the project, and served with a policy that runs nothing. The workspace
gets them inside `.git/attachments`, so the coder can look at them but they
never enter the change, and the coder's prompt lists them. The planner and
reviewer are given them as image attachments through the AI SDK, so the
plan and the review follow what the pictures show. A retry keeps them.
`builder.construction.images` sets the count, size and disk.

The existing run model stays: states queued → planning → implementing →
verifying → reviewing → completed, plus needs_user_decision, cancelling →
cancelled and failed; a lease with a fencing token per run; budgets (operations,
minutes, repairs); cancellation; the reconciler. A request that only asks about
the app ends at planning: the planner's `answer` is shown, the run moves from
planning to completed and the request is "answered", with no workspace change,
verification or review. Fencing moves from individual
tool calls to runtime tasks when agents run in the runtime; the per-tool-call
journal remains for the scripted engine and tests. A lease is renewed while a
coding agent works, not only at tool calls. When a renewal finds the lease lost
or the run cancelled, the agent and its whole process group are stopped at
once: fencing refuses a stale worker's writes to our records, but only
stopping the process keeps it out of a workspace another worker took over.

## 12. Verification

Runs in a fresh worker, trusting nothing from the agent's workspace. The
behaviour diff decides which semantic checks apply.

1. **Static:** Pint, Larastan, our PHPStan rules, Pest architecture presets
   (`arch()->preset()->laravel()`, security, our conventions), TypeScript types.
2. **Semantic checks triggered by the behaviour diff:**
    - new or changed mutating surface: authentication and authorization present
      (policy, `can:`, or a non-trivial FormRequest `authorize`);
    - model with a tenant or owner column: isolation enforced;
    - migration: `migrate --pretend` SQL rules (drops, renames, type changes,
      non-null without default, indexes without `CONCURRENTLY`), rollback present;
    - new job: retry, backoff and failure behaviour;
    - new mail or SMS: approval gate, and previews force the `log` mailer;
    - lockfile changes: dependency policy.
3. **Tests:** the full suite (Pest, or PHPUnit in imported apps); protected acceptance tests generated from the
   plan's criteria before coding (by a model other than the coder, confirmed by
   the owner in plain language, frozen); Pest browser tests on touched screens.
4. **Invariants** as Pest tests, many from helpers our capability packages ship
   (for example `assertTenantIsolated(Project::class)`).
5. **Independent review** by a different provider, when triggered.

Only DERIVED, CONFIRMED and PACKAGE CONTRACT statements feed hard gates. AI
interpretations and proposals produce warnings and review prompts only. Purely
visual edits get light verification: build, `vue-tsc`, a visual smoke test, and
a behaviour diff showing that no behaviour changed.

**Scope by risk, never by diff size.** "Small" is a property of meaning: a
three-line authorization change is riskier than a 200-line isolated component.

| Change                                                                                                                  | Verification                                                                                                                                                                               |
| ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| While the agent works                                                                                                   | Tests selected by test impact analysis, and cheap static checks: fast feedback.                                                                                                            |
| Local and low-risk (only classes or only notes; no rule, schema, permission, billing or cross-area Effect)              | May commit on targeted checks.                                                                                                                                                             |
| Any other kept change                                                                                                   | Targeted checks plus the full suite, before "Keep this change".                                                                                                                            |
| Authentication, authorization, tenancy, billing, migrations, middleware, configuration, dependencies, or unknown impact | The full suite, whatever the size.                                                                                                                                                         |
| Publishing (the integration boundary)                                                                                   | **The full checks on the exact commit, always**, however many small commits led to it. Then smoke checks at the app's address: a push is "sent", and only an app that answers is "online". |

Lack of evidence broadens verification: strong observed impact allows narrow
checks, partial evidence widens them, and unknown impact runs everything. Test
impact analysis is the fast path, never the trust boundary.

**A checkpoint is not an accepted change.** Agents commit freely inside their
disposable workspace; only an accepted change reaches the project, after its
verification. The Change Record, not the commit, is the unit of acceptance.
The change is always the diff against the baseline commit recorded when the
workspace was prepared, and that commit ID lives in our database, outside
anything the agent can rewrite. Acceptance commits only the state that was
checked: when the app moved on after the check, the change is built and
checked again on the current app, never merged unchecked onto newer commits.

**Evidence is what ran, not what anyone says ran.** The suite check writes a
JUnit report. A verify item counts as tested only when the test named for it
is in a file the change touches and the report shows it ran and passed. A
named test that is missing, skipped or misnamed is a blocking finding. A
suite without a report leaves the reviewer's claim as a claim, shown to the
owner as not confirmed.

**The change is judged, not the app it started from.** Format and lint
checks run only on the files the change added or modified
(`files` on the check). When a whole-app check fails, it runs again on the
starting commit in the same workspace: the change is taken out with
`git apply --reverse`, the check runs, and the change is put back. A failing
test or output line that is also there before the change is the app's old
problem. Only the new problems go back to the coder, and a change whose only
problems are old ones goes on to review. The result keeps both outcomes
(`at_start`, `new_problems`), and the owner sees "failing before this change
too". A change to the package files skips the comparison, as the starting
commit would need other packages installed.

**Our failures never cost a repair.** A command no runner took, or one that
never answered, a workspace that did not start, or a crash in our own code
stops the checks as interrupted. They run again, up to
`builder.verification.retries` times, and the coder never hears of it. When
they still cannot run, the run stops and says it is our fault. Only problems
the coder can fix count against the repairs budget
(`BUILDER_RUN_MAX_REPAIRS`, 4 by default).

**Common safety mistakes are found by pattern, on added lines only.** The
review scans the lines a change adds for unescaped Blade output (`{!! !!}`),
`v-html`, queries built from values mixed into their text, models open to
every field, and committed `.env` files (`builder.verification.safety_scan`).
Each one found is a blocking finding that names the line and the safe way.
Code the app already had is never held against a change. A comment on the
line, or the line above, that says why it is safe lets it through: a reason
a person can read and question. The owner sees the clean result as one line
of the change's proof.

**Shortcuts in PHP code are found by an analyser, on added lines only.**
When a change touches the app's PHP code (not its tests), verification runs
the Sloppy analyser (`heyosseus/sloppy`, a pinned PHAR in the box image,
never added to the app) over the files it touched. It reads the code with a
parser, without running it or asking a model, so the same code always gets
the same answer. Four of its rules are held against a change: an error caught
and ignored (SL107), a relation read once per item in a loop (SL203), a query
inside a loop (SL204) and `Model::all()` (SL210). Its size and structure rules
are too noisy on real code to send a change back for, so they are not run.
Shortcuts on lines the change added are kept on the verification. They never
hold the change back, so the owner is not kept waiting for them. After the
owner keeps the change, a queued job (`TriageShortcuts`, after
`shortcuts.triage.delay_minutes`) asks the decision model (Jev through the
AI SDK's classification) whether each one is a real problem, with the whole
kept file to read. A shortcut whose line a change kept with it rewrote is
logged as gone and not asked about. The answers are logged on the run as
`shortcuts_triaged`, and each call as a `model_call` with the role `triage`.
The real ones become a tidy-up (`TidyShortcuts`): a change the app asks for in
the owner's name ("Tidy up 2 things in my app's code."), with the shortcuts in
its brief (`feature_requests.tidy`). It starts only while the owner has no
change of the main app being built, or built and touched lately; otherwise it
checks again later, for a day. It is built by the coding agents'
`light_model` on `shortcuts.tidy.max_budget_usd`. When its run completes and
its patch removed each flagged line or added a comment saying why it stays,
`KeepTidy` keeps it through `AcceptChange`, in the owner's name, and the owner
is not notified; the owner can undo it like any kept change. The chat list
and the chat name it by what it does, not by the words it was asked in:
"Tidying up 2 things in your app's code in the background.", then "I tidied
up 2 things…" once kept (`FeatureRequest::background()`). Until kept it reads
as being built, never as waiting for the owner. A tidy-up put aside is not
shown. When the light
model fails or leaves a shortcut, the usual model tries once; when that fails
too, the tidy-up is put aside (`tidy_put_aside`) and the app stays as it was.
A tidy-up built on an app that changed since is built again on the new one.
Tidy-ups are not triaged, so they never loop. A comment on
the line, the line above or the line below lets one through. Where the
analyser is missing, nothing is read or said. The owner sees the clean result
as one line of the change's proof.
`builder.verification.shortcuts` turns it off. No first-party Laravel package
reads code for these shortcuts; Larastan checks types, not these.

**The change is run without its code** (direction 32). A coder can misread
a request, build the misreading and write tests that match it. No check that
runs can find the misreading. But the checks can measure how much the
change's tests say. When the checks pass, verification measures three things
(`builder.verification.change_evidence`), and keeps them on the verification
(`verifications.evidence`):

- **New tests** (`NewTests`). The whole change is taken out with
  `git apply --reverse`. Then only what it did under the tests' folders is put
  back, and the test files it touched run again. A new test that fails there
  tried what the change does. A new test that passes there says nothing about
  the change. A change that only adds tests is not measured: its tests pass
  without it by design.
- **Routes** (`AppRoutes`). `route:list --json` runs with the change and
  without it. The framework lists the routes itself, so routes from packages,
  attributes and providers are there too. The difference names each route the
  change added, removed, or whose middleware it changed.
- **New code** (`NewCode`). The test map's coverage run also writes a line
  report. Each new line of PHP that can run is one of three kinds: a test the
  app already had runs it, only the change's own tests run it, or no test
  runs it.

These are measurements, not checks. They never change the result of the
checks, and what cannot be measured is not kept. Nothing here sends a change
back by itself, because what a measurement means depends on what the owner
asked for: a route that lost `auth` can be the request or a mistake. The
reviewer reads all three with the plan. It blocks a criterion about new
behaviour whose test passes without the change, a route that lost a check on
who may use it, and a new route that changes data without one, unless the
request asks for exactly that. The owner reads them in the proof, in plain
words: "It added 3 tests that fail without this change and pass with it",
"A part of your app no longer checks who may use it: /teams. Make sure you
wanted that." and "Tests ran 122 of its 123 new lines of code." Tests that
pass with and without the change are a gap, and so is new code when more than
`unrun_gap_share` of its lines are run by no test. Only Laravel's own
middleware for who may use a route (`auth`, `verified`, `can`, `signed`,
`password.confirm`) are read as such; an app's own middleware is not guessed
at. A change to the package files is not measured, as the starting commit
would need other packages installed. On the fixture, all three took about 8
seconds.

**What the app does is recorded while its tests run** (direction 32). Tests
check what their author thought of. Some mistakes are wrong in every app,
whatever the request, so they need no author. A recorder
(`resources/trace-recorder`, in the box image at `/opt/trace-recorder`) writes
one line for each request a test makes: the route, the status, whether the app
refused it, and each effect in order. An effect is a query, a transaction that
starts, commits or rolls back, a job, a mail, a notification or an outside
call. Each effect has the nearest line of the app's own code and the number of
transactions the request had open. What the test's own code does inside a
request has no line: a test can play a second person who saves at the same
moment, and that save is not the app's.

Each query, job, mail, notification and outside call also says where in the
request it happened (direction 33). `frames` is the app's own code on the way
to it, nearest first, as `Class::method`, at most six. `phase` is the part of
the request: `middleware`, `authorization`, `validation`, `handling` (the
route's own code), `rendering`, `model` (a model's hooks and observers),
`listener`, `job` or `error`. The recorder reads the phase from a fixed list
of the framework's calls, from the effect outward, and the nearest one wins.
A policy that a controller asks is `authorization`, and a value that the
answer reads late is `rendering`. A call chain that matches nothing is
`unknown`, never a guess. The boundary rules read these two fields.

The recorder changes no file of the app. The coverage command sets PHP's
`auto_prepend_file` in an ini file under `storage/logs/test-map/trace`. That
file gives Laravel a copy of its package list with the recorder's provider
added, and keeps the copy in the same folder, so `bootstrap/cache` stays as it
was. The recorder has a neutral name and holds nothing of ours. It rides the
coverage run that the test map already needs, so the suite does not run again.
On the fixture it added about 1% to that run, and two runs gave the same
lines. A box image without the recorder records nothing, and nothing is said.

`AppTraces` reads the lines for four shapes
(`builder.verification.traces`):

- **Saved on a read.** A GET request committed a write.
- **Kept after a refusal.** The app refused a request (a status of 400 or
  more, or validation errors) and still committed a write.
- **Sent before saved.** A job, a mail, a notification or an outside call
  left while a transaction was open. If the transaction fails, it is sent
  anyway.
- **Repeated lookup.** One line ran the same select `repeats` times or more
  in one request.

A write that a rollback undoes is not counted. A transaction that the test
tools open is not counted as the request's own. A shape counts against a
change only when its effect comes from a line the change added, or from the
app's code on a route the change added. The same shape in code the app
already had is counted (`existing`) and not reported.

A test can put a fake in place of the mail, the notifications, the queue or
the jobs. Nothing goes out through a fake. When a request starts, the
recorder puts a stand-in where each of these fakes is. The stand-in is the
same fake with the same memory: it notes each send, then does what the fake
does, so the test's own assertions hold. A faked notification is noted as the
app would send it: on the queue, or as the email it sends now. A job that
waits for the transaction is noted when the transaction commits, as the queue
would take it.

Some sends stay hidden: everything under a fake of events, a notification to
a channel that is not email, and a job the app runs before it answers, which
did not run under the fake. A request that ran the change's code and opened a
transaction with sends hidden is counted as not seen (`unseen`), never as
clean. The proof then speaks only for what was saved: "Its tests only pretend
to send emails and messages, so we could not watch when it sends them." On
the fixture, fakes were active in 28% of requests. Only requests that tests
make are recorded, so code that no test reaches says nothing here; the
new-code measurement shows that gap.

Like the other measurements, these never send a change back by themselves.
The first three go to the reviewer, which blocks them unless the request or
the plan asks for exactly that, and to the owner's proof: "Opening /reports
changes what your app has saved…", or, when the tests reached the new code and
nothing was found, "We watched what your app saved and sent while its tests
used the new code 26 times. Nothing was saved by mistake or sent too early."
A repeated lookup joins the shortcuts above (rule `SL204`), so it is tidied
after the change is kept and the owner does not wait for it.

**Three parts of a request must not change anything** (direction 33, the
boundary rules). Laravel runs code in phases, and the rules follow the phase,
not the class or folder, so they hold whatever style the app is written in. A
policy method that saves is a problem only when it runs as a check; the same
method called by the controller is not. `AppBoundaries` reads `phase` from
the same recording (`builder.verification.boundaries`) and finds a write, a
job, a mail, a notification or an outside call in one of these phases:

- **While authorizing.** A check of who may act can run many times per page,
  for example once per row, so what it saves repeats.
- **While validating.** It runs before the app decides to act, so what it
  saves or sends stays when the request is refused.
- **While rendering.** Views, resources and Inertia props can run more than
  once per request, and after the route's code has returned.

Reads are allowed in all three. A phase of `unknown` is never held against a
change. The finding is held to the change the same way as the shapes above:
by a line the change added, or by a route the change added. A rendering
effect has no controller in its `frames`, so it is held by the resource's or
the view's own line. A query in a compiled Blade view has no line yet and is
not counted. Each finding was seen to happen, but only on the paths the
tests take. It goes to the reviewer ("GET /posts while Laravel checked
whether the person may act: update posts at app/Policies/PostPolicy.php:9 in
App\Policies\PostPolicy::view") and to the owner's proof ("At /posts your
app saves or sends something while it checks who may do something…"). When
the recorder named the phases and the tests reached the new code without a
finding, the proof says so. Like the other measurements, it never changes
the checks' result by itself.

The recording only shows what the tests run, and it never shows the app
start. So `BoundaryCode` also reads the PHP files the change touched, as the
change leaves them, before anything takes the change out of the workspace.
It names the methods Laravel itself runs in each phase: every public method
of a policy, a form request's `authorize`, `rules` and validation hooks, a
resource's `toArray`, and a service provider's `boot` and `register`. On
the lines the change added, it finds a save, a job, a mail, a notification,
an event or an outside call in those methods, and, while the app starts,
any query too. A closure that `boot` only registers runs later and is not
counted. It reads one method at a time and does not follow calls, so each
finding is likely, not proven. These go to the reviewer as "read from the
code, not seen running" (`read`), never to the owner's proof. A line the
recording already holds against the change is said once, as seen. A finding is
also named by what it is, not by its line: the rule, the method and the
kind of effect. The file as it was before the change is rebuilt from the
file and its diff and read the same way. A finding it already had only
moved, for example when the change reformats a policy, and is counted as
`existing`, seen or read. The change is held only to what it has more of. The
app's start is a fourth rule, read only: a query there runs for every
request, command and queue worker, and before a database may exist.

**The owner may want what a phase rule finds** (an exception, direction
33). An audit log of each refusal, for example, is written while the app
checks who may act. Each gap line of the proof carries a control ("I want it
this way"), shown only while the change waits for the owner. Pressing it
stores each finding of that rule in the change by what it is, never the
rule itself (`accepted_findings`, in the control plane: the agent writes the
app's code, so a code comment cannot grant one). The line then says it is
the owner's choice, with what it costs, and can be undone. The reviewer
does not hold those findings against the change. Once the change is kept,
the finding is part of the app as it was, so a later change that does more
of the same is asked about again.

**An outside service stays where the app already calls it from** (a
containment rule, direction 33). Nobody declares these rules yet; they
are read from the app. A service the recording shows the rest of the app
calling only from code that some areas claim (the `paths` in the notes,
read from the main branch, so a change cannot move them) is kept to those
areas. A service already called from code no area claims is kept nowhere,
and a service the app never called has no place yet. A call the change's
own lines make from elsewhere goes to the reviewer (`containment`), with
the areas that call it today, never to the owner's proof: whether a
second place is wanted is for the plan to say.

**The files that decide how the app is checked are protected** (direction
33). `phpunit.xml`, `tests/Pest.php`, `phpstan.neon` (and their `.dist`
forms) and `.github` join the protected paths
(`builder.construction.protected_paths`). A coding worker's tools refuse to
write them, and anything the worker changed there is put back before the
change is taken, as with the protected acceptance tests. A change therefore
cannot pass its checks by changing how they run. Changing these files is a
person's decision.

The full design, with what comes after this first step (static effect
analysis, a ratchet by finding identity, debt and exceptions, strict mode,
and faults derived from effect signatures), is the proposal in
[docs/research/boundaries-and-chaos.md](../research/boundaries-and-chaos.md).
Only what this section describes is built.

**One failure at a time is caused where the change sends or saves**
(direction 32, the fault engine). A recording shows what the app does when
everything works. It does not show what the app leaves behind when an email
cannot be sent or a save fails. Tests rarely check that. So, once the checks
pass, verification causes those failures (`builder.verification.faults`).

Nothing is random. `AppFaults` reads the recording for the places a failure
can be caused, in requests that ran the change's code:

- **A send.** Each mail and each outside call of a request.
- **An answer.** Each outside call the app's code makes itself, when the
  app's code sends or saves something after it. The call does not fail. It
  is made, and a server error is given as its answer. Laravel's HTTP client
  gives the app such an answer and throws nothing. A call a package makes
  for the app is not a place: the app's code does not get its answer. For a
  call a job makes, only what the rest of that job does counts as after.
- **A save in a transaction.** The last write of each transaction that a
  request commits.
- **A save in steps.** The last write the app's code makes outside a
  transaction, when the request saved or sent something before it. Most
  requests that save twice have no transaction, so this is the common place.
- **A job.** Each job the sync queue ran in a request, when the job sent
  something or added a row that stayed. This place does not fail. The job
  runs a second time. A job of the framework that only delivers one email,
  notification or broadcast is not a place: it has no code of the app to
  make safe.
- **A save in a job.** The last save a job makes after it sent something.
  The save is refused, and the job is run again, the way a queue tries a
  failed job again.
- **A job that waits.** Each job the sync queue ran in a request, when the
  job sent or saved something, or the app's code did something after it.
  This place does not fail. Tests run a queued job where it is dispatched.
  In use it waits on a queue, and a worker runs it after the response. So
  the job is held back and runs when the response is made, the way a worker
  runs it: no one is signed in, and the request and the session are empty.
  A job of the framework that delivers an email or a notification is a
  place here too: the worker runs what the app's code puts in it. A job the
  app sends to the sync queue by
  name (`dispatch_sync`, or a job that names the `sync` connection) is not
  such a job, and is never held.
- **An event.** Each event a request dispatches that has two or more
  listeners Laravel found by itself (event discovery). This place does not
  fail. The found listeners run in the reverse order. Laravel takes found
  listeners in the order the disk lists their files, so their order is not
  the same on every machine. Listeners the app registers by hand have the
  order its code gives them, and are not a place.

For each place, verification runs the one test that made the request again,
with `TRACE_RECORDER_FAULT` naming the test, the request and the effect. The
recorder then makes that one effect fail the way it fails in use: the mail
transport cannot connect, the outside call times out, or the database
refuses the write before it runs. A job is run again when it is done, the
way a queue runs it again when a worker stops before it marks the job as
done. The second run is marked in the trace, and an error in it stays in it.
For a job that waits, the recorder puts a sync queue in place that asks it
before each job, and only in that run. Before the held job runs, the
recorder gives the app an empty request and an empty session, and has the
app forget its guards, which hold the signed-in person. It puts all three
back when the job is done, so the rest of the test runs as before. For an
answer, the recorder puts a
middleware on the HTTP client the same way. The call still reaches a fake
of the test, and then gets a 500 as its answer. For an event, the fault also names
the event. The recorder puts its found
listeners in the reverse order for that one request, and gives them their
order back when the request ends.
The trace of that request shows what stayed:

- **Saved, then failed.** A send failed, the person got a server error, and
  a write from before the failure was kept. A second try can save it twice.
- **Sent, then lost.** A save failed and was lost, but a mail, a job or an
  outside call had left before it.
- **Saved in part.** A save failed and was lost, but a write of the app's
  code from before it was kept: an order without its items.
- **Done twice.** A job ran twice, and both runs sent the same thing or
  added the same row from the same line. A queue gives a job to a worker at
  least once, so a job must be safe to run again.
- **Sent again.** A save failed in a job after the job sent something, the
  job was tried again, and it sent the same thing again from the same line.
  A job that asks if it ran before passes the run above. It fails here when
  it marks that only after it sent.
- **Called again.** An outside call got no answer, and the request made the
  same call again from the same line. A call that got no answer can still
  have arrived, so the service can do it twice: a payment taken twice.
- **Answer not checked.** An outside call was answered with a server
  error. The app's code did not ask the answer for its status, and the
  request went on to send and save the same as when the call works: an
  order marked as paid when the payment failed.
- **Needs its job done.** A job ran after the response and not where it
  was dispatched, and the request did not do the same. What the request
  does after it dispatches a job only works when the job is done.
- **Job needs the request.** A job ran after the response the way a worker
  runs it, and the job did not do the same. A send, or a save of its code
  that stayed, is missing or new from its line. A job that takes the
  person or what they sent from the request it was dispatched in
  (`auth()->user()`, `request()`, `session()`), and not from what it was
  given, finds nothing on a queue. A job that was given a model the request
  deletes after it queued the job is found too: the worker cannot load the
  model.
- **Depends on order.** The found listeners of an event ran in the reverse
  order, and the request did not do the same. A send, or a save of the
  app's code that stayed, is missing or new from its line, or the answer
  has another status.

A save in a transaction is lost when the transaction rolls back. A save in
steps is lost when the request ends in a server error. An app that catches
the failure and answers in its own way took the failure in, and nothing is
said. The recorder keeps no values, so the two runs of a job are compared by
shape only. An update, a delete, or an insert that says what to do with a
row that is there (`on conflict`, `insert ignore`) can be made again, and is
not held against the job. A job that asks first and stops is clean.

A call made again is a finding only for a POST or a PATCH with no
idempotency key. The recorder marks a call that has a header or a field
named for idempotency (`keyed`). It reads the name, never the value. A GET,
a PUT and a DELETE can be made again. More calls from the line than in the
normal run means a new try. The same number means a loop that carried on
with its next call, and nothing is said.

An answer is judged by two facts. The error answer tells the recorder when
the app's code asks it for its status (`successful()`, `failed()`,
`status()`, `throw()`), and the trace says so (`asked`). The framework asks
every answer, and a package that watches outside calls can ask too. Neither
counts. The second fact is the shape of the request. An app that asked, or
that did not do the same as in the normal run, took the error in, and
nothing is said. The recorder marks each call the app's code made itself
(`direct`), so only those are places. One limit: an app that reads only the
body of the answer and carries on with the same shape is a finding. The
recorder keeps no values, so it cannot see that the body was used.

The two orders of an event's listeners are compared by shape too. The
recorder lists each such event in the trace (`events`), with the line that
dispatched it and its found listeners. The same sends and saves from the
same lines, with the same answer, is clean when the listeners use no table
together. When two of them use one table and one of them saves to it, the
shape cannot say what stayed there: the place is `missed`. A query is a
listener's when the listener is among the app's code on the way to it
(`frames`). What a listener changes only in memory is not seen. A job that
waits is compared the same way. The two groups are what jobs did and what
the app's code did after the job was dispatched. A request that reads the
table its job saves to, with the same shape in both runs, is `missed`.
What differs among the things the job did is the job's finding (job needs
the request). What differs in the rest is the request's (needs its job
done). One limit: a job that finds no person and carries on with the same
shape, such as an update that now changes no row, is not seen.

A finding counts against a change only when the failed effect, or what
stayed, comes from a line the change added. The rest is counted (`existing`)
and not reported. The places are tried in a fixed order (direction 33).
Places on the change's own lines come first. Next come places on a line or a
route that `AppTraces` or `AppBoundaries` has a finding about. Then sends
come before jobs, and jobs before saves: what cannot be taken back is tried
first. An answer is tried with the sends. It is the change's when the
change makes the call or wrote what the request does after it. The order comes only from the trace, the patch and those findings. At
most `points` places are tried, and no place starts after `seconds`, so the
owner's wait has a limit. A place whose failure did not happen is counted as
`missed`, never as clean. What a job on the sync queue does is not a place
of the request, because in use that job runs later on a queue. The job as a
whole is the place, and the save after its send is a second place of the
job. Both are tried with the jobs, before the saves of the request. They
are the change's when the change queues the job or wrote what it does. A
job the app sends to the sync queue by name is different: in use it runs
where the app dispatches it, once, and its error is the request's. The
recorder reads the connection the job names, does not mark the job, and
what the job does is a place of the request. A queued listener or a queued
email that names the `sync` connection in its own class, and an encrypted
job, keep the mark: the job the queue gets does not show that name. A
job that waits is the job's third place, and is the change's too when the
change wrote what the request does after the job. An
event is tried with the jobs too. It is the change's when the change
dispatches it or wrote what one of its listeners does. A
job that takes the failure of its save in is not tried again, and nothing
is said. A second run that the trace cut short is missed. An email that a test
fakes is a place too: the stand-in of the fake fails it the same way, before
the fake takes it.

The reviewer blocks all ten, unless the request or the plan asks for
exactly that. The owner reads each in the proof: "If saving fails at /invitations,
your app has already sent something. People are told about something that
was not saved." When failures were caused and nothing stayed: "We made things
go wrong 3 times while your app used the new code, such as an email that
cannot be sent or a save that fails. Each time, your app left nothing half
done." For a job: "Your app does some work on its own after someone uses
/orders. If that work is cut off and starts over, it sends or adds the same
thing twice." When the job sends twice only after its save failed, the
owner reads that in its place: "If saving fails during that work and it
starts over, it sends the same thing twice." For a call made again: "If an outside service is slow to
answer at /orders/{order}/pay, your app asks it again. The service may then
do the same thing twice, such as take a payment twice." For an answer that
is not checked: "If an outside service says it could not do what your app
asked at /orders/{order}/pay, your app does not look at that answer. It
carries on as if the service did it." For an event: "When
someone uses /orders, your app does a few things one after the other, and
nothing says which comes first. When they happen the other way round, your
app does not do the same things." For a job that waits: "Your app does some
work on its own after someone uses /orders, and does not wait for it. But
what your app does next only goes right when that work is already done."
For a job that needs the request: "Your app does some work on its own after
someone uses /orders. That work runs a moment later, after your app has
answered. By then something it counts on is gone, such as who the person
is, and it does not do the same things."
On the fixture the reference change has 2 places, both clean,
in about 2 seconds. A copy of it that sends an email before its last save is
found.

**Made-up colours are sent back too** (direction 26, the first design check
that graduated from the contract). The lines a change adds to screen files
(Vue, Blade, TSX, JSX; not tests, and not CSS, where the theme lives) are
checked for colours written out instead of taken from the theme: hex values
and colour functions, in a Tailwind arbitrary value (`text-[#1a2b3c]`) or an
inline style. Theme references (`bg-[var(--brand)]`), sizes (`w-[73%]`) and
the palette's own classes pass. Each file with one is a blocking finding. A
comment that mentions the colour, on the line or the line above, lets it
through. The owner sees the clean result as one line of the change's proof.
Pictures are checked the same way: an `<img>` tag a change adds (read across
its lines) must have an `alt` description, or `alt=""` when it is only
decoration. A tag with attributes spread in from elsewhere, or one that runs
into lines the change did not add, is unknown and passes. Its clean result
is a proof line too. `builder.verification.design_scan` turns both off.

**Screens are opened at three widths** (direction 26, the first browser
check). When the checks pass and a change touches a screen file (or CSS),
verification builds the app, serves it in the workspace and runs the screen
check (`resources/screen-check`, baked into the box image with Chromium). It
visits every GET route without parameters, signed out. It then seeds the
database with the app's own seeder, gives the app's first user (or one it
makes) a password for the purpose, and signs in to measure the pages behind
a login. Pages with parameters are reached through the links the measured
pages show. At 390,
820 and 1280 px it records sideways scrolling, words or controls cut off at
the screen's edge (a layout that hides overflow cuts them off instead of
scrolling), script errors and, at 390 px, controls under 24 by 24 px with no
room around them (WCAG 2.2 AA 2.5.8, with its spacing and inline-link
exceptions). The result is kept on the verification (`screens`); it never
changes the checks' result. A page names its screen through Inertia's page
component. On a page whose screen file the change touched, each kind of
problem is a blocking finding at its narrowest width. Other pages are
measured but never blamed. The owner sees a clean result as one proof line.
At 390 px it also measures contrast (WCAG 2.2 AA 1.4.3: 4.5 to 1, or 3 to 1
for large text; text over a picture or gradient is unknown). Faint words on
a touched screen are a gap line in the proof, never a send-back: they
usually come from the app's shared theme. At 1280 px it presses Tab through
the first 20 controls and names those that look the same focused as not
(2.4.7). Hidden focus is a gap line for the same reason. The check also takes a
picture of each touched Inertia screen (up to `shots_max`) at each width.
Verification copies them out of the workspace to `shots_disk`, and the
owner sees the first screen as Phone, Tablet and Computer pictures under the
first proof line, served only to people who can view the app.
Where the tool is not installed, nothing is measured and nothing is said.
`builder.verification.screens` holds the command and its switch.

Depend on the idea of observed test dependencies, not on Pest's cache format:
use affected-test output or a supported extension point.

**Soft requirements climb the same ladder** (direction 26). "Feels fast",
"consistent", "accessible", "not cluttered" are checked as far as evidence
allows, and no further:

| Kind of requirement    | How it is checked                                                                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Measurable             | Deterministic checks: response time budgets, query counts, bundle size.                                                                    |
| Structurally inferable | Heuristics and static analysis: token use instead of invented values, one primary action per surface, existing components reused.          |
| Visually observable    | Browser checks on touched screens: contrast, no sideways overflow at 390, 820 and 1280 px, visible focus, touch targets of at least 44 px. |
| Subjective             | The reviewer (a model) or a person judges hierarchy, clutter and coherence against the design contract.                                    |
| Unknown                | Ask the owner one question (§7).                                                                                                           |

Subjective qualities are never reported as passed checks. The owner sees the
results in plain words ("Buttons are easy to tap on phones"), not the ladder.

## 13. Packages: trust, understanding and adapters

Dependency order: Laravel native → Laravel first-party → our first-party →
trusted ecosystem → evaluated → unknown (review).

Trust and understanding are separate axes; policy uses both.

| Level          | Meaning                                                 | Unlocks                                         |
| -------------- | ------------------------------------------------------- | ----------------------------------------------- |
| L0 unknown     | nothing known                                           | human review                                    |
| L1 reputable   | advisories, license, maintenance, compatibility checked | install with review                             |
| L2 installable | adapter covers install, configuration, environment      | agent may install; guided setup                 |
| L3 verified    | declared vs observed passes; verifiers exist            | automatic verification; appears as a capability |
| L4 lifecycle   | upgrade sets, removal and replacement tested            | automated upgrades; replacement possible        |

**Package adapters** are our knowledge about a package, kept in our registry and
versioned by package version range; the package is unchanged. They contain the
capability manifest (models, tables, routes, events, authorization concepts
introduced), install recipe, configuration and environment schemas, supported
versions, verifiers and invariants, Rector sets per upgrade step, deprecations
and replacements, breaking changes, extension points, failure modes,
incompatibilities, removal and migrate-away strategy, and agent guidance as a
portable Boost-style guideline.

**Declared vs observed:** installing a package in a fixture application and
running `builder/introspect` checks the adapter against reality. Mismatches are
adapter bugs, found automatically.

**Our first-party packages** carry all of this inside the package
(`composer.json` `extra.builder`, `rector/` sets, upgrade fixtures, Boost
guidelines), are open source on Packagist, and make upgrades executable:
dry-run each step's set on a branch, report residuals, estimate the blast radius
from the graph, give residuals to an agent, verify, offer the branch. Build one
only when evaluation data shows repeated generation that Laravel plus a trusted
package does not cover. Freeze the manifest schema only after two real upgrades.

**Package replacement** is L4 on both sides: install the new package, transform,
let the agent fill gaps, verify, remove the old one. Not in scope yet; not
precluded.

**Policy v0:** a curated allowlist, deny by default, `composer audit` and
`npm audit`, licenses, and a review queue. Runner hooks block other installs;
verification re-checks the lockfile.

## 14. Visual editor

A primary product surface and a projection of real source code. Never a
separate page-builder document tree.

### Selection context

Resolved progressively when the user clicks in the preview; any level may be
`null`, with a reason:

```
element     DOM path, visible text, screenshot crop, current classes
source      file:line:col in the Vue template, component name
screen      Inertia page, route, URL
behavior    keys (Wayfinder action bound to the element, or the page's view behaviour)
capability, actors, permissions, side effects   (from the Product Behavior Graph)
impl_refs   component, policy, action, tests
```

It powers the inspector card (no model call), direct edits, and change requests
seeded with exactly what the user pointed at.

### Traceability (preview builds only)

A Vite plugin stamps elements with `data-source` from the single-file-component
compiler's source locations; Vue's development metadata gives component names;
the Wayfinder index links elements to behaviours. Conventions for generated
code: meaningful component names, every server action through Wayfinder, no
dynamic component resolution for interactive elements. Production output stays
clean.

### Direct edits (deterministic)

- **Tailwind classes:** spacing, sizing, alignment, flex and grid, typography,
  radius, borders, shadow, visibility, gap, position; tokens grouped by utility
  family with variant and responsive prefixes; values snap to the project's
  `@theme` steps but are not limited to them (§26.12). Any other class goes
  only when Tailwind would apply the new class in its place (`w-[calc(…)]`
  against a new width). The package `tales-from-a-dev/tailwind-merge-php`
  decides this. It is a PHP port of tailwind-merge, which the app's own `cn()`
  uses. Laravel has no first-party package for this, and it saves us from
  keeping Tailwind's clash rules ourselves. It is set up with the names the
  app's `@theme` gives each scale, so `text-hero` from `--text-hero` is a size.
  A `text-` class with a name the theme does not give is kept, because it may
  be a size or a colour.
- **Literal text** in templates, or translation files for translation keys.
- **Show or hide** by breakpoint.
- **This instance or all instances:** editing a shared component changes it
  everywhere, so the editor asks; "this one only" adds the class at the usage
  site, which shadcn-vue components merge with `cn()`.
- **Not static → agent:** dynamic `:class`, `v-if`, loops, props, database
  content, anything tied to permissions or behaviour.

Edits collect on a visual-session branch, commit on save, and get light
verification.

**Designing a change before it is kept.** A change that waits to be kept has
its own branch, `changes/{id}` (`OpenChangeForDesign`). The branch holds the
change's base, the changes it follows up on, and then the change itself.
`design_base` marks the commit where the change's own code starts. The copy of
the change is an editable preview that runs from this branch, so the owner
designs "After" as they design the app. `Preview::branch()` names the branch
that design edits, undo, formatting and rebuilds use. Each edit records its
change in `visual_edits.feature_request_id`. After each commit on the branch,
`FollowDesignedChange` reads the change's patch back from `design_base` to the
branch head (`AmendChangeFromDesign`) and rebuilds the copy. So keeping the
change keeps the edits, and the app does not move until then. The design panel
lists only the edits of what is on show. A copy started before this has no
branch; design mode then shows the app without the change.

Direct edits are written without the app's formatting, so the preview shows
them in about a second. When the editable preview shows the newest version,
`FormatEditedFiles` waits `builder.preview.format.after_seconds` (10 s). Then
it runs the app's own formatters (`builder.construction.formatters`) on the
changed files. The formatters work on copies in the preview's workspace, so
the running build does not see them. The job commits the result. If the owner
changed the app meanwhile, it does nothing; the next rebuild asks again.

Formatting moves the app on while the owner may still be editing. So
`FormattedRevisions` remembers each formatting commit. An edit sent on the
version before one continues on the formatted version. `FollowLocation` finds
the element there by its place in the order, because formatting keeps every
element and its order. Class lists are compared without order, because
Tailwind ignores order and the formatter may sort the classes. Undo follows the
element from the edit's commit to the newest version.

### Both users

Target A: text, size, spacing, alignment, appearance, show/hide, "What this
does", "Describe a change". Target B: additionally classes, component, props
and states, behaviour, permissions, related API, tests, source, history.

### Live feedback

Hot reload needs WebSockets, which the current PHP preview gateway cannot relay.
Visual sessions run the Vite dev server behind an edge proxy (for example Caddy
or Traefik) that asks the control plane to authorize each request, keeping the
gateway's grant, session and isolation rules.

## 15. Previews

Implemented (G2.4): a preview runs the application with a change applied in its
own workspace and serves it at `http://{host}.{preview domain}`.

- Separate origin per preview; a global middleware hands preview hosts to the
  preview gateway before routing, sessions or cookies, so a preview host never
  reaches the control plane's routes.
- Single-use 60-second grant → HttpOnly, host-only, SameSite=Lax cookie on the
  preview host; requests without it never reach the application.
- Requests are relayed as the preview host (links and emails point at the
  preview); form and multipart bodies are rebuilt; the gateway's own cookie is
  stripped.
- Reaped after maximum age or idle time; stopping kills the server and removes
  the workspace.
- Serves built assets; hot reload arrives with the edge proxy.

### What the app does behind the page

An app does work the page does not show: it sends email, saves rows and
writes down its problems. The owner must see that work to try the app. The
builder shows it in tabs beside the app, for the preview on show. Each tab
reads the preview's workspace, so the app itself does not change.

- **Emails** (built). A preview sends email to its log
  (`MAIL_MAILER=log`, `MAIL_LOG_CHANNEL=single`), so nothing leaves the
  machine. The builder reads the log file (`builder.preview.log`), finds
  each email in it and lists them newest first. An email opens as its
  reader sees it, in a sandboxed frame without scripts. A link to the app
  opens that page in the app on show, so sign-up, password reset and
  verify-email flows can be tried to the end.
- **Problems** (built). The same log file holds the app's errors. The
  tab lists them in plain words, newest first, with the place in the code
  that caused them. The same fault met again is counted, not listed again.
  The details for a developer stay folded. Each problem offers "Ask me to
  fix this", which starts a normal change. A problem leaves the list when
  its fix is kept, or when the owner clears it. It returns, marked "came
  back", if the app runs into it again after that. The log itself is never
  changed; the builder keeps only which problems the owner cleared.
- **Saved data** (built: tables and counts). The preview's tables, with
  how many rows each holds: what a sign-up or an order saved. They are read
  through the app itself (`db:show`), with the settings it runs with, never
  from the control plane's database. Laravel's own tables stay folded. The
  owner can start the data again, with the app's example data
  (`migrate:fresh --seed`) or empty, after a second click; only the copy
  they try changes. A table opens to its newest 50 rows, with what
  visitors sign in with hidden. Below the tables are the files the app
  stored, such as uploads, newest first: a picture shows, anything else
  downloads. Changing rows comes later, with an undo.
- **Schedule** (built). The tasks the app runs on its own
  (`schedule:list`), each with when it runs in plain words and how long
  until it runs next. "Run it now" runs one at once (`schedule:test`), so
  a daily reminder email can be tried without waiting a day. What it sent
  or ran into shows in the other tabs.
- **Pages** (built). The address beside Back and Forward opens a list of
  the app's pages, read through the app itself (`route:list`): each web
  address that needs nothing filled in, with a lock when a visitor must
  sign in. Picking one opens it in the app on show. Framework addresses
  that answer with data, not a page, are left out.
- **Jobs** need no tab while previews run queued work at once
  (`QUEUE_CONNECTION=sync`).

## 16. Model gateway and credentials

Every model call, from the control plane or a runtime, goes through one metered
path.

- The runtime gets a base URL and a short-lived token; the gateway injects the
  real credential, so an agent with a shell never sees it; it records usage and
  enforces budgets as hard limits.
- The gateway also adds our instructions on its side (how to work, the
  discretion and observability rules), so the box holds only the task: the
  plan, its acceptance criteria and the owner's own request for their own app
  ([§19](#19-learning-and-privacy)). Not built yet: today the SDK driver sends
  our working rules inside the task. This protects the rules only in our own
  boxes; a worker on the owner's machine sees whatever it is sent. So the
  working rules are written to be read: plain engineering guidance, with
  nothing in them that would do harm when read
  ([§11](#workers-one-boundary-for-ours-and-theirs)).
- **Credentials vault** per account, encrypted, masked, revocable, each checked
  by a test call before saving: `api_key`, `claude_subscription_token`,
  `codex_chatgpt_token`, and provider OAuth (for example OpenRouter) later.
- **Pricing is Grandma-first: one unified price.** The owner pays one plan
  price and never sees credits, tokens, models or cost per call. What each
  change costs us is internal telemetry (§25.2), not an owner-facing number.
- **Power users choose pay as you go,** or bring their own keys, from
  settings. It is an option they look for, not an onboarding question.
- **Subscription tokens:** technically supported, but Anthropic's documentation
  states that third-party developers may not offer claude.ai login or rate
  limits in their products unless previously approved. They stay flagged off on
  our cloud until the providers approve; allowed with the local runner. When
  used, only the agent process receives them, never test or preview commands.
- **Model choice:** presets ("Recommended", "Best quality", "Lowest cost")
  mapping each stage to tested models; an advanced override per stage.

## 17. Safety and approvals

Development-time changes flow freely; high-consequence operations need explicit,
scoped, expiring approval, explained in business language: production
deployments, destructive migrations, secrets, payment configuration, outbound
email or SMS, paid infrastructure, data deletion, domain and DNS changes,
integrations with real consequences. The behaviour diff detects most of them
deterministically (a new mail channel, a destructive migration, a new secret).

### Outside services and their keys

Payments and email are what make most business apps usable, so the owner can
connect them without a developer.

- **A fixed catalogue** in `config/builder.php` (`services`): payments through
  Stripe with Laravel Cashier, and email through Resend with Laravel's mail.
  Each entry names its fields and how to check them, fixed settings (for
  example `MAIL_MAILER=resend`), the change the owner sees asked for, and the
  agent's guidance.
- **The owner pastes keys; nobody else sees them.** They are stored encrypted
  with the project and never sent back to the page. The agent gets only their
  names, in a section of every plan and build prompt, and is told to read them
  through config and keep them out of the repository.
- **The first connection is a normal change.** It goes through the plan,
  checks, review and the owner's keep. New keys for a connected service change
  only the keys.
- **The keys follow the app wherever it runs.** A preview gets them as
  environment variables, but the preview's own settings win, so a preview
  never sends real email. A Laravel Cloud release appends them to the app's
  environment variables before it deploys. An owner who publishes to their own
  branch sets them where they host. The checks never get them: the app's tests
  fake outside services.

## 18. Imported applications

1. Read-only introspection and a conformance report: supported as-is; harmless
   variation, recorded as the project's own conventions so agents follow them;
   problematic structure (missing authorization, unsafe patterns, abandoned
   packages).
2. Baseline: run their tests; offer characterization tests for critical flows,
   confirmed by a person, when there are none.
3. Opt-in normalization, one rule set per branch, verified. Never a rewrite.
4. Frontier models only for semantic refactors the owner asked for.

The graph for an imported application leans on AI interpretation at first and
shows it honestly; normalization improves it over time.

## 19. Learning and privacy

- **Loop:** normalization rules that fire, transform residuals, verifier failure
  categories, repeated plan operations and escalations feed a candidate queue
  (rule, verifier, package, adapter fix, template). People triage; AI drafts;
  promotion follows the governance in §10.
- **Telemetry by default, code by consent:** aggregate operational outcomes need
  no customer source. Code-derived patterns (even fingerprints) are opt-in.
  The corpus for rule building is our fixture and evaluation applications plus
  projects that explicitly opt in under a written policy.
- **Metrics:** pass rate per task class and engine, cost, repairs, share of work
  done without a model, tokens avoided, generated foundation code per feature,
  behaviour-diff and annotation accuracy.
- **The customer repository shows no trade secrets:** by default, a project's
  repository is a private repository in our organisation. Owners can also bring
  their own. Either way, it must look like the work of the app's own developer.
    - Commit subjects are written in a developer's words (the planner's
      `commit_subject`). They never quote the owner's request. They have no
      trailers and do not name the builder or its screens.
    - Commits are committed by their author, unless an operator sets
      `BUILDER_COMMITTER_NAME` and `BUILDER_COMMITTER_EMAIL`.
    - Agent prompts do not mention a platform, a builder or a control plane. The
      coder is told to write as the app's own developer. Files the runner puts in
      a workspace go inside `.git/` and have neutral names.
    - The workspace box holds nothing of ours either: no control-plane code,
      keys or prompts ([§11](#adapters)).
    - The project notes are never committed to the repository (§26.3).
- **The code is the owner's to take.** "Download your app" in the app menu
  sends the main branch's files as a zip (`git archive`), in a folder named
  after the app. The history and the notes stay with us.
- **Workers see compiled text, never our machinery.** What stays on the
  server, what a brief may carry and what is open on purpose are listed in
  [§11](#workers-one-boundary-for-ours-and-theirs). A brief lint enforces it.

## 20. Deliberately not built yet

Postponed in version 8 because they exist mainly for elegance, not to answer an
observed user problem (each returns when a measurement asks for it):
content-addressed snapshot storage (V0 stores plain per-snapshot rows); the
second provider adapter and learned routing (the contract and telemetry stay);
package trust levels and adapters beyond an allowlist; custom Rector rules and
the rule-promotion pipeline; the typed-operation catalogue beyond
`capability_config` and `agent_task`; AI comparison of prose intent; the edge proxy and hot reload; imported
applications; the invariant lifecycle interface; showing all five provenance
classes to users (three badges suffice). Postponed in version 9: a precedent
sources pipeline (public examples, domain research, aggregate insights), an
editing interface for precedents, semantic retrieval over the library,
application-type and design-flow precedents beyond the pilot's domains, and
fetching or screenshotting referenced products.

Also not built:

Sprints, estimates, boards, backlog grooming and any project-management
vocabulary in the product; a requirements database beyond one context table;
onboarding questionnaires; a persistent engineering graph or graph database; trust scoring; a package
marketplace; adapters beyond the packages we use; package replacement; a large
rule catalogue; a page-builder document tree; generic multi-framework support;
targeted test selection; multi-agent decomposition within one request;
automated upgrades across many projects; inferred invariants as protections;
online routing experiments on high-risk work.

Not built at all (version 23): a formal planning language (PDDL) or symbolic
planner, a theorem prover, a symbolic world model of the application, a formal
behaviour or specification language, a copy of the app's schema or
authorization model, a dependency ontology built by a model, and confidence
scores for assumptions. Rules start as plain language and become checks
progressively (§31.2); effects carry provenance, not percentages.

Not V1 (version 25, direction 26), each a consequence of the core loop working
rather than a prerequisite for proving it: business usage analytics, semantic
runtime journey tracking and observability, production-derived Effects,
anomaly detection, runtime behaviour reconciliation, goal optimisation from
telemetry, an expert marketplace and expert matching, semantic undo, a mature
architecture-rule compiler or complexity-budget scoring, mature audits and
adversarial review, a learned model router, a large precedent database,
native PHP or Symfony support, a fully deterministic Effect graph, and full
reverse engineering of existing repositories.

Not built for workers (version 31, direction 31): a separate gateway service,
OAuth or device flow for workers, our own signed capability tokens, graph or
embedding queries, a model that answers product questions, field-level
redaction, a git server for local workers, and streaming preview traces.

## 21. Status and staged plan

### Built

| Increment | What exists                                                                                                                                                                                 |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| G0.1      | Control plane on Laravel 13, Inertia, Vue, PostgreSQL and Redis; checks; CI                                                                                                                 |
| G0.2      | Customer-app fixture (teams, roles); invitations reference solutions; platform-owned acceptance suites                                                                                      |
| G2.5      | Protected verification in a fresh workspace: setup, checks, protected acceptance; passed / failed / errored / skipped / not applicable; Unverified                                          |
| G2.1–G2.3 | Runs with a state machine, fenced leases, operation journal, server-side tools, budgets, cancellation, reconciler, scripted driver                                                          |
| G3        | Planner / coder / reviewer roles on laravel/ai with faked tests; repairs; usage logging. The coder runs inside the control plane today and moves to the runtime with the execution adapters |
| G2.4      | Previews on their own host with single-use grants                                                                                                                                           |

### Next stages

Version 10 replaces the staged plan with V0 ([§26](#26-v0-what-we-build-now-version-10)).
The version 8 stages remain the likely order of what V0's failures will ask
for; none is started without that evidence.

## 22. Risks

- **Sandbox economics and isolation** decide margins. Buy sandboxes; cache
  installs in template snapshots; isolate runtimes and keep secrets out.
- **Wrong deterministic knowledge at scale.** Governance for rules and adapters
  (§10, §13).
- **Rules hidden in code are the hardest and most valuable part for Target A.**
  AI descriptions can be wrong; provenance, test-verified rules and observable
  code conventions are the mitigations.
- **Test-observed facts cover tested paths only.** Show "not verified".
- **Behaviour grouping decides comprehension.** Too fine reads like a route list;
  too coarse blurs rules. Needs its own evaluation tasks.
- **Wrong protected tests or invariants.** Owners confirm criteria in plain
  language; inferred invariants stay proposals.
- **Routing data is sparse early.** Evaluation matrices, not production traffic,
  drive routing v0 and v1.
- **Cross-provider hand-offs lose tacit context.** If a stage keeps needing a
  transcript, the artifact design is missing something.
- **Visual editing gets hard at dynamic classes, shared components and
  conditional rendering.** The instance/all choice and "not static goes to an
  agent" keep it honest.
- **A bad precedent steers inexperienced owners confidently the wrong way**, and
  the library is content that needs upkeep. Curation status, sources, the
  escape hatch, the review trigger on replaced or reversed options, and P1
  deciding whether the library exists at all.
- **Vendor terms** for subscription logins; approval before enabling on our
  cloud.
- **Corpus privacy.** Learn from customer code only with consent.

## 23. Open decisions

- The unified price and the fair-use limit it includes; the pay-as-you-go
  rates for power users.
- Providers beyond Anthropic and OpenAI, and the OpenAI agent SDK choice.
- Curated presets only, or also an open model picker.
- Approval from Anthropic (and a position from OpenAI) for subscription tokens
  in a hosted product.
- Which box provider to start with. The code does not depend on the answer
  ([§11](#adapters)).
- The product's public name and category (not "Laravel builder").
- Whether to charge for accepted changes rather than raw usage (§25.6).
- Recruiting 3–5 owners for the behaviour-diff study (§26.7).
- Who writes and reviews precedent files (us, or domain experts per vertical),
  and whether owners' option choices may be aggregated anonymously.
- Test impact analysis needs Pest. The template uses Pest (§27.6), but the
  fixture and imported apps may use PHPUnit: convert the fixture, or keep a
  PHPUnit path with no observed Effects.

## 24. Convention over generation: reassessment

Version 7 re-examined the whole design against reuse → configure → compose →
transform → generate.

### 24.1 Where we still over-rely on generation

- **Our own demo feature.** Team invitations (23 generated files in the fixture)
  are foundation code that every multi-user app needs. Under this thesis the
  product path for "invite people" is to compose a capability and configure
  it; agent-built invitations stay only as an evaluation task.
- **Plans that tell the agent how to implement.** The G3 planner writes task
  lists for the coder. Strong coding agents plan their own implementation, so
  the plan shrinks to intent, criteria, assumptions, affected behaviours and
  typed operations. The task list goes.
- **Our own agent loop in the control plane.** G3's tool loop re-implements a
  harness that the Agent SDK already provides. It moves to the runtime and the
  SDK (§11); the per-call journal stays only for scripted runs and tests.
- **Per-feature protected tests.** For capability-backed behaviours, the
  capability ships its contract tests; generated protected tests are only for
  genuinely application-specific behaviour.
- **Screens generated from nothing.** Agents should compose the starter kit's
  components and a small set of page patterns (list, detail, form, settings)
  that live as ordinary files in the app.
- **Behaviour names and purposes.** Capability manifests carry their behaviours'
  human names, so only custom behaviours need AI annotation.

### 24.2 Recurring concerns and where they should come from

Laravel or first-party first, then trusted packages through adapters; our own
capability only for what neither covers:

| Concern                                                                         | Source                                                                                                                                                                                     |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sign-in, registration, password reset, email verification, two-factor, passkeys | starter kit (Fortify)                                                                                                                                                                      |
| Billing and subscriptions                                                       | Cashier (first-party), with an adapter                                                                                                                                                     |
| Feature access and flags                                                        | Pennant                                                                                                                                                                                    |
| API tokens                                                                      | Sanctum                                                                                                                                                                                    |
| Social login                                                                    | Socialite                                                                                                                                                                                  |
| Search                                                                          | Scout                                                                                                                                                                                      |
| Realtime                                                                        | Reverb and Echo                                                                                                                                                                            |
| Files                                                                           | Storage and disks                                                                                                                                                                          |
| Notifications, queues, scheduling, rate limiting, health                        | framework                                                                                                                                                                                  |
| Roles and permissions beyond a simple enum                                      | a trusted package (for example Spatie's permission package) with an adapter                                                                                                                |
| Audit history, media handling, outgoing webhooks                                | trusted Spatie packages with adapters, when needed                                                                                                                                         |
| **Organizations: memberships, ownership, invitations, the tenant boundary**     | **our first capability**: recurring, not covered by the current starter kits as far as we have seen, and where standardisation buys verification (tenant isolation, "always has an owner") |
| Onboarding, approvals, usage metering                                           | our capabilities only when evaluation data shows repeated generation                                                                                                                       |

Rejected for now: a separate admin-panel framework, because a second UI stack
would break the one blessed frontend and the visual editor.

### 24.3 Laravel defaults to exploit deliberately in V0

The official Vue starter kit as the project template; Fortify; policies and
gates for all authorization; FormRequests for validation; named resource routes
(stable anchors and behaviour grouping); route model binding; Eloquent,
migrations and PostgreSQL; the database queue driver; notification classes with
the log mailer in previews; the scheduler; enums and config for business
parameters and roles; Pennant; Storage; Pest with architecture presets and
browser tests; Pint; Larastan; Wayfinder and Inertia `<Form>`; Boost; `make:*`
generators. Single-purpose action classes are a light convention, not a
requirement.

### 24.4 What stays fully open to coding agents

Domain modelling and business logic; custom workflows and state machines;
integrations with arbitrary external services; custom screens and interactions
beyond the page patterns; data migrations and backfills; debugging and
performance work; anything novel. There are no forbidden zones in the codebase
beyond protected paths and the dependency policy.

### 24.5 Is the product layer framework-independent?

Mostly. The ontology table in §4 is now stack-neutral, and every Laravel term
sits in implementation references, derivation data or the Laravel stack
profile. Two tests keep it honest: the product UI at levels 1–3 must render
without a single framework word, and the Product Behavior Graph schema must
validate without any Laravel-specific enum value.

### 24.6 Implementation details that were leaking, now fixed

- Behaviour keys were route names and class names → opaque keys plus anchors.
- Surface kinds `queue` and `command` → `trigger` and `operator_tool`.
- Capability sources named Laravel package tiers → built-in, platform,
  trusted-package, custom.
- "Tables written" as data → business data concepts, with tables in
  implementation references.
- Build-cache inputs (file paths) inside product records → a separate
  `derivations` table.
- Project Context rules referenced route names → they reference behaviour keys.
- Automation as a separate entity → a view over behaviours.

### 24.7 Where we risk becoming low-code, and the escape hatch

| Risk                                             | Escape hatch                                                                                                                                                                         |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Capabilities as closed boxes                     | Capabilities extend through ordinary Laravel: bind your own action, listen to events, override policies, publish views and components. Customisation is code, never a settings maze. |
| Configuration schemas that cannot express a need | Any request that does not fit falls through to an agent task that edits code.                                                                                                        |
| A component catalogue                            | UI components are copied into the app and owned by it (the shadcn approach), never a locked library.                                                                                 |
| The visual editor                                | "Describe a change" is always available next to direct edits.                                                                                                                        |
| Typed operations                                 | `agent_task` is always a valid operation.                                                                                                                                            |
| Behaviours only from capabilities                | The graph is derived from all code, including custom code and edits made outside the platform.                                                                                       |

Ejecting a capability into app code is a later, L4-style feature.

### 24.8 What gets more valuable as models improve

The Product Behavior Graph and behaviour diffs; Project Context; provenance and
independent verification; trusted capabilities, adapters and deterministic
rules; the evaluation harness and routing; the gateway, credentials and
sandboxes; visual selection context; approvals.

What we keep deliberately thin, because better models absorb it: planning
detail, context-compilation heuristics, prompt engineering, step-level
orchestration, and our own agent loop, which we drop.

### 24.9 What the first slice can prove

On the customer-app fixture, one sequence covering the whole ladder:

1. **Reuse and configure.** "Only managers can invite": a configuration edit with
   no model tokens for the change, and a behaviour diff in plain language.
2. **Discover.** The decision check asks one question only when the answer
   matters, and remembers it.
3. **Generate the application-specific part.** For example "remind invitees the
   day before their invitation expires": the agent writes only the new
   behaviour, composing scheduler and notification conventions.
4. **Observe.** Every step shows a behaviour diff at level 1 with zero framework
   words, and levels 4–5 for the power user.
5. **Ordinary software.** The fixture's own CI (clone, install, test) passes
   with no platform involvement.

Measured: share of each change done without a model, generated lines per
behaviour, questions asked per request.

### 24.10 Explicitly not building yet

A first-party organizations package (prove the configure path on the fixture's
own code first, then extract when a second app needs it); package adapters
beyond what the slice uses; the trust engine; a rule library beyond
`rector-laravel` and one normalization set; any generic multi-framework layer
beyond keeping the product schema neutral; our own component library; page
templates beyond four patterns; the visual editor before stage 6; learned
routing; imported applications; deployment.

## 25. Outcomes, measurement and falsification

User research on current AI app builders (direction 09) shows the category has
largely solved "can AI quickly make an application?". The complaints cluster
around "can it keep changing that application without wasting my time, credits
or trust?": repeated explanation, many prompts for simple behaviour, credits
spent on rework and tiny UI tweaks, and regressions in things that used to work.
Version 8 optimises for that second problem, and treats every mechanism in this
document as a **hypothesis to be tested**, not a truth.

### 25.1 What each mechanism is for

| Complaint                                                    | Mechanism                                                                | Hypothesis                                                                           |
| ------------------------------------------------------------ | ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
| "I already explained this"                                   | Project Context, Context Compiler                                        | C1: later requests need fewer corrections and retries                                |
| Many prompts for simple behaviour; credits burned on rework  | targeted questions; minimum sufficient context                           | C2, C3: lower cost per accepted change                                               |
| "It broke something that worked"                             | behaviour diff with unexpected changes; full test suite; protected tests | B1: owners catch unrelated changes they would otherwise miss                         |
| "What does this do?" asked again and again                   | behaviour cards                                                          | B2: fewer explanatory model calls; owners answer questions about their app correctly |
| "I didn't know that was possible"; decisions regretted later | precedent options with a reason from the user's context (§7)             | P1: important decisions are raised before they cause rework, and reversed less often |
| Credits spent on tiny UI tweaks                              | deterministic visual edits                                               | V1: UI tweaks complete without agent runs                                            |
| Backend churn                                                | convention over generation; capability metadata                          | D1: fewer files touched and fewer regressions per change                             |

### 25.2 The economic metric: cost per accepted change

The unit is a **change request**: from the user's request to its outcome
(accepted, rejected, abandoned, or superseded by a correction).

- **All cost is attributed to it:** every model call through the gateway
  (including clarification, planning, annotation, memory and review calls),
  sandbox and runtime seconds at their rate, verification runs, retries and
  escalations, and external tools.
- **Accepted** means the user explicitly accepts in the review, or keeps the
  change with no revert or corrective follow-up on the same behaviours within
  seven days. The two are recorded separately.
- **Cost per accepted change** = total cost of all change requests in a period
  (failed and abandoned ones included) ÷ accepted changes. Tokens per prompt is
  deliberately not a target.
- The same record also yields user-side cost: turns, questions answered and
  wall-clock time to acceptance, since the user's time is spent too.

### 25.3 Telemetry V0 preserves

An append-only event stream keyed by change request. No dashboards in V0, only
the raw events:

| Event                                      | Fields that matter                                                                                                                                                               |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `request.created`                          | source (chat, behaviour card, visual selection), target scopes, project age                                                                                                      |
| `question.asked` / `question.answered`     | key, category, pre- or post-build, latency to answer, whether the user left; options source (precedent and version, or model), option chosen, "Something different", "Show more" |
| `suggestion.shown` / `suggestion.decided`  | next-step key, accepted, not now, don't suggest                                                                                                                                  |
| `context.compiled`                         | entry ids included, tokens per section, condition (for experiments)                                                                                                              |
| `stage.finished`                           | stage, engine, model, tokens in and out, cost, duration, outcome                                                                                                                 |
| `sandbox.usage`                            | seconds, cost                                                                                                                                                                    |
| `verification.finished`                    | per check, first attempt or retry                                                                                                                                                |
| `behavior_diff.computed`                   | requested changes, unexpected changes                                                                                                                                            |
| `unexpected_change.resolved`               | kept or undone; later confirmed as a real regression or not                                                                                                                      |
| `review.decided`                           | accepted, rejected, follow-up requested, free-text reason                                                                                                                        |
| `change.reverted` / `correction.requested` | within the seven-day window                                                                                                                                                      |
| `memory.written`                           | source (answer, classifier, consolidation), provenance                                                                                                                           |
| `visual_edit.applied`                      | deterministic, or handed to an agent                                                                                                                                             |

These are enough to compute every metric in direction 09: cost per accepted
change, first-attempt pass rate, retries before acceptance, whether questions
reduced retries, context size against success, success and cost by task class
and provider, transform and verification outcomes, and unexpected changes
detected.

### 25.4 Experiments

Superseded in version 10 by [§26.7](#267-experiments-revised). The version 8
design set pass marks on 3–5 runs per condition, which is false precision for
noisy agent runs; it also compared structured context against nothing rather
than against a flat notes file, the strongest cheap alternative.

### 25.5 How much clarification helps speed

- **Before the first visible result:** at most three questions, and one or two
  is the aim; opening discovery must not delay the first preview beyond one
  build.
- **Typical request:** at most one question before building; everything else is
  built with a stated assumption and confirmed in the review.
- **Harm signals, tracked:** time to first preview, users leaving after a
  question, questions answered with "you decide".
- Precedents do not raise the budget: they change which question is asked and
  how, not how many.
- The threshold is tuned by experiment C, not by opinion.

### 25.6 What the architecture does not solve

Billing policies and credit expiry, outages and infrastructure reliability,
support quality, account and project recovery, pricing transparency, the
quality ceiling of the models themselves, and hosting and deployment
operations. These need operational excellence: backups (every project is a git
repository, pushed to storage we back up and optionally to the owner's own
GitHub), status reporting, transparent billing, recovery tooling and good
support. They are tracked as product operations, separate from this
architecture. One business option the metrics make possible: charging for
accepted changes rather than raw usage.

### 25.7 What was postponed, and why

Kept because they answer observed pain: Project Context and the Context
Compiler (repeated explanation), the question gate (rework), behaviour diffs
with unexpected-change detection (regressions and trust), behaviour cards
(repeated explanatory calls), visual edits (credits on tiny tweaks), protected
verification and previews, the runtime with the Agent SDK, the gateway, and
telemetry.

Postponed until a measurement asks for them: see the list at the top of
[§20](#20-deliberately-not-built-yet). The common thread is that each is sound
engineering but addresses no complaint we have evidence for yet.

### 25.8 The slice that tests the claims

> **Version 10:** the V0 flow in [§26.2](#262-the-flow) replaces this slice.

The strategic problem is _continuing to change_ an application, so the slice is
about continuation, not initial generation:

1. **The same sequential scenario in the harness and with real owners.** An owner
   works on an existing app (the customer-app fixture, then a small booking
   fixture) through 6–10 changes: chat requests, a behaviour-card request, one
   visual tweak.
2. **The platform:** compiles context, asks only gated questions, runs the Agent
   SDK agent in the runtime, verifies, previews, and shows the behaviour diff
   with requested and unexpected changes.
3. **One planted unrelated change** tests whether owners catch regressions
   through behaviour diffs.
4. **Every event** in §25.3 is recorded.

It answers, with numbers: does accumulated context lower cost per accepted
change and retries on later requests? Do questions pay for themselves? Do owners
catch unrelated changes? If the answers are no, we simplify or remove the
mechanism, as §25.4 commits us to.

## 26. V0: what we build now (version 10)

**The high-level architecture is frozen.** This section is what gets built now.
Where it is simpler than §6–§25, it wins for V0; the rest is the direction of
travel, and each part is built only when a V0 failure asks for it. The
development rule is the product's own: idea → cheapest believable prototype →
real interaction → observe pain or value → generalise only if necessary.

### 26.1 Shape

V0 is deliberately asymmetric: an ordinary Laravel application from our
template, one coding agent from one provider behind the execution boundary (no
provider concepts in the control plane), git, scoped Markdown context, the
Laravel verification we already have, one visual selection path, and a
plain-language behaviour review. The core mechanism is real, not mocked:
**selective context, behaviour notes, Effects and verification**. What V0 defers
is breadth and automation: behaviour notes and Effects are written by people and
by the agent, not extracted.

### 26.2 The flow

| Step                                     | V0                                                                                                                                 | State                     |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------- |
| 1. Describe a small application          | Project created from the template; the description seeds the notes' `project.md`                                                   | new                       |
| 2. Answer one useful product question    | The planner may return one question before building (optionally with hand-written precedent options); the run waits for the answer | new (run state exists)    |
| 3. See something generated               | Run, verification, preview                                                                                                         | built                     |
| 4. Select an element or request a change | Text requests; one selection path from instrumented components in the preview                                                      | text built; selection new |
| 5. The agent uses project context        | Context selection (§26.3)                                                                                                          | new                       |
| 6. See the changed application           | Preview                                                                                                                            | built                     |
| 7. Review what changed                   | Requested, "may also affect" and unexpected changes in plain language (§26.5)                                                      | new                       |

### 26.3 Context as Markdown in the application

```
project.md              # goal, users, terminology, design, app-wide rules
capabilities/
    invitations.md
    billing.md
```

**Where the notes live.** The notes are a trade secret, so they never go in the
app's repository (§19). The database is the lasting copy:

- `project_notes` holds one row per file for each line of work (the main branch
  and each idea's branch). A write from the owner, a question's answer or a
  kept change is saved there first and is available at once.
- Each workspace gets a copy in a hidden directory (`BUILDER_NOTES_DIRECTORY`,
  default `.product-notes`) before its baseline commit. The workspace can be
  thrown away at any time.
- A run's patch never includes that directory. What the run did to the notes
  is kept on the change as `note_changes` (each file before and after). Keeping
  the change applies them; undoing it reverses them. A file someone edited in
  the meantime keeps their version. A follow-up starts from the notes its
  parent left.
- An idea starts with a copy of the main notes. Using it brings back the files
  the idea changed; throwing it away forgets them.
- Files a workspace's setup makes and that should survive it, such as `.env`
  (`BUILDER_WORKSPACE_FILES`), are kept encrypted in `workspace_files`. The
  first workspace saves them, and every later workspace gets the saved copy.
- Notes that older versions kept in `.builder/` are imported when a project is
  imported. `php artisan projects:move-notes` moves them out of existing
  repositories with one commit.

The layout of the files:

```
project.md              # goal, users, terminology, design, app-wide rules
capabilities/
    invitations.md
    billing.md
```

A capability file is plain Markdown with a small frontmatter:

```markdown
---
capability: invitations
summary: Owners and admins invite people to join a team.
paths:
    [
        app/Actions/Invitations/**,
        app/Http/Controllers/Invitation*,
        resources/js/pages/invitations/**,
    ]
behaviors:
    - key: invite-member
      name: Invite a member
effects:
    - to: membership
      strength: strong # strong | possible | historical
      reason: Accepted invitations create memberships.
      source: agent # agent | package | analysis | owner | tests
      observed: 2026-09-26
    - to: billing
      strength: possible
      reason: Active members may count towards paid seats.
      source: owner
---

# Invitations

## Rules

- Only owners and admins can invite.

## What each behaviour does

### Invite a member

…
```

**Selection is the V0 Context Compiler** (§8), deterministic:

1. `project.md`, always.
2. The target capabilities' files. Targets come from the planner choosing among
   the capability names and summaries, or from a visual selection's capability.
3. For each target, its Effects as one-line hints (name and reason), not the
   affected capabilities' files.
4. The agent may open any other notes file itself; the pack guides, it
   never imprisons.
5. Log the files and tokens included.

**Writing knowledge.** An answer to a question is appended to the relevant file
deterministically. The agent may edit its workspace copy of the notes as part
of its change, including Effects it discovered ("accepting an invitation changes
the seat count"); they are kept with the change and listed in the review. Owners
edit the notes on the Understanding page. An edit made on an old copy is refused.

**The planner's view of the code** is also deterministic, and kept small because
every planning call pays for it. The file list is grouped by folder, so each
folder is named once; this halves its size. The app's addresses come from
`route:list --json`, run in the workspace, as one line each: method, path, the
code that handles it and its name. With this map, the planner can name the right
files in its tasks, and the coding agent searches less. It is left out when the
app cannot list its routes.

**Progression.** V0 is stage 3 of: one `PROJECT.md` → plus capability files →
frontmatter and behaviour notes → indexed retrieval → the richer compiler with
`context_entries` (§7). Each later stage is built only when the one before it
demonstrably limits us.

### 26.4 Effects

An Effect says: this behaviour or capability may have a meaningful relationship
with another area, worth inspecting when it changes. It is a soft relevance hint:
not a contract, not an exhaustive dependency list, not a requirement that the
other area change, not proof of causality. The wording to users is "May also
affect: Billing".

- **Strength** is `strong`, `possible` or `historical`; never a percentage.
  Each Effect has a reason, a source (agent, package, analysis, owner,
  `tests` from test impact analysis, `history` from kept changes, §6)
  and when it was last observed; an Effect whose reason no longer holds is removed or
  downgraded, by the agent or the owner.
- **Context:** Effects are listed as hints; the agent decides whether they
  matter. "Change the Invite button text" does not look at billing; "invited
  users become members immediately" probably does.
- **Review:** the changed files are mapped to capabilities through `paths`, so a
  change is classified deterministically as _requested_ (a target capability),
  _may also affect_ (a capability named by a target's Effects) or _unexpected_
  (anything else, including code no capability claims).
- **Verification:** V0 runs the full suite anyway; Effects only order what the
  review asks the owner to look at. Later, Effects with observed evidence
  select targeted tests for the fast path; the full suite stays the gate (§12).
- **Never:** load a whole related subsystem because an Effect exists, run
  extra work automatically, or block until every Effect is handled.

### 26.5 Behaviour review

The reviewer (already built) writes, for each touched behaviour, what it did
before and what it does now, in plain language, from the behaviour notes and the
diff. The classification in §26.4 decides the sections: "Requested", "May also
have changed" and "Also changed" (⚠). No graph extraction: the owner tests (D)
start with hand-written diffs, and the automatic version is built only if they
help.

### 26.6 Hypotheses, each tested cheaply first

| Hypothesis                                                       | Cheapest implementation                         | Tested by                                            |
| ---------------------------------------------------------------- | ----------------------------------------------- | ---------------------------------------------------- |
| A. Accumulated context reduces repeated explanation              | `project.md`                                    | harness: A vs B                                      |
| B. Selective context eventually beats flat notes                 | capability files, selected by the control plane | harness: B vs C across project sizes                 |
| C. Advisory Effects improve selection or regression detection    | a handful of hand-written Effects               | harness: C with and without Effects; planted changes |
| D. Owners value behaviour-level explanations of changes          | hand-written behaviour diffs                    | owner sessions, with a planted unrelated change      |
| E. Small contextual questions cut rework without annoying people | the agent asks, no classifier                   | owner sessions and the harness                       |
| F. Common approaches help owners who do not know what to ask for | hand-written precedent cards                    | owner sessions: cards vs an open question            |
| G. Visual selection reduces ambiguity                            | one or two instrumented components              | owner sessions                                       |

Also tested by hand before anything is automated: a "What this does" panel on
one element, written by hand, to see whether owners open it and find it useful.

### 26.7 Experiments, revised

Replaces the pass marks in §25.4: fixed thresholds on 3–5 noisy agent runs were
false precision.

**Technical, in the harness.** Many short, paired tasks: every condition gets
the same repository state and the same request.

| Condition | The agent receives                                  |
| --------- | --------------------------------------------------- |
| A         | repository + request                                |
| B         | A + `PROJECT.md`: all the project's knowledge, flat |
| C0        | A + the selected notes files, without Effects       |
| C         | A + the selected files with Effects                 |

B is generated by concatenating the same notes files that C selects from,
so the content is identical and only the selection differs. The question is not
whether C beats B on a small project (it may not) but **at what project size it
starts to**. The same tasks therefore run at several sizes of accumulated
knowledge (a handful of capabilities, then dozens). Measured per task:
first-attempt success, verification pass, tokens, runtime, agent turns, retries,
unrelated regressions, cost per accepted change. Results are reported as
distributions and paired differences, and read as trends. If C never pulls
ahead, or only far beyond realistic sizes, we use flat notes: that is a good
outcome, because it is simpler.

The simulated owner is an engineering tool for iteration, scenarios and
automated checks. It is not evidence that people value anything.

**Qualitative, with owners.** A few real sessions with hand-made prototypes for
D, E, F, G and "What this does": did they understand what happened, did the
questions annoy them, did the explanations help, did they notice the planted
change, did they feel more confident, would they use it. Five owners are not
statistics, but they expose major failures quickly.

Complaints about other builders show that a problem exists, not that people want
our solution. Every mechanism above is a product hypothesis until owners touch
it.

### 26.8 Deferred, and what would bring each back

| Deferred                                            | Built when                                                                                    |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `context_entries`, the resolver, indexed retrieval  | Markdown selection demonstrably limits us (C beats B but misses, or files grow too large)     |
| Behaviour extraction and the Product Behavior Graph | hand-written behaviour diffs help owners (D) and keeping notes by hand becomes the bottleneck |
| The question gate and classifier                    | questions help (E) but the agent asks badly                                                   |
| Precedent files and retrieval                       | hand-written cards beat open questions (F)                                                    |
| A second provider; routing                          | a task class where another provider is clearly better, with data to show it                   |
| Package trust beyond an allowlist                   | requests for unknown packages become frequent                                                 |
| Custom Rector rules                                 | a real transformation we keep repeating                                                       |
| The visual editor beyond one path                   | selection helps (G)                                                                           |

The package allowlist records why each package was approved, compatible
versions and integration notes. Rector stays in the architecture as a principle
(known transformation → deterministic tool; unknown semantic change → agent);
its first custom rule comes from a problem we actually hit.

### 26.9 Decisions before generation (version 11)

Direction 12 adds a principle: **use the cheapest mechanism that can make a
trustworthy decision** (deterministic code, then a typed decision model such as
Jev, then a small generative model, then a frontier agent, then the user). The
test for every decision is not what the call costs but **what its answer changes
downstream**. A decision earns its place only when a confident answer lets us
skip or downgrade something expensive (a frontier call, a model tier, an agent
run, context tokens). Otherwise it is telemetry, not control.

**Fail-safe to the baseline.** When a decision is unsure, the run does exactly
what it would do without the decision layer. Decisions that make a run cheaper
(skip the planner, a cheaper coder, no question) act only above a high,
per-decider threshold; decisions that make it safer (more context, a stronger
model, more review emphasis) act at any confidence. So the layer can only lose
money through _confident_ mistakes, and those are measured.

| Decision (V0)                                               | Mechanism                                                                                                        | Changes downstream                                                     | When unsure  |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | ------------ |
| Areas the change is about                                   | Level 0 from the selected step or element's paths; else named by the planner, whose call happens anyway          | the context pack                                                       | include more |
| Complexity: trivial, normal, substantial                    | decision model                                                                                                   | trivial: skip the planner, cheaper coder; substantial: strongest coder | normal       |
| Touches permissions, persisted data, is destructive (flags) | decision model before the change; **Level 0 from the diff after it** (policies, migrations, deletes), which wins | more context and review emphasis; approval gates                       | assume yes   |
| Direct visual edit or agent                                 | Level 0: the selection maps to a static class                                                                    | no agent run at all                                                    | agent        |
| Is this statement durable product knowledge                 | decision model                                                                                                   | write a proposed context note                                          | do not write |

**Stays deterministic:** sorting a change by area, risk from the diff, protected
suites, budgets, verification, Effects lookup, and direct visual edits.
**Goes straight to a generative model:** anything that produces text (plans,
questions, option wording, behaviour descriptions) or needs the repository read.

**Intent** is a primary label plus independent yes/no flags, each its own typed
decision. V0 keeps only labels that change a path (complexity and the flags);
the primary intent is recorded for learning, not used for control, until data
shows it would change something.

**Decisions only add, never restrict.** A flag can add context, review emphasis
or a stronger model. It never removes context, limits tools, or tells the coder
what not to touch; in the brief it appears at most as "likely". The coder still
explores.

**Rollout in shadow mode.** Decisions first run without acting, and each is
compared with what actually happened (the areas the planner named, the final
diff's risk, whether the change needed repairs). A decision starts acting only
when its confident errors are rare enough that the repairs they cause cost less
than the calls they save.

**Provider independence.** One `Decider` contract returns a choice, the
probabilities, a confidence and who decided. Drivers: rules, Jev, and a small
model through the gateway with structured output. Thresholds are set per
driver, because a small model's self-reported confidence is not calibrated the
way Jev's is.

**Telemetry:** a `decision` event per decision (name, driver, choice,
confidence, threshold, acted or shadow, fallback used, latency, cost), joined
later with the change request's outcome (verification, repairs, acceptance,
the diff's areas). This yields the share decided at each level, confident
errors, missed escalations and their repair cost, and the effect on cost per
accepted change.

**As built (V1.1, shadow mode).** Jev is called through the AI SDK's
classification API (`Laravel\Ai\Classification`, the `typesafe` provider).
The SDK is the `Decider` contract: its provider list is the driver choice and
the failover. One call per new change request, queued beside the run, asks
five typed questions:

- complexity (a choice);
- whether the request is only a question (yes/no);
- whether it touches permissions, persisted data, or is destructive (yes/no each).

The question decision was added because a question already skips the build
(§19). If Jev can spot one with confidence, the planner call can be skipped
for it too. The state sent is the owner's words only.

Each answer is a row in `decisions` (choice, probabilities, confidence,
threshold, acted, latency), not a run event: decisions belong to the request
and are made before its run exists. `acted` is always false for now.

`php artisan builder:decisions` joins the answers with the outcome, read from
the final diff and its repairs:

- a migration means persisted data;
- a policy, middleware or authorization call means permissions;
- a drop or delete outside a migration's `down()` means destructive;
- the number of changed files outside tests, plus the repairs, gives the complexity;
- an answered request is a question.

It reports, per decision, how often the answer was confident, and how often a
confident answer was right. A decision may start acting only when that report
shows its confident errors are rare.

**The honest expectation.** A change's cost is dominated by the coder loop and
verification, and its latency by verification, so a 100 ms decision matters
only when it removes a stage. V0 therefore proves the layer on one decision
with a real payoff: _complexity_, which lets trivial changes skip the planner
and use a cheaper coder. It keeps the layer only if shadow data shows that
this lowers cost per accepted change once the repairs caused by wrong
"trivial" calls are counted.

### 26.10 Audits and adversarial reviews (version 12)

Direction 13 adds two maintenance workflows. An **audit** asks whether the
application is healthy and whether what we know about it is still true. An
**adversarial review** asks how the application can fail, be abused or surprise
its owner. Principles: **users choose assurance; the control plane chooses
intelligence**, and **incremental work maintains understanding locally;
periodic review reconciles it globally**.

**No ceremonial reviews.** Each level has a written promise that is exactly
the work it does, and every report states its coverage (areas inspected, checks
run, counterexamples tried) and what it did _not_ do. Every finding carries its
evidence: **reproduced** (a generated test fails), **observed** (a tool's
output, such as a vulnerable package or a route any member can reach) or
**reasoned** (a model's judgement). Reasoned findings are shown as "worth
checking", never as fact.

| Level                        | Does                                                                                                                                                                                                                                                                                    | Intelligence                                                           | When           |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | -------------- |
| **Quick health check**       | Reconciles the notes with the code: paths that match nothing, code no area claims, Effects naming unknown areas, areas changed since the last audit whose notes did not change; dependency advisories (`composer audit`, `npm audit`); the full test suite and static analysis          | deterministic; at most one small-model call to phrase the summary      | **V0**         |
| Thorough audit               | Quick, plus one reasoning pass per area changed since the last audit (and the areas their Effects name): notes and rules against the code, behaviours without tests, proposed Effects                                                                                                   | frontier model per area, bounded by a budget shown up front            | after V0       |
| Deep audit                   | Thorough across every area, changed areas first; tracing workflows across areas; architectural drift                                                                                                                                                                                    | frontier model; decision model to prioritise areas                     | later          |
| Permissions challenge        | The first adversarial level: a **who-can-do-what matrix** from route and policy introspection, probed with generated requests as each role, including another team's records (tenant isolation needs no stated intent: crossing it is always a finding); compared with the notes' rules | deterministic probes; a model only turns prose rules into expectations | first after V0 |
| Serious / adversarial review | Counterexamples for failure paths (partial failures, retries, duplicates, races, outages, destructive operations), dangerous combinations of valid behaviours, claims in the notes challenged                                                                                           | independent frontier reviewer (another provider), budgeted             | later          |

**Scope from Behaviour and Effects, never limited by them.** The areas changed
since the last audit (the diff mapped through `paths`, as in §26.4) come first,
then the areas their Effects name. Effects are _leads_: "what if billing fails
after the cancellation succeeds?". The adversary always also spends part of its
budget outside the Effects and outside the notes, and an interaction it finds
there becomes a proposed Effect.

**What updates knowledge automatically, and what is proposed.** Facts derived
from code (a path renamed, an Effect's `observed` date refreshed with
deterministic evidence) are fixed in a maintenance commit listed in the report.
Anything about intent (rules, decisions, behaviour notes, owner-written
Effects) is proposed. A contradiction between intent and code ("you said only
owners manage billing; admins can change payment methods") is a question with
two answers, keep the code or change it, and is never resolved automatically.
Effects are never deleted automatically; stale ones are proposed as
`historical`.

**Counterexamples become regression tests.** The adversary writes tests in a
scratch copy and runs them. A failing test makes the finding _reproduced_ and
travels with it: "Fix this" opens a normal change request with that test as a
protected acceptance test, so the fix must pass it and it stays as protection.
Tests that could not break the application are offered as extra coverage.

**Proposes, never refactors.** Review runs have read-only access to the project
(tests go to a scratch copy). Their output is findings and proposed change
requests; remediation goes through the normal pipeline, one owner decision per
finding. There is no "fix everything".

**Independent review without exploding cost.** A second provider is used only
at the serious and adversarial levels, and for areas the diff marks risky
(permissions, persisted data, destructive). It gets the claims, the code and the
tools, never the builder's transcript. The expected cost and time are shown
before starting; when the budget runs out the review stops and reports its
coverage.

**Delta-aware.** An `audits` record keeps the level, focus, base and head
commits, coverage, findings and cost. The next review starts from the diff
since the last one at that level or deeper.

**Triggers, never the calendar alone:** many accepted changes since the last
review, a lockfile change, changes the diff marks as touching permissions,
migrations or destructive operations, repeated repairs or reverts in one area,
a new area, and before publishing. Each is suggested once, in plain words
("Billing and staff permissions changed since the last review. A thorough
check before publishing?"), and the owner decides.

**Two depths of the same finding.** The owner sees a plain title, the
consequence ("two people changing the same booking at once could leave
availability wrong") and three answers: _Fix this_, _It's intended_ (records a
confirmed note, so it is not raised again), _Not now_. A power user expands
into files, the reproduction, the test and a suggested fix.

**Telemetry:** per review, the level, focus, cost, duration and coverage; per
finding, the evidence type and the owner's answer, with time to fix. The
question that justifies the workflows: do areas that were reviewed see fewer
later reverts, corrections and verification failures than they did before, or
than areas that were not? Also the cost per reproduced finding, and the
dismissal rate by evidence type (a high rate for reasoned findings means the
reasoning passes are ceremony and should shrink).

V0 builds only the **quick health check**: it is deterministic, and it keeps the
agent-maintained notes of §26.3 from rotting. Everything else waits for owners
to be using the product, since a review of software nobody has built yet proves
nothing.

### 26.11 Laravel-native active testing (versions 13–14, later stage)

Direction 14: **exploit framework determinism before spending model
intelligence**, in assurance as in building. A generic scanner crawls a running
application and guesses its routes, inputs and boundaries. We can read them
from the framework: routes with middleware and model bindings, FormRequest
rules, policies and gates, enums and casts, factories, and the existing tests.
Not built in V0; this section fixes the shape so nothing built now blocks it.

**Probes are generated tests, not HTTP scans.** Each probe runs inside the
application's own test harness in a scratch copy: factories build a controlled
world (team A with an owner, an admin and a member; team B likewise), and the
probe sends one request as one actor. This needs no running server or network,
it is reproducible, and a probe that fails is already the regression test §26.10
attaches to a reproduced finding. A probe is: route, actor, one mutation of a
known-valid request, the expectation, and the observed result.

**Known-valid first, then mutate one dimension.** Baselines come from existing
tests, factories and the FormRequest rules. Mutations come from the declared
contracts: boundary and out-of-domain values from validation rules (`min`,
`max`, `Rule::enum`, `in`), missing and unexpected fields, a bound model from
another team or a deleted one, another actor.

**Where expectations come from:**

1. **Invariants that need no stated intent:** another team's records are
   refused; routes behind `auth` refuse guests; out-of-domain input is rejected
   with a validation error, never a server error; any 500 is a finding.
2. **The notes' rules** ("only owners and admins can remove members"), turned
   into an expected allow/deny matrix. This is the only step that needs a
   model, and the matrix is shown for confirmation before it is trusted.
3. **Adversarial hypotheses** (§26.10), each turned into a probe and either
   reproduced or not. Traffic runs both ways: a probe that finds something
   unexpected hands it to a model to explain the product consequence and
   propose a fix.

**Priority comes from the framework:** mutating and privileged routes, routes
binding team-owned models, payments, file uploads, signed URLs and webhooks
first; public read-only pages last.

**Known limits,** reported as coverage rather than hidden: closures, custom
rules and conditional rules (`sometimes`, `required_if`) are probed only for
presence; missing or broken factories leave a route unprobed; a policy that
exists is not proof that it is enforced, so probes always exercise the route
itself.

**The first slice** is the permissions challenge in §26.10: the
who-can-do-what matrix and tenant isolation, using the same introspection
(`builder/introspect`, §6) and the generated-test runner. Validation
boundaries, request integrity (CSRF, signed routes, methods, replay) and
data-integrity probes follow only if the first slice finds real problems in
real applications.

**Static knowledge chooses the probes; only runtime results are evidence.**
Introspection (routes, middleware, bindings, FormRequest rules, registered
policies, roles from their enum or config, factories) decides what to probe and
how to build the request. Whether a request is actually allowed is always
observed by running it, because enforcement can live in controllers,
middleware, query scopes or nowhere.

**How much of the authorization matrix is automatic.** The routes, the actors
(when roles are an enum or config, as in the fixture) and the team model (found
from bindings and relationships, confirmed once by the owner) can be derived.
World building works where factories do. The expectations split in half:
isolation and guest denial need no intent and are fully automatic; the
in-team role expectations need the notes' rules and one owner confirmation.

**Rules are generators without combinatorial fuzzing.** One dimension changes
per probe. Each field gets its equivalence classes (valid, missing, each rule's
boundary, one out-of-domain value), so the count grows with fields × rules,
not their product. Fields are combined only where a conditional rule links
them. A budget and the framework's priority order cut the rest.

**From hypothesis to probe, and back.** A model states a hypothesis as a probe
spec (route, actor, mutation, expectation) that we compile into a test; free
test code is allowed only where the spec cannot express it. Safety comes from
the sandbox, not from trusting the model. A failing probe goes back to a model
as a packet: the spec, the request and response, the route's handler, policy
and FormRequest, the relevant rule from the notes and the Effects. The model
explains the consequence and proposes a fix, and the probe becomes the
regression test.

**Framework-specific vs generic.** Laravel-specific: building worlds from
factories, probes from routes, bindings, policies and rules, isolation from
relationships, and failure paths through Laravel's fakes (queue, mail, HTTP,
time). Generic: dependency advisories, configuration and debug exposure, HTTP
headers, and the race harness itself.

**Environment rule.** Probes run only in disposable sandboxes or previews with
their own database, fakes for outgoing mail, queues and HTTP, controllable
time, and snapshots to reset. Races need a real server (the preview host) and
parallel requests. Testing production is out of scope unless a later policy
explicitly allows it, and then only read-only.

**Out of V0:** all of it, including the introspection this relies on, beyond
what V0's own verification already uses.

### 26.12 Visual properties on a Tailwind substrate (version 15)

Direction 15 makes the §14 inspector concrete and moves it into the V0 demo:
**the owner manipulates understandable visual properties; the platform keeps
clean, ordinary Tailwind source.** Explicit design controls are deterministic;
semantic design intent ("make overdue invoices stand out") goes to the agent.

**The property model.** The inspector speaks only `StyleValue`, never class
names:

```
StyleValue { property, value (number | keyword), unit (px | rem | % | vw | null),
             breakpoint (base | md | lg), state (normal | hover | focus | active | disabled) }
```

A property schema (control type, units, semantic presets, plain labels and
consequence hints such as "50% changes with the available space; 400px stays
fixed") drives the inspector. A **Tailwind adapter** turns a `StyleValue` into
a utility and reads utilities back. The **current value shown** comes from the
preview's computed style, so it is right whatever set it (theme, component
defaults, inheritance); the source's classes decide _where_ an edit is written.

**V0 properties:** width and max width (with units); padding and margin per
side; gap; layout (list or grid; across or down, wrap, alignment,
distribution; grid columns); border width; corners and shadow as semantic
scales; text size and weight; colours from theme tokens only; show or hide per
device. Everything else waits.

**Values and scales,** checked against Tailwind v4 in the fixture: spacing
accepts any quarter step of `--spacing` (15px is `p-3.75`), widths accept any
fraction (`w-13/20`), and border widths any integer (`border-3`). So "canonical
or arbitrary" is rarely about validity. The rules:

- **Prefer theme-relative utilities** (`p-3.75` over `p-[15px]`), because they
  follow the app's theme.
- **Snap to the preferred steps:** sliders move in the theme's usual steps (4px
  for spacing, the named radius and shadow scale), and typing any value is
  still allowed.
- **Arbitrary values** (`w-[50vw]`, `w-[65%]` when no fraction is exact) only
  when the unit has no scale, or the exact value cannot be expressed.
- Colours are theme tokens only in V0.

**Devices and states are variants of the same value.** Phone is the base
value, tablet is `md:` and desktop is `lg:`. Tailwind is mobile-first, so the
inspector shows inherited values as "same as phone" and editing phone changes
every device that has no value of its own. States map to `hover:`, `focus:`,
`active:` and `disabled:`, and combine (`lg:hover:`).

**Writing source with the app's own merge.** The edit runs `twMerge` from the
application's own `node_modules`, with its own configuration, in the workspace
(checked in the fixture: `flex flex-col gap-4` + `flex-row` gives
`flex gap-4 flex-row`). `twMerge` moves the new class to the end, so we write
the merged set **in place**: the new utility takes the position of the class it
replaced, and everything else keeps its order, giving one-word diffs. Source is
patched, never wrapped in runtime `cn(...)` calls.

**Where the edit lands.** Most visible elements in a shadcn-vue app are
components. "This one" writes to the usage site's `class` attribute (the
component merges it last with `cn()`); "all of these" edits the component's own
classes. V0 edits:

- static `class="…"` attributes;
- literal string arguments of `cn(…)` in `:class`, where each
  `condition && '…'` argument is a named state ("Selected", "Error") the owner
  can pick.

Anything else (`:class="styles(x)"`, template strings, cva variants, computed
properties) goes to the agent. Blade uses the same mutation on `class="…"` and
literal `@class([...])` entries; only the source mapping differs.

**Confidence is checked, not assumed.** After an edit is applied to the preview,
the computed style is compared with the intended value. If they differ (for
example `!important`, custom CSS, specificity or an inherited constraint), the
edit is reverted and offered to the agent with the selection. Other fallbacks
to the agent: an unsupported property, a non-literal class source, a semantic
request, or a change to a shared component's variants.

**Preview first, then commit.** While the owner drags, the preview applies the
value as an **inline style**. A new class would do nothing, because Tailwind
only generates CSS for classes it has seen in the source. On release, the class
is written to source, the preview's assets are rebuilt, and the page reloads.
In V0 that rebuild takes seconds, not hot reload (the edge proxy and HMR stay
postponed, §20); the inline style hides the delay. A visual edit is a change
request with no model calls: its verification is that the build succeeds and
the page renders.

**The Visual Editability Contract,** in the template's `AGENTS.md` for the coding
agent, and checked (reported, not blocking) in verification:

- classes are static strings, or `cn()` with literal arguments;
- conditional classes are `condition && '…'`;
- components accept `class` and merge it last with `cn()`;
- no class names built by concatenation or template strings, which Tailwind
  cannot scan either;
- no inline styles or custom CSS for properties the inspector manages;
- no `!` important utilities;
- design values come from `@theme` tokens.

**The adapter boundary now, without generalising.** Two seams: the Tailwind
adapter (styling substrate) and the source locator (rendered element → file
and position, from the preview-only Vite plugin, and later a Blade
precompiler). Both are small interfaces with one implementation each. The
framework-adapter questions in direction 15 (§23) are recorded as the future
interface; V0 does not build a generic adapter layer.

**Native PHP later:** the visual editor, Tailwind adapter, context notes,
Effects, the review by area and test-based verification carry over. Route,
policy and validation introspection, behaviour extraction and Laravel-native
assurance (§26.11) do not; they would become inferred rather than known, and
the product should say so.

**Is it worth the V0 demo?** Yes. It is the "select an element" step of the V0
flow (§26.2) and hypothesis G, it is visible, and it proves the principle with
no model call. Its risks are specific: the rebuild delay after each commit, and
component-instance ambiguity in shadcn-vue apps. Both are handled above.

## 27. V1 plan (version 16)

Direction 16 freezes the V1 direction. This section is the engineering plan
that answers it. For V1, it wins over §21 and §26 where they differ. The
ambition is the depth of one loop, not the breadth of features.

Direction 26 names that loop: V1 is **the smallest complete evolution loop**,
from request to published and checked change, and **the demo is evolution, not
generation**. First-generation demos are common; ours builds an app, then
makes ten changes in a row, and by change 10 the owner still talks about their
business exactly as at change 1. Speed and polish to the first result match
the best builders; the edge is that the tenth change is as easy as the first.
The thesis: can a real application grow more complex without the owner's
experience growing more complex?

### 27.1 What proves the differentiation

The claim is "it understands the product, retrieves what matters, knows what
else may be affected, scopes the change, verifies it and explains it". Only
these components prove that claim, so only these are **required**:

1. Project notes with selective compilation (built: §26.3).
2. Behaviours and Effects in the capability notes, and the change sorted by
   area (built: §26.4). Capabilities, rules and behaviours keep stable keys
   that tests and Change Records share (§6), in their thinnest form.
3. **The Change Brief** with _preserve_ and _verify_ clauses (new; §27.4).
4. The coding agent running in a sandboxed runtime through an agent SDK.
5. Independent verification: the full suite, protected acceptance tests, and
   the brief's verify items as tests.
6. **An honest behaviour diff:** each "preserved" line says how we know
   (§27.4).
7. Selection with "What this does", and the deterministic Tailwind editor
   (§26.12).
8. Creating a project from the template, and a constrained import that drafts
   the notes for the owner to confirm.
9. A minimal Project Understanding page: the notes files rendered in
   product language and editable.
10. Git boundaries per accepted change, revert, and deploy. Publishing goes
    through a host contract with no host hard-coded (direction 28): a host
    takes a commit and gives back an address and its deploy status, and
    changing host changes configuration, not the loop. Grandma's apps publish
    to **Laravel Cloud** by default, so she never picks a host. They run in our
    Cloud organization, and for now we absorb their hosting cost: her price
    does not change with it. We still read each app's cost from Cloud's usage
    API so operators see it next to model spend. She never sees a Cloud
    account or token. Power users may connect their own Cloud organization with a scoped
    API token and deploy from their own repository, and Cloud bills them
    directly; or they bring another host, or a plain branch that their own host
    deploys from. Cloud has no sign-in for platforms and deploys only from a
    repository the organization's own Git account can read, so direct billing
    for Grandma waits for one. Cloud apps cannot move between organizations, so
    moving an app to the owner's own account later means creating it again and
    moving its data.
    Smoke checks, error intake and the health state sit above the contract and
    are the same for every host. Publishing is one click to a default
    address, and it is a loop, not a push (direction 26): publish, run smoke
    checks against the published app (it boots, sign-in works, the critical
    journeys and invariants answer), link the deployment to the changes it
    carries, take in basic errors from the published app, and show one plain
    state: "Published", "Checks passed" or "Needs attention". Runtime is light
    in V1: no analytics, tracing or anomaly detection.
11. Telemetry per change request (§25.3), including cost per accepted change.

### 27.2 Postponed to V1.1

| Postponed                                                          | Why it can wait                                                                                                                                                                                                        |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Decision layer (Jev) acting on runs                                | The brief's model call already classifies. A decision model pays off only by skipping stages, and needs traffic to show which ones. V1.1 runs it in shadow mode only (§26.9, "As built"); V1 routes deterministically. |
| Thorough and deep audits, adversarial review                       | They need real applications with history. V1 keeps the quick health check (deterministic), which protects the notes.                                                                                                   |
| Precedent library                                                  | Hand-written cards in owner sessions first (§26.6, F).                                                                                                                                                                 |
| Compatibility mode                                                 | V1 preserve clauses already cover "don't change these"; the mode is a user-chosen set of protected interfaces on top.                                                                                                  |
| Backend flow visualisation                                         | Behaviour notes already say "what happens" in words. A diagram only restates them until behaviour is extracted automatically.                                                                                          |
| Small-generative-model tier                                        | No V1 job needs it: answers are written deterministically, and the coder updates the notes in its own diff.                                                                                                            |
| Deploy checks beyond the smoke checks (queues, schedule, env diff) | Laravel Cloud deploys and reports from the Git branch. V1 runs the smoke checks, shows a plain health state and keeps the revert.                                                                                      |
| Multi-provider routing                                             | The reviewer already runs on a different provider through laravel/ai. Anything more waits for telemetry.                                                                                                               |

### 27.3 Still architecture for architecture's sake

- **Surface and Implementation Reference as stored entities.** In V1 they are
  lines in the notes and `paths` globs, not tables.
- **Four context levels plus "current task".** V1 has two files (project,
  capability) with behaviour sections; the task is the request.
- **A Behavior Index separate from the notes.** The index is a view generated
  from the capability files' `behaviors`, not a second store to keep in step.
- **Nine intent categories and six flags before any data.** Only flags that
  change a path matter (permissions, persisted data, destructive); the category
  is telemetry.
- **Change Brief and Plan as two artifacts.** The brief is the plan: extend
  `Plan` rather than add a second document.
- **A framework adapter layer.** V1 keeps Laravel knowledge in its own
  namespace and adds no generic interface until a second framework exists.

### 27.4 What coding-agent SDKs already do, and what stays ours

**Theirs:** the tool loop, file editing and shell, running the tests while
working, context compaction, subagents, planning to-dos, loading `CLAUDE.md`,
hooks, permission policies, MCP and session resume. Our server-side tools,
operation journal and reconciliation (G2.1–G2.3) were built because the coder
ran in the control plane. Once the agent runs inside a disposable sandbox the
unit of safety is the **whole run**: its output is a diff, and budgets,
cancellation and the fenced run state machine remain. Moving the coder to the
Agent SDK therefore deletes code rather than adding it. The compiled context
reaches the agent as the brief (and may be written as the run's `CLAUDE.md` in
the sandbox, never committed).

**Ours**, because no SDK does it:

- what the product is, and choosing what matters for this change;
- the brief's _preserve_ clauses;
- verification the agent cannot edit;
- sorting the change by area and explaining it in product language;
- deterministic paths (Tailwind edits, the notes check);
- cost per accepted change.

**The honest behaviour diff.** "Preserved: billing" is a claim, so the diff
says how it knows:

- _verified_: tests that cover the area ran and passed;
- _untouched_: no file the area claims changed;
- _not checked_.

Without that label, "preserved" is ceremony.

**The brief (extending `Plan`):** understood as, current behaviour, intended
change, **preserve** (from the rules and behaviours of the target and Effect
areas), may also affect, relevant paths, and **verify** items. The verify
items are the acceptance criteria; the coder turns them into tests, and the
independent verification and the reviewer check that they exist and pass.
Planning depth follows consequence: a selection edit gets no brief, a small
change a short one, and a new capability a full one.

### 27.5 The smallest differentiated engine

```
request ─┬─ selection with a static class edit ──► Tailwind mutation ─► build ─► commit
         └─ otherwise
              ─► areas (from the selection's paths, else named by the brief call)
              ─► compiled context (§26.3)
              ─► Change Brief (one model call; skipped for trivial edits later, §26.9)
              ─► agent in the sandbox (SDK), notes updated in its diff
              ─► independent verification (suite, protected tests, verify items)
              ─► review by area, before/now, preserved with evidence
              ─► owner accepts ─► commit (revertible) ─► optional deploy
```

Everything else is either an input to that loop (import, project creation) or
a later layer on top of it.

### 27.6 Dependency order

1. **Execution in a runtime:** a sandbox provider behind the workspace driver,
   and the Agent SDK coder replacing the in-process tool loop. The SDK coder
   is built, and the in-process loop is removed; the scripted driver still
   applies known-good patches through the server-side tools. The runtime is
   hosted by a provider; we still need to choose one (§23).
2. **Brief and honest diff:** extend `Plan` and `Review`; test coverage per area
   from the test paths in the notes.
3. **Selection:** the preview-only Vite source locator, element to area through
   `paths`, and "What this does" from the notes.
4. **Deterministic editor:** property schema, Tailwind adapter, in-place
   `twMerge`, inline preview, commit and rebuild.
5. **Project lifecycle:** create from the template (Pest, PHP 8.5 per the
   blessed stack; the fixture and control plane use PHPUnit today), constrained
   import with drafted notes, the Understanding page, the quick health check.
6. **Deploy:** through the host contract (Laravel Cloud by default, direction
   28), show status, revert.

Telemetry (1–6) and the owner sessions run alongside.

### 27.7 Risks

**Implementation risk,** highest first:

- the sandboxed runtime with the Agent SDK: provider choice, credentials,
  cost, and build time per change;
- setup of imported applications (environment, database, `npm build`), the
  most likely place for import to fail;
- source mapping for components and instances in shadcn-vue apps;
- verification time per change (suite plus asset build);
- notes drifting from the code, since agents maintain them.

**Product-validation risk,** highest first:

- whether owners read and trust the behaviour diff and its "preserved" lines
  (D);
- whether the target owner exists and will pay, when power users may prefer a
  coding agent directly;
- whether deterministic visual editing matters when agents make small edits
  quickly (G);
- whether selective context shows any advantage at V1 project sizes (B); it may
  only appear later.

### 27.8 Milestones

1. **M1: the continuation loop, in a sandbox.** On the fixture (an existing app
   with the notes): request, brief with preserve and verify, Agent SDK in the
   runtime, verification, review by area with evidence-labelled "preserved",
   accept and commit, and the next request using the updated notes.
   Measured: cost per accepted change, first-attempt pass, unexpected changes.
   Covers demo steps 5–12.
2. **M2: point and edit.** Source locator, "What this does", the inspector
   (width, spacing, layout, border, corners, columns, with devices), in-place
   merge, inline preview, commit, rebuild, and fallback to the agent with the
   selection. Covers demo steps 3–4 and 13–15. **Owner sessions (D, E, G) run
   after M2**, before M3 is built.
3. **M3: a real project lifecycle.** Create from the template with one
   question; constrained import that drafts notes for confirmation; the
   Understanding page; the quick health check; deploy through the
   Cloud-connected branch, after the full checks pass on that exact commit
   (§12); and revert. Covers demo steps 1–2, 16 (quick) and 18.

### 27.9 V1, not a prototype, when

- someone other than us creates or imports their own app and ships several
  changes, visual edits and a deploy **without our help**;
- every agent run happens in an isolated sandbox, with no secrets in prompts or
  notes, per-change cost attribution, and budgets that stop runaway runs;
- verification is independent of the agent, and it cannot edit protected
  tests;
- every accepted change is a commit that can be reverted, including after
  deploy;
- the behaviour diff never claims more than its evidence, and "preserved"
  always says how it is known;
- the owner can read and correct the notes, and the quick check catches notes
  that have drifted from the code;
- every failure (setup, verification, budget, deploy) ends in a clear next step
  for the owner, never a dead end;
- telemetry answers cost per accepted change, first-attempt pass, unexpected
  change rate and edits made without a model;
- at least 3–5 real owners have used the loop, and we know what they did and
  did not value.

Two corrections to direction 16, from §26.12: 15px padding is `p-3.75` in
Tailwind v4 (a theme-relative utility), not `p-[15px]`; and any fraction is a
valid width (`w-73/100`), though `w-[73%]` reads more clearly and is fine as
the arbitrary form.

## 28. Grandma first: the translation layer (version 17)

Directions 17 and 18 add one product rule and a filter for what comes next.
This section is the engineering answer. For V1 it adds to §27; it does not
replace any milestone.

### 28.1 The rule

**Grandma first. Power users can drill down. Never require Grandma to drill
up.** The test for every surface: could someone who knows their business very
well, and software hardly at all, make the correct decision here? If not, the
surface leaks implementation and must be simplified, or the complexity moves
into the engine.

> No internal architectural noun is allowed into the default UI unless Grandma
> needs it to make a business decision.

The translation layer is part of the V1 architecture, not copywriting. Every
internal record keeps one user-language projection, and the page shows that
projection first. Technical detail stays one click away, under "Details" (or
"Show the code" for power users), and is never needed to act.

### 28.2 Vocabulary

The default UI uses the right-hand column. Code, notes and logs keep the
left-hand one.

| Internal                                     | Default UI                                                 |
| -------------------------------------------- | ---------------------------------------------------------- |
| Feature request, run                         | Change                                                     |
| Change Brief, plan                           | Here's what I'm changing                                   |
| Acceptance criteria, verify items            | Done when                                                  |
| Preserve clauses                             | I'll keep these the same                                   |
| Effects, "may also affect"                   | This may also touch                                        |
| Capability                                   | The area's own name (Teams, Billing), or "part of the app" |
| Behaviour                                    | What people can do                                         |
| Actor                                        | Who can do it                                              |
| Rules in the notes                           | Things that must always be true                            |
| Project Context, the notes                   | What I know about your business                            |
| Verification                                 | Checks I ran                                               |
| Behaviour diff, review by area               | What changed                                               |
| Evidence: verified / untouched / not checked | Checked by a test / Not touched / Not checked yet          |
| Unexpected change                            | Something I didn't expect to change                        |
| Accept (commit)                              | Keep this change                                           |
| Revert                                       | Undo this change                                           |
| Commit history                               | What changed, in the owner's words (the change summaries)  |
| Preview                                      | Try it                                                     |
| Quick health check                           | Quick check: look for obvious problems                     |
| Deploy (push to the Cloud branch)            | Publish                                                    |
| Tailwind classes                             | Direction, wrap, alignment, space, columns per device      |

Assumptions stay visible, as "Decisions I made for you": they are the product
decisions the owner is most likely to want to correct.

### 28.3 Depth

Every surface renders the same records at four depths (§3 already has five
levels; this is the same ladder, named by the question each answers):

1. **What** does this do? (default)
2. **Why and when**: who can do it, what else it may touch.
3. **How**: the files, tests, rules and packages involved.
4. **Source**.

A lower level is never required to operate a higher one.

### 28.4 What directions 17 and 18 change in V1

Only what the V1 loop already produces the data for:

- **Pages follow §28.2.** The change page leads with "Here's what I'm
  changing", "I'll keep these the same", "This may also touch", "Done when"
  and "Checks I ran"; the file lists and a plain-words log of what happened
  move under Details. Model and provider names, token counts, workers,
  budgets and our own checks are never shown: messages that name them are
  replaced with a vague one (§19).
- **"What changed" is product history.** The project page lists kept changes by
  their summaries; commit hashes are details. (Direction 18, §9.)
- **"Things that must always be true"** is the Understanding page's name for
  the rules in the notes. They already feed the preserve clauses, the review
  and verification. (Direction 18, §7.)
- **Honest confidence.** The preserved lines say "Checked by a test", "Not
  touched" or "Not checked yet". No percentages. (Direction 18, §10.)
- **The inspector speaks in visual concepts** (§26.12 already did); Tailwind
  shows only when the power user asks.
- **Selection answers "What this does"** from the area's notes, and shows the
  area's rules as the first answer to "Why is this here?". A fuller "why"
  needs decision history. (Direction 18, §1.)
- **The Understanding page is "Your business"**: what this app is for, who
  uses it, how things work, important rules, connected services, things to add
  later. It is not an ontology editor.
- **The quick check and publish** use the labels in §28.2.

### 28.5 Differentiators after V1

Direction 18 ranks five: why is this here; things that must always be true;
what happens if I change this; explain my app and what changed while I was
away; goal-aware simplification. The V1 loop already stores the raw material
for each (notes with rules and Effects, change summaries, assumptions, evidence
labels). Later stages, in order:

1. **Decision history.** Store a kept change's assumptions and the owner's
   answers as decisions in the notes (value, why, "Change this rule"), so
   "why is this here" can cite them.
2. **Impact preview before important changes.** Show "This may also touch"
   before the agent runs when the brief flags permissions, stored data or
   destructive changes. Question frequency follows consequence and
   reversibility.
3. **Invariants as tests.** Turn "Things that must always be true" into
   protected tests, reused by verification and audits.
4. **Explain my app** as a numbered list from the notes, where "Number 4 is
   wrong" starts a reconciliation (notes stale, understanding wrong, or code
   drifted).
5. **Main goal** in the project notes, and suggestions judged against it.

Progressive autonomy, safe experiments (a preview of an unkept change is
already one), "Simplify this", the complexity budget and product-level undo
stay later. A feature that improves none of complexity cutting, observability
or evolution does not belong in the core product.

### 28.6 Open

- Whether the owner approves the brief ("Make the change") before the agent
  runs on every change, or only on consequential ones. V1 runs straight
  through and asks at "Keep this change"; §28.5 (2) is the proposal.
- Whether to enforce §28.2 mechanically (a check that fails when a default-UI
  page uses an internal noun). V1 relies on review.

## 29. Human judgment where it has leverage (version 18)

Direction 19 reframes people in the loop. A developer is not the fallback when
the AI fails. People add concentrated judgment where it has unusual leverage,
and the platform carries that judgment into every later change. The promise is
"you no longer need a developer for every change", not "never again".

### 29.1 Who decides what

| Kind of decision                      | Who                     |
| ------------------------------------- | ----------------------- |
| Routine implementation                | AI                      |
| Ambiguous but low risk                | AI with the owner       |
| High-consequence product decision     | Owner                   |
| High-consequence engineering judgment | Developer or specialist |

Escalation is a normal path, not a failure state.

### 29.2 Three sources, one understanding

The owner (intent, rules, goals), the platform (implementation, verification,
the notes it maintains) and developers (architecture, risk, simplification,
long-term direction) all write to the same notes. Nothing a
developer says lives only in a chat or a report.

### 29.3 What V1 does

Only what the notes already support:

- **Engineering direction is a section of `project.md` in the notes.** The Context
  Compiler includes the project notes in every change (§26.3), so a rule such
  as "Use Actions for state-changing operations" or "External integrations go
  through adapters" reaches every later brief, coder and reviewer. A developer
  writes it once.
- **The Understanding page (M3) shows it** as "Guidance from your developer",
  editable, with the other sections. Changes to it are commits like any other,
  so its history is visible.
- **The reviewer checks changes against it**, because the reviewer already
  receives the project notes.
- **"Ask a developer" brings in one of our own developers**
  ([direction 26](direction/26-evolution-loop-and-design-contract.md),
  "Human-in-the-loop: tiny V1 version"). The test is whether one hour of
  engineering judgment changes later AI work. The owner asks in their own
  words, about the whole app or about one change (app menu, or a link that
  names the change). The developers are in-house: they answer as operators
  (`viewOperations`) under "Questions for developers", so no account,
  invitation or role is added for them. Every operator but the asker is
  told of a new question (`DeveloperAsked`), and operations counts the
  questions still waiting.
    - **What they read is written once, without a model**
      (`WriteReviewRequest`, kept in `developer_reviews.bundle`): the
      question, what the app is for, its rules, decisions and guidance so far.
      For the app, each area with its code paths, rules and how many tests
      run it. For a change, how it was understood, what must stay, the
      assumptions, the notes of its areas, what the checks showed and what
      nothing checks yet, the problems still open, its code, and the kept
      changes in the same areas. It names the commit (`revision`), and the
      code download (`PackProject`) is taken at that commit, so the review
      reads the same code however the app moves on. It holds no scores,
      routing or other machinery, because it can be downloaded and passed on.
    - **The answer has three parts:** a short answer, what they noticed, and
      guidance, one rule per line. The owner is told once in the builder.
    - **Only the owner makes guidance part of the app.** They choose which
      points to keep. Each kept point joins "Engineering direction" with the
      developer's name and the date. The review keeps the commit it was given
      on, so stale guidance can be found later (§30.3). Kept guidance fixes
      the answer, and only the kept points show afterwards.
    - **Every later change is held to it.** The coder reads it in the
      project notes. The second look reports a blocking finding for code
      that goes against it, and a change it passed says so among its proof.
    - **Not in V1:** our developers writing code here (an owner who wants
      that uses "Use my own Claude Code or Codex", §11), naming in a change
      which point of guidance it followed, asking before risky changes,
      payment.

Grandma sees none of the vocabulary: no "architecture consultation", no
"audit". Where V1 shows anything, it says "Guidance from your developer".

### 29.4 Later

In order, each built on the notes rather than beside them:

1. **Review packet**: built in V1 as what "Ask a developer" writes (§29.3).
   Still to come: the **unconfirmed assumptions the design rests on**, once
   assumptions are kept in the notes (§30.3). "These assumptions decide the
   tenancy model" is the kind of catch the packet exists for. Then: say in a
   change which kept guidance it followed, only where the second look
   weighed that point.
2. **"Ask a developer to review it first"** beside "Continue", offered before
   high-consequence changes (permissions, stored data, billing, destructive
   changes), from the same flags as §28.5 (2).
3. **Expert sessions** (developer check, architecture session, feature review,
   periodic health review) whose output is edits to the notes and invariants,
   reviewed by the owner.
4. **A marketplace for judgment, not for feature work** ("lend your judgment to
   my software"), with levels from general developer to specialist.

The developers are our own staff, so V1 uses operator accounts. Access for
the owner's own or hired developers (accounts, permissions, payment) is not
designed yet, and V1 has no invitations or roles (see AGENTS.md: later
gates).

## 30. Software stewardship (version 19)

Source: [direction 20](direction/20-software-stewardship.md). The product is a
software stewardship platform: build, understand, operate, change, bring in
judgment, keep that judgment, and keep evolving safely. The test is no longer
"can Grandma build a booking MVP" but "can Grandma still own the product after
years of changes". This section answers the direction's closing question: what
is the smallest durable structure that gives most of the leverage?

### 30.1 Three primitives, no new ones

| Primitive         | What it holds                                                                                                                                                                   | Where it lives now                                                                           |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| **Notes**         | Owner intent (rules, invariants), developer guidance, the areas of the app                                                                                                      | Our database, with a copy in each workspace (§26.3)                                          |
| **Change Record** | What the owner wanted, how it was understood, the assumptions used and how each is known, before and after, what was kept the same, what it may touch, the commit, the evidence | The accepted feature request and its brief, verify items, preserved items, checks and commit |
| **Evidence**      | Which checks ran, which tests cover which promise, and what was not checked                                                                                                     | Runs and verification, linked from the Change Record                                         |

The feature request already is the Change Record: the change page shows it in
owner language (§28). V1 adds no new entity for it. Visual edits (M2) are
Change Records without a model: file, element, before and after, commit.

Expert guidance is not a fourth store. It is the "Engineering direction"
section of the notes (§29.3), so it reaches every later change through the
Context Compiler.

### 30.2 What V1 does

- Keeps every change as a Change Record with evidence, and lists them on the
  project page as "What changed".
- Keeps owner rules and developer guidance in the notes, visible on the
  Understanding page (M3).
- Marks evidence honestly: "not checked" is never shown as "verified" (§27).
- Shows each change's assumptions as "Decisions I made for you" (the planner's
  `assumptions`). They are plain text for that change only: not kept in the
  notes, not checked against evidence, and never asked before building. The
  next steps close that gap (§30.3).

### 30.3 Later, in order

1. **Assumptions in the notes.** Keeping a change keeps its assumptions: each
   one the owner confirmed becomes a rule or decision in the area's notes, and
   the rest go under "## Assumptions" with how each is known (checked in the
   code, confirmed by you, assumed). The planner reads them first (§7), and one
   question before building uses the same list. Markdown, no new store.
2. **Change Records in the handover package.** Export a short Markdown record
   of each kept change with the notes, so the handover package (direction 20
   §17) needs no extra work. They stay out of the repository (§19).
3. **Guidance that ages.** Record the commit each guidance item was last
   reviewed against, and show "This guidance was written before … It may need
   another review" when its area changed a lot since. The count comes from
   Change Records per area, so no new data is needed.
4. **Review freshness.** "Billing was reviewed 8 months ago; 6 billing changes
   since." Same count.
5. **Intent against reality.** Compare owner rules with what policies, routes
   and tests allow ("You said managers cannot see payroll; the app lets them").
   This builds on the active testing in direction 14.
6. **Selective context for humans.** The review packet (§29.4) includes only
   the areas, rules, Change Records and evidence that the question touches.
7. **Scoped expert access** (read-only snapshot, isolated preview, no
   credentials or customer data) and **explainable escalation** (the reason
   is always shown; bring-your-own developer always works). These need the
   access model that later gates design.

## 31. Complexity moves upward (version 20)

Source: [direction 21](direction/21-complexity-moves-upward.md). Capable agents
do not remove the work of managing software; they move it up to continuity,
context selection, rule enforcement, verification and change history. The
platform carries that work so the owner does not have to. We must beat
"Claude Code + Laravel + good documents + an attentive human architect", not
"repo + a bare prompt".

### 31.1 Four primitives, as they exist in V1

This refines §30.1 by splitting the notes by what they do. It adds no store.

| Primitive     | Owner sees                      | V1 home                                                                  |
| ------------- | ------------------------------- | ------------------------------------------------------------------------ |
| Understanding | About your app, How things work | The notes' `project.md` and each area's summary and behaviours           |
| Constraints   | Things that must always be true | "## Rules" in each area, and "Engineering direction" in `project.md`     |
| Relationships | Things this is connected to     | `effects` in each area's frontmatter                                     |
| Changes       | What changed                    | Kept feature requests (brief, evidence, commit) and visual edits (§30.1) |

Code and runtime stay the source of implementation reality (§1, principle 10).
A behaviour stays light: a key, a plain name, and, when known, who does it,
its key rules and the tests that prove it. Knowledge items keep their kind
(decision, assumption, invariant, guidance; §7 Provenance) and little else.
The database is not normalized further until real use asks for it.

### 31.2 Knowledge is not enforcement

A rule the agent has read can still be broken. Constraints therefore
graduate from prose towards checks:

1. **V1 (built):** rules become the brief's _preserve_ clauses; _verify_ items
   become tests the independent verification runs; "preserved" says how it is
   known (§27.4).
2. **Later: the Constraint Compiler.** "This must always be true" becomes an
   actor × data × action matrix (for example Owner A → Org B → deny), then
   policy, route and query tests in the app's suite. Laravel's fixed places
   for authorization, validation, routing and tests make this feasible.
3. **Later: guidance as guardrails.** Some developer guidance becomes a
   structural check (for example "no Stripe calls outside BillingGateway" as a
   dependency search in the quick check).

### 31.3 Selective context is the hypothesis

The claim is "task-relevant product state beats accumulated history", not
"structured beats Markdown". The hierarchy (project → area → behaviour)
stores knowledge; the Context Compiler (§8, §26.3) picks the small packet the
agent sees. The experiment in direction 21 §9 (full documents against the
compiled packet, same model and code) tests it directly.

### 31.4 Measure human interventions

- **Research log:** [docs/research/interventions.md](../research/interventions.md)
  records each time a human had to steer, with one of six reasons: missing
  context, wrong interpretation, ignored constraint, missed effect, bad
  verification, architecture drift. It stays a Markdown log until the
  categories prove useful.
- **Metric:** human interventions per kept change, beside cost per kept change
  (§25.3). What the builder records are **owner actions**: follow-ups,
  retries, stops and undos. They are observed facts, not proof that something
  failed. An action becomes an intervention only when an operator classifies
  why it happened (later, phase 2 of the operations screens).
- **Definitions (built):** _completed_ (a run finished its build and review),
  _verified_ (the latest checks passed with tests for the change;
  _unverified_ is never counted as passed), _kept_, _pushed_ (the code
  reached the host), _published_ (the app answered its checks after the push)
  and _healthy_ (not measured: nothing checks a published app afterwards)
  are separate. Every percentage is shown with its counts.
- **Operations screens (built, phase 1):** operators named in
  `config/operations.php` see what needs attention (silent queue workers,
  backlog, stuck runs and expired leases, failures by stage and reason,
  exhausted budgets, preview failures and edit-to-screen time, workspace
  cleanup, spend with a completeness label) and each change's history, with
  queue, machine and owner time kept apart. Facts are recorded at the source:
  worker heartbeats, preview rebuilds, stop reasons, the execution settings
  version, cleanup failures, where each cost came from (a coding agent that
  reports no cost is priced from config when its model is named), the
  decision model's calls, and which changes a publish contains. No prompts
  or customer code appear on list screens.
- **Evolution Benchmark (later):** a fixed sequence of 20–50 realistic
  changes to one app, measured at changes 1, 5, 10, 20, 35 and 50 for
  regressions, corrective prompts, cost and missed rules. This project is the
  first one.
- **Learned relationships:** when kept changes show two areas changing
  together repeatedly, the relationship becomes a `history` Effect (see the
  test-impact entry). Later: propose it to the owner ("Remember this
  relationship?") so it is written into the notes.
- **Test-impact prototype (running):** tag some tests with the
  behaviour they prove (a `behavior:<key>` group; Pest groups and PHPUnit's
  `#[Group]` both work). For real changes, map behaviour → tests → affected
  tests → behaviours, and log useful, noisy and missed Effects, and important
  behaviours with no tests. Build an Effect graph only if this pays off.
  Built: when a change's suite check passes, the suite runs again with code
  coverage (`builder.verification.test_map`), before the protected tests are
  copied in. PHPUnit's coverage XML and test list XML, both documented
  formats, give which tests ran which code files; each run is kept as a test
  observation. Tests of area B that ran code area A claims give A an Effect
  on B with source `tests` (strong from two tests, possible from one). The
  review gets the areas whose tests ran the changed code, and the changed PHP
  files no test ran. `php artisan builder:effects` compares, per change, the
  areas the tests reached with the areas touched outside the ask ("missed"),
  and lists the behaviours no test proves. For the fast path, each requested
  area in the coder's context lists its existing tests: first the test files
  seen running its code (most tests first), then the ones it claims by path.
  The agent runs those while it works; the full suite still decides. First
  real map (the fixture, 2026-09-27): 92 tests over 32 code files; the
  observed Effects matched the written ones. History: kept changes (accepted,
  not undone) about area A that also changed area B give A a `historical`
  Effect on B with source `history`, once `builder.context.history.min_changes`
  (2) of the latest `window` (50) kept changes agree. The date is the latest
  such change's. Foundation: code more than `foundation_share` (half) of
  the tests run, once the suite has `foundation_min_tests` (10), such as the
  user model, middleware and providers (88–100% of the fixture's tests). It
  would tie every area to every other, so it makes no `tests` or `history`
  Effect and lists no tests for an area. A change to it is reported apart as
  reaching the whole app: a broad change (§8). Lines: the map also keeps,
  per file, the line ranges each test ran (short gaps bridged, since blank
  lines and comments are not executable). A change reaches the tests that
  ran its changed lines: numbered as in the new file when the map was made
  with the change in place, else as in the old one. So a change to one
  method of a large or foundation file is narrow when few tests run that
  method. When no test ran any changed line (new code, a signature), the
  whole file counts: unknown broadens, never narrows. Changed code no test
  ran is a gap only in a top folder coverage measured (usually `app/`); a
  config file or migration there is unknown to the map, not untested. Next: impact by
  behaviour, as tests gain `behavior:` groups.
  The owner sees this evidence in plain words. Each part says how many tests
  run its code, and lists what they check, in the tests' own names. Each
  change says how it is known to work: the checks that passed, the problems
  caught and fixed before the owner saw it, the tests it added, how many
  tests ran the changed code, and the code no test runs yet. Each part also
  lists what the owner asked for in the changes they kept, in their words:
  the criteria a named test proved. The app's current code is searched for
  each test name, since the last test map can predate a kept change; a test
  renamed or removed since is shown as changed or gone, never as passing.
