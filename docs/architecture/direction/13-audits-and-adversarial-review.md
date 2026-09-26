# Direction 13: Periodic audits and adversarial review

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

Add another major product concept:

The system should support **periodic codebase audits** and **periodic adversarial reviews** as first-class maintenance workflows.

These are distinct.

A normal audit asks:

> Is this codebase healthy, coherent, and maintainable?

An adversarial review asks:

> How can this fail, be abused, contradict itself, or surprise the user?

This is a natural extension of real software-engineering practice and should become part of the product lifecycle rather than something users need to manage outside the platform.

## 1. Users choose review depth, not model names

Do not expose model selection as the primary UX.

The user should choose the level of engineering assurance they want.

For example:

### Quick health check

Look for obvious problems, stale understanding, and recent regressions.

### Thorough audit

Review architecture, tests, dependencies, permissions, data flows, integrations, and likely problem areas.

### Deep audit

Perform a broad engineering review of the application, trace important workflows, challenge architectural drift, and reconcile accumulated product understanding with the actual repository.

Internally, the control plane maps this to:

- scope
- model strength
- deterministic tools
- verification depth
- number of review passes
- repository coverage
- expected cost

The abstraction should be:

> Choose how hard you want us to look.

Not:

> Choose Claude Opus vs GPT-X.

Power users may optionally inspect the underlying execution plan, but model choice should remain primarily an implementation detail.

## 2. Audit depth should not map naively to model size

Avoid:

```
Quick = cheap model
Deep = expensive model
```

The actual execution plan should consider:

```
user-selected depth
    +
audit focus
    +
repository size
    +
known risk
    +
recent changes
    +
application history
    ↓
audit plan
    ↓
tools + models + verification
```

Examples:

A deep dependency audit may mostly use deterministic tools.

A deep architecture audit may require frontier reasoning.

A permissions audit may use:

- route introspection
- policy inspection
- static analysis
- behavioral tests
- strong reasoning model

The user is selecting assurance level.

The engine decides how to achieve it efficiently.

## 3. Audits help solve stale-understanding problems

One of the risks in the architecture is accumulated knowledge becoming stale:

- Project Context
- Behavior Index
- implementation references
- advisory Effects
- package assumptions
- architectural assumptions

Do not require every incremental change to perfectly refresh the entire application's understanding.

Use two complementary maintenance mechanisms.

### Incremental maintenance

Feature work updates nearby understanding.

```
change
    ↓
update relevant Behavior / Context / Effects
    ↓
continue
```

### Periodic reconciliation

Audit performs broader inspection.

```
repository
    ↓
compare against accumulated understanding
    ↓
detect drift / contradictions / missing relationships
    ↓
propose updates
```

This gives us a practical answer to the staleness problem.

## 4. Audits should reconcile intent with implementation

Example:

Project Context:

```
Only Owners manage billing.
```

Actual implementation:

```
Owners and Administrators can modify payment methods.
```

Audit result:

```
Possible behavior mismatch

You previously said only Owners should manage billing,
but Administrators currently have access to payment methods.
```

Do not silently choose which is correct.

Surface the contradiction and let the user resolve it.

Similarly:

Stored Effect:

```
Invitation acceptance may affect Billing.
```

Audit discovers:

```
Membership count no longer affects billing.
```

Possible result:

```
This Effect may be stale.

Invitation acceptance no longer appears to influence Billing.
```

Suggest downgrading or removing it.

## 5. Audits may discover new Effects

Effects remain advisory relevance links, not contracts.

During broader review, the system may discover:

```
removing staff
    → may affect future appointment assignments
```

or:

```
accepting an invitation
    → may affect billable seat count
```

Propose these as new possible Effects.

Do not automatically turn them into authoritative dependencies.

The goal is:

> accumulate useful hints about where future changes may have consequences.

## 6. Audit findings should use progressive disclosure

A non-technical user should not receive:

```
Cyclomatic complexity in AppointmentController = 19.
```

Prefer:

```
Scheduling is becoming harder to change safely.

Booking rules are currently spread across several parts of the application.
Consolidating them would make future scheduling changes safer.
```

Power user expands:

```
AppointmentController
AvailabilityService
BookingService
RescheduleAppointment
relevant tests
```

Same underlying finding.

Different depth.

## 7. Audits should propose work, not silently rewrite the application

A periodic audit should normally:

```
inspect
    ↓
identify
    ↓
explain
    ↓
propose
```

Do NOT treat:

```
"Deep audit"
```

as:

```
"Automatically refactor everything you dislike."
```

Example result:

```
Deep audit complete

Important findings:

1. Billing permissions may not match your stated rules.
2. Appointment cancellation interacts with two areas not currently tracked.
3. Several scheduling tests no longer cover current behavior.

Recommended maintenance:
[Review findings]
```

