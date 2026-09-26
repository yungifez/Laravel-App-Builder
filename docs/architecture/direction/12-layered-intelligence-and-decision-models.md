# Direction 12: Layered intelligence and decision models

> Source: direction from the project owner, recorded as given (formatting repaired
> only). "Jev" is TypeSafe AI's typed decision model: it returns one label from a
> defined set, a probability for each option and a calibrated confidence.

Add another architectural principle:

**Most decisions in the system should not require a frontier model.**

The platform should use the cheapest reliable decision mechanism first, then escalate only when confidence is insufficient or the task is too complex.

A particularly good fit is Jev for typed, low-cost decision routing.

Use Jev for decisions such as:

- change intent classification
- risk classification
- context relevance
- model routing
- whether clarification is needed
- whether a request likely touches permissions
- whether a request likely touches persisted data
- whether a change appears cross-capability
- whether a task is trivial / normal / substantial

Do not make Jev authoritative.

It should be a fast decision layer with confidence-aware fallbacks.

## 1. Change intent classification

Before touching code, classify the user's request.

Possible primary intents:

```
visual
behavior
permission
data_model
integration
debugging
structural
discovery
other
```

But do not assume a task is strictly single-label.

Example:

```
"Let managers edit customer addresses."
```

This may be:

```
primary intent: permission
touches behavior: yes
mutates persisted data: yes
```

A useful decision packet might look conceptually like:

```
primary_intent
touches_permissions
touches_persisted_data
likely_cross_capability
needs_clarification
complexity
```

The control plane then uses normal deterministic code to decide what context to retrieve and what execution path to use.

Example:

```
if visual + trivial:
    try deterministic visual edit

if permission:
    load actors, rules, policies, auth tests

if data_model:
    load models, migrations, data invariants

if integration:
    load integration context, jobs, retries, secrets boundaries

if behavior:
    load behavior + Effects

if discovery:
    use precedent/question system
```

The important point is:

**the Change Compiler becomes demand-driven.**

## 2. Jev should be a decision layer, not a control authority

If Jev says:

```
visual change
```

that means:

```
try the visual path first
```

not:

```
the system is forbidden from touching behavior.
```

Likewise:

```
touches_permissions = false
```

should not mean permission context can never be inspected.

Agents need escape hatches.

Treat decision-model outputs as:

```
likely path
likely context
likely risk
```

not as truth.

## 3. Use confidence-aware escalation

A general pattern should be:

```
deterministic / cheap path
        ↓
confident enough?
   yes      no
    │        │
    │        ↓
    │   stronger semantic model
    │        ↓
    │   still uncertain?
    │        ↓
    │   frontier agent / user
    ↓
  continue
```

This should apply throughout the platform.

## 4. Layered intelligence architecture

Conceptually:

### Level 0 — Deterministic

Use normal software where possible.

Examples:

- Laravel introspection
- AST analysis
- Git diff
- package contracts
- known implementation mappings
- direct Tailwind edits
- source/component mapping
- known Behavior refs
- advisory Effects

If normal code can answer the question reliably, do not use an LLM.

### Level 1 — Decision models

Use Jev or a similar cheap typed decision model.

Examples:

- intent
- risk
- relevance
- yes/no gates
- task complexity
- likely capability
- likely need for clarification
- model routing

These should return confidence information.

### Level 2 — Small generative models

Use when lightweight classification is insufficient but full agentic reasoning is unnecessary.

Examples:

- semantic classification
- summarization
- memory consolidation
- context refinement
- ambiguous domain interpretation
- producing a compact explanation

### Level 3 — Frontier agents

Use for genuinely difficult work.

Examples:

- implementation
- architecture
- difficult debugging
- repository exploration
- novel domain modeling
- complex refactoring
- unfamiliar integrations

### Level 4 — User / human

Escalate when the missing information is fundamentally a product/business decision.

Examples:

- who should be allowed to do something
- what billing model is desired
- whether a destructive workflow is acceptable
- which precedent fits the business
- production approval

The user should not be asked implementation questions the platform can safely answer itself.

## 5. "LLM fallback" should become a general architectural pattern

Do not treat fallback as an exception specific to Jev.

Many subsystems should have a cheap primary path and semantic fallback.

### Context selection

First:

```
scope
hierarchy
Effects
implementation refs
```

If ambiguous:

```
small model refines relevance
```

If still unclear:

```
coding agent explores
```

### Memory extraction

First:

```
deterministic rules / decision model
```

Question:

```
Is this a durable product decision?
```

If uncertain:

```
small model interprets context
```

Do not invoke a frontier model on every conversation message.

### Precedent matching

First:

```
taxonomy
structured lookup
embeddings
```

If the domain is unusual:

```
semantic model interprets the request
```

If still unknown:

```
targeted external research / user clarification
```

### Risk estimation

First:

```
changed files
known Effects
Laravel structure
migrations
permission changes
integrations
```

Then a cheap classifier can estimate:

```
low
medium
high
```

If high or uncertain:

```
escalate reasoning.
```

### Model routing

Use a cheap decision model to choose:

```
inexpensive coding model
normal coding model
strongest available model
```

But do not force a low-confidence routing decision.

Use a conservative fallback.

## 6. Jev and Effects work well together

Effects are advisory relevance links.

Example:

```
Behavior:
Cancel appointment

Known possible Effects:
- availability
- notifications
```

User asks:

```
"Charge 50% if they cancel within 24 hours."
```

The engine can cheaply ask:

```
Is billing probably relevant?
Is this a high-impact behavior change?
Should context expand beyond scheduling?
```

