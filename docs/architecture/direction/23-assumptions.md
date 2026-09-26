# Direction 23: Assumptions, one important question at a time

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

We should add assumptions as a first-class internal primitive, but keep the user experience extremely simple.

The key insight is:

> Good developers constantly ask themselves, "What am I assuming here?"

They then either:

- verify the assumption from code or documentation
- ask the client
- choose a safe default
- or flag it because getting it wrong would be expensive

This is normal software engineering judgment.

The product opportunity is to encode that discipline into the environment so non-developers benefit from it automatically.

## 1. Assumptions should be internal context

Do not create a giant "Assumption Manager" that Grandma has to maintain.

Internally, the system can track things such as:

```
Customers belong to one location.

Managers can invite contractors but not employees.

Billing is organization-wide.

Invitation emails keep the current wording.
```

But assumptions should normally remain invisible unless they become relevant to the current task.

The internal system can be sophisticated.

The user should only see the decision they need to make.

## 2. Assumptions should be the first thing the model questions

Before inventing a new clarification question, the engine should inspect existing assumptions.

Priority order:

```
existing assumptions relevant to the task

    ↓

assumptions contradicted by the new request

    ↓

assumptions that have become more consequential

    ↓

assumptions with increasing downstream dependency

    ↓

only then discover entirely new ambiguity
```

This makes the product feel continuous.

Instead of:

```
How should multi-location accounts work?
```

prefer:

```
Earlier I was working on the assumption that each customer belongs to one location.

This feature may change that.

Can a customer use more than one location?
```

That feels like someone who actually understands the evolving product.

## 3. Ask one high-value question by default

Do not overwhelm the user with:

```
7 unresolved assumptions
```

or:

```
14 requirements questions.
```

The system may internally have many uncertainties.

The default interface should surface only the highest-value question.

Example:

```
One thing I want to confirm

I'm assuming customers belong to one location at a time.

This matters because you're adding multi-location accounts.

Can a customer use more than one location?

[Yes] [No]

Ask me more questions
```

This should be the standard interaction pattern.

## 4. "Ask me more questions" provides progressive disclosure

Grandma can answer one important question and keep moving.

A power user can choose:

```
Ask me more questions
```

and receive additional ambiguities before implementation.

Example:

```
Other things worth confirming:

- Can staff work across multiple locations?
- Is billing shared across locations?
- Should managers see every location or only their own?
```

This avoids separate beginner/expert modes.

The same product supports both through depth on demand.

Principle:

> One question by default. Depth on demand.

## 5. Clarification priority should not simply equal model uncertainty

The important question is not:

```
How uncertain is the model?
```

It is:

```
How bad would it be if this assumption were wrong?
```

A useful conceptual scoring model is:

```
clarification priority
    =
consequence if wrong
    ×
difficulty to reverse
    ×
relevance to current task
```

with uncertainty acting as a modifier.

Examples:

### Low consequence

```
The button belongs beside the existing Invite button.
```

Proceed.

### Low-risk product assumption

```
Invitation email wording remains unchanged.
```

Proceed and record only if useful.

### Material assumption

```
Managers may invite contractors but not employees.
```

Possibly ask.

### Structural assumption

```
Customers belong to one organization only.
```

Ask before building around it.

### Dangerous assumption

```
Managers may access financial records.
```

Ask.

Do not ask users questions merely because ambiguity exists.

Ask when being wrong matters.

## 6. Use deterministic evidence before asking

Assumptions should first attempt to resolve themselves.

Example:

The model assumes:

```
Invitations use the existing notification flow.
```

Before asking the user, inspect:

```
current Behavior
code
tests
framework introspection
TIA
previous Change Records
product decisions
```

If evidence confirms it:

```
Assumption → Fact
```

No user interruption required.

The flow becomes:

```
ASSUMPTION

    ↓

Can deterministic evidence resolve it?

    ├── yes → FACT / KNOWN STATE
    │
    └── no
         ↓
   assess consequence
         ↓
   auto / record / confirm / block
```

This continues the broader architecture principle:

> Use AI to resolve ambiguity. Use deterministic systems to eliminate uncertainty wherever possible.

## 7. Assumptions have a lifecycle

Keep the lifecycle simple.

Possible states:

```
UNCONFIRMED
CONFIRMED
REJECTED
SUPERSEDED
```

A confirmed assumption should usually become a durable product decision.

Example:

```
Assumption:
Managers can invite contractors only.

    ↓ owner confirms

Decision:
Managers can invite contractors but not employees.
```

A rejected assumption should trigger re-planning where necessary.

Example:

```
Assumption:
Customers belong to one location.

    ↓ owner rejects

Decision:
Customers may use multiple locations.

    ↓

scheduling, permissions, reporting, and billing may need reconsideration.
```

Never silently promote an inference into truth.

## 8. Assumptions can become more important over time

A harmless MVP assumption may eventually become structural.

Example:

```
Assumption:
Each customer belongs to one location.
```

Initially it affects one small workflow.

Later:

```
Scheduling
Billing
Reports
Notifications
Staff assignment
```

all depend on it.

The system can then surface:

```
Quick question before this gets harder to change:

Can a customer ever use more than one location?

[Yes] [No]
```

This creates the concept of **assumption debt**.

Assumption debt is not simply having unconfirmed assumptions.

