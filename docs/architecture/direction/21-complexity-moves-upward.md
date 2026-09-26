# Direction 21: Complexity moves upward

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We should update the product thesis again based on what is showing up in real Claude Code usage and in long-horizon agent research.

The strongest conclusion is:

> Capable coding agents do not eliminate software-management complexity. They move that complexity upward.

Developers absorb that complexity through:

- repository familiarity
- architecture knowledge
- testing discipline
- Git
- code review
- institutional memory
- understanding of what should and should not change

Non-developers do not naturally have those abstractions.

Our job is not to teach Grandma all of them.

Our job is to build an environment where:

> the complexity still exists, but Grandma does not have to carry it.

## 1. The problem is not simply “Claude needs more memory”

Long-running Claude Code users already compensate with:

- CLAUDE.md
- memory files
- handoff documents
- hooks
- decision files
- project-state files
- worktrees
- review agents
- custom governance systems

This is evidence that continuity is a real problem.

But the more important observation is:

> Knowledge and enforcement are different problems.

A user can document:

```
Managers must never access payroll.
```

Claude can read and understand that rule.

And still violate it in a future change.

Therefore we should not treat all durable knowledge as prompt context.

Some things should merely inform the agent.

Some things should increasingly become executable constraints.

## 2. Separate soft knowledge from hard requirements

There is a spectrum.

### Soft product preference

```
Keep onboarding simple.
```

Use this as context.

### Product decision

```
Customers choose their own appointment time.
```

Store this in product understanding and include it when relevant.

### Architectural guidance

```
State-changing business logic goes through Actions.
```

Feed it as implementation guidance and potentially support it with structural checks.

### Important product rule

```
Managers cannot change billing.
```

Drive targeted verification from it.

### Critical invariant

```
One clinic must never access another clinic's patient records.
```

Where feasible, compile this into deterministic assurance.

The principle is:

> Important decisions should graduate from prose into enforcement.

Grandma does not need to understand this taxonomy.

She might simply say:

```
This must always be true.
```

That is enough for the engine to treat the statement differently.

## 3. Introduce the idea of a Constraint Compiler

We already have the Context Compiler concept.

There should also be a conceptual **Constraint Compiler**.

Example:

Human language:

```
Managers cannot view payroll.

    ↓
```

Product invariant:

```
Manager → Payroll → DENY

    ↓
```

Framework knowledge:

```
routes
Policies
queries
exports

    ↓
```

Executable assurance:

```
Pest tests
policy tests
browser checks
possibly static checks
```

Another example:

```
People from one organization must never see another organization's records.
```

The system can derive an actor/data-boundary matrix:

```
Owner A → Org A → allow
Member A → Org A → allow
Owner A → Org B → deny
Member A → Org B → deny
unauthenticated → deny
```

This is much stronger than placing:

```
Be careful about tenant isolation.
```

inside CLAUDE.md.

Laravel is unusually useful here because it exposes standardized places for:

- authorization
- validation
- persistence
- routing
- model relationships
- tests
- jobs
- events

This gives us a good substrate for turning product intent into machine-checkable assurance.

## 4. Selective context matters more than Markdown vs database

Do not frame the product hypothesis as:

```
structured data beats Markdown.
```

That is too simplistic.

The real hypothesis is:

> Task-relevant product state beats indiscriminately accumulated historical context.

Research into long-horizon software agents increasingly supports active management of context rather than append-only history.

The architecture should therefore distinguish:

```
knowledge organization
```

from:

```
working context selection.
```

A possible organization hierarchy remains:

```
Application
    ↓
Capability
    ↓
Behavior
```

But the active task should not simply load the whole capability.

Instead:

```
task
    ↓
deterministic signals
    ↓
relevant Behavior
    ↓
relevant Decisions
    ↓
relevant Invariants
    ↓
relevant Effects
    ↓
relevant implementation facts
    ↓
small working packet
```

The hierarchy stores knowledge.

The Context Compiler decides what the agent actually sees.

## 5. Our current application is an unusually strong experiment

We are already building the platform with:

```
Laravel
    +
Claude Code
    +
good requirements documents
    +
Marvellous manually maintaining product and architectural continuity
```

