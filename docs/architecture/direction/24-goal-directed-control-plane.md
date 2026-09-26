# Direction 24: A lightweight goal-directed control plane

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We should connect the platform to goal-directed agent research, but only take the sane parts.

Do not turn Laravel into PDDL, theorem proving, or a giant symbolic world model.

The practical principle is:

> Use Laravel itself as much of the formal system as possible.

Laravel already gives us structured, machine-readable concepts:

- routes
- middleware
- Policies/Gates
- FormRequests/validation
- Eloquent models/relationships
- migrations/schema
- events/listeners
- jobs/queues
- commands
- container bindings
- configuration
- tests

These should remain the source of implementation truth wherever possible.

Our semantic/product layer should focus on the things Laravel cannot know:

- what the user is trying to achieve
- why something exists
- what is assumed
- what the user explicitly decided
- what must remain true at the product level
- which Behaviors are meaningful to the owner
- what changed semantically

## 1. Use the closed-loop goal-agent idea, not the formal-language machinery

For every substantial change, the engine should conceptually know:

### Goal

What outcome does the user want?

Example:

```
Managers can refund orders up to $500.
```

### Current Behavior

What does the product currently do?

```
Only owners can issue refunds.
```

### Assumptions

What are we treating as true without confirmation?

```
“Small refund” means $500.
```

### Invariants / Preserve

What must remain true?

```
Members cannot refund.
Owners remain unrestricted.
Customer notifications still send.
```

### Effects

What other Behaviors may warrant inspection?

```
Billing
Audit history
Notifications
```

### Action

Let the coding agent make the bounded implementation change.

### Observation

Use deterministic evidence:

```
Laravel introspection
Boost
Pest
TIA
static analysis
build results
```

### Verification

Did the desired Behavior become true while preservation constraints still hold?

### Record

Persist the accepted product transition in a Change Record.

That is enough.

We do not need a general-purpose symbolic planner.

## 2. Core execution loop

Keep the loop simple:

```
USER REQUEST
    ↓
Identify Goal / Behavior
    ↓
Check existing assumptions first
    ↓
Resolve from deterministic evidence where possible
    ↓
Ask one high-value clarification if materially necessary
    ↓
Load relevant decisions / invariants
    ↓
Expand using Effects / TIA / static evidence
    ↓
Compile internal Change Brief
    ↓
Coding agent edits
    ↓
Deterministic verification
    ↓
Pass?
    ├── no → repair / replan
    └── yes
          ↓
     Behavior Diff
          ↓
     Change Record
```

This gives us the useful part of goal-directed agents:

> repeatedly compare current state with desired state.

Not:

> generate a giant plan and hope it stays valid.

## 3. Use AI for ambiguity, deterministic systems for resolved facts

Important architectural rule:

> Use AI to resolve ambiguity. Use deterministic systems to enforce resolved decisions.

Examples:

### AI useful

```
What does the owner mean by “contractor”?

Which product interpretation best fits the business goal?

Is this an important ambiguity worth asking about?

How should a novel feature be modeled?
```

### Deterministic systems useful

```
Which routes exist?

What Policy protects this action?

Which tests exercise this implementation?

Which Behaviors are affected according to TIA?

Does the full suite pass?

Did a forbidden dependency appear?

What files actually changed?
```

Once something is knowable from software, do not keep asking a model to infer it.

## 4. Do not formalize what Laravel already formalizes

This should be an explicit rule.

### Authorization

Do not build a duplicate authorization model.

Product layer:

```
Managers cannot refund over $500.
```

Implementation truth:

```
relevant Laravel Policy / Gate.
```

Verification:

```
Pest actor matrix.
```

### Validation

Do not invent a validation graph.

Use actual FormRequests / validation rules.

### Persistence

Do not maintain a second schema model unless needed for presentation.

Use migrations, schema introspection, and Eloquent relationships.

### Async behavior

Use actual Events, Listeners, Jobs, queues, and configuration.

### Dependency / impact information

Use:

```
Pest TIA
static imports
Vite module graph
event/listener structure
container bindings
runtime evidence later
```

rather than asking an LLM to build an imaginary dependency graph.

Principle:

> Real software structure should outrank our duplicated interpretation of it.

## 5. Behavior should remain lightweight

Do not create a massive Behavior ontology.

V1 Behavior can be something close to:

```
ID:
booking.cancel

Description:
Customer cancels an upcoming booking.

Actors:
Customer

Important rules:
Cannot cancel within 24 hours.

Tests:
CancelBookingTest

Effects:
Availability
Cancellation fees
Notifications
```

Possibly even less.

The main value is connecting:

```
human meaning
    ↕
tests
    ↕
implementation
```

not achieving formal completeness.

## 6. Assumptions should remain lightweight

No probabilistic belief system.

An assumption only needs enough state to support product behavior.

Example:

```
Statement:
Customers belong to one location.

Status:
unconfirmed

Source:
inferred

Material:
yes
```

Potential statuses:

```
unconfirmed
confirmed
rejected
superseded
```

The engine should inspect assumptions before inventing new questions.

If a relevant high-consequence assumption exists:

```
surface the highest-value question first.
```

Grandma UX:

