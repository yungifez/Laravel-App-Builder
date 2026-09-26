# Architecture

**Version 8.** This document consolidates the direction in [direction/](direction/)
into one architecture. Version 7 adds the "convention over generation"
reassessment ([§24](#24-convention-over-generation-reassessment)), aligns the
product ontology, removes implementation details from the product model, and
replaces Project Memory with hierarchical Project Context and discovery
([§7](#7-project-context-and-discovery)). Version 8 re-justifies the design
against observed user complaints: it adds outcome measurement and falsification
([§25](#25-outcomes-measurement-and-falsification)), makes the Context Compiler
a named subsystem ([§8](#8-context-compiler)), splits behaviour diffs into
requested and unexpected changes, and postpones what exists mainly for
elegance. When they disagree, the direction documents state intent
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
  └── Runtime adapter  our containers/VMs · bought sandboxes · local runner
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

- A behaviour is one entry handler plus what it causes. It gets an opaque, stable
  key the first time it is discovered, and an **anchor** (route name, job class,
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
prototype would make the question easier to answer. A deterministic gate then
decides:

| Situation                                                                                                                                            | Action                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Answered by context                                                                                                                                  | Use it. Never re-ask unless the user reopens the decision.                                                                                                          |
| Engineering decision (controllers, queues, validation, migrations, policies, tests)                                                                  | Decide by convention. Never ask.                                                                                                                                    |
| Unanswered, wrong guess touches data model, money, permissions, destructive behaviour, external integrations, legal expectations or a major workflow | **Ask before building.** One concise question, multiple choice with a recommended default.                                                                          |
| Unanswered, easier to judge after seeing it, or low consequence                                                                                      | **Build with the default**, record it as `proposed`, and ask for confirmation in the review ("I set it up so cleaners are assigned automatically. Is that right?"). |
| Several high-consequence unknowns                                                                                                                    | Ask the minimum set that avoids likely rework, one at a time, most consequential first.                                                                             |

The rule underneath: ask when the cost of a wrong assumption is meaningfully
greater than the cost of interrupting. The consequence categories come from the
decision's reach in the Product Behavior Graph and the change class, so the gate
is mostly mechanical; the model only proposes the decision list and defaults.

### Not annoying people

- At most one question before building, for a typical request.
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

### Provenance

Only the user makes intent. An answer, an explicit statement or an approval of a
listed assumption is `confirmed`. A default the platform chose is `proposed`
until the user confirms it, and is shown as "assumed". Anything the model
inferred from conversation is `ai_interpretation` and never drives hard gates.
Facts derived from the code belong in the Product Behavior Graph, not in Project
Context. Package guarantees are `package_contract`. Not every sentence becomes
memory: only durable, product-relevant statements are written.

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

Two kinds: intent against behaviour ("you said only owners manage billing, but
administrators can change payment methods"), and new intent against older intent
at another scope. Both appear as a plain question with two answers, "keep how
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
  including "Do customers choose a cleaner?" (no, assign whoever is available)
  → first slice built.
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

Application essentials form a stable prefix, cached per commit and context
version. No embeddings or retrieval system in V0: the scope hierarchy is the
retrieval strategy until an experiment shows it is not enough. The agent can
still query more through MCP (`context.query`, `introspect.query`,
`capability.describe`, `transform.apply`, `package.request`, `verify.run`) and
explore the repository freely; the pack guides, it never imprisons.

The deeper engineering graph for a task is still built on demand in the runtime
by `builder/introspect --around=<behavior-key>` and discarded after the run.

## 9. The change pipeline

```
request (chat, behaviour card, or visual selection)
 → intent: which capability and behaviours?           small model + graph + context
 → decision check: what does this depend on that      §7; at most a few questions,
   the context does not answer?                        otherwise proceed with stated assumptions
 → classify into change classes and typed operations
 → deterministic operations                           no model
 → agent tasks                                        execution router → agent adapter
 → normalization                                      curated Rector set + Pint
 → verification                                       §12
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

### Execution router

| Stage                                            | Starts with                                                       | Escalation                                                     |
| ------------------------------------------------ | ----------------------------------------------------------------- | -------------------------------------------------------------- |
| Interpret request, plan                          | strong reasoning model                                            | —                                                              |
| Classify, map to capabilities, name UI, annotate | cheap model                                                       | stronger model on low confidence                               |
| Deterministic operations                         | no model                                                          | go semantic when unsure                                        |
| Implement                                        | coding engine per task class                                      | after 2 failed repairs: stronger model or a different provider |
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

The existing run model stays: states queued → planning → implementing →
verifying → reviewing → completed, plus needs_user_decision, cancelling →
cancelled and failed; a lease with a fencing token per run; budgets (operations,
minutes, repairs); cancellation; the reconciler. Fencing moves from individual
tool calls to runtime tasks when agents run in the runtime; the per-tool-call
journal remains for the scripted engine and tests.

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
3. **Tests:** the full Pest suite; protected acceptance tests generated from the
   plan's criteria before coding (by a model other than the coder, confirmed by
   the owner in plain language, frozen); Pest browser tests on touched screens.
4. **Invariants** as Pest tests, many from helpers our capability packages ship
   (for example `assertTenantIsolated(Project::class)`).
5. **Independent review** by a different provider, when triggered.

Only DERIVED, CONFIRMED and PACKAGE CONTRACT statements feed hard gates. AI
interpretations and proposals produce warnings and review prompts only. Purely
visual edits get light verification: build, `vue-tsc`, a visual smoke test, and
a behaviour diff showing that no behaviour changed.

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
  family with variant and responsive prefixes; choices limited to the project's
  Tailwind `@theme` scale.
- **Literal text** in templates, or translation files for translation keys.
- **Show or hide** by breakpoint.
- **This instance or all instances:** editing a shared component changes it
  everywhere, so the editor asks; "this one only" adds the class at the usage
  site, which shadcn-vue components merge with `cn()`.
- **Not static → agent:** dynamic `:class`, `v-if`, loops, props, database
  content, anything tied to permissions or behaviour.

Edits collect on a visual-session branch, commit on save, and get light
verification.

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

## 16. Model gateway and credentials

Every model call, from the control plane or a runtime, goes through one metered
path.

- The runtime gets a base URL and a short-lived token; the gateway injects the
  real credential, so an agent with a shell never sees it; it records usage and
  enforces budgets as hard limits.
- **Credentials vault** per account, encrypted, masked, revocable, each checked
  by a test call before saving: `api_key`, `claude_subscription_token`,
  `codex_chatgpt_token`, and provider OAuth (for example OpenRouter) later.
- **Seamless default:** included credits. Bring-your-own is an option chosen in
  one onboarding question.
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

## 20. Deliberately not built yet

Postponed in version 8 because they exist mainly for elegance, not to answer an
observed user problem (each returns when a measurement asks for it):
content-addressed snapshot storage (V0 stores plain per-snapshot rows); the
second provider adapter and learned routing (the contract and telemetry stay);
package trust levels and adapters beyond an allowlist; custom Rector rules and
the rule-promotion pipeline; the typed-operation catalogue beyond
`capability_config` and `agent_task`; mapping design context onto theme tokens;
AI comparison of prose intent; the edge proxy and hot reload; imported
applications; the invariant lifecycle interface; showing all five provenance
classes to users (three badges suffice).

Also not built:

Sprints, estimates, boards, backlog grooming and any project-management
vocabulary in the product; a requirements database beyond one context table;
onboarding questionnaires; a persistent engineering graph or graph database; trust scoring; a package
marketplace; adapters beyond the packages we use; package replacement; a large
rule catalogue; a page-builder document tree; generic multi-framework support;
targeted test selection; multi-agent decomposition within one request;
automated upgrades across many projects; inferred invariants as protections;
online routing experiments on high-risk work.

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

### Next stages (version 8: measurement first)

The plan is reordered so that every claim can be tested before more is built on
it. Each stage ends with a measurement, not only a demo.

| Stage                     | Build                                                                                                                                                                                           | Tests the claim                                                                          |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| **0 Measure first**       | The change-request record and the outcome events (§25.3); cost attribution through the gateway and sandbox; the evaluation harness skeleton with **sequential scenarios** and a simulated owner | Nothing yet: makes every later claim measurable                                          |
| **1 Observe**             | `builder/introspect` v0 with test-run side-effect tracing; Product Behavior Graph V0; behaviour cards; behaviour diff with **requested vs unexpected changes**                                  | Behaviour-level review helps owners catch unrelated changes (§25.4, B1)                  |
| **2 Execute**             | `AgentTask`/`AgentResult`; runtime adapter and reproducible template; runner; `claude-agent-sdk` and `script` adapters; gateway and vault; routing table v0                                     | An Agent SDK agent completes the continuation scenario end to end                        |
| **3 Context**             | `context_entries`, resolver, Context Compiler (§8) with inclusion logging; the decision check; selective memory creation; the "What I know" page                                                | Context and targeted questions lower cost per accepted change and retries (§25.4, C1–C3) |
| **4 Run the experiments** | The condition matrix in §25.4 on the scenario set; the owner study                                                                                                                              | Keep, simplify or remove context and questions by the pre-registered thresholds          |
| 5 Deterministic first     | `capability_config`; Rector (`rector-laravel` only) and normalization after the agent                                                                                                           | Share of changes done without a model rises without more regressions                     |
| 6 Laravel semantics       | PHPStan and architecture rules; diff-triggered checks and approval gates; `migrate --pretend`                                                                                                   | A seeded set of bad changes is all caught                                                |
| 7 Visual editor           | Selection context, inspector, Tailwind and text edits, semantic hand-off                                                                                                                        | Tiny UI changes stop costing agent runs (§25.4, V1)                                      |

Then, by evidence: a second provider and learned routing; intent-vs-behaviour
checks beyond structured rules; the first first-party capability package;
adapters; imported applications; deployment through Laravel Cloud.

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
- **Vendor terms** for subscription logins; approval before enabling on our
  cloud.
- **Corpus privacy.** Learn from customer code only with consent.

## 23. Open decisions

- Included credits at launch, and pricing.
- Providers beyond Anthropic and OpenAI, and the OpenAI agent SDK choice.
- Curated presets only, or also an open model picker.
- Approval from Anthropic (and a position from OpenAI) for subscription tokens
  in a hosted product.
- The sandbox provider for managed runtimes.
- The product's public name and category (not "Laravel builder").
- Whether to charge for accepted changes rather than raw usage (§25.6).
- Recruiting 3–5 owners for the behaviour-diff study (§25.4).

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

| Complaint                                                   | Mechanism                                                                | Hypothesis                                                                           |
| ----------------------------------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
| "I already explained this"                                  | Project Context, Context Compiler                                        | C1: later requests need fewer corrections and retries                                |
| Many prompts for simple behaviour; credits burned on rework | targeted questions; minimum sufficient context                           | C2, C3: lower cost per accepted change                                               |
| "It broke something that worked"                            | behaviour diff with unexpected changes; full test suite; protected tests | B1: owners catch unrelated changes they would otherwise miss                         |
| "What does this do?" asked again and again                  | behaviour cards                                                          | B2: fewer explanatory model calls; owners answer questions about their app correctly |
| Credits spent on tiny UI tweaks                             | deterministic visual edits                                               | V1: UI tweaks complete without agent runs                                            |
| Backend churn                                               | convention over generation; capability metadata                          | D1: fewer files touched and fewer regressions per change                             |

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

| Event                                      | Fields that matter                                                          |
| ------------------------------------------ | --------------------------------------------------------------------------- |
| `request.created`                          | source (chat, behaviour card, visual selection), target scopes, project age |
| `question.asked` / `question.answered`     | key, category, pre- or post-build, latency to answer, whether the user left |
| `context.compiled`                         | entry ids included, tokens per section, condition (for experiments)         |
| `stage.finished`                           | stage, engine, model, tokens in and out, cost, duration, outcome            |
| `sandbox.usage`                            | seconds, cost                                                               |
| `verification.finished`                    | per check, first attempt or retry                                           |
| `behavior_diff.computed`                   | requested changes, unexpected changes                                       |
| `unexpected_change.resolved`               | kept or undone; later confirmed as a real regression or not                 |
| `review.decided`                           | accepted, rejected, follow-up requested, free-text reason                   |
| `change.reverted` / `correction.requested` | within the seven-day window                                                 |
| `memory.written`                           | source (answer, classifier, consolidation), provenance                      |
| `visual_edit.applied`                      | deterministic, or handed to an agent                                        |

These are enough to compute every metric in direction 09: cost per accepted
change, first-attempt pass rate, retries before acceptance, whether questions
reduced retries, context size against success, success and cost by task class
and provider, transform and verification outcomes, and unexpected changes
detected.

### 25.4 Experiments, with thresholds set in advance

**Context (C1–C3), run in the evaluation harness.** The benefit of accumulated
context only appears in _later_ requests, so tasks are **sequential scenarios**:
a project grows through 6–10 requests (the cleaning business: bookings,
automatic assignment, recurring bookings, cancellations, reminders). A
**simulated owner** holds the hidden intent, answers questions from it, and
rejects wrong results; protected tests encode that intent. Conditions:

| Condition | The agent receives                                                                |
| --------- | --------------------------------------------------------------------------------- |
| A         | repository + current request                                                      |
| B         | A + compiled context pack (context, current behaviour, confirmed rules)           |
| C         | B + the pre-build question gate                                                   |
| D         | A + the full conversation history (the obvious alternative to structured context) |

Measured per request: first-attempt pass, retries, tokens, runtime, regressions
in untouched behaviours, cost per accepted change. Each scenario runs 3–5 times
per condition, because agent runs vary.

Decision rules, fixed before running:

- If B does not beat A by at least 20% on cost per accepted change **or** 10
  points on first-attempt pass rate in the later requests of each scenario,
  shrink Project Context to application-level essentials only.
- If B does not beat D, structured context is not worth its machinery; use
  selected history instead.
- If C does not reduce retries relative to B, raise the question threshold.
- If B's packs regularly exceed a few thousand tokens without a matching gain,
  cut the lower-priority sections.

**Behaviour observability (B1, B2), with real owners.** In the pilot with 3–5
owners (source plan §11), each performs a series of changes. One planted change
also alters an unrelated behaviour (the cancellation cut-off). Groups see a
behaviour diff, or a plain AI summary of the change. Measured: detection rate of
the planted change, time to decide, stated confidence, and correct answers to
"who can do X?" questions. If owners with behaviour diffs catch fewer than half
of planted changes, or do no better than with a summary, rethink the review
experience before building more on the graph.

**Visual edits (V1).** Share of UI-adjustment requests completed without an
agent run, with no increase in rejected changes.

### 25.5 How much clarification helps speed

- **Before the first visible result:** at most three questions, and one or two
  is the aim; opening discovery must not delay the first preview beyond one
  build.
- **Typical request:** at most one question before building; everything else is
  built with a stated assumption and confirmed in the review.
- **Harm signals, tracked:** time to first preview, users leaving after a
  question, questions answered with "you decide".
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
