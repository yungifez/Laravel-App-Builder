# Direction 16: A coherent V1

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We have iterated on the product enough that I want to freeze a coherent V1 direction.

The product is a human-driven AI software-development platform built around Laravel.

The goal is NOT:

```
Lovable, but for Laravel.
```

Laravel is the implementation substrate and structural advantage.

The product thesis is:

**Build real software with AI without losing control of how it works.**

The deeper internal principle is:

**Convention over generation.**

And increasingly:

**Decision before generation.**

**Escalate intelligence only when needed.**

The product should exploit deterministic framework knowledge, known conventions, reusable capabilities, targeted context, and deterministic transformations before spending frontier-model intelligence.

# 1. V1 definition

V1 should be the first version where the platform can:

- understand what the user is building
- build a real Laravel application
- continue modifying it without treating every prompt as a blank slate
- maintain selective product context
- understand important behaviors
- identify likely related effects
- compile a targeted change brief
- choose an appropriate execution path
- verify changes
- explain behavior changes to the user
- support visual deterministic editing
- deploy through a normal production lifecycle
- periodically review the application for health and failure modes

V1 does NOT need to support every future idea.

The point is to prove the core engine.

# 2. Blessed stack

Initial implementation stack:

- Laravel 13
- PHP 8.5
- Vue 3
- TypeScript
- Inertia
- Tailwind
- Pest
- PostgreSQL
- Laravel Wayfinder where useful
- Laravel Cloud as the default deployment target

Laravel should be treated as more than a code-generation target.

Its conventions provide deterministic information to the control plane.

Examples:

- routes
- middleware
- FormRequests
- validation
- policies
- gates
- Eloquent
- migrations
- events
- jobs
- queues
- notifications
- factories
- tests
- Artisan commands

The engine should exploit this structure aggressively.

# 3. Core engine

The V1 engine should revolve around:

```
user request
    ↓
intent / risk classification
    ↓
selective context retrieval
    ↓
Behavior + advisory Effects
    ↓
Change Brief
    ↓
execution
    ↓
verification
    ↓
behavior diff
    ↓
update project understanding
```

The core concepts are:

- Project Context
- Capability
- Behavior
- Surface
- Effect
- Implementation Reference
- Change Brief
- Verification Result
- Behavior Diff

Do not build a giant full-code dependency graph.

# 4. Project Context

The system should accumulate durable product understanding.

Examples:

- product purpose
- users
- terminology
- business rules
- design direction
- workflows
- constraints
- major decisions
- rejected approaches
- future direction

Context should be hierarchical:

```
Application
    ↓
Capability
    ↓
Behavior
    ↓
Current task
```

Lower scopes inherit relevant higher-level knowledge.

For V1, the storage can remain simple.

For example:

```
.builder/
    project.md
    capabilities/
        billing.md
        appointments.md
        membership.md
```

The important feature is selectivity.

The system should not send the entire accumulated project history to every model invocation.

# 5. Context Compiler

Introduce a lightweight Context Compiler.

Given a task, it should compile the smallest useful context from:

- relevant application context
- relevant capability context
- relevant behavior context
- current implementation facts
- advisory Effects
- package/capability knowledge
- current user request

Example:

```
REQUEST
Managers should be able to invite contractors.

PRODUCT CONTEXT
Organizations are called Workspaces.
Managers handle day-to-day membership operations.
Only Owners control billing.

CURRENT BEHAVIOR
Owners and Administrators can invite members.

POSSIBLE EFFECTS
Membership
Billing
Onboarding

RELEVANT IMPLEMENTATION
MembershipPolicy
InviteMember
InvitationAccepted
related tests
```

This becomes the agent briefing.

# 6. Effects

Effects are advisory relevance links.

They are NOT:

- strict contracts
- complete dependency declarations
- proof that another area must change
- mandatory orchestration instructions

Example:

```
Invite member

May also affect:

Membership
Billing
Onboarding

Reason:
Accepted members may become billable seats.
```

Effects should guide:

- context expansion
- verification
- review
- user warnings
- later audits

But agent judgment remains important.

A task such as:

```
Change the button label.
```

should not cause Billing inspection just because Billing exists as an Effect.

Possible internal Effect strengths:

- strong
- possible
- historical

No fake precision percentages.

Effects can also include:

- reason
- source
- last observed

# 7. Product Behavior Index

Persist a lightweight user-focused Behavior Index.

Purpose:

- explain what the application currently does
- support behavior diffs
- connect user intent to implementation
- help agents receive targeted context
- support review and audit

Example:

```
Capability:
Team management

Behavior:
Invite member

Actors:
Owner
Manager

Outcome:
Invitation created
Invitation email sent

Surface:
Members screen

Effects:
Membership
Billing
Onboarding

Implementation refs:
route
action
policy
test
```

Implementation references should remain references.

Do not persist every method-level relationship.

# 8. Product ontology vs implementation ontology

Keep separate layers.

Product concepts:

- Capability
- Behavior
- Actor
- Rule
- Data
- Surface
- Effect
- Integration

Implementation concepts:

- route
- controller
- FormRequest
- policy
- action/service
- model
- event
- job
- package
- Vue component
- test

The product value partly comes from mapping between these layers.

Non-technical users see product concepts.

Power users can drill into implementation.

# 9. Change Intent Classification

Before touching code, classify the request.

Initial categories:

- visual
- behavior
- permission
- data model
- integration
- debugging
- structural
- discovery
- other

Also allow orthogonal flags:

- touches permissions
- touches persisted data
- likely cross-capability
- potentially destructive
- requires external service
- complexity/risk

This classification should determine:

- context scope
- verification scope
- execution path
- whether clarification is needed

# 10. Jev / decision layer

Use a cheap typed decision model such as Jev where appropriate.

Potential V1 uses:

- intent classification
- risk classification
- context relevance
- model routing
- clarification need
- cross-capability likelihood

Do not make Jev authoritative.

Use confidence-aware fallback.

Pattern:

```
deterministic
    ↓ insufficient
cheap decision model
    ↓ uncertain
small generative model
    ↓ still hard
frontier agent
    ↓ unresolved business decision
user
```

# 11. Layered intelligence

Conceptual hierarchy:

### Level 0 — deterministic

- Laravel introspection
- AST/static analysis
- Git
- known mappings
- package contracts
- Tailwind mutation
- Pest/static tools

### Level 1 — decision models

- intent
- relevance
- risk
- routing
- yes/no gates

### Level 2 — small generative models

- summarization
- semantic refinement
- memory consolidation
- ambiguous classification

### Level 3 — frontier agents

- architecture
- implementation
- debugging
- unfamiliar integrations
- difficult reasoning
- deep repo exploration

### Level 4 — user/human

- product decisions
- approvals
- high-consequence ambiguity

This should help control cost without sacrificing escape hatches.

# 12. Change Brief

Before substantial execution, compile a Change Brief.

Example:

```
REQUEST
Managers should also invite contractors.

UNDERSTOOD AS
Permission + behavior change.

CURRENT BEHAVIOR
Owners and Administrators can invite members.

INTENDED CHANGE
Managers may invite contractors.

PRESERVE
Existing Owner/Admin behavior.
Invitation emails.
Current billing behavior.
Current onboarding behavior.

MAY ALSO AFFECT
Membership
Billing
Onboarding

RELEVANT IMPLEMENTATION
MembershipPolicy
InviteMember
InvitationAccepted
related tests

VERIFY
Manager can invite contractor.
Ordinary member cannot.
Existing paths still work.
No unexpected billing changes.
```

This may become one of the core artifacts of the engine.

# 13. Preserve clauses

A major purpose of the engine should be helping the agent understand what NOT to change.

This should come from:

- existing Behavior
- explicit product rules
- Effects
- current tests
- compatibility mode

The platform should reduce accidental regressions by expressing preservation expectations explicitly.

# 14. Verification

V1 verification should be behavior-aware.

Possible categories:

### Happy path

Main requested behavior works.

### Alternate path

Existing related behavior still works.

### Exception path

Unauthorized/invalid case fails correctly.

### Permissions

Relevant actors receive correct access.

### Validation

Input rules are enforced.

### Data shape

Expected persisted/output structure is correct.

### Side effects

Notifications/jobs/events/integrations still behave correctly.

### Preserve checks

Important unrelated behavior remains unchanged.

Do not run every category blindly.

Use intent/risk to determine what matters.

Use:

- Pest
- Larastan/static analysis
- Pint
- browser tests
- Laravel-aware checks
- package/dependency checks

# 15. Behavior Diff

After execution, show changes in product language.

Example:

```
Invite teammates

Before:
Owners and Administrators could invite members.

Now:
Managers can also invite contractors.

Preserved:
Existing invitation email behavior.
Billing behavior.
Onboarding behavior.

Unexpected behavior changes:
None detected.
```