It is:

> important unverified beliefs that more of the product is beginning to depend on.

The platform should notice when assumption debt becomes dangerous.

## 9. Assumptions should be revisited when the product evolves

Example:

The application originally assumed:

```
one location.
```

The owner later asks:

```
Add a second location.
```

The system should notice the contradiction:

```
Something we assumed earlier may no longer be true.

When you started, your app assumed your business had one location.

You're now adding another.

Should customers be able to use more than one location?
```

This is much better than blindly extending an obsolete architecture.

Possible triggers for revisiting assumptions:

- new Behavior contradicts one
- new evidence contradicts one
- many parts of the product now depend on one
- expert review flags one
- the original context has materially changed

## 10. Assumptions belong in Change Records

A meaningful Change Record should preserve material assumptions used during implementation.

Example:

```
Requested:
Managers can invite contractors.

Assumptions used:

Contractors use the existing membership system.
VERIFIED FROM CODE.

Contractors do not consume a billing seat until acceptance.
CONFIRMED BY OWNER.

Managers cannot invite regular employees.
CONFIRMED BY OWNER.

Changed:
...

Preserved:
...

Verified:
...
```

This gives future decisions traceability.

Months later:

```
Why don't pending contractors count toward billing?
```

The platform can answer:

```
That rule was explicitly confirmed when contractor invitations were added.
```

Much better than:

```
The AI appears to have implemented it that way.
```

## 11. Expert review should explicitly attack assumptions

One of the highest-leverage things a senior developer can do is ask:

> Which assumptions are we building the architecture on?

An expert review packet could include:

```
Unconfirmed assumptions

- Franchises are financially independent.
- Customers can belong to multiple locations.
- Employees cannot move between franchises.
```

A senior developer might immediately recognize:

```
These assumptions determine the tenancy model.
```

That is valuable human judgment before implementation becomes expensive.

Expert reviews therefore should not only inspect:

```
code
```

They should inspect:

```
product assumptions
architecture assumptions
unresolved high-consequence decisions
```

## 12. The same question UX can be reused across the entire lifecycle

This is important.

We already use a similar interaction when discovering the business idea.

Example:

```
I run a cleaning company and scheduling is a mess.
```

The platform identifies the highest-value uncertainty:

```
One thing I want to understand:

Do customers normally choose their cleaner,
or do you assign whoever is available?

[Customers choose]
[We assign]

Ask me more questions
```

That same interaction should continue throughout the application's life.

### During initial discovery

```
What are you trying to build?

    ↓

highest-value business ambiguity
```

### During implementation

```
One thing I want to confirm...
```

### During evolution

```
Something we assumed earlier may have changed...
```

### During expert review

```
These are the assumptions most likely to affect this decision...
```

This means we do not need separate heavy workflows for:

- requirements gathering
- assumption clarification
- product discovery
- architecture discovery
- evolution questions

They are all versions of:

> What is the most important thing I do not know yet?

## 13. This is exactly what good developers already do

A good developer hears:

```
We need multi-location support.
```

and immediately thinks:

```
Are customers global or location-specific?

Can staff work across locations?

Is billing shared?

Are permissions location-scoped?

Can data move between locations?
```

But a good engineer usually does not dump all five questions on the client at once.

They identify which answer determines the rest of the architecture and ask that first.

That is the behavior we should encode.

The engine becomes:

```
notice ambiguity
    ↓
identify assumption
    ↓
resolve from evidence if possible
    ↓
assess consequence + reversibility
    ↓
ask one high-value question if needed
    ↓
remember the answer
    ↓
continue building
```

This is not an artificial AI workflow.

It is normal engineering judgment made explicit and scalable.

## 14. Epistemic state should become part of context

Not every statement in Project Context should be treated equally.

Internally, statements may have types such as:

```
FACT
We observed or verified this.

DECISION
The owner explicitly chose this.

ASSUMPTION
We are temporarily treating this as true.

GUIDANCE
An expert recommends this.

INVARIANT
This must remain true.

EFFECT
This may interact with another area.
```

This prevents **epistemic drift**:

> inferred things gradually becoming treated as established truth.

That is a major long-running-agent failure mode.

The Context Compiler should not only decide:

```
What context is relevant?
```

It should also preserve:

```
What kind of knowledge is this?
```

## 15. KISS remains mandatory

Do not expose an epistemology dashboard to Grandma.

No:

```
Assumption Registry
Confidence Matrix
Epistemic State Editor
```

Default UX remains:

```
One thing I want to confirm...
```

or:

```
Something we assumed earlier may have changed...
```

The complexity belongs underneath.

The user should feel:

> I'll keep track of the uncertain stuff. I'll only bother you when one of those uncertainties actually matters.

That is exactly the product philosophy.

## Final principle

The platform should behave like a thoughtful software engineer.

A good engineer does not know everything.

They:

- notice what they are assuming
- investigate what can be verified
- ask the client only when judgment is genuinely needed
- remember the answer
- revisit assumptions when circumstances change

The platform should do the same.

But unlike a human engineer with scattered notes, it can maintain this discipline continuously across the entire lifetime of the application.

This gives us another major piece of the control plane:

> Context should include not only what the system believes, but how certain it is about why that belief exists.

And the user-facing rule remains beautifully simple:

> One important question at a time.
