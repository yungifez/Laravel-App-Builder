# Direction 04: Two audiences, progressive disclosure and observability

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We are serving two very different user targets with the same underlying application model.

Do NOT design two separate products.

Design one system with progressive disclosure.

## Target 1: The non-technical owner

Think:

- "grandma who needs a website"
- small business owner
- founder with an idea but little technical knowledge
- someone who should never need to know what Laravel, a route, controller, API endpoint, queue, model, migration, webhook, or policy is

This user primarily cares about:

- what their application does
- what customers can do
- what staff can do
- what happens automatically
- what information is stored
- who can see or change things
- what other services are connected
- whether the application behaves the way they intended

They should be able to inspect their application and notice incorrect behavior without having to prompt the AI to explain the codebase.

Example:

Instead of showing:

```
POST /appointments/{appointment}/cancel
AppointmentController::cancel
AppointmentPolicy::cancel
CancelAppointmentAction
```

show:

```
Cancel an appointment

Customers can cancel their appointment up to 24 hours before it starts.

What happens:
- the appointment is marked cancelled
- the calendar event is removed
- a cancellation email is sent
```

This gives the user enough information to say:

"That's wrong. Customers should only be able to cancel 48 hours before."

That is a core product goal.

The system should expose application behavior clearly enough that a non-technical owner can audit the application's behavior simply by browsing the product.

They should not have to repeatedly ask an AI: "What does this page do?" or: "What happens when someone clicks this button?"

That information should already be materialized and visible.

## Target 2: The power vibe coder

This user may not be a professional Laravel developer, but they want considerably more control.

They care about:

- application structure
- implementation choices
- data flows
- permissions
- APIs
- automations
- background jobs
- packages
- tests
- technical history
- source code when necessary

They should be able to progressively reveal more information.

For the same cancellation feature, they may eventually see:

```
Cancel appointment

Product behavior
Customers may cancel until 24 hours before start.

Permissions
Customer
Staff

Data changed
appointments.status

Side effects
cancellation notification
Google Calendar event removal

Surfaces
Customer booking portal
Admin dashboard
Public API

Technical implementation
Route: appointments.cancel
Policy: AppointmentPolicy::cancel
Action: CancelAppointment
Tests: AppointmentCancellationTest
```

And from there: Open source · View tests · View history · Ask AI to change.

The underlying information is the same.

The difference is disclosure depth.

## Do not create "simple mode" and "expert mode" as hard silos

Avoid forcing users to declare themselves technical or non-technical.

Use progressive disclosure instead.

A useful conceptual progression is:

1. **What this does**: plain business language.
2. **How it behaves**: rules, actors, outcomes, automatic actions.
3. **Data and permissions**: what it reads/writes, who can do it, integrations involved.
4. **Technical implementation**: routes, actions, jobs, policies, packages, APIs, tests.
5. **Source code**: actual implementation.

A simple user may never expand beyond levels 1-2.

A power user may routinely use levels 3-5.

Both should feel like first-class users.

## Do not center the application model around "pages"

A web page is only one way application behavior can be exposed.

We need a broader internal abstraction.

Use an internal concept such as **Surface**.

A Surface represents a way application behavior is exposed or entered.

Possible internal kinds:

- screen
- form
- UI action
- API
- webhook
- scheduled task
- automation
- background process
- command
- integration entry point

The exact enum can evolve.

The important point is that the system should not assume all useful application behavior originates from a browser page.

Examples: a customer booking screen (`/book`), an API endpoint (`POST /api/bookings`), a Stripe webhook (`POST /webhooks/stripe`), a nightly job ("Expire old invitations every night").

Internally these may all be represented consistently.

But do NOT expose the term "Surface" to ordinary users unless there is a reason.

It is an implementation abstraction for us.

## Translate technical surfaces into purpose

User-facing language should describe what something accomplishes, not what protocol it uses.