```
One thing I want to confirm...

Can customers use more than one location?

[Yes] [No]

Ask me more questions
```

No Assumption Registry UI.

## 7. Invariants should start as plain business language

Example:

```
People from one clinic must never see another clinic's patients.
```

Do not require a formal specification language.

Associate the invariant with:

- relevant Behaviors
- relevant tests
- relevant implementation areas

Where possible, compile it into executable verification.

Example:

```
Owner A → Clinic A → allow
Owner A → Clinic B → deny
Manager A → Clinic B → deny
unauthenticated → deny
```

Where it cannot be made fully executable:

```
keep it as high-priority context and verification guidance.
```

The goal is progressive formalization, not mandatory formal specification.

## 8. Tests are the semantic bridge

A major architectural opportunity is:

```
PRODUCT
   Behavior
      │
      ▼
     Tests
      │
      ▼
Pest TIA / execution evidence
      │
      ▼
IMPLEMENTATION
```

And reverse impact:

```
changed implementation
      ↓
affected tests
      ↓
affected Behaviors
```

This gives us a deterministic basis for:

- Effects
- context expansion
- verification selection

without constructing a giant graph ourselves.

## 9. Effects should be evidence-based and advisory

Effect means:

> A change here may warrant checking another Behavior.

Possible evidence:

### Observed

TIA/test execution.

### Static

Laravel structure, imports, events, models, Vite graph.

### Historical

Past accepted changes repeatedly touched both Behaviors.

### Semantic

Owner/expert/model believes the Behaviors interact.

Effects do not need to be exhaustive or perfectly deterministic.

The provenance matters more than fake confidence percentages.

Use the hierarchy:

```
observed
static
historical
semantic
```

and preserve uncertainty.

## 10. Unknown should broaden verification

Copy the philosophy used by good TIA systems:

> Unknown impact should not produce false confidence.

Conceptually:

```
strong observed/local impact
    → targeted verification

partial evidence
    → broader verification

high-risk or structural change
    → full suite

unknown/global change
    → broaden aggressively
```

This is especially important for:

- auth
- permissions
- tenant isolation
- billing
- migrations
- framework upgrades
- global middleware/configuration
- critical invariants

## 11. Distinguish iteration commits from accepted changes

Agents benefit from small checkpoint commits.

Do not require the full suite before every internal commit.

Model:

```
edit
    ↓
targeted/TIA verification
    ↓
checkpoint commit
    ↓
continued work
    ↓
Change Record ready
    ↓
broader/full verification
    ↓
accepted into canonical state
```

Therefore:

```
checkpoint commit
    ≠
accepted verified product change.
```

Full verification should occur at meaningful integration boundaries.

“Small” must mean low-risk/local, not few lines changed.

A one-line Policy or tenant change can be high-risk.

## 12. Change Record becomes the execution trace

Each meaningful accepted change should preserve:

```
Requested goal

Relevant assumptions

Decisions used

Before

After

Preserved behavior

Effects considered

Verification evidence

Implementation commit
```

This creates an explainable trace from:

```
owner intent
    ↓
implementation
    ↓
verified result
```

That supports later questions such as:

```
Why is this here?

Why does billing behave this way?

What changed last month?

Which assumption caused this architecture?
```

## 13. KISS should govern the entire semantic layer

Avoid building:

- PDDL
- custom theorem prover
- giant dependency ontology
- duplicated Laravel schema model
- formal Behavior DSL
- elaborate assumption confidence engine

The engine should remain a thin semantic/control layer above real software.

Use:

```
Laravel facts
    >
duplicated representation

executable tests
    >
prose specification

observed TIA relationships
    >
guessed dependency edges

real Git changes
    >
imagined implementation state

deterministic evidence
    >
model confidence
```

The semantic layer exists to bridge:

> human intent ↔ software reality.

Nothing more.

## 14. Practical V1 state model

A sane V1 may only need conceptual primitives like:

### Project

```
goal
users
business context
```

### Behavior

```
description
actors
key rules
tests
```

### Knowledge item

Type:

```
decision
assumption
invariant
guidance
```

Plus small metadata.

### Effect

```
source Behavior
target Behavior
evidence/provenance
```

### Change Record

```
goal
before
after
preserve
verification
Git reference
```

Do not over-normalize the database until real usage demands it.

## 15. Broader agent-research takeaway

We should borrow the goal-agent principles:

- explicit goals
- explicit current state
- distinguish assumptions from facts
- maintenance constraints/invariants
- observe after action
- compare result with goal
- replan on failure
- preserve an execution trace

But Laravel should remain the practical state representation and deterministic substrate.

The model does not need a formal planning language.

It needs enough structured product meaning to know:

```
what outcome matters
```

and enough deterministic tooling to know:

```
what is actually true.
```

## Final architecture principle

The engine should be:

> a lightweight goal-directed control plane over a real Laravel application.

Not:

> a formal symbolic model of the Laravel application.

The core strategy is:

> Resolve ambiguity once.
> Use Laravel and deterministic tooling to enforce and verify as much of the resolved meaning as possible.
> Ask humans only where real judgment remains.

That is the sane version of goal-directed agent research that fits this product.
