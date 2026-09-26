# Direction 26: The smallest complete evolution loop, non-functional requirements and a design contract

> Source: direction from the project owner, recorded as given (formatting repaired
> only). It is part of the owner's conversation "Lovable AI Models". The part
> between these two excerpts is [direction 24](24-goal-directed-control-plane.md).

**Owner:**

So is there anything there for v1

**Assistant:**

Yes, but only a thin runtime foundation. I would not put “semantic runtime observability” into V1 as a major feature.

For V1, I’d include just enough that we don’t paint ourselves into a corner later.

The V1 runtime pieces should be:

- Post-deploy smoke verification. After publishing, verify the app actually boots and a few critical journeys still work. “Tests passed locally” is not the same as “production is healthy.”
- Behavior/Journey IDs that survive into tests and optionally runtime events. If booking.cancel is already a stable identifier in our Product Model, we can later attach telemetry to it without redesigning everything.
- Change correlation. Every accepted Change Record should know which deployment/commit introduced it. Later, if errors spike, we can say “this started after the cancellation change.”
- Basic error ingestion. Not a whole Datadog competitor. Just enough to know that deployed software is throwing errors and associate them with the relevant release/change where possible.
- Critical invariant smoke checks where feasible. Especially auth, tenancy, billing, migrations, queue availability, etc. Some checks may be executed immediately after deployment.
- A tiny deployment health state: something like Published / Checks passed / Needs attention, translated into Grandma language.

So V1 could end at:

```text
CHANGE
   ↓
targeted verification
   ↓
full-suite integration gate
   ↓
deploy
   ↓
post-deploy smoke checks
   ↓
accepted Change Record
```

Rather than:

```text
deploy
↓
🤞
```

But I would not build in V1:

- business usage analytics;
- semantic runtime journey tracking;
- automatic “87% of customers aren't using this” insights;
- production-derived Effects;
- anomaly detection;
- runtime Behavior reconciliation;
- product-goal optimization from telemetry.

Those are excellent later features.

The important architectural decision for V1 is simply:

Give product concepts stable identities now so runtime evidence can attach to them later.

For example:

```text
Behavior
booking.cancel

Journey
customer.first-booking

Invariant
tenant.data-isolation

Change
chg_0194...
```

Tests use those identities.

Change Records reference them.

Later runtime events can use the same identities.

Then V2 doesn't need to invent a mapping between “things the product understands” and “things happening in production.”

So I'd amend V1 slightly:

Runtime-aware, not runtime-observability-heavy.

That keeps V1 tight while preserving the really powerful build → verify → deploy → observe → learn → evolve loop for later.

**Owner:**

I mean the entire thing yo u proposed, not just runtimes

**Assistant:**

Ah yes. A lot of what we just discovered should move into V1, because some of it is not “extra features”; it is the actual engine.

I’d now define V1 as the smallest complete software-evolution loop, not merely an app generator.

V1 should contain these core pieces

| V1 primitive                              | Why it belongs now                                                             |
| ----------------------------------------- | ------------------------------------------------------------------------------ |
| Grandma-first discovery                   | Establishes business intent without requiring software knowledge.              |
| One-question assumption loop              | Prevents hidden assumptions from compounding during long-running work.         |
| Product Understanding                     | Gives the system durable goals, users, workflows and decisions.                |
| Behaviors                                 | Gives us the semantic unit that connects product meaning to tests/code.        |
| Important invariants                      | Lets “this must always be true” become more than prose.                        |
| Advisory Effects                          | Gives us impact/context expansion without a giant dependency graph.            |
| TIA-backed observed Effects               | Gives Effects deterministic evidence instead of relying only on AI.            |
| Internal Change Brief                     | Defines what changes, what stays, and what must be verified.                   |
| Selective Context Compiler                | Stops us from dumping all requirements/history into the model.                 |
| Laravel/Boost deterministic introspection | Replaces model guessing with authoritative framework facts.                    |
| Verification gates                        | TIA during iteration, broader/full suite at integration boundaries.            |
| Change Record                             | Makes product evolution durable rather than leaving history in chat/Git alone. |
| Behavior Diff                             | Makes the result understandable to Grandma.                                    |
| Owned workspace + Git                     | We need canonical state independent of model providers.                        |
| Deterministic visual editing              | Strong complexity-cutting UX without spending model intelligence.              |
| Deploy + smoke verification               | Completes the change lifecycle.                                                |

That sounds large, but most of these should be very thin in V1.

For example, “assumptions” does not mean building an Assumption Management System.

Internally:

```text
Assumption:
Customers belong to one location.

Status:
unconfirmed

Importance:
high
```

Externally:

One thing I want to confirm
Can customers use more than one location?

[Yes] [No]

Ask me more questions

Done.

Similarly, V1 invariants don't require some grand formal specification language.

Grandma says:

People from one clinic must never see another clinic's patients.

We store that as an invariant and try to attach actual tests to it.

That's enough.