| Instead of                | Show                                                    |
| ------------------------- | ------------------------------------------------------- |
| `POST /api/invoices`      | Create invoices from another system                     |
| `GET /api/customers/{id}` | Let connected applications look up customer information |
| `POST /webhooks/stripe`   | Receive payment updates from Stripe                     |
| `ExpireInvitationJob`     | Expire old invitations. Runs automatically every night. |

Power users can reveal technical details if they want them.

## The hierarchy should focus on business behavior

```
Application
    ↓
Capabilities
    ↓
Behaviors
    ↓
Surfaces
    ↓
Implementation
```

Example:

```
Capability:
Appointments

Behaviors:
- create appointment
- reschedule appointment
- cancel appointment
- send reminder

Surfaces:
- customer booking website
- staff dashboard
- public API
- Google Calendar integration
- scheduled reminder process

Implementation:
- Laravel routes
- controllers
- actions
- policies
- jobs
- models
- notifications
- Vue components
- tests
```

The user should normally start at the Capability or Behavior level.

Implementation detail should be progressively revealed.

## User-facing navigation should avoid framework vocabulary

A simple user should ideally see concepts such as:

- What people can do
- What happens automatically
- Your data
- People & permissions
- Connections
- History

rather than: Routes, Controllers, Models, Jobs, Events, APIs.

Examples:

- **What people can do**: Book an appointment · Invite a teammate · Upload a document · Cancel a subscription
- **What happens automatically**: Send booking reminders · Retry failed payments · Expire invitations · Generate monthly reports
- **Your data**: Customers · Appointments · Invoices · Documents
- **People & permissions**: Owner · Administrator · Staff · Customer
- **Connections**: Stripe · Google Calendar · Email provider

These views should be generated from the actual application implementation, not manually-maintained documentation.

## Observability is a core product feature

The system needs a persistent, cached representation of user-relevant application behavior.

Do not interpret this as requiring a complete graph of every class and function in the codebase.

The goal is not to model every internal dependency.

The goal is to answer questions the end user cares about without invoking an LLM every time.

Examples:

- What can customers do?
- What can administrators do?
- What happens when this button is pressed?
- What information does this action change?
- Does this send an email?
- Does this charge someone?
- Does this call another service?
- What happens automatically?
- Who can access this?
- What changed after the last AI edit?

Prefer a compact, code-derived Product Behavior Index rather than a giant universal application graph.

## Product Behavior Index

The repository remains the source of truth.

The Product Behavior Index is a generated projection of the codebase.

Potential records may contain information such as: name, human-readable purpose, capability, behavior, actor, outcome, permissions, inputs, data read, data written, side effects, integrations, surfaces, implementation references, invariants, provenance.

Example:

```
Behavior:        Invite project member
Purpose:         Allow project owners to invite another person to collaborate.
Actors:          Owner, Administrator
Inputs:          email, role
Outcome:         Pending invitation created.
Data written:    project_invitations
Side effects:    Invitation email queued.
Surfaces:        Project settings screen, API
Implementation:  InviteProjectMember action, ProjectPolicy::invite
```

The non-technical user may only see:

```
Invite a teammate

Owners and administrators can invite someone to the project.
The person receives an email invitation.
```

The power user can progressively reveal the rest.

## Derive as much as possible mechanically

Do not rely on an LLM to regenerate these explanations every time they are viewed.

Use deterministic extraction from Laravel wherever practical.

Potential sources include: routes, middleware, controllers, FormRequests, policies, Actions/Services, Eloquent models and relationships, events, listeners, jobs, notifications, schedules, migrations, Composer packages, Inertia pages, Vue components, Wayfinder relationships, tests, capability metadata.

The extraction system should generate structured facts.

AI can help produce or maintain human-readable semantic annotations when meaning cannot be determined mechanically.

## Product memory complements the index

Keep the previously discussed versioned project memory.

Its job is different.

