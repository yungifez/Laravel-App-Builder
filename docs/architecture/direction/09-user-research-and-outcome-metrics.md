# Direction 09: User research and outcome metrics

> Source: direction from the project owner, recorded as given (formatting repaired
> only). Based on research into current AI app-builder complaints, including
> G2/AWS Marketplace reviews, Trustpilot, Product Hunt and review ecosystems, and
> current Lovable guidance.

Use the following findings to challenge and refine our architecture.

The important question is no longer merely "Can this architecture produce good software?" We also want to know: "Does it directly reduce the failure modes users currently experience with AI app builders?"

Several recurring complaint categories strongly overlap with the problems we are trying to solve.

## 1. Repeated explanation and reprompting is a real problem

A recurring complaint is effectively: "I already explained what I wanted." or "I have to keep telling it the same thing." or "It took many prompts to get a relatively simple behavior right."

This reinforces the importance of hierarchical Project Context. We should not treat every user prompt as an isolated instruction. The system should accumulate durable product understanding over time.

For example:

```
Application context:        This is a cleaning business.
Users:                      Owner, Office staff, Cleaner, Customer
Existing product decision:  Customers do not select individual cleaners.
Goal:                       Reduce scheduling workload for office staff.
```

Later, "Add recurring bookings." The agent should not rediscover all of this. It should ask only the genuinely new question, such as: "Should recurring bookings try to keep the same cleaner, or assign whoever is available each time?"

This is one of the primary reasons for maintaining structured Project Context.

## 2. The objective is not fewer tokens per prompt

Do not optimize around the shortest possible model prompt as the primary economic measure.

A clarification question itself costs tokens. But a small question may avoid an entire failed implementation trajectory: wrong implementation → schema change → frontend change → test failures → user correction → agent rereads code → reimplementation → further regression fixes.

Therefore a better economic metric is **cost per accepted change**, or potentially **cost per verified user outcome**.

Measure all relevant execution cost from user request → clarification → execution → retries → verification → user accepts result. This should include model input/output cost, sandbox/runtime cost, retries, verification model calls, agent escalation, and potentially expensive external tools.

A model that costs more per call may be cheaper per accepted change if it succeeds in one attempt. Likewise, asking one useful question may lower total cost substantially.

## 3. Store structured knowledge, not entire conversation history

Do not solve repeated explanation by dumping the full chat history into every agent invocation. That creates another token problem.

The desired architecture is: accumulated product knowledge → select relevant context → compile small context pack → agent.

Project Memory should contain durable knowledge such as product purpose, users, terminology, major workflows, confirmed rules, constraints, design direction, important decisions, rejected approaches, future direction. It should NOT simply contain every conversation message.

## 4. Introduce a Context Compiler

Treat relevant-context selection as a distinct architectural responsibility.

```
                CURRENT TASK
                     │
                     ▼
             CONTEXT COMPILER
          ┌─────────┼─────────┐
      Application Capability Behavior
       context      context   context
          └─────────┼─────────┘
                    + current implementation facts
                    + relevant package/capability knowledge
                    ↓
            AGENT CONTEXT PACK
```

Example. User: "Add invoice reminders." Possible compiled context:

```
PRODUCT          Accounting software for small construction companies.
USERS            Owners and bookkeepers.
TERMINOLOGY      The product calls customers "Clients."
BILLING CONTEXT  Invoices belong to Clients. Invoices may be partially paid.
                 Overdue invoices remain payable.
CURRENT REQUEST  Automatically remind Clients about overdue invoices.
RELEVANT IMPLEMENTATION
                 Invoice, InvoicePolicy, InvoiceNotification, billing scheduler, relevant tests
```

This is preferable to sending every project chat, every memory entry, every behavior, or the entire repository. The Context Compiler should compile, not dump.

## 5. Hierarchical context also becomes a retrieval strategy

Because Project Context already exists at Application → Capability → Behavior → Task, context selection becomes easier. For a Billing task: a small amount of global context + high-relevance Billing capability context + very high-relevance behavior context + relevant implementation facts.

This hierarchy therefore serves both product understanding and token efficiency.

Do not introduce an unnecessarily complex generic retrieval/RAG system before testing whether this hierarchy already solves most needs.

## 6. Memory extraction itself can become expensive

Do not assume every user interaction needs a frontier model to update memory. Avoid: every user message → expensive model → summarize → rewrite entire project memory.

