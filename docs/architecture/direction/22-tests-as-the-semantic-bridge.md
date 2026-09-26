# Direction 22: Tests as the semantic bridge (Pest TIA)

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We should update the architecture around Pest TIA because it may give us a much more deterministic foundation for Effects, context selection, and verification than we previously assumed.

The important insight is:

> Tests can become the bridge between product semantics and implementation reality.

Pest TIA already observes which implementation artifacts are exercised by which tests.

If we associate tests with product Behaviors, we can use that observed execution information to infer which Behaviors may affect one another.

## 1. Pest TIA as implementation evidence

Conceptually, Pest already gives us:

```
Test
    ↓
observed runtime dependencies
    ↓
source files
database tables
Blade views
Inertia pages
Vite/frontend dependencies
```

Then after a code change:

```
changed implementation
    ↓
reverse dependency lookup
    ↓
affected tests
```

This is significantly better than asking an LLM:

```
What might this change affect?
```

because at least part of the answer comes from actual execution evidence.

## 2. Associate tests with Behaviors

Tests should be traceable to product Behaviors.

Potentially something as lightweight as Pest groups:

```
behavior:invite-member
behavior:accept-invitation
behavior:billing-seats
behavior:onboarding
```

Then the graph becomes:

```
Behavior
    ↓
Tests
    ↓
observed implementation
    ↓
other Tests
    ↓
other Behaviors
```

Example:

```
Invite Member
    ↓
InviteMemberTest
    ↓
MembershipPolicy
InviteMember Action
invitations table
InvitationAccepted event
    ↓
affected tests
    ↓
Accept Invitation
Billing Seats
Onboarding
```

Now the platform can say:

```
This change may also affect:

- invitation acceptance
- seat billing
- onboarding
```

with evidence from the actual application rather than pure model speculation.

## 3. Effects should gain provenance

Do not redefine Effects as merely TIA relationships.

Instead, let Effects collect evidence from several sources.

Possible sources:

### Observed

Runtime/test evidence.

Example:

```
Changing MembershipPolicy caused tests for Billing Seats
to become affected.
```

Strong evidence.

### Static

Framework/source structure.

Examples:

```
Route → Controller
Policy → Model
Event → Listener
Action → Job
Vue component → imported component
```

### Historical

Repeated real changes have shown the two Behaviors moving together.

Example:

```
Four previous appointment cancellation changes also touched
Availability and Notifications.
```

### Product semantic

Owner, expert, or model reasoning says two concepts may interact.

Example:

```
Cancellation may affect cancellation fees.
```

Useful, but softer.

Effects remain advisory.

Their provenance tells us why we believe the relationship exists.

## 4. Observed Effects may become one of the strongest kinds

Suppose:

```
Behavior A
Cancel Appointment
```

has tests that execute:

```
CancelAppointment
Appointment
AvailabilityService
NotificationService
```

Behavior B:

```
Show Available Times
```

has tests that also execute:

```
AvailabilityService
Appointment
```

This gives us an observed relationship.

It does not prove that the two Behaviors are semantically identical or always dependent.

It means:

> A change to implementation involved in Behavior A may warrant checking Behavior B.

That is exactly what Effects are supposed to mean.

## 5. TIA can help drive the Context Compiler

Effects should not only determine tests.

They can help decide what context the implementation agent needs.

Example:

Primary request:

```
Managers can invite contractors.
```

Primary Behavior:

```
Invite Member
```

TIA / observed relationships identify:

```
Accept Invitation
Billing Seats
Onboarding
```

Then the Context Compiler can load only relevant rules from those Behaviors.

This gives us:

```
primary Behavior
    ↓
deterministic/observed neighboring Behaviors
    ↓
relevant Decisions
    ↓
relevant Invariants
    ↓
relevant implementation
    ↓
compact working context
```

Therefore TIA could support both:

```
context selection
```

and:

```
verification selection.
```

## 6. Effects should improve over time

Every accepted change can add evidence.

Example:

```
Change:
Managers can invite contractors.

Changed:
MembershipPolicy
InviteMember
InvitationAccepted

TIA affected:
invite-member
accept-invitation
billing-seats
onboarding

Verified:
all four
```

Persist the observation.

If:

```
invite-member
    ↔
billing-seats
```

continues appearing across future changes, that Effect becomes historically well-supported.

This gives us a compounding loop:

```
change
    ↓
observe
    ↓
verify
    ↓
update relationships
    ↓
future context becomes better
```

The application teaches the engine about itself.

## 7. Missing tests remain a major blind spot

TIA only knows what execution has revealed.

If Calendar Sync is affected by cancellation but no relevant test exists:

```
TIA cannot see the relationship.
```

Therefore:

```
TIA
    +
static Laravel analysis
    +
historical change evidence
    +
product semantics
    +
expert/user knowledge
```

should cooperate.

Never assume TIA is a complete dependency graph.

Effects remain advisory.

## 8. Unknown should broaden verification

We should borrow an important philosophy from TIA:

> Lack of knowledge should cause broader verification, not false confidence.

Conceptually:

```
strong observed impact
    → narrow targeted verification

partial evidence
    → expand verification

structural/global change
    → broad verification

high-risk unknown
    → full suite / deeper review
```

This is a better default than pretending the Effect graph is complete.

## 9. Full-suite verification still needs to exist

TIA cannot become an excuse to never run the complete test suite.

The fast path and the trust boundary are different things.

During iteration:

```
changed code
    ↓
TIA
    ↓
affected tests
    ↓
fast feedback
```

But before accepting sufficiently significant work:

```
full suite
    ↓
commit / merge / accepted Change Record
```

We need a verification gate around durable changes.

However, running the entire suite before every tiny commit may become unnecessarily expensive as projects grow.

Therefore make this risk-aware.

## 10. Do not define "small" only by line count

A three-line authorization change can be more dangerous than a 200-line isolated UI component.

"Small commit" should mean something closer to:

```
narrow Behavior scope
low consequence
no critical invariant touched
no schema change
no permission change
no billing/payment change
no cross-capability Effects
strong TIA coverage
no uncertain/global dependency
```

A small visual copy/style change may qualify.

A one-line tenant-scope change does not.

## 11. Proposed verification tiers

### Fast iteration

During active agent work:

```
TIA-selected tests
relevant static checks
formatting/lint where cheap
```

Goal:

```
rapid feedback.
```

### Small verified commit

For truly low-risk/local changes:

```
TIA affected tests
relevant Behavior verification
structural/static checks
```

Potentially allow commit without immediately running the full suite.

But the commit remains part of an un-integrated working branch until broader verification occurs.

### Significant commit

For substantive product changes:

```
targeted Behavior checks
    +
TIA affected tests
    +
full test suite
    +
relevant static/build checks
```

before the change is considered accepted.

### Integration boundary

Before:

```
merge into canonical branch
deploy
release
important Change Record acceptance
```

the full suite should run regardless of how many individually small commits occurred.

This prevents a series of locally safe changes from accumulating into an unsafe combination.

## 12. "Commit" and "accepted change" may need to be different concepts

Agents benefit from making small Git commits.

We should not destroy that advantage by forcing a 20-minute suite before every internal checkpoint.

Better model:

```
agent checkpoint commit
    ≠
verified accepted change
```

An agent may create:

```
small isolated checkpoint commits
```

using targeted verification.

But before those commits are integrated into canonical project state:

```
full verification gate.
```

Conceptually:

```
edit
    ↓
targeted tests
    ↓
checkpoint commit
    ↓
more work
    ↓
Change Record complete
    ↓
full suite
    ↓
accepted / merge
```

This preserves:

- fast iteration
- small commits
- bisectability
- rollback boundaries

without weakening final assurance.

## 13. High-risk changes should always force broader verification

Regardless of apparent code size, full-suite or expanded verification should trigger for areas such as:

```
authentication

authorization

tenant isolation

billing/payments

migrations/schema changes

shared infrastructure

global middleware

dependency/framework upgrades

route/global configuration

critical invariants

changes with unknown impact
```

The system should determine this from intent + Effects + changed implementation, not raw diff size.

## 14. We can eventually compile invariants into the same gate

Example business invariant:

```
People from one organization must never access another organization's records.
```

That becomes executable verification.

Then any Effect/implementation change touching tenancy causes those checks to be selected automatically.

This produces:

```
PRODUCT RULE
    ↓
executable constraint
    ↓
change impact
    ↓
verification gate
```

That is significantly stronger than:

```
rule stored in CLAUDE.md.
```

## 15. Tests become a semantic bridge

The architecture becomes:

```
            PRODUCT MODEL

              Behavior
                 │
                 │ owns/proves
                 ▼
                Test
                 │
                 │ Pest TIA
                 ▼
         IMPLEMENTATION REALITY
                 │
                 │ reverse impact
                 ▼
                Test
                 │
                 ▼
          Other Behavior
```

This may let us build much of the Behavior-to-implementation relationship from actual evidence instead of maintaining a giant manually-authored graph.

## 16. Do not couple directly to Pest's private storage format

Pest's internal TIA cache should not become a hard dependency unless it exposes a supported API.

Initial options:

1. use affected-test output
2. write a Pest integration/plugin
3. capture dependency events through supported extension points
4. eventually request/contribute an official graph export
5. reproduce only the necessary instrumentation ourselves if required

The architecture should depend on the concept of observed test dependencies, not on an undocumented cache implementation.

## 17. This strengthens Laravel as our initial substrate

Laravel/Pest give us mature deterministic machinery for:

```
routes
authorization
validation
persistence
migrations
events
queues
frontend integration
tests
TIA
```

This means we can potentially use existing framework tooling to build product-level understanding.

We are not merely using Laravel because agents can generate Laravel well.

We are exploiting its determinism to make:

```
context selection
impact analysis
constraint enforcement
verification
```

more deterministic.

That directly supports the broader pitch:

```
complexity cutting
observability
evolution.
```

## 18. Prototype this before designing a giant Effect graph

The immediate experiment should be tiny.

Use the current application.

Associate several Pest tests with Behavior IDs.

Then for real changes:

```
Behavior ID
    ↓
Pest tests
    ↓
TIA affected tests
    ↓
mapped Behavior IDs
```

Observe whether the neighboring Behaviors it surfaces are genuinely useful.

Track:

- useful Effects found
- noisy Effects
- missed Effects
- tests absent for important Behaviors
- whether it improves context selection
- whether it catches regressions we otherwise would have missed

If this works on real application changes, it may become one of the strongest deterministic primitives in the entire engine.

## Final principle

Use TIA aggressively for speed and evidence.

Do not confuse speed with final assurance.

The intended verification model is:

```
small/local/low-risk change
    → targeted deterministic verification

substantial or risky change
    → targeted verification + full suite

integration/deployment boundary
    → full suite always
```

Most importantly:

> "Small" is a semantic/risk property, not a line-count property.

This lets us keep commits small and iteration fast while still maintaining a hard verification boundary around accepted application evolution.
