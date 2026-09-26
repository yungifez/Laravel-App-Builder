# Direction 05: The Product Behavior Graph

> Source: direction from the project owner, recorded as given (formatting repaired
> only). Refines the application graph around product behavior.

I agree that a comprehensive persistent graph of the entire codebase is too complicated and likely to become brittle.

We still need a persistent representation of the application, but its purpose is primarily:

- end-user observability
- progressive disclosure
- human-readable inspection of application behavior
- behavior diffs after AI changes
- lightweight context for agents

It should NOT attempt to persist every internal code dependency.

## Replace the old graph with a Product Behavior Graph

The persistent graph should model what the application DOES from a product perspective.

```
Application
    ↓
Capability
    ↓
Behavior
    ├── Actor
    ├── Rule / Invariant
    ├── Data
    ├── Side Effect
    ├── Integration
    └── Surface
            ↓
    Implementation References
```

Example:

```
Capability: Appointments
    Behavior: Book appointment
        Actor: Customer
        Reads: Availability
        Writes: Appointment
        Rule: Selected time must still be available
        Side effect: Send confirmation
        Surface: Customer booking experience
        Surface: Public API
        Implementation references:
            appointments.store
            CreateAppointment
            AppointmentPolicy
            CreateAppointmentTest
```

The graph should be understandable in business terms before technical details are revealed.

## Capability

Capability is the top-level product concept.

Examples: Appointments, Customers, Projects, Organizations, Billing, Documents, Notifications, Reporting.

These should generally correspond to concepts a non-technical owner understands.

Capabilities may be implemented by Laravel itself, Laravel first-party packages, our first-party packages, trusted third-party packages, or custom application code.

The graph should not care initially how the capability is implemented.

## Behavior

Behavior is the most important persistent unit.

Examples: Book appointment, Cancel appointment, Invite teammate, Upload document, Approve request, Cancel subscription, Generate report, Reset password.

A Behavior represents something meaningful the application allows or performs.

This is where users should be able to inspect whether the application matches their intent.

For example: "Cancel appointment. Customers can cancel up to 24 hours before the appointment." A business owner can immediately say: "That should be 48 hours."

This is the kind of observability the product must enable without repeatedly invoking an LLM.

## Surface

Do not use "Page" as the internal abstraction. Use something broader such as **Surface**: a way a Behavior is exposed or triggered.

Possible internal kinds include: screen, form, UI action, API, webhook, automation, scheduled process, command, background process, integration entry point.

Do NOT necessarily expose the term Surface to normal users. Translate it into human language:

| Technical               | Simple user                          |
| ----------------------- | ------------------------------------ |
| `POST /api/invoices`    | Create invoices from another system  |
| `POST /webhooks/stripe` | Receive payment updates from Stripe  |
| `ExpireInvitationJob`   | Expire old invitations automatically |

Power users can progressively reveal the underlying technical surface.

## Implementation references should be references, not the graph itself

This is a major simplification.

Do not persist a graph like:

```
Route → Controller → Request → Policy → Action → Model → Event → Listener → Job
```

Instead, a Behavior can contain lightweight implementation references such as:

```
route:  appointments.store
action: App\Actions\CreateAppointment
policy: App\Policies\AppointmentPolicy
test:   tests/Feature/Appointments/CreateAppointmentTest.php
```

These give users and agents navigation into the implementation.

If deeper dependency analysis is required, derive it on demand from Laravel and the codebase.

## Separate persistent product graph from ephemeral engineering graph

We should effectively have two graph concepts.

### 1. Persistent Product Behavior Graph

Long-lived and relatively small. Contains Capability, Behavior, Actor, Rule / Invariant, Data, Side Effect, Integration, Surface, and implementation references. Used for end-user observability, progressive disclosure, behavior inspection, behavioral diffs, and lightweight agent context.

### 2. Ephemeral Engineering Graph

Generated for the current task when deeper code understanding is required. May contain relationships such as route → controller → FormRequest → policy → action → model → event → listener → job → test.

This graph should generally be disposable.

Laravel is structured enough that we can reconstruct relevant engineering relationships when needed.

Do not keep the entire engineering graph synchronized forever.

**Persist what users need to observe. Derive what is cheap to rediscover.**

## Project Memory remains separate

Do not try to encode all historical reasoning and product intent into graph edges.

Keep the versioned Project Memory alongside the Product Behavior Graph. It should store product intent, decisions, terminology, unusual business rules, reasons behind decisions, rejected alternatives, important discoveries, and invariants that cannot be reliably derived from code.