Examples that usually should NOT become durable memory: "Make that button slightly bigger." "Try a different shade."

Examples that likely SHOULD: "Managers must never be able to access billing." "We call these Clinics, not Organizations." "Customers should never choose a cleaner directly." "Eventually patients should also be able to book through mobile."

Memory creation should be selective. Use deterministic classification, small models, or batched consolidation where appropriate. Strong models should be used only where semantic judgment warrants their cost.

## 7. Recent complaints reinforce the value of upfront/contextual discovery

A recurring theme in external reviews is that poorly planned projects consume additional credits and require more rework. Some users explicitly want the builder to better understand project requirements and establish a clearer plan before implementation.

This supports our earlier product thesis: the system should subtly guide the user through good product/agile thinking.

However: DO NOT turn this into an enterprise requirements interview. Preserve the magic of "I described something, and software started appearing."

The ideal interaction is:

```
User:    I need scheduling for my cleaning company.
System:  Sure. One thing: should customers choose a cleaner,
         or should we assign whoever is available?
User:    Assign automatically.
System:  proceeds.
```

The agile/product-discovery process should be happening invisibly.

## 8. Preserve speed to first result

Positive reviews of AI app builders consistently praise rapid movement from idea to something working. Do not optimize engineering discipline so aggressively that we destroy this advantage.

Avoid "Question 1 of 27. Please define your personas." Instead: ask only high-value questions, use sensible defaults, build thin vertical slices, show something quickly, let the user react to concrete software, capture new knowledge from their reaction.

This is closer to actual agile development anyway.

## 9. Not every important question belongs before implementation

**Ask before building** when the answer changes fundamental data ownership, money, permissions, destructive behavior, core workflow, or difficult-to-reverse architecture.

**Build first, confirm afterward** when the implementation is cheap to change, visual feedback makes the decision clearer, multiple choices are reasonable, or an initial version helps the user understand the question.

The product should use prototyping as a discovery tool.

## 10. Regressions and unrelated changes are a major trust problem

Another recurring complaint is: "I asked it to change one thing and something that previously worked broke."

Do not make our answer merely "Our coding agent will hopefully be better." Instead combine scoped context, Laravel-aware understanding, targeted tests, Product Behavior Index, behavior diffs, and deterministic verification.

After a change, we should ideally be able to say:

```
REQUESTED CHANGE
Managers can now invite contractors.

OTHER OBSERVED BEHAVIOR CHANGES
None detected.
```

Or:

```
WARNING
Another behavior also changed:
Customer cancellation cutoff 48 hours → 24 hours
This does not appear related to your request.
```

This is more realistic than promising perfect generation. The platform should make agent errors visible in product language.

## 11. Behavior diffs directly address a current category weakness

Developers can inspect `git diff`. Non-technical owners cannot. Our system should derive a behavior diff. A technical change `- isOwner()` / `+ isOwner() || isManager()` becomes "TEAM INVITATIONS. Before: Only owners could invite people. Now: Owners and managers can invite people." The user can meaningfully review this.

Behavior-level review should be considered one of the product's core advantages.

## 12. Direct visual editing should reduce unnecessary agent usage

Another recurring pain point is spending prompts/credits on tiny UI adjustments. Our visual editor should remove many such tasks from the agent loop (padding, margin, gap, text size, width, alignment, radius, typography, basic layout). Where the mapping to Tailwind/source is deterministic, perform direct transformation. No agent required.

If the user selects something and asks for semantic behavior ("Only managers should see this."), selection context should be passed to the agent.

Visual selection helps both token economics and prompt precision.

## 13. Backend churn should be reduced through conventions

Users also report frustration when backend work requires repeated fixing or destabilizes previously working functionality. Our answer should be convention over generation: Laravel default → Laravel first-party package → trusted ecosystem package → first-party capability → deterministic transform → custom generation.

This should reduce architectural variance and therefore reduce the number of ways a later agent can misunderstand an application. Do not generate infrastructure merely because a model can.

## 14. Package/capability reuse should reduce future context too

When a known capability is installed, the system should already understand much of it. An Organizations capability provides known semantics around organizations, memberships, roles, invitations, ownership. The agent should not repeatedly inspect thousands of lines to rediscover what the capability does.

Capability metadata becomes reusable compressed context. This is another potential token advantage.