If confidence is low:

```
let the agent inspect.
```

This preserves the principle:

```
Effect → nudge
```

not:

```
Effect → contract
```

Jev helps decide whether the nudge is worth following for this specific request.

## 7. This can substantially reduce unnecessary frontier usage

Without a decision layer, we risk repeatedly invoking a large model to answer questions like:

```
What kind of request is this?
Which context should I load?
Is this probably high risk?
Which model should handle it?
Is this conversation turn worth storing?
```

These are often classification problems.

The frontier model should spend its intelligence on things that actually require generative reasoning.

This supports:

**Convention over generation.**

Add:

**Decision before generation.**

And:

**Escalate intelligence only when needed.**

## 8. Example end-to-end flow

User:

```
"Make this card wider."
```

### Level 0

Visual selector identifies source component and Tailwind classes.

Intent classifier strongly indicates:

```
visual
trivial
```

Direct transform:

```
max-w-lg → max-w-xl
```

Verification:

```
page still renders
```

No coding agent required.

---

User:

```
"Managers should also be able to refund orders."
```

### Level 1

Classification:

```
permission: strong
behavior: strong
persisted data: likely
risk: moderate
```

Context Compiler loads:

- refund behavior
- actors
- permission rules
- advisory Effects
- relevant policies
- relevant tests

Agent implements.

Verification prioritizes:

- authorization
- refund behavior
- affected accounting/payment behavior

Behavior diff shown to user.

---

User:

```
"We need billing to work like enterprise procurement."
```

Classifier is uncertain.

Small model cannot confidently determine intended workflow.

System uses precedent/research and/or asks the user a product question.

Only then does the coding agent receive an implementation task.

## 9. Every routing decision should be observable internally

Store lightweight execution telemetry:

```
decision
confidence
chosen path
fallback used
final outcome
```

Example:

```
intent: permission
confidence: high
decided_by: decision model
fallback_used: false
```

Later we can measure:

```
% resolved deterministically
% resolved by Jev
% escalated to small model
% escalated to frontier agent
```

Do the same for:

- intent
- memory classification
- context selection
- risk
- model routing

This lets us optimize with data rather than assumptions.

## 10. Measure whether the cheap decisions are actually good

Do not assume Jev improves the architecture just because it is inexpensive.

Track:

- misclassification rate
- unnecessary escalations
- missed escalations
- execution failures caused by routing
- cost savings
- latency savings
- accepted-change cost

A wrong cheap classification that causes an expensive retry is not a saving.

The important metric remains:

**cost per accepted change**

not:

**cost per classifier invocation**

## 11. Prefer graceful fallback over increasingly elaborate rules

Do not create a massive hand-built decision tree trying to cover every possible user request.

The value of this architecture is:

```
simple deterministic rules
    +
cheap probabilistic decisions
    +
generative fallback
```

The fallback means the cheap layer can remain simple.

If a request does not fit neatly:

```
escalate.
```

Do not endlessly expand the classifier taxonomy.

## 12. Keep the taxonomy small initially

For V0, something like:

```
visual
behavior
permission
data_model
integration
debugging
structural
discovery
other
```

may be enough.

Add orthogonal flags:

```
touches_permissions
touches_persisted_data
likely_cross_capability
requires_external_service
potentially_destructive
```

And one rough complexity/risk classification.

Do not create dozens of intent categories before real usage justifies them.

## 13. This should be part of the core engine in V0

The core engine should not merely be:

```
prompt → coding agent
```

V0 should demonstrate:

```
request
    ↓
intent/risk decision
    ↓
relevant context selection
    ↓
Behavior + Effects
    ↓
Change Brief
    ↓
appropriate execution path
    ↓
verification
    ↓
behavior diff
    ↓
learning
```

The decision layer can be small, but it should be real.

This is part of what differentiates the engine from a normal coding-agent wrapper.

## 14. Do not over-orchestrate the frontier agent

There is a danger here.

If the control plane makes every micro-decision before the agent sees the task, the agent becomes artificially constrained and may perform worse.

The control plane should provide:

- relevant knowledge
- likely intent
- important constraints
- suggested Effects
- verification goals

The frontier agent should still have room to reason and discover.

Think:

```
better briefing
```

not:

```
scripted puppet.
```

## 15. Strategic principle

The platform should use the least expensive form of intelligence capable of making a trustworthy decision.

Conceptually:

```
Can software know it?
    ↓ no
Can a cheap decision model decide it confidently?
    ↓ no
Can a small generative model resolve it?
    ↓ no
Use frontier reasoning.
```

This could become one of the main cost and reliability advantages of the platform.

## What was asked

Integrate this layered intelligence model into the core engine. Specifically address:

1. What decisions should Jev own in V0?
2. Which decisions should remain deterministic?
3. Which decisions should skip Jev and go directly to a generative model?
4. What confidence thresholds/fallback philosophy should we use?
5. Should intent be multi-label, primary + flags, or something else?
6. How should Jev outputs influence the Change Compiler?
7. How do we prevent cheap misclassification from causing expensive downstream errors?
8. What execution telemetry should we capture?
9. How can this architecture remain provider-independent?
10. Where could we accidentally over-orchestrate the coding agent?
11. What is the smallest real implementation of: decision → context → agent → verification that proves the idea?
12. Which current architecture concepts become simpler because an LLM fallback exists?

Most importantly, challenge whether this actually reduces accepted-change cost and latency in practice.

Do not optimize individual calls in isolation.

A cheap wrong decision followed by an expensive repair is worse than using the right model immediately.
