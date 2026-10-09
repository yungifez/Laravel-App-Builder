# Direction 20: Software stewardship

> Source: direction from the project owner, recorded as given (formatting repaired
> only: the diagram in §19 was split across a code fence).

We should push the product thesis further.

The system is increasingly not just an AI application builder.

It is becoming a **software stewardship platform**:

```
Build it
    ↓
Understand it
    ↓
Operate it
    ↓
Change it
    ↓
Bring in expert judgment when needed
    ↓
Preserve that judgment
    ↓
Keep evolving safely
```

This is the layer that may let the product move beyond the “great for MVPs” ceiling that many AI builders face.

The goal is not just to create software.

The goal is to keep increasingly sophisticated software understandable and evolvable over years.

## 1. The real problem starts after the MVP

Most AI builders are strongest at:

```
blank page
    ↓
prompt
    ↓
generated app
    ↓
a few iterations
    ↓
launch
```

The difficult part begins later:

```
month 1
several features

    ↓

month 6
permissions
integrations
billing exceptions
old workflows
strange edge cases
historical decisions

    ↓

month 18

"Can we change this without breaking something?"
```

Traditional vibe coding tends to accumulate entropy.

The platform should aim for the opposite:

```
software complexity increases
    ↓
product understanding also increases
    ↓
verification knowledge increases
    ↓
future changes remain scoped
```

The ideal outcome is:

> The application becomes easier for the platform to understand as verified history accumulates.

Not harder.

## 2. The human layer is not a fallback

Do not position expert involvement as:

```
AI failed.
Hire a developer.
```

Instead:

> AI handles recurring implementation work.
> Humans provide concentrated judgment where human judgment has unusually high leverage.

This is especially important once the product becomes serious.

A good developer might spend one hour setting direction such as:

```
Keep billing isolated behind one service boundary.

External providers should go through adapters.

Organizations own customers, not individual users.

All permission rules remain policy-driven.

Avoid introducing enterprise-specific workflows until there is real demand.
```

These decisions should persist long after the developer leaves.

The leverage becomes:

```
1 hour expert judgment
        ↓
durable architectural guidance
        ↓
dozens of future AI changes inherit it
```

This turns developer time into persistent influence rather than temporary implementation labor.

## 3. Human judgment should become part of the application’s memory

The system should preserve several kinds of durable knowledge:

### Owner judgment

```
business goals
workflows
priorities
exceptions
important product rules
```

### Expert judgment

```
architectural direction
risk guidance
implementation constraints
simplification decisions
specialist advice
```

### Implementation reality

```
code
routes
policies
models
tests
runtime behavior
```

All three can disagree.

The platform should continuously reconcile them.

Conceptually:

```
OWNER INTENT
"People from one clinic must never see another clinic's patients."

        ↕

EXPERT GUIDANCE
"Tenant isolation must be enforced below the controller layer."

        ↕

IMPLEMENTATION REALITY
"This is what the policies, queries, routes, and tests currently allow."
```

This creates a powerful audit capability:

```
You said:
Managers cannot access payroll.

The application currently allows:
Managers to access the payroll export endpoint.

These appear inconsistent.
```

This is not merely code analysis.

It is product governance made understandable to a nontechnical owner.

## 4. Introduce a Change Record

Several existing concepts may actually belong under one central primitive.

Every meaningful application change should create a durable **Change Record**.

Example:

```
CHANGE

User wanted:
Managers can refund orders under $500.

Understood as:
Permission + refund Behavior change.

Before:
Only owners could issue refunds.

After:
Managers can refund ≤ $500.
Owners remain unrestricted.

Kept the same:
Customer notifications.
Refund provider.
Audit logging.

May affect:
Billing.
Permissions.

Expert guidance:
All refunds must continue through RefundService.

Implementation:
commit abc123

Verified:
✓ Manager $200 allowed
✓ Manager $700 denied
✓ Owner $700 allowed
✓ Member denied
✓ Notification still sent
```

The Change Record is not a chat transcript.

It is not merely a Git commit.

It is:

> a product-level change with evidence.

This may simplify several other concepts.

## 5. Change Records can power many future features

### Why is this here?

Trace current Behavior or UI back to the decision/change that introduced it.

### What changed while I was away?

Summarize Change Records in business language.

### Decision history

Show why rules changed over time.

### Behavior history

Track before/after states.

### Audit

Compare current implementation against intended changes.

### Effects learning

Observe which areas repeatedly changed together.

### Expert reviews

Let a developer inspect the exact product and technical consequence of a change.