## 15. The Product Behavior Index also reduces repeated explanatory model calls

Users should not have to ask "What does this page do?", "Who can use this?", "Does this send an email?", "What happens when this button is clicked?". These should be materialized from the application where possible. This provides end-user observability without spending a new model call every time the user wants to understand their own application. For power users, reveal deeper implementation detail progressively.

## 16. Some complaints are not solved by architecture

Do not overclaim. External complaints also include normal SaaS operational issues such as billing policies, credit expiry, outages, support quality, project loss/recovery, infrastructure reliability. Our control-plane architecture does not automatically solve these. They require reliable infrastructure, backups, transparent billing, good support, recovery tooling, operational excellence.

Keep product/AI architecture and SaaS operational quality conceptually separate. Both matter.

## 17. Build metrics around outcomes

From V0, capture enough telemetry to eventually compare:

- **Model efficiency**: cost per accepted change
- **Reliability**: first-attempt verification pass rate
- **Rework**: number of agent retries before acceptance
- **Clarification quality**: whether clarification reduced retries
- **Context effectiveness**: relevant context size vs successful completion
- **Model routing**: success/cost by task class and provider
- **Deterministic tooling**: transform success, verification failures, rollback rate
- **Regression detection**: unexpected behavior changes detected

Do not obsess over dashboards in V0. But preserve the underlying events needed to measure these later.

## 18. Evaluate our context hypothesis experimentally

Our theory is: better structured product context + targeted clarification + selective retrieval should reduce retries, repeated explanation, model exploration, regressions, and total tokens per accepted change.

Do not merely assume this. Test it.

A useful internal experiment: take a representative set of feature requests and compare:

- **Condition A**: agent receives repo + current user request.
- **Condition B**: agent receives repo + current user request + compiled relevant Project Context + relevant Product Behavior information + important confirmed rules.

Measure: success on first attempt, tokens, runtime, test pass rate, regressions, number of retries, final accepted cost.

This should tell us whether our Context Compiler is genuinely valuable.

## 19. Avoid optimizing token count at the expense of correctness

If providing an extra 2,000 tokens of highly relevant product context prevents a failed 30,000-token agent trajectory, that is a win. The target is not minimum context. It is **minimum sufficient context**.

## 20. A possible compounding cost advantage

Our architecture attacks AI cost from several independent directions: incremental product knowledge + selective Context Compiler + Laravel conventions + trusted packages + capability metadata + first-party capabilities + Rector + deterministic visual editing + targeted verification + multi-model routing → less open-ended generation, less repository exploration, less repeated context, fewer retries, fewer regressions, cheaper models where appropriate.

Do not assume every mechanism will produce a large saving. But their effects may compound.

## 21. The strategic insight from the research

The current category seems to have largely solved "Can AI quickly make an application?" The complaints increasingly cluster around "Can it continue changing that application without wasting my time, credits, or trust?"

Our architecture should optimize around that second problem. We should care deeply about understanding accumulated intent, avoiding unnecessary regeneration, maintaining product coherence, making changes observable, reducing regressions, preserving user trust, and keeping cost tied to useful outcomes.

This may be a stronger long-term differentiation than raw initial generation quality.

## What was asked

Use these findings to reassess the architecture and V0. Reason about:

1. Whether hierarchical Project Context is worth its complexity based on these complaint patterns.
2. How to implement a minimal Context Compiler.
3. How memory creation can remain selective and cheap.
4. How to measure cost per accepted change.
5. How to experimentally validate whether context actually reduces retries/tokens.
6. How much clarification is beneficial before it harms speed-to-first-result.
7. How Product Behavior Index and behavior diffs directly address trust/regression complaints.
8. Which complaint categories our architecture does NOT solve.
9. What telemetry V0 should preserve so these hypotheses can be tested.
10. Whether any current subsystem exists mainly because it sounds architecturally elegant rather than because it addresses a real user pain.
11. What should be removed or postponed.
12. What single vertical slice would best test these claims against actual user behavior.

Do not assume our theory is correct. Design V0 so we can falsify it. If structured product context does not materially improve accepted-change cost, reliability, or user confidence, we should be willing to simplify or remove it. Likewise, if behavior-level observability does not help users catch mismatches or understand changes, we should learn that early.

The architecture should increasingly be justified by measurable user outcomes rather than elegance.