Power users can inspect technical diffs beneath this.

This should be tested with users before overbuilding extraction infrastructure, but V1 should contain a real basic implementation.

# 16. Product discovery

Replace the old concept:

```
AI quizzes the user.
```

Use incremental discovery.

Only ask questions when the answer materially affects implementation.

Examples:

- ownership
- permissions
- billing
- workflow
- destructive actions
- important extensibility
- external integrations

Ask one or two high-value questions rather than running a giant onboarding questionnaire.

# 17. Pattern / Precedent Library

Users often do not know what options exist.

The platform should be able to say:

```
Common approaches include:

Simple
Customer requests a time and staff confirms.

Automatic
Customer chooses available times.

Advanced
Availability considers staff, location and buffers.

Which is closest?
```

This library should help:

- discovery
- defaults
- product education
- reducing unknown unknowns

It is not a rigid template system.

Users must always be able to choose:

```
Something different.
```

For V1, keep this manually curated and small.

# 18. Progressive disclosure

The product serves:

### Non-technical owners

They care about:

- what people can do
- what happens automatically
- who has access
- what data exists
- what integrations do
- what changed

### Power vibe coders

They may want:

- implementation refs
- routes
- policies
- models
- tests
- packages
- source
- history

Do not create hard Beginner / Expert modes.

Use progressive disclosure.

# 19. Deterministic visual editor

A significant V1 UX advantage should be interactive frontend editing that does not require a coding agent for common design changes.

The editor exposes human concepts.

Examples:

```
Border:
2 px

Width:
60 %

Corners:
Rounded

Layout:
Across

Items:
Wrap to another line

Gap:
16 px

Alignment:
Center
```

Internally:

```
border-2
w-3/5
rounded-xl
flex-row
flex-wrap
gap-4
items-center
```

Use Tailwind as the deterministic substrate.

# 20. cn-based Tailwind mutation

Use `cn` / Tailwind merge semantics as the normalization primitive.

Concept:

```
current classes
    +
generated class for desired property
    ↓
cn(...)
    ↓
normalized classes
    ↓
patch source
```

Example:

```
cn("flex flex-col gap-4", "flex-row")
```

becomes:

```
flex flex-row gap-4
```

Responsive example:

```
cn(
    "grid-cols-2 md:grid-cols-3",
    "md:grid-cols-4"
)
```

becomes:

```
grid-cols-2 md:grid-cols-4
```

Do not permanently wrap every element in cn calls.

Use it to calculate the normalized result and patch clean source.

# 21. Human-readable unit system

Users can select units such as:

- px
- %
- rem
- viewport units where useful

Example:

```
Width = 75%
```

→ prefer canonical Tailwind:

```
w-3/4
```

If the user selects:

```
73%
```

→ use:

```
w-[73%]
```

Likewise:

```
Padding = 16px
```

→ p-4

```
Padding = 15px
```

→ p-[15px]

Prefer canonical Tailwind values when possible.

Allow custom values when necessary.

# 22. Semantic visual controls

Some properties should use human labels instead of numeric CSS language.

Examples:

Corners:

- Square
- Subtle
- Medium
- Rounded
- Pill

Shadows:

- None
- Subtle
- Normal
- Strong
- Dramatic

Layout:

- Across
- Down
- Grid

Wrapping:

- Single line
- Wrap

Power users can inspect resulting Tailwind classes.

# 23. Responsive and state editing

Expose:

```
Phone
Tablet
Desktop
```

and:

```
Normal
Hover
Focus
Active
Disabled
```

Example:

```
Phone:
1 column

Tablet:
2 columns

Desktop:
4 columns
```

maps to:

```
grid-cols-1 md:grid-cols-2 lg:grid-cols-4
```

State editing similarly maps to Tailwind variants.

# 24. Visual Editability Contract

Generated frontend code should prefer:

- Tailwind utilities
- standard variants
- traceable class composition
- CSS variables/theme tokens
- minimal hidden custom CSS
- clean component/source mapping

Avoid unnecessarily:

- opaque style helper functions
- custom CSS overriding utilities
- arbitrary selectors where standard variants suffice
- excessive inline styles

The goal is not stylistic restriction.

The goal is reliable deterministic editing.

# 25. Visual fallback

If deterministic editing encounters:

- complex arbitrary selectors
- important overrides
- custom CSS
- opaque dynamic class generators
- semantically ambiguous layout