### Semantic undo

Eventually reason about reversing a product change rather than merely reverting a commit.

This suggests Change Records could become a core historical primitive.

## 6. Expert guidance must age

Human advice can become stale too.

Do not treat expert guidance as permanent truth.

Internally it may carry:

```
Guidance:
External payments go through BillingGateway.

Reason:
Payment provider may change.

Scope:
Billing

Added by:
Expert review

Observed against:
commit abc123

Status:
active

Last reviewed:
...
```

Potential statuses:

```
active
needs review
superseded
```

Grandma does not see this schema.

She sees:

```
Engineering guidance

Keep payment providers replaceable.
```

And later:

```
This guidance was written before marketplace billing was added.
It may need another review.
```

This prevents expert guidance from becoming another form of technical debt.

## 7. Human reviews should themselves have freshness

Do not show a permanent:

```
Expert reviewed ✓
```

Instead reason about changes since the review.

Example:

```
Billing was professionally reviewed 8 months ago.

6 significant billing changes have happened since then.

The review may no longer represent the current system.
```

This creates legitimate recurring expert work based on real product change rather than manufactured consulting demand.

## 8. This can create a new kind of developer market

Traditional consulting has a minimum viable engagement size because onboarding is expensive.

A senior developer cannot usually be useful in 30 minutes because the first 30 minutes are spent discovering:

```
what the product does
why it works this way
what the architecture is
what the owner is actually trying to change
```

Our system can prepare the context before the expert arrives.

Example review packet:

```
Business:
Residential cleaning company

Goal:
Reduce scheduling administration

Current scale:
4 locations
32 staff

Important rules:
- customers belong to the company
- staff can work at multiple locations
- repeat customers usually keep the same cleaner
- late cancellations may incur a fee

Current change:
Add franchise support

May affect:
Billing
Permissions
Customer ownership
Scheduling

Relevant architecture:
[expand]

Questions:
1. Should franchise boundaries map to organizations or locations?
2. Which information should remain shared?
3. What must be locked down before migration?
```

Now one hour of senior engineering time can be spent mostly on judgment.

This creates the possibility of:

> micro-consulting for software architecture.

Instead of a $20,000 engagement:

```
"I want someone experienced with multi-tenant SaaS
to spend one hour checking whether this direction
will survive our franchise plans."
```

That becomes accessible to small businesses.

## 9. Selective context applies to humans too

This is an important symmetry.

Do not make the AI understand the entire application when it does not need to.

Do not make the human understand the entire application when they do not need to either.

A payments specialist gets:

```
Billing
related Effects
payment rules
relevant code
verification history
```

An accessibility specialist gets:

```
relevant surfaces
components
product requirements
accessibility-related decisions
```

A database expert gets:

```
schema
scale assumptions
persistence behaviors
migrations
performance evidence
```

Selective context reduces search space for both AI and humans.

## 10. The marketplace should be about judgment, not labor

Do not build:

```
Upwork inside the platform.
```

The primary marketplace action should not be:

```
Build this feature for me.
```

It should be closer to:

> Lend your judgment to my software.

Possible engagements:

```
architecture review
billing review
security review
data model review
scalability review
accessibility review
simplify this product
launch readiness
migration strategy
permissions audit
```

Implementation can still happen when needed, but judgment is the differentiating economic unit.

## 11. Developer expertise tiers can coexist

Possible categories:

```
General developer review

Senior architecture review

Security specialist

Database / performance specialist

Accessibility specialist

Payments specialist

Domain-specific reviewer
```

Not every product decision requires a senior architect.

The platform reduces onboarding overhead enough that different kinds of experts can be useful in short, focused engagements.

## 12. The marketplace creates incentive risks

We should design against bad incentives early.

If the platform takes a cut every time it recommends:

```
Hire an expert.
```

then the platform financially benefits from escalating too often.

Similarly, an expert paid hourly may benefit from making the application sound more complicated than necessary.

Therefore:

### Human escalation should be explainable

Examples:

```
major data-boundary change

high-consequence billing architecture

irreversible migration

conflicting existing guidance

important assumption cannot be verified

repeated verification failures
```

### Bring-your-own developer should always be possible

The platform should not require marketplace experts.

### Reputation should reward durable simplification

Potentially value:

```
useful guidance
reduced complexity
durable decisions
quality outcomes
```

not just:

```
hours billed.
```

## 13. Expert access can be safer than normal contractor access

Traditional workflow:

```
give contractor GitHub
give contractor staging
maybe expose secrets
hope nothing goes wrong
```