The Product Behavior Graph tells us what the current application appears to do. Project Memory tells us what the product is intended to do and why.

```
Intended behavior:
Customers cannot cancel within 48 hours.

Current implementation:
Customers can cancel until 24 hours before.

Possible mismatch.
```

Do not automatically decide which one is correct. Surface the mismatch.

## Progressive disclosure

The same underlying behavior should serve both target users.

**Non-technical owner:**

```
Cancel appointment

Customers can cancel their appointment before it begins.

A cancellation email is sent automatically.
```

Optionally reveal: "Customers currently have until 24 hours before the appointment."

**Power vibe coder** can expand to:

```
Actors          Customer, Staff
Rules           Customer cutoff: 24 hours
Data changed    appointments.status
Side effects    cancellation email, calendar update
Surfaces        customer booking portal, admin dashboard, API
Implementation  AppointmentPolicy::cancel, CancelAppointment,
                appointments.cancel, AppointmentCancellationTest
```

Then optionally: Open source · View tests · View history.

Do not require separate simple and expert products. Use progressive disclosure.

## Behavior diffs

After AI changes, derive product-level behavior diffs. Instead of only a code diff, show ordinary users "Member invitations. Previously: Only owners could invite people. Now: Owners and administrators can invite people." or "Subscription cancellation. Previously: Access ended immediately. Now: Access remains until the end of the billing period."

This is a core part of observability.

## Data should also be understandable

Avoid exposing tables as the primary abstraction. The user should see concepts such as Customers, Appointments, Invoices, Organizations, Documents. Power users can drill down to models, tables, relationships and migrations.

The Product Behavior Graph should connect behaviors to business-level data concepts.

## UI implications

Top-level views such as What people can do, What happens automatically, Your data, People & permissions, Connections, and History are projections of the Product Behavior Graph. A simple user does not need to know the graph exists. Power users can drill deeper from the same views.

## Visual preview integration

When a user selects an element in the preview (for example "Invite teammate"), the product should show relevant Behavior information: what this does, who can use it, what happens next. Power users expand to data, permissions, side effects, surfaces, tests, technical implementation and source. This connects the visual builder to the behavioral model.

## Generation strategy

Do not rely on the LLM to reconstruct these descriptions every time the user opens the UI. The Product Behavior Graph should be cached/materialized. Use deterministic extraction wherever possible (routes, middleware, FormRequests, policies, Actions/Services, Eloquent models, events, listeners, jobs, notifications, scheduler, migrations, packages, Inertia pages, Vue components, Wayfinder, Pest tests, our capability manifests). AI should fill semantic gaps where source structure cannot establish product meaning.

Deterministic extraction may establish:

```
POST /projects/{project}/invitations
requires ProjectPolicy::invite
creates ProjectInvitation
queues InvitationNotification
```

Semantic annotation may establish: "Human purpose: Invite someone to collaborate on a project."

We should not ask the model to rediscover the deterministic portion every time.

## Incremental regeneration

```
coding agent completes task
    ↓
Git identifies changed files
    ↓
determine potentially affected behaviors/capabilities
    ↓
re-run relevant extractors
    ↓
update Product Behavior Graph
    ↓
compute behavior diff
    ↓
show user
```

This is closer to incremental compilation than maintaining an independently authored graph. The source code remains authoritative.

## V0 direction

Do not build a complete ontology initially. Start with the smallest useful Product Behavior Graph: entities Capability, Behavior, Actor, Surface, and behavior attributes purpose, permissions, outcome, side effects, implementation refs. Add richer concepts such as Data, Rules, Integrations and Invariants as real use cases demand them.

The main thing we need to prove is:

1. We can derive useful behavioral information from a Laravel application.
2. We can present it in language understandable to a non-technical user.
3. A power user can progressively drill into technical detail.
4. After an agent changes the code, we can update the relevant behavior records and show a meaningful behavior diff.
5. We can do this without maintaining a complete persistent code dependency graph.

## What was asked

Identify what belongs in the persistent Product Behavior Graph, in Project Memory, and in ephemeral agent context; what can be extracted deterministically from Laravel; where AI annotation is genuinely necessary; how staleness is detected and corrected; how incremental regeneration works; the minimal V0 schema; the storage model; how the preview maps elements back to Behaviors; and how this serves both users without becoming another giant architecture project.