The Product Behavior Index records observable facts derived from the current application.

Project Memory records things that source code may not clearly explain:

- product intent
- terminology
- important decisions
- invariants
- reasons behind architectural choices
- unusual business rules
- deliberately rejected approaches
- important discoveries

Combine: deterministic code facts + project memory = human-readable application understanding.

For example, code tells us "customer may call CancelAppointment"; memory tells us "Product policy says customers must not cancel within 48 hours of appointment time."

If implementation and intent disagree, that should be visible:

```
Intended:
Customers cannot cancel within 48 hours.

Current application behavior:
Customers can cancel until 24 hours before.

Possible mismatch
```

Do not automatically assume either side is correct.

Surface the discrepancy for review.

## Behavior diffs are more important than source diffs for ordinary users

After the coding agent changes something, derive a user-readable behavior diff.

Developer diff:

```diff
- $user->isOwner()
+ $user->isOwner() || $user->isAdmin()
```

Business-user diff:

```
Member invitations

Previously:
Only owners could invite people.

Now:
Owners and administrators can invite people.
```

Another example:

```
Subscription cancellation

Previously:
Access ended immediately.

Now:
Access continues until the end of the billing period.
```

This should allow non-technical users to review what an agent actually changed.

Git/source diffs should remain available for power users.

## Visual editing should include behavior inspection

When a user selects something in the preview, do not only expose styling controls.

Example: user selects "Invite member".

Simple view:

```
What it does
Sends someone an invitation to join this project.

Who can use it
Owners and administrators.

What happens next
An invitation email is sent.
```

Power user expands: Data · Permissions · Side effects · API usage · Technical implementation · Source code · Tests.

This means the visual builder becomes an interface into both presentation and application behavior.

That is a major product differentiator.

## APIs should not feel second-class

Because the internal abstraction is not "page", an API-only SaaS should still fit naturally into the platform.

A capability may expose behavior through API, webhook or background automation without having any browser screen.

The simple product view can still describe it in human terms:

```
Order integration

Other systems can:
- create orders
- check order status
- cancel eligible orders

Your application automatically:
- sends shipping updates
- receives payment status changes
```

The power user may reveal the underlying endpoints.

## Do not overbuild the index

We are explicitly trying to avoid the complexity of a giant complete application graph.

The persistent representation should focus on user-observable product behavior.

Internal code relationships can be discovered on demand when an agent needs them.

A useful principle is:

**Persist what is valuable to observe. Derive what is cheap to rediscover. Remember what cannot be inferred from code.**

This gives us three complementary systems:

- **Product Behavior Index**: persistent/cached projection used for user observability.
- **Project Memory**: versioned intent, decisions, terminology, invariants, and discoveries.
- **Working Context**: ephemeral code/dependency information assembled for an agent task.

Do not collapse all three into one giant graph.

## The product must succeed for both target users

Always evaluate UX and architecture against these two people.

**Target A**: a person with essentially no software-development knowledge. They should be able to build, inspect, understand, approve, and maintain a useful application without learning Laravel concepts.

**Target B**: a power vibe coder who wants substantially more visibility and control but still values AI doing most implementation work. They should be able to drill down through business behavior → rules → data → permissions → surfaces → implementation → tests → source code without leaving the product.

The platform should not dumb itself down for Target A or become an IDE that excludes Target A.

Progressive disclosure is the mechanism that allows both to coexist.

## What was asked

Determine the internal model for Capability, Behavior, and Surface; what belongs in the Product Behavior Index; what remains ephemeral; which fields can be extracted deterministically; where AI semantic annotations are appropriate; how annotations are kept from becoming stale; how behavior diffs are generated; how behavior connects to visual elements; how API-only and automation-only functionality fits; how the UI progressively reveals technical detail; how this works without a giant graph-maintenance problem; and the smallest V0. Favor a small, derived, refreshable index over a comprehensive persistent model of every code dependency.