I think V1's central loop should now literally be this

```text
USER REQUEST
     ↓
What do we already know?
     ↓
Check assumptions first
     ↓
Ask ONE question if materially necessary
     ↓
Identify Behavior being changed
     ↓
Load relevant decisions/invariants
     ↓
Effects:
semantic + static + TIA evidence
     ↓
Compile internal Change Brief
     ↓
Agent implementation
     ↓
TIA targeted verification
     ↓
broader/full verification where required
     ↓
Behavior Diff
     ↓
Change Record
     ↓
Deploy
```

That is the product.

Not:

```text
prompt
→ Claude
→ files
→ preview
```

What I would explicitly add to the previous V1 plan
Assumptions

This should move into V1.

Because you're already discovering the problem while using Claude Code for the project itself.

The engine maintains assumptions as context and always checks relevant assumptions before inventing new clarification questions.

One important question by default.

More questions on demand.

That's cheap enough and fundamental enough to be V1.

Invariants / constraints

Also V1, but narrowly.

We need at least the ability to say:

Things that must always be true

and map some of them to Pest verification.

We do not need a universal Constraint Compiler in V1.

Just prove:

```text
natural-language invariant
→ Behavior/test relationship
→ relevant verification automatically selected
```

That's enough to validate the idea.

TIA-backed Effects

I would absolutely pull this into V1 now.

It gives us a concrete implementation for part of the Effects hypothesis.

Initially:

```text
Behavior ID
→ Pest group/test
→ TIA
→ affected tests
→ other Behavior IDs
```

No giant graph engine.

We just need to show that it improves:

- impact detection;
- context selection;
- verification selection.

If it does, enormous win.

Change Records

I would now call these V1 core too.

Again, tiny.

Something like:

```text
Requested
Before
After
Preserved
Assumptions/decisions used
Affected Behaviors
Verified
Git commit
```

That can power a surprising amount of product functionality later.

What is NOT V1

This is where we need discipline.

Not V1:

- full expert marketplace;
- automatic expert matching;
- sophisticated review freshness;
- runtime semantic observability;
- production-derived Effects;
- semantic undo;
- mature architecture-rule compiler;
- comprehensive static architecture enforcement;
- automatic complexity-budget scoring;
- product-level anomaly detection;
- mature audits;
- adversarial review system;
- learned model router;
- enormous precedent database;
- native PHP/Symfony support;
- fully deterministic Effect graph;
- full existing-repository reverse engineering.

Those are consequences of the core model working.

They shouldn't be prerequisites for proving it.

Human-in-the-loop: tiny V1 version

I would actually include bring your own developer/expert before building the marketplace.

Very simple:

Export/share review context

The platform produces:

```text
What this product does
What is being changed
Relevant decisions
Important invariants
Current assumptions
Possible Effects
Relevant implementation
Verification results
Questions needing judgment
```

Developer can leave guidance.

We save that guidance into project context.

That's enough to test the thesis:

Can one hour of engineering judgment meaningfully influence later AI work?

Marketplace comes later if this proves valuable.

Likewise runtime gets only a toe in V1

As we just discussed:

```text
deploy
→ smoke check
→ associate deployment with Change Record
```

That's it.

Don't build Datadog-for-Grandma yet.

I would also cut the V1 Product Model down dramatically

We were heading toward lots of conceptual entities.

I think V1 can probably survive with something close to:

```text
PROJECT
  goals
  users
  important context

BEHAVIOR
  what happens
  actors
  rules

KNOWLEDGE
  decision / assumption / invariant / guidance

EFFECT
  related Behavior
  evidence/source

CHANGE
  before / after / preserve / verify
```

Possibly even fewer physical database tables than that.

The conceptual distinction matters more than database normalization initially.

And Grandma gets only about five surfaces

This is important.

The backend may be sophisticated, but V1 should not look sophisticated.

Probably something conceptually like:

Build

What are you trying to make/change?

Your app

- What this app does
- Who uses it
- Important rules
- What I know about your business

Preview

Actual application + deterministic visual editing.

Changes

Managers can now invite contractors.
Billing stayed the same.
Checks passed.

Publish

Ready to publish.

That's roughly it.

Assumptions, Behaviors, Effects, TIA, Constraint Compiler, Boost, Git, models and agent orchestration sit underneath.

The real V1 demo should be evolution, not generation

This is the thing I'd now make non-negotiable.

Don't launch V1 and demo:

“Look! I made a CRM in five minutes.”

LaraCopilot, Lovable, Replit and everyone else can do impressive first-generation demos.

Our demo should be something like:

```text
Week 1:
Build booking SaaS.
```

Then sequentially:

Add staff roles.
Add multiple locations.
Change cancellation rules.
Add contractor staff.
Add billing.
Change seat billing rules.
Add managers.
Restrict manager refunds.
Change customer ownership.
Add franchise support.

And the magic is:

By change 10, the user is still talking about their business exactly like they were at change 1.

The system carries the increasing software complexity.