escalate to the agent.

Pattern:

```
deterministic fast path
    ↓ uncertain
semantic model fallback
```

# 26. Backend visualization

Provide a product-first visualization of important backend behavior.

Example:

```
Refund invoice

Who can do it:
Owner

What happens:

Validate request
    ↓
Process refund
    ↓
Update invoice
    ↓
Notify customer
```

Power user expands:

```
POST /invoices/{invoice}/refund
    ↓
RefundInvoiceRequest
    ↓
InvoicePolicy
    ↓
RefundInvoice
    ↓
RefundProcessed
    ↓
SendRefundNotification
```

Do not expose raw implementation pipelines as the primary interface for non-technical users.

# 27. Project Understanding UI

Provide a place where users can inspect and correct what the platform believes.

Possible sections:

- Product
- Users
- Capabilities
- Important rules
- Design
- Integrations
- Future direction
- Open questions

Power users can expand into:

- Behaviors
- Effects
- routes
- policies
- models
- tests
- packages

This gives the user control over accumulated system understanding.

# 28. Backwards Compatibility Mode

Keep this feature.

Compatibility mode means:

```
achieve the requested change
while preserving specified existing interfaces and behaviors.
```

Possible protected areas:

- API responses
- routes
- database behavior
- public component APIs
- known Behaviors
- package interfaces

Useful particularly for existing/mature projects.

# 29. Packages and capabilities

Package policy:

```
Laravel native
    ↓
Laravel first-party
    ↓
platform first-party
    ↓
approved ecosystem
    ↓
unknown → review
```

V1 does not need dynamic package trust scoring.

Start with a manual allowlist.

Approved packages can carry capability metadata such as:

```
provides
install recipe
verification
known Effects
compatibility
upgrade notes
```

The engine should prefer reuse over regeneration.

# 30. Model/provider abstraction

Do not bind the core product to one provider.

Initial adapters may include:

- Anthropic
- OpenAI

But V1 does not need a learned model router.

Use basic task-aware routing and collect telemetry.

Possible decision factors:

- task class
- complexity
- risk
- context size
- previous failure
- required tools

# 31. Planning

Replace:

```
every project begins with the strongest model writing a giant plan.
```

With:

```
planning depth is proportional to uncertainty and consequence.
```

Examples:

Button spacing:

```
no plan.
```

Permission change:

```
short Change Brief.
```

New billing system:

```
frontier planning.
```

Large architectural migration:

```
deep plan + review.
```

Store durable product decisions and relevant plans where useful.

Do not force giant planning artifacts onto every change.

# 32. Deployment lifecycle

Default production path:

```
Preview
    ↓
Verify
    ↓
Review
    ↓
Deploy to Laravel Cloud
```

Support important checks such as:

- migrations
- queues
- scheduled jobs
- environment variables
- secrets
- health
- rollback point

Git remains underneath everything.

Significant changes should have identifiable boundaries so users can meaningfully undo/revert them.

# 33. Existing Laravel repository import

Strong V1 candidate.

Flow:

```
import repository
    ↓
Laravel introspection
    ↓
inspect documentation
    ↓
infer initial capabilities/behaviors
    ↓
mark uncertainty
    ↓
ask several high-value questions
    ↓
present:
"Here is what I understand about your application."
```

This demonstrates that the engine is not dependent on having generated the application itself.

Keep imported-project support constrained initially if necessary.

# 34. Codebase Health Audit

Introduce periodic engineering review.

User chooses:

- Quick
- Thorough
- Deep

Internally, the control plane determines:

- tools
- models
- scope
- verification depth

Audit asks:

```
Is the software healthy?
```

Possible areas:

- architecture drift
- tests
- dependency health
- permissions
- data flows
- integration health
- stale Context
- stale Behavior
- stale Effects

Audits should normally:

```
inspect
identify
explain
propose
```

not automatically refactor the entire application.

# 35. Adversarial Review

Keep separate from ordinary audit.

Adversarial review asks:

```
How could this fail?
```

Challenge:

- permissions
- tenant boundaries
- invalid states
- destructive operations
- concurrent actions
- retries
- partial integration failure
- hidden coupling
- product assumptions
- Behavior claims

Use independent model reasoning where worthwhile.

The reviewer should produce concrete counterexamples rather than vague concerns.

# 36. Independent review

Important changes may receive independent review.

This can mean:

- another model
- another provider
- separate adversarial agent
- eventually a human engineer