The user chooses what becomes implementation work.

## 8. Audits should be delta-aware

Do not reread the repository blindly every time.

Store:

```
last audit commit
current commit
```

Then use:

```
changes since last audit
    +
known risky capabilities
    +
existing Behavior / Effects
    +
broader sampling according to selected depth
```

A quick audit may focus mainly on changed areas.

A deep audit may inspect the whole repository while prioritizing areas changed since the last review.

This can significantly reduce cost.

## 9. Periodic review should be triggered meaningfully

Avoid arbitrary nagging such as:

```
"It has been seven days. Run an audit."
```

Useful triggers include:

- large number of changes since last audit
- major new capability
- dependency/framework upgrade
- multiple agent failures or reversions
- substantial auth/permission changes
- billing/payment changes
- before production launch
- before a major release
- long period of active development without broader review

Example:

```
You've made substantial changes to Billing,
Memberships, and Permissions since the last deep review.

A thorough audit may be useful before release.
```

The user decides whether to run it.

---

# Adversarial Review

Keep adversarial review separate from normal auditing.

## 10. The distinction

### Audit

Asks:

```
Is the system healthy?
```

Looks for:

- architecture drift
- weak tests
- dependency problems
- stale context
- duplicated logic
- poor maintainability
- inconsistent patterns
- missing verification

### Adversarial review

Asks:

```
Where can this break?
```

Actively challenges:

- permissions
- assumptions
- invariants
- edge cases
- failure states
- concurrency
- destructive operations
- external integrations
- retry behavior
- product behavior
- hidden coupling
- tenant isolation
- stale Effects
- unexpected interactions

This should feel closer to:

> Try to disprove that the application is safe and correct.

## 11. Use independent reasoning where possible

Adversarial review is a particularly good use for model/provider separation.

If one model built the change, another may review it.

Conceptually:

```
builder
    ↓
verifier
    ↓
adversarial reviewer
```

Do not run three frontier agents for every tiny feature.

Use this according to review depth and application risk.

But for deep adversarial review, independent reasoning is valuable because the reviewer should challenge rather than continue the builder's assumptions.

## 12. Users still select depth/focus, not models

Possible UX:

### Quick challenge

Look for obvious edge cases and dangerous assumptions.

### Serious review

Actively test workflows, permissions, failure states, and likely cross-feature interactions.

### Adversarial

Try hard to break the application and challenge product assumptions, implementation boundaries, integrations, and data handling.

Power user can optionally focus on:

- Permissions & access
- Billing/payments
- Multi-tenancy
- Data integrity
- Workflow correctness
- Integrations
- Concurrency
- Product behavior
- Everything

Internally, the control plane chooses appropriate models and tools.

## 13. The Behavior Index gives the adversary something to challenge

The reviewer should not only inspect source code.

It should challenge the application's claims about itself.

Example:

Behavior Index says:

```
Only Owners can export customer records.
```

Adversarial review asks:

- Can an Administrator access another path?
- Is the API route protected equivalently?
- Can a background action bypass the policy?
- Does another capability indirectly expose the same data?
- Does the frontend restriction exist without backend enforcement?

The Behavior Index becomes a set of claims that can be tested.

This is substantially more useful than generic linting.

## 14. Effects become attack leads

Example:

```
Cancel appointment
```

Possible Effects:

```
Availability
Notifications
Billing
Calendar
```

Adversarial review can use these as prompts:

```
What happens if Billing fails after the cancellation succeeds?

What if the calendar provider times out?

What if the reminder job is already queued?

Can cancellation and rescheduling race?
```

Effects remain advisory.

The reviewer should also search for interactions NOT represented in Effects.

Example:

```
Recurring appointments also interact with cancellation,
but no Effect exists.
```

That becomes a proposed new Effect.

## 15. Generate counterexamples, not vague warnings

Prefer:

```
Possible failure:

A patient begins cancelling an appointment
while a receptionist reschedules the same appointment.

Both operations may succeed against the original state,
potentially leaving availability inconsistent.
```

Rather than:

```
Potential race condition in AppointmentService.
```

For non-technical user:

```
Two people changing the same booking at nearly the same time
could leave availability incorrect.
```

Power user expands into:

- affected code
- reproduction
- tests
- suggested mitigation

This keeps adversarial findings actionable and understandable.

## 16. Challenge product decisions, not only implementation

Adversarial review can also identify dangerous combinations of otherwise valid behaviors.

Example:

Project Context:

```
Managers can issue refunds.
```

Implementation:

```
Managers can also modify refund destinations.
```

The reviewer can say:

```
Managers currently control both refund approval
and where the refund is sent.

Is that intentional?
```

Do NOT automatically conclude it is wrong.