That's the V1 proof.

So, compared with our earlier V1, I'd make four meaningful additions now:

- Assumption tracking + one-question clarification
- Business-language invariants with executable verification where possible
- TIA-backed observed Effects
- Change Records as durable product history

Those discoveries are too central to postpone.

Everything else we've explored recently mostly becomes V1 architecture foresight or V1.1+ product functionality.

The V1 thesis becomes very crisp:

Can we make a real application progressively more complex without making the owner's experience progressively more complex?

If we can demonstrate that, then we've proven something materially different from an MVP builder.

**Owner:**

Non functional requirements wg ui design would be garder but we shalll discuss tomorrow
👍

**Assistant:**

Yep. That’s probably the next genuinely hard area.

Functional requirements are comparatively friendly to our deterministic approach:

“Managers cannot refund over $500.” → testable.

Non-functional requirements are fuzzier:

- “The app should feel fast.”
- “This page should be easy to understand.”
- “Keep the design consistent.”
- “Don’t make the UI feel cluttered.”
- “This should be accessible.”
- “The architecture should remain maintainable.”

And UI/design intent is even harder because some requirements are measurable, some are heuristic, and some are genuinely subjective.

The interesting problem for tomorrow is probably:

How far can we convert soft requirements into observable evidence without pretending subjective qualities are deterministic?

I suspect we'll end up with the same hierarchy:

```text
MEASURABLE
→ deterministic checks

STRUCTURALLY INFERABLE
→ heuristics/static analysis

VISUALLY OBSERVABLE
→ browser/computed-layout checks

SUBJECTIVE
→ model/human judgment

UNKNOWN
→ ask
```

So we preserve the same philosophy: extract every bit of determinism available, then use AI only for what remains ambiguous.

That’ll be a good one.

Today 9:02 AM
**Owner:**

One tging id say is. I definitely need to come up with a BEEFY design.md

**Assistant:**

Yes. And I think design.md becomes much more important in our architecture than a normal design-system document.

It would effectively be the design-side equivalent of the Product Model: the durable source of intent that stops a long-running agent from slowly turning the UI into generic AI slop.

The key is: beefy in coverage, not bloated in prose.

I’d want it to capture at least:

- Design philosophy: what the product should feel like and what it should never feel like.
- Grandma principle: simplest understandable interaction by default, progressive disclosure for depth.
- Visual language: typography, spacing, radii, borders, shadows, density, colour usage, hierarchy.
- Layout rules: page widths, grids, panels, sidebars, modals, responsive behaviour.
- Interaction principles: when to use modal vs page vs inline edit, confirmations, destructive actions, loading, empty states.
- Component conventions: buttons, forms, tables, cards, navigation, command surfaces, prompts, question UI.
- Design tokens: ideally machine-readable enough that Claude doesn't invent 17px, random radii, or five shades of gray.
- Accessibility requirements: contrast, keyboard, focus, target sizes, semantics, reduced motion, etc.
- Responsive rules: not just “mobile friendly,” but actual expectations.
- Content design: how technical concepts get translated into Grandma language.
- Motion: when animation is useful and when it becomes noise.
- States: default, hover, selected, error, warning, disabled, loading, success.
- Examples of good/bad patterns.
- Explicit anti-patterns: probably extremely important.
- References/inspiration, including what specifically we like about each reference rather than “make it like Linear.”
- How to handle ambiguity: preserve existing design language rather than inventing a new pattern.

And I'd explicitly include things like:

Do not introduce a new visual pattern when an existing product pattern already solves the problem.

Do not expose internal implementation terminology merely because the implementation uses it.

Do not increase information density unless the information materially improves the user's next decision.

Those are design invariants.

The interesting part for our eventual engine is that design.md doesn't have to remain prose forever.

We can progressively split its contents into the same hierarchy we've been discovering:

```text
DESIGN INTENT
"Calm, direct, low cognitive load"
        ↓

DESIGN DECISIONS
"One primary action per major surface"

        ↓

DESIGN INVARIANTS
"Destructive actions always require explicit confirmation"

        ↓

TOKENS
spacing / radius / typography / colors

        ↓

DETERMINISTIC CHECKS
token usage
contrast
responsive overflow
focus states
component consistency

        ↓

VISUAL / AI REVIEW
hierarchy
clutter
coherence
appropriateness
```

So design.md can initially be the human-readable canonical design contract, while we gradually discover which parts can become deterministic.

And for your current Claude Code dogfooding, this is perfect. Give Claude a serious design.md, then watch where it still drifts.

If Claude knows:

“Use existing patterns, 8px spacing scale, one dominant action, progressive disclosure, no developer terminology”

and still produces a cluttered technical UI, that tells us exactly which design requirements cannot remain prose-only.

So yes: BEEFY design.md, absolutely.

But I'd build it almost like a constitution:

principles → rules → patterns → tokens → examples → forbidden patterns.

Not a 40-page mood board that Claude has to reread on every task.