Do not make paid human review structurally mandatory.

Use risk and user-selected assurance level.

# 37. Human escalation

Replace the old rule:

```
if a fix takes more than X minutes, escalate to a human.
```

Time is a weak proxy.

Escalate based on:

- consequence
- uncertainty
- repeated failure
- architectural blast radius
- production impact
- security/data significance

A 5-minute permissions mistake may be more important than a 3-hour CSS refactor.

# 38. Future deterministic active assurance

Not V1.

But preserve room for later Laravel-native active testing.

The future system may combine:

- route:list
- FormRequests
- Policies
- factories
- tests
- model bindings
- deterministic request generation
- authorization matrices
- isolated active probes
- adversarial model hypotheses

Unlike generic DAST tools, the platform already knows significant application structure before probing.

Do NOT build this into V1.

# 39. Framework adapter boundary

V1 is deeply Laravel-native.

Do not weaken Laravel support to prematurely support every framework.

But conceptually separate:

```
Core Engine
    ↓
Laravel Adapter
```

Core concepts:

- intent
- Context
- Behavior
- Effects
- Change Brief
- verification
- review
- model routing
- visual property model

Laravel adapter provides:

- routes
- middleware
- validation
- policies
- Eloquent
- migrations
- jobs
- events
- Pest
- factories
- Blade/Vue mapping
- Laravel-specific assurance

Future possibilities:

- Blade-first mode
- native PHP
- Symfony
- other frameworks

But those are not V1 priorities.

Laravel should remain the first deeply understood environment.

# 40. What V1 explicitly does NOT need

Avoid scope explosion.

Do not require V1 to include:

- native PHP
- Symfony
- multiple frontend frameworks
- mobile generation
- learned model routing
- large first-party package ecosystem
- automatic trust scoring
- full DAST/exploit testing
- exhaustive full-code dependency graph
- perfect automatic Effect discovery
- giant precedent library
- human engineer marketplace
- sophisticated corpus learning
- fully autonomous production operations

These can come later.

# 41. V1 product claim

V1 should be able to credibly demonstrate:

```
"This system does not simply prompt an AI against your repository.

It understands the product you are building,
selectively retrieves what matters,
understands current behavior,
knows what else may be affected,
compiles a scoped change,
chooses the right level of intelligence,
verifies the result,
and shows you what changed in terms you can understand."
```

That is the actual differentiation.

# 42. V1 end-to-end demo

A compelling V1 demonstration could be:

1. User creates or imports a Laravel application.
2. Platform establishes initial Project Understanding.
3. User visually selects an Invite Member button.
4. Platform shows:

    ```
    What this does:
    Owners and Administrators can invite members.
    An invitation email is sent.
    ```

5. User says:

    ```
    Managers should be able to invite contractors.
    ```

6. Engine classifies:

    ```
    permission + behavior
    ```

7. Context Compiler retrieves:

    ```
    membership context
    invitation Behavior
    advisory Effects
    policies
    actions
    tests
    ```

8. Change Brief is generated.
9. Coding agent implements.
10. Verification runs.
11. Behavior Index refreshes.
12. User sees:

    Before: Owners and Administrators could invite members.

    Now: Managers can also invite contractors.

    Preserved: Invitation email, billing behavior, onboarding behavior.

13. User clicks a card visually.
14. Changes width, border, radius, layout and responsive columns through human-readable controls.
15. Tailwind source updates deterministically with no coding-agent call.
16. User runs a Thorough Health Audit.
17. Later, before production, user requests an Adversarial Review.
18. Application deploys through Laravel Cloud.

If V1 can execute that experience credibly, we have demonstrated the core thesis.

# Final challenge

Now critique this V1 as a product and engineering plan.

Do NOT add more features merely because they are technically interesting.

I want you to identify:

1. Which V1 components are genuinely required to prove the differentiation?
2. Which are important but can be postponed to V1.1?
3. Which still look like architecture for architecture's sake?
4. Where are we duplicating functionality already provided by coding-agent SDKs?
5. What is the smallest implementation of the Core Engine that remains genuinely differentiated?
6. What is the likely engineering dependency order?
7. Which parts have the highest implementation risk?
8. Which parts have the highest product-validation risk?
9. What should the first three end-to-end milestones be?
10. What must be true before we can honestly call this V1 rather than a prototype?

The product should stay ambitious.

But the ambition should come from depth of the engine, not breadth of unrelated features.