Surface the combination as a product-level concern.

This is a major differentiator from ordinary code review.

## 17. Adversarial review should examine failure paths

Normal agents naturally optimize toward the happy path.

Adversarial review should deliberately explore:

- partial failures
- retry behavior
- duplicate requests
- stale state
- concurrent actions
- integration outages
- unexpected ordering
- missing permissions
- malformed inputs
- abandoned workflows
- rollback behavior

Example:

```
Payment succeeds.
Confirmation email fails.
Job retries.
Does payment execute again?
```

This is a natural use for generated tests.

## 18. It should produce proposed verification

A useful finding can generate:

```
counterexample
    ↓
reproduction
    ↓
candidate regression test
    ↓
proposed fix
```

But the audit/review itself should not automatically rewrite everything.

Keep detection/reasoning separate from remediation approval.

## 19. Use periodic adversarial review around meaningful boundaries

Good triggers:

- before first production launch
- before major releases
- after large permission changes
- after billing/payment implementation
- after major multi-tenant changes
- after integrations are added
- after a large batch of agent-generated changes
- after repeated regressions
- after framework/package upgrades affecting core behavior

Again, use meaningful change/risk rather than arbitrary calendar schedules.

## 20. Audits and adversarial reviews can refresh the engine

These workflows are not external bolt-ons.

They feed back into the core engine.

Possible outputs:

- updated Context
- corrected Behavior entries
- new implementation refs
- new advisory Effects
- stale Effects downgraded
- missing tests identified
- capability relationships discovered
- architecture drift recorded
- new invariants proposed
- unresolved product questions surfaced

Therefore:

```
feature work
    ↓
local learning
```

while:

```
periodic audit / adversarial review
    ↓
global learning
```

The application's accumulated understanding becomes self-correcting rather than relying entirely on perfect incremental maintenance.

## 21. This also fits layered intelligence

A review can use:

### Deterministic tooling

- Composer audit
- static analysis
- route inspection
- tests
- migrations
- Git history
- dependency checks
- Laravel introspection

### Decision models

- risk prioritization
- likely problematic capabilities
- review routing
- relevance filtering

### Small models

- summarize findings
- consolidate context
- classify issues

### Frontier models

- architecture review
- adversarial reasoning
- cross-feature analysis
- complex counterexample generation

### Independent reviewer

For selected high-assurance reviews.

Again:

**users choose the assurance level; the control plane allocates intelligence.**

## 22. Potential lifecycle

The product now begins to look like a full software-engineering lifecycle:

```
UNDERSTAND
    ↓
BUILD
    ↓
VERIFY
    ↓
REVIEW CHANGE
    ↓
OPERATE
    ↓
AUDIT
    ↓
ADVERSARIAL REVIEW
    ↓
IMPROVE
    ↓
continue building
```

This is strategically important.

Most AI builders heavily optimize the first:

```
idea → application
```

Our differentiation increasingly targets:

```
application → long-lived software
```

## 23. Do not overdo maintenance UX

A non-technical owner should not feel like they suddenly need to become a CTO.

Most of the time, they should see simple prompts like:

```
Your application has changed significantly since its last deep review.

Run a thorough health check before publishing?
```

or:

```
This release changes payments and staff permissions.

A deeper challenge review may be useful before launch.
```

They choose:

```
Quick
Thorough
Deep
```

The complexity stays inside the engine.

## 24. Strategic principles

Add:

**Users choose assurance; the control plane chooses intelligence.**

And:

**Incremental work maintains understanding locally; periodic review reconciles it globally.**

And for adversarial review:

**Normal development asks how to make the feature work. Adversarial review asks how to make it fail.**

This gives the platform a maintenance model much closer to real professional software development without requiring the end user to understand software-engineering process.

## What was asked

Integrate audit and adversarial review into the architecture. Specifically address:

1. What review levels should exist in V0 vs later?
2. How should review depth map to model/tool allocation?
3. What should a health audit inspect?
4. What should an adversarial review inspect?
5. How should Behavior and advisory Effects guide review scope?
6. How do we ensure Effects remain hints rather than limiting the adversary?
7. How should audits reconcile stale Context/Behavior information?
8. What findings should automatically update knowledge, and what requires user confirmation?
9. How should independent model review work without exploding cost?
10. How should reviews be delta-aware?
11. What triggers should recommend a review?
12. How should findings be shown differently to non-technical users and power users?
13. How can generated counterexamples become regression tests?
14. How do we avoid audits becoming uncontrolled auto-refactoring?
15. What telemetry would tell us whether audits and adversarial reviews actually reduce future regressions?

Most importantly: do not build a ceremonial "AI review" button. Each review level should produce meaningfully different engineering work, and the user-facing promise should correspond to what the engine actually does.
