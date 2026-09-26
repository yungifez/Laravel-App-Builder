# Direction 11: Selective context, Effects and cheap validation

> Source: direction from the project owner, recorded as given (formatting repaired
> only). It responds to the pushback on version 9: a flat notes baseline, testing
> behaviour diffs by hand before building the graph, the weakness of small-run
> experiments, and owners as the real bottleneck.

I agree with most of the pushback, but I want to refine one important point before we change direction too aggressively.

## 1. The CLAUDE.md baseline is necessary, but it does not invalidate selective context

We absolutely should test against a well-maintained `CLAUDE.md` or `PROJECT.md`.

That is probably the strongest cheap baseline.

However, the value proposition of our context system is not:

> Structured information is inherently better than Markdown.

The hypothesis is:

> **Selective, task-relevant context becomes more useful than repeatedly providing the model with the entire accumulated product history as an application grows.**

A `CLAUDE.md` file works exceptionally well when the project is small.

We should expect it to.

The problem is what happens when the accumulated knowledge eventually includes:

- users
- terminology
- design principles
- permissions
- billing decisions
- scheduling rules
- integration assumptions
- rejected ideas
- product goals
- feature-specific rules
- exceptions
- long-term direction
- dozens of capabilities

For a request such as:

> Change appointment cancellation.

Most of that information is irrelevant.

Even if the model can fit the entire file into context, we are:

- paying to send irrelevant information
- increasing signal-to-noise burden
- relying on the model to identify which historical facts matter
- potentially distracting it with unrelated instructions

The real experiment should therefore compare:

### A — Minimal

Repository + current request.

### B — Flat project notes

Repository + current request + well-maintained `CLAUDE.md` / `PROJECT.md`.

### C — Selective context

Repository + current request + only relevant project/capability/behavior context.

The question is not whether C beats B on tiny projects.

It may not.

The interesting question is:

> **At what project complexity does selective context begin to materially outperform a flat notes file in cost, reliability, or regressions?**

If the answer is "very late" or "never," simplify the system.

If it appears early enough to matter, we have evidence for the Context Compiler.

## 2. The selective system does not need to begin as a complicated database

Do not interpret "selective context" as requiring the full architecture immediately.

V0 can still use Markdown.

For example:

```
.builder/
    project.md
    capabilities/
        billing.md
        appointments.md
        organizations.md
```

The control plane can select which files to provide.

This already gives us:

- scope
- selectivity
- version control
- human readability
- model portability

without requiring a sophisticated memory database.

A more structured system should emerge only when this simple representation becomes limiting.

Possible evolution:

```
Stage 1:
one PROJECT.md

Stage 2:
PROJECT.md + capability files

Stage 3:
metadata/frontmatter and behavior notes

Stage 4:
indexed retrieval

Stage 5:
richer Context Compiler
```

The architecture should allow progression rather than assuming Stage 5 is necessary on day one.

## 3. Add advisory Effects instead of rebuilding the old dependency graph

There is still a useful part of the graph idea that a flat notes file does not provide elegantly.

Call it:

**Effects**

An Effect means:

> This behavior or capability may have a meaningful relationship with another part of the product and may be worth inspecting when this behavior changes.

Effects are NOT:

- contracts
- exhaustive dependency declarations
- requirements that another area must change
- proof of causality
- strict orchestration instructions

They are soft relevance hints.

Example:

```
Behavior:
Invite member

Possible effects:
- Membership
  Reason: accepted invitations create memberships.

- Billing
  Reason: active members may count toward paid seats.

- Onboarding
  Reason: invitation acceptance starts onboarding.
```

This gives the agent/control plane useful guidance without pretending we have a complete dependency graph.

## 4. Effects should guide, not control

The coding agent should remain free to decide whether an Effect is relevant to the current task.

Example:

User:

```
Change the Invite button text.
```

Stored Effects:

```
Membership
Billing
Onboarding
```

The agent obviously should not inspect billing.

But:

User:

```
Invited users should become members immediately.
```

Now Membership and Billing may matter.

Therefore use:

```
task semantics
    +
directly relevant implementation
    +
Effects as hints
    ↓
agent judgment
```

Do NOT use:

```
Effect exists
    ↓
automatically load entire related subsystem
    ↓
automatically run everything
    ↓
block unless all Effects are handled
```

That would recreate the rigidity we are trying to avoid.

## 5. Effects can be discovered progressively

We do not need to know every Effect at application creation.

If an agent discovers while implementing something that:

```
Invitation acceptance
    → affects seat billing
```

it can propose that Effect for future tasks.

Likewise, a trusted package might provide known Effects.

Possible sources:

- agent discovery
- trusted package metadata
- deterministic analysis
- explicit product knowledge
- user-confirmed relationship

Effects can be incomplete.

That is acceptable.

The purpose is to improve relevance, not create a perfect system model.

## 6. Effects should be allowed to be uncertain

Use language such as:

```
May also affect:
Billing
Member onboarding
```

rather than:

```
Dependencies:
Billing
Member onboarding
```