Our workspace model allows scoped expert permissions.

An architecture reviewer could receive:

```
read-only code snapshot
product model
relevant Change Records
test evidence
isolated preview
```

with:

```
no production credentials
no customer data
no deployment rights
```

Grandma sees:

```
This developer can review your app,
but cannot publish changes or access customer information.
```

If experimentation is needed:

```
isolated worktree
```

If implementation is needed:

```
separately approved write access.
```

This could become an important trust differentiator.

## 14. Owning the workspace becomes core infrastructure

The canonical state is no longer merely:

```
Git repository
```

It becomes:

```
Git repository
    +
Product Context
    +
Behaviors
    +
Decisions
    +
Invariants
    +
Effects
    +
Change Records
    +
Verification evidence
    +
Expert guidance
```

Claude, OpenAI, other models, deterministic tooling, and human experts can all operate on the application temporarily.

But we own continuity.

Provider sandboxes are disposable compute.

The platform owns the durable understanding.

## 15. This changes the definition of “production software”

Production readiness should not only mean:

```
deploys
has auth
tests pass
does not immediately fail
```

A serious business eventually needs to answer:

> Who understands this software in three years?

Traditional answer:

```
maintain an engineering team.
```

Typical vibe-builder answer:

```
hopefully the AI can rediscover the codebase.
```

Our answer should be:

> The application carries enough knowledge about itself that owners, AI, and occasional experts can continue evolving it together.

That is closer to **evolution readiness** than production readiness.

## 16. The Grandma test becomes much harder

The commodity test is:

```
Can Grandma build a booking MVP?
```

The important test is:

```
Can Grandma still own the product
when it has:

70 employees
6 locations
payments
permissions
integrations
customer history
strange business rules
years of changes
```

And can she still understand:

```
what it does
why it works that way
what a change may affect
when expert judgment is useful
```

If yes, this is much bigger than an AI app builder.

The real promise becomes:

> Grandma can own increasingly sophisticated software without eventually needing to become a software engineer just to understand it.

## 17. Anti-lock-in can become a major trust advantage

The valuable state should not exist only as opaque platform memory.

Users should eventually be able to export:

```
Product Context

Behaviors

important rules

invariants

architectural guidance

decision history

Change Records

verification expectations
```

alongside normal source code.

If they leave, they should not receive:

```
200,000 lines of code.
Good luck.
```

They receive something closer to:

> a complete software handover package.

This may actually make users more comfortable staying because ownership feels real.

Developers taking over the project also benefit.

## 18. Human services strengthen the ecosystem rather than destroy it

Do not position:

```
AI replaces developers.
```

Position:

```
AI reduces routine implementation labor.

Human expertise becomes more leveraged.
```

Developers can become:

- architects
- reviewers
- specialists
- package experts
- capability authors
- verification-pattern contributors
- domain advisors

The platform can create more software owners without eliminating the market for professional software judgment.

## 19. The product becomes a persistent software organization

Conceptually:

```
             OWNER
        product judgment
              │
              ▼
    ┌──────────────────┐
    │ PRODUCT MODEL    │
    │                  │
    │ Context          │
    │ Behaviors        │
    │ Decisions        │
    │ Invariants       │
    │ Effects          │
    └────────┬─────────┘
             │
   ┌─────────┴─────────┐
   ▼                   ▼
  AI                 EXPERT
implementation       engineering judgment
   │                   │
   └─────────┬─────────┘
             ▼
       CHANGE RECORD
             │
             ▼
         WORKSPACE
             │
             ▼
       VERIFICATION
             │
             └──────→ feeds understanding
```

Then repeat.

The owner contributes product judgment.

The AI contributes continuous implementation capacity.

Experts contribute concentrated engineering judgment.

The platform preserves all three.

That is much closer to:

> a persistent software organization in miniature

than:

> an AI coding chat.

## 20. Possible category-level thesis

The product may ultimately be less about:

```
AI app building
```

and more about:

> software stewardship.

Internally, the lifecycle becomes:

```
build
    ↓
understand
    ↓
operate
    ↓
evolve
    ↓
review
    ↓
preserve knowledge
    ↓
evolve again
```

That is the direction that can potentially break the MVP ceiling.

## Next architecture question

We should now be very careful not to turn:

```
Product Model
Change Record
Expert Guidance
```

into enormous bureaucratic schemas.

The next design problem should be:

> What is the smallest amount of durable structured knowledge that gives us most of this leverage?

KISS matters here more than almost anywhere else.

The sophistication should emerge from a few strong primitives, not dozens of entities.