This is a very strong baseline.

We should not compare ourselves only against:

```
repo + dumb prompt.
```

We need to beat:

> Claude Code + Laravel + excellent requirements documents + an attentive human architect/product owner.

If our engine cannot materially reduce the human-management work required compared with that setup, much of the architecture is unnecessary ceremony.

## 6. Treat every manual intervention as product research

Every time Marvellous interrupts Claude, classify the reason.

Start with only a small number of categories.

### Missing context

Claude did not know something already decided.

Potential solution:

```
better selective context.
```

### Wrong interpretation

Claude misunderstood what the product should do.

Potential solution:

```
discovery
Behavior understanding
better Change Brief
```

### Ignored constraint

Claude knew an important rule but violated it.

Potential solution:

```
Constraint Compiler
executable invariants
```

### Missed effect

Claude changed A without considering related B.

Potential solution:

```
Effects
impact preview
targeted verification
```

### Bad verification

Claude claimed completion without proving the actual user outcome.

Potential solution:

```
behavior-aware verification
```

### Architecture drift

The feature worked but moved the application in an undesirable architectural direction.

Potential solution:

```
expert guidance
architectural constraints
structural checks
```

Do not overbuild the analytics system initially.

A simple research log is enough.

## 7. Core dogfooding metric

One of the most useful metrics may simply be:

> Human interventions per accepted change.

Alongside:

- prompts
- retries
- files inspected
- regressions
- tests run
- tokens used
- time to accepted change

But human interventions are particularly interesting because the platform is intended to eliminate the need for repeated manual steering such as:

```
Remember this.

That is not what we meant.

Do not touch billing.

We already decided this.

Check the other role.

Why did this change too?

That works, but we do not want this architecture.
```

Each of these interventions is evidence of a missing product capability.

## 8. Dogfood the Change Record manually first

Do not immediately build a complicated Change Record schema.

Start by manually creating one for meaningful Claude Code tasks.

Example:

```
Requested:
Allow external developers to perform architecture reviews.

Meaning:
Experts provide judgment, not necessarily implementation.

Existing assumptions:
- Grandma remains primary UX
- developer review is optional
- bring-your-own developer is allowed

Keep true:
- experts do not require production access
- guidance remains understandable to the owner
- AI remains the default implementation path

May affect:
- permissions
- workspace isolation
- project understanding
- expert marketplace

Done when:
- reviewer access is scoped
- expert guidance persists
- owner can understand the recommendation
```

After implementation:

```
Actually changed:
...

Verified:
...

Unexpected:
...
```

Do this repeatedly.

We will discover which fields provide real leverage and which ones are architecture fantasy.

## 9. Run a direct context-selection experiment

Use the same codebase and model.

### Control

```
Claude Code
+ repository
+ existing requirement documents
```

### Experimental

```
Claude Code
+ repository
+ only relevant requirement excerpts
+ current Behavior
+ Preserve expectations
+ Effects
+ verification expectations
```

Measure:

- number of corrections
- unnecessary exploration
- token use
- first-attempt quality
- regressions
- verification quality

This directly tests whether the Context Compiler adds real value.

## 10. Change Record should be different from chat and Git

Three distinct things:

### Chat

Ephemeral interaction.

Useful for conversation, not authoritative history.

### Product Model

Durable current understanding.

What the application is supposed to mean now.

### Change Record

Durable product evolution.

What changed, why, what was preserved, and how it was verified.

Git remains implementation history.

The Change Record gives product-level historical meaning to that implementation history.

This distinction appears repeatedly in real agent workflows where users create separate:

- task state
- decisions
- handoffs
- summaries
- project status

because raw conversation history is not enough.

## 11. Let the application teach the engine

Effects should not necessarily be fully authored upfront.

The platform can observe history.

Example:

```
appointment cancellation change
    repeatedly touches
availability + notifications
```

After enough evidence:

```
Cancellation frequently affects Availability and Notifications.

Remember this relationship?
```

Likewise:

```
organization membership changes
    repeatedly require
tenant isolation checks
```

Then future changes automatically include them.

The Product Model should become not merely larger, but better calibrated to the actual application.

The loop becomes:

```
change
    ↓
observe implementation
    ↓
verify
    ↓
compare with expectations
    ↓
improve understanding
    ↓
next change receives better context
```

This is a much more interesting form of memory.

## 12. Expert guidance can also become enforceable

Human steering becomes much more valuable if parts of it can graduate into automated checks.

Expert says:

```
State-changing business logic belongs in Actions.
```

Initially:

```
architectural guidance.
```

Later, where practical:

```
structural analysis can flag unusually large controller-side state mutation.
```

Expert says:

```
All third-party billing calls must go through BillingGateway.
```

This can potentially become:

```
static search / dependency rule
flag direct Stripe usage elsewhere.
```

Expert says:

```
Every permission decision should go through a Policy.
```

The system can inspect relevant Laravel routes and implementation paths and flag suspicious bypasses.

Not every architectural principle can be deterministically verified.

But some can.

That means:

> One hour of expert judgment can leave behind not only memory, but guardrails.

This strengthens the expert-marketplace concept significantly.

## 13. Simplify durable state into four primitives

Rather than creating dozens of ontology entities, consider four major concepts.

### 1. Understanding

What is the product and how should it work?

Contains:

- goals
- users
- Behaviors
- important decisions

### 2. Constraints

What must or should remain true?

Contains:

- product invariants
- permission/data boundaries
- architectural guidance
- compatibility promises

Some remain soft.

Some compile into hard verification.

### 3. Relationships

What may interact?

This is Effects.

Keep it advisory and lightweight.

### 4. Changes

What happened and why?

Change Records containing:

- requested
- before
- after
- preserved
- verification
- implementation reference
- expert guidance involved

Underneath all of this:

```
code + runtime
```

remain the source of implementation reality.

This four-part model may provide most of the leverage without turning the system into bureaucracy.

## 14. Grandma-facing projection remains extremely simple

Grandma does not see:

```
Understanding
Constraints
Relationships
Change Records
```

She sees:

```
About your app

How things work

Things that must always be true

Things this is connected to

What changed
```

That is enough.

Power users and developers can progressively drill into the underlying structure.

## 15. We should benchmark evolution, not generation

Do not benchmark:

```
Can we generate a CRM?
```

That is becoming commodity.

Benchmark what happens after many changes.

Create an application and apply realistic sequential changes:

```
memberships

    ↓

multiple roles

    ↓

invitations

    ↓

seat billing

    ↓

contractors do not count until accepted

    ↓

suspended users retain history

    ↓

managers can invite only contractors

    ↓

enterprise approvals

    ↓

API compatibility requirement

    ↓

audit log

    ↓

users belonging to multiple organizations
```

Measure at:

```
change 1
change 5
change 10
change 20
change 35
change 50
```

Track:

- regressions
- corrective prompts
- context/token cost
- time to accepted change
- human interventions
- missed historical rules
- verification quality

The real challenge is not individual feature difficulty.

It is preserving accumulated semantics.

Internally this can be thought of as an **Evolution Benchmark**.

## 16. Our own product should be the first Evolution Benchmark

This application is already accumulating:

- Grandma-first decisions
- workspace ownership decisions
- provider-independence decisions
- product Context
- Behavior concepts
- Effects
- Change Records
- expert steering
- model-routing decisions
- Laravel architecture
- marketplace principles
- visual-editing semantics

Claude Code has to implement these ideas over time.

That is ideal.

We are effectively building the control plane while experiencing the problems created by the absence of that control plane.

Do not hide that friction.

Instrument it.

The next 30–50 real implementation tasks may reveal failure modes more valuable than further theoretical architecture work.

## 17. Refined product thesis

The thesis is becoming:

> Capable agents do not eliminate software-management complexity. They move it upward.

Developers currently carry that complexity through experience and engineering process.

Non-developers should not have to inherit those responsibilities just because AI can write code.

The platform should absorb:

- continuity
- context selection
- architectural memory
- rule enforcement
- verification discipline
- impact awareness
- change history

while allowing the owner to remain in business/product language.

The goal is not:

```
Teach Grandma how to manage Claude Code correctly.
```

The goal is:

> Build the system around Claude Code that Grandma should never have needed to invent.

That is increasingly the strongest articulation of the opportunity.