For internal representation, simple levels may be sufficient:

```
strong
possible
historical
```

Do not invent precise confidence percentages.

An Effect can also have:

```
reason
source
last observed
```

If the underlying evidence changes, it can become stale or be reconsidered.

Again, this is guidance, not law.

## 7. Effects may help with verification and behavior review

Effects are useful beyond context selection.

Example:

Requested change:

```
Managers may invite contractors.
```

Direct behavior:

```
Invite member.
```

Possible Effects:

```
Membership
Billing
Onboarding
```

Verification can use these as hints when deciding what else may deserve checking.

Likewise, if behavior extraction observes that Billing changed during an invitation task, the system can surface:

```
Another area may have changed:
Billing.
```

This still does not mean every Effect requires exhaustive testing.

It prioritizes attention.

## 8. Therefore the CLAUDE.md experiment should test the actual hypothesis

The meaningful comparison is:

> Does selectively providing relevant project knowledge and advisory Effects reduce retries, unnecessary exploration, unrelated changes, or total accepted-change cost compared with a well-maintained flat project notes file?

This is much stronger than comparing:

```
structured context
vs
no context.
```

The flat-notes baseline should absolutely be included.

If flat notes perform just as well, we should use flat notes.

That would be a positive result because it makes the product simpler.

---

# Response to the other pushbacks

## 9. Small-run experiments are weak evidence

Agreed.

Three to five coding runs per configuration cannot support strong quantitative claims.

Model execution is too noisy.

We should not set arbitrary "pass marks" and pretend a 20% difference from five runs demonstrates anything.

Instead, use two complementary approaches.

### Many small paired technical scenarios

Run cheaper, shorter tasks repeatedly.

Every setup receives the same repository state and same request.

Measure:

- first-attempt success
- verification pass
- token usage
- runtime
- number of agent turns
- retries
- unrelated regressions
- accepted-change cost

Use enough repetitions to identify obvious trends, while acknowledging noise.

### A small number of real-user sessions

Use real owners primarily for qualitative questions:

- Did they understand what happened?
- Did questions annoy them?
- Did behavior explanations help?
- Did they notice unexpected changes?
- Did they feel more confident?
- Would they actually want to use this?

Five owners are not statistical proof.

They can still expose major UX failures immediately.

Do not confuse quantitative coding-agent experiments with qualitative product validation.

## 10. The simulated owner is not a substitute for users

Agreed.

An LLM pretending to be a business owner is useful for:

- cheap iteration
- generating scenarios
- finding obvious conversational problems
- automation tests

It is not evidence that humans will value the product.

It may particularly favor interfaces and explanations that resemble the prompts we gave it.

Treat simulated-user experiments as engineering tools, not market validation.

## 11. Owners are currently the real bottleneck

Agreed strongly.

We have now refined the architecture enough.

More architecture work has diminishing value without people touching something.

The fact that the architecture document has grown through repeated revisions while the product remains untested is itself evidence that we should change development mode.

Freeze the high-level architecture temporarily.

Start building the smallest interactive experience that tests the important assumptions.

## 12. Complaints about Lovable do not imply demand for our solution

Agreed.

External complaints establish:

```
the problem exists.
```

They do NOT establish:

```
users want our particular solution.
```

For example:

Users may complain that agents make unrelated changes.

They may still not care about a behavior-diff interface.

They may simply want a better model to stop making mistakes.

Likewise:

Users may complain about repeated prompting.

They may not want clarification questions.

They may prefer the agent to make assumptions.

These are product hypotheses requiring actual validation.

## 13. Test Behavior Diff before building the Product Behavior Graph

Agreed completely.

This should become one of our highest-priority cheap tests.

The expensive architectural hypothesis is:

> Users benefit from seeing changes represented in product language rather than source-code language.

We can test this with no graph extraction whatsoever.

Create handcrafted scenarios.

Example:

User requested:

```
Allow managers to invite staff.
```

Then show:

### Version A

```
Your change is complete.
```

### Version B

```
Invite staff

Before:
Only owners could invite staff.

Now:
Owners and managers can invite staff.
```

### Version C

```
Invite staff

Requested change:
Managers can now invite staff.

Also changed:
Customers can now permanently delete their accounts.
```

Plant an unrelated change deliberately.

Observe whether users:

- notice it
- understand it
- care about it
- reject the change
- ask for more details

If even a perfect handcrafted Behavior Diff fails to help them, do not spend weeks building extraction infrastructure.

## 14. Likewise, test "What this does" manually first

Before implementing automatic Behavior extraction, give users a visual prototype.

They click:

```
Invite teammate
```

and see:

```
What this does

Allows owners and managers to invite someone to the workspace.

What happens next

The person receives an invitation email.
```

Ask whether this information is useful.

If users never open it or do not care, reconsider how central observability should be.

## 15. Test subtle questioning manually too

We can test the hidden-agile hypothesis before implementing a question classifier.

Example:

User:

```
Add subscriptions.
```

Version A:

```
Agent makes an assumption.
```

Version B:

```
Agent asks:
"Should one subscription cover the whole organization,
or should pricing depend on the number of members?"
```

Observe:

- annoyance
- response rate
- perceived usefulness
- downstream rework

The main agent itself can decide when to ask during early prototypes.

We do not need a dedicated classification subsystem yet.

## 16. Test precedents manually

Likewise, do not build a precedent retrieval system yet.

For a booking scenario, manually provide:

```
Simple:
Customer requests time, staff confirms.

Automatic:
Customer chooses available times.

Advanced:
Availability considers staff/resources.
```

Then ask:

```
Which is closest?
```

See whether users find this more helpful than an open-ended question.

If they do, precedent retrieval becomes worth investing in.

## 17. V0 should be intentionally asymmetric

We do not need every strategic idea represented equally.

V0 can be:

```
opinionated Laravel project
    +
one coding agent SDK
    +
Git
    +
PROJECT.md / scoped Markdown context
    +
basic Laravel verification
    +
one visual selection path
    +
handcrafted/simple behavior review
```

Keep interfaces flexible enough that later we can add:

- multiple model providers
- automatic Behavior Index
- richer Context Compiler
- Rector orchestration
- package trust
- precedent retrieval

But do not implement them simply because we know we may want them.

## 18. Multi-model should remain an architectural affordance, not V0 complexity

We know we want the ability to use OpenAI for some tasks and Anthropic for others.

Therefore:

```
do not couple control-plane concepts to Claude.
```

But V0 can still use one provider.

Build an execution boundary clean enough that another adapter can be added later.

Do not build:

```
dynamic learned model routing
```

until we have enough task data to make routing evidence-based.

## 19. Package trust starts as an allowlist

Do not build a trust-scoring engine yet.

Start with:

```
Laravel defaults
Laravel first-party
small manually reviewed package allowlist
```

Record:

- why approved
- compatible versions
- common integration notes

If unknown-package requests become frequent, then invest in dynamic evaluation.

## 20. Rector should be introduced when we have a real repeated transformation

Do not build a large transformation infrastructure before encountering repeated migrations/refactors.

However, keep Rector in the architecture because the principle remains valuable:

```
known transformation
    → deterministic tool

unknown semantic change
    → agent
```

The first custom Rector rule should ideally come from a real repeated problem observed while building applications.

## 21. A better V0 hypothesis stack

Rather than proving the entire architecture, test these hypotheses independently.

### Hypothesis A

Accumulated context reduces repeated explanation.

Cheap implementation:

```
PROJECT.md
```

### Hypothesis B

Selective context eventually beats flat project notes.

Cheap implementation:

```
split Markdown by capability
manually/selectively load relevant files
```

### Hypothesis C

Advisory Effects improve context selection or regression detection.

Cheap implementation:

```
manually annotate a handful of Effects
```

### Hypothesis D

Users value behavior-level change explanations.

Cheap implementation:

```
handcrafted Behavior Diffs
```

### Hypothesis E

Small contextual questions reduce expensive rework without annoying users.

Cheap implementation:

```
agent asks questions manually
```

### Hypothesis F

Showing common approaches helps users who do not know what to ask for.

Cheap implementation:

```
manually curated precedent cards
```

### Hypothesis G

Visual selection reduces prompt ambiguity and makes editing easier.

Cheap implementation:

```
instrument one or two Vue components
```

Each can be validated before building generalized infrastructure.

## 22. The product architecture should grow from validated pain

The guiding development rule should now be:

```
idea
    ↓
cheapest believable prototype
    ↓
real interaction
    ↓
observe pain/value
    ↓
generalize only if necessary
```

This is consistent with the product philosophy itself.

We are trying to make users follow good agile/product-development practices invisibly.

We should build the product the same way.

## 23. The architectural thesis remains useful

This simplification does NOT mean abandoning the larger thesis.

We still believe in:

- convention over generation
- opinionated implementation
- trusted capabilities
- progressive product understanding
- behavior-level observability
- visual selection
- deterministic tooling where appropriate
- model-provider independence
- user-readable software
- gradual accumulation of product knowledge

What changes is:

> We stop assuming each idea deserves a generalized subsystem before a simple version proves value.

## 24. Immediate next move

The next milestone should not be another architecture revision.

Build the smallest end-to-end flow where a real person can:

1. describe a small application
2. answer one useful product question
3. see something generated
4. select an element or request a change
5. have the agent use existing project context
6. see the changed application
7. review a plain-language description of what changed

Use simple Markdown context.

Use manually-authored Behavior information if necessary.

Use one model/provider.

Use an ordinary Laravel application.

Then observe where the simple implementation actually fails.

Those failures should determine which sophisticated subsystem gets built next.

The key question is no longer:

> Can we design a perfect architecture?

It is:

> Which parts of this architecture earn their complexity once users and agents actually interact with the product?

We should absolutely test each hypothesis cheaply, but the minimum V0 must include a real selective-context + behavior + effects + verification engine, because that is the central technical thesis. What we defer is breadth and automation, not the core mechanism.
